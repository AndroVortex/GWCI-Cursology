-- sp_attempt_submit: entrega un intento y lo califica con la regla A, sobre las copias:
--   - cada pregunta vale la suma de los pesos de sus opciones correctas;
--   - se ganan los pesos de las correctas marcadas, pero si se marcó alguna incorrecta la
--     pregunta vale 0;
--   - grade = ROUND(100 * puntos / máximo, 2), la misma expresión que exige ck_attempt_result
--     (ExamGrader en PHP calcula igual).
-- Si aprueba, el capítulo queda terminado y se recalculan el nivel y el curso.
-- p_selected: arreglo JSON con los ids de las opciones marcadas (puede estar vacío).
-- Devuelve lo mismo que sp_attempt_get, ya con las respuestas correctas.
-- Errores: ATTEMPT_NOT_FOUND, ATTEMPT_ALREADY_SUBMITTED, INVALID_SELECTION (opciones que no
-- son del intento, repetidas o más de una en preguntas de opción única).

DROP PROCEDURE IF EXISTS sp_attempt_submit;

DELIMITER $$

CREATE PROCEDURE sp_attempt_submit(
    IN p_user_id BIGINT UNSIGNED,
    IN p_attempt_id BIGINT UNSIGNED,
    IN p_selected JSON
)
BEGIN
    DECLARE v_found BIGINT UNSIGNED;
    DECLARE v_chapter_id BIGINT UNSIGNED;
    DECLARE v_level_id BIGINT UNSIGNED;
    DECLARE v_submitted DATETIME(6);
    DECLARE v_count INT UNSIGNED;
    DECLARE v_matched INT UNSIGNED;
    DECLARE v_earned DECIMAL(14,2);
    DECLARE v_passed BOOLEAN;
    DECLARE v_was_done BOOLEAN DEFAULT FALSE;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_selected IS NULL OR JSON_TYPE(p_selected) <> 'ARRAY' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_SELECTION';
    END IF;

    START TRANSACTION;

    SELECT id, course_chapter_id, course_level_id, submitted_at
      INTO v_found, v_chapter_id, v_level_id, v_submitted
      FROM test_attempt
     WHERE id = p_attempt_id AND user_id = p_user_id
       FOR UPDATE;

    IF v_found IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ATTEMPT_NOT_FOUND';
    END IF;
    IF v_submitted IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ATTEMPT_ALREADY_SUBMITTED';
    END IF;

    -- Cada id debe ser una opción distinta del intento (los repetidos o ajenos no cuentan).
    SELECT COUNT(*), COUNT(DISTINCT aa.answer_id)
      INTO v_count, v_matched
      FROM JSON_TABLE(p_selected, '$[*]' COLUMNS (answer_id BIGINT UNSIGNED PATH '$' NULL ON ERROR)) s
      LEFT JOIN test_attempt_answer aa ON aa.attempt_id = p_attempt_id AND aa.answer_id = s.answer_id;

    IF v_matched <> v_count THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_SELECTION';
    END IF;

    UPDATE test_attempt_answer aa
      JOIN JSON_TABLE(p_selected, '$[*]' COLUMNS (answer_id BIGINT UNSIGNED PATH '$')) s ON s.answer_id = aa.answer_id
       SET aa.selected = 1
     WHERE aa.attempt_id = p_attempt_id;

    IF EXISTS (SELECT 1
                 FROM test_attempt_question aq
                 JOIN test_attempt_answer aa ON aa.attempt_id = aq.attempt_id AND aa.question_id = aq.question_id
                WHERE aq.attempt_id = p_attempt_id AND aq.selection_mode_snapshot = 'single'
                GROUP BY aq.question_id
               HAVING SUM(aa.selected) > 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_SELECTION';
    END IF;

    UPDATE test_attempt_question aq
      JOIN (SELECT question_id,
                   CASE WHEN SUM(selected = 1 AND is_correct_snapshot = 0) > 0 THEN 0
                        ELSE SUM(CASE WHEN selected = 1 AND is_correct_snapshot = 1 THEN weight_snapshot ELSE 0 END)
                   END AS earned
              FROM test_attempt_answer
             WHERE attempt_id = p_attempt_id
             GROUP BY question_id) score ON score.question_id = aq.question_id
       SET aq.earned_points = score.earned
     WHERE aq.attempt_id = p_attempt_id;

    SELECT SUM(earned_points) INTO v_earned FROM test_attempt_question WHERE attempt_id = p_attempt_id;

    UPDATE test_attempt
       SET earned_points = v_earned,
           grade = ROUND(100 * v_earned / max_points, 2),
           submitted_at = NOW(6)
     WHERE id = p_attempt_id;

    SELECT passed INTO v_passed FROM test_attempt WHERE id = p_attempt_id;

    IF v_passed THEN
        SELECT completed_at IS NOT NULL INTO v_was_done
          FROM user_chapter_progress
         WHERE user_id = p_user_id AND course_chapter_id = v_chapter_id;

        INSERT INTO user_chapter_progress (user_id, course_chapter_id, course_level_id, completed_at)
        VALUES (p_user_id, v_chapter_id, v_level_id, NOW(6)) AS incoming
        ON DUPLICATE KEY UPDATE
            last_accessed_at = NOW(6),
            completed_at = COALESCE(user_chapter_progress.completed_at, incoming.completed_at);

        IF NOT COALESCE(v_was_done, FALSE) THEN
            CALL sp_learning_recalculate(p_user_id, v_level_id);
        END IF;
    END IF;

    UPDATE user_course_level SET last_accessed_at = NOW(6)
     WHERE user_id = p_user_id AND course_level_id = v_level_id;
    UPDATE user_courses uc
      JOIN course_level l ON l.course_id = uc.course_id AND l.id = v_level_id
       SET uc.last_accessed_at = NOW(6)
     WHERE uc.user_id = p_user_id;

    COMMIT;

    CALL sp_attempt_get(p_user_id, p_attempt_id);
END$$

DELIMITER ;
