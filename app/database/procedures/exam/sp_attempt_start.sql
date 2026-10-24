-- sp_attempt_start: abre un intento del examen o retoma el que el alumno dejó abierto
-- (intentos ilimitados, uno abierto a la vez). Copia las preguntas y TODAS sus opciones a
-- test_attempt_question / test_attempt_answer: lo que el instructor cambie después no
-- afecta al intento. El máximo de cada pregunta es la suma de los pesos de sus correctas.
-- También registra la visita al capítulo (avance "en curso" y último acceso).
-- Devuelve lo mismo que sp_attempt_get (sin las claves del examen).
-- Errores: CHAPTER_NOT_FOUND, CHAPTER_NOT_EXAM, NO_LEVEL_ACCESS, EXAM_WITHOUT_QUESTIONS.

DROP PROCEDURE IF EXISTS sp_attempt_start;

DELIMITER $$

CREATE PROCEDURE sp_attempt_start(
    IN p_user_id BIGINT UNSIGNED,
    IN p_chapter_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_level_id BIGINT UNSIGNED;
    DECLARE v_course_id BIGINT UNSIGNED;
    DECLARE v_is_test BOOLEAN;
    DECLARE v_minimum DECIMAL(5,2);
    DECLARE v_access BIGINT UNSIGNED;
    DECLARE v_attempt_id BIGINT UNSIGNED;
    DECLARE v_number INT UNSIGNED;
    DECLARE v_max_points DECIMAL(14,2);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT ch.course_level_id, l.course_id, ch.is_test, ch.minimum_passing_grade
      INTO v_level_id, v_course_id, v_is_test, v_minimum
      FROM course_chapter ch
      JOIN course_level l ON l.id = ch.course_level_id
     WHERE ch.id = p_chapter_id;

    IF v_level_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_FOUND';
    END IF;
    IF NOT v_is_test THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_EXAM';
    END IF;

    START TRANSACTION;

    -- Bloquea el acceso del alumno al nivel: serializa los intentos que abra en paralelo.
    SELECT course_level_id INTO v_access
      FROM user_course_level
     WHERE user_id = p_user_id AND course_level_id = v_level_id
       FOR UPDATE;
    IF v_access IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NO_LEVEL_ACCESS';
    END IF;

    SELECT id INTO v_attempt_id
      FROM test_attempt
     WHERE user_id = p_user_id AND course_chapter_id = p_chapter_id AND submitted_at IS NULL
     LIMIT 1;

    IF v_attempt_id IS NULL THEN
        SELECT SUM(a.weight) INTO v_max_points
          FROM course_chapter_test_question q
          JOIN test_question_answer a ON a.question_id = q.id AND a.is_correct = 1
         WHERE q.course_chapter_id = p_chapter_id;

        IF v_max_points IS NULL OR v_max_points <= 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EXAM_WITHOUT_QUESTIONS';
        END IF;

        SELECT COALESCE(MAX(attempt_number), 0) + 1 INTO v_number
          FROM test_attempt
         WHERE user_id = p_user_id AND course_chapter_id = p_chapter_id;

        INSERT INTO test_attempt (user_id, course_chapter_id, course_level_id, attempt_number,
                                  minimum_grade_snapshot, max_points)
        VALUES (p_user_id, p_chapter_id, v_level_id, v_number, v_minimum, v_max_points);
        SET v_attempt_id = LAST_INSERT_ID();

        INSERT INTO test_attempt_question (attempt_id, question_id, course_chapter_id, question_snapshot,
                                           selection_mode_snapshot, max_points)
        SELECT v_attempt_id, q.id, q.course_chapter_id, q.question, q.selection_mode,
               SUM(CASE WHEN a.is_correct = 1 THEN a.weight ELSE 0 END)
          FROM course_chapter_test_question q
          JOIN test_question_answer a ON a.question_id = q.id
         WHERE q.course_chapter_id = p_chapter_id
         GROUP BY q.id, q.course_chapter_id, q.question, q.selection_mode;

        INSERT INTO test_attempt_answer (attempt_id, question_id, answer_id, answer_snapshot,
                                         is_correct_snapshot, weight_snapshot)
        SELECT v_attempt_id, a.question_id, a.id, a.answer, a.is_correct, a.weight
          FROM test_question_answer a
          JOIN course_chapter_test_question q ON q.id = a.question_id
         WHERE q.course_chapter_id = p_chapter_id;
    END IF;

    INSERT INTO user_chapter_progress (user_id, course_chapter_id, course_level_id)
    VALUES (p_user_id, p_chapter_id, v_level_id) AS incoming
    ON DUPLICATE KEY UPDATE last_accessed_at = NOW(6);

    UPDATE user_course_level SET last_accessed_at = NOW(6)
     WHERE user_id = p_user_id AND course_level_id = v_level_id;
    UPDATE user_courses SET last_accessed_at = NOW(6)
     WHERE user_id = p_user_id AND course_id = v_course_id;

    COMMIT;

    CALL sp_attempt_get(p_user_id, v_attempt_id);
END$$

DELIMITER ;
