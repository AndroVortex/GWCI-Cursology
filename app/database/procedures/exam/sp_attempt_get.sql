-- sp_attempt_get: un intento de examen del alumno, desde las copias de test_attempt_*.
-- Lo llaman sp_attempt_start y sp_attempt_submit para devolver siempre la misma forma.
-- Resultados (en este orden):
--   1) el intento + chapter_completed_at, level_completed_at y course_completed_at del alumno
--   2) preguntas (por el orden del examen)
--   3) opciones (por el orden de cada pregunta)
-- Mientras el intento está abierto, las opciones NO incluyen is_correct_snapshot ni
-- weight_snapshot, y las preguntas no incluyen earned_points.
-- Errores: ATTEMPT_NOT_FOUND (no existe o no es del alumno).

DROP PROCEDURE IF EXISTS sp_attempt_get;

DELIMITER $$

CREATE PROCEDURE sp_attempt_get(
    IN p_user_id BIGINT UNSIGNED,
    IN p_attempt_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_found BIGINT UNSIGNED;
    DECLARE v_submitted BOOLEAN;

    SELECT id, submitted_at IS NOT NULL INTO v_found, v_submitted
      FROM test_attempt
     WHERE id = p_attempt_id AND user_id = p_user_id;

    IF v_found IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ATTEMPT_NOT_FOUND';
    END IF;

    SELECT a.id, a.course_chapter_id, a.course_level_id, a.attempt_number, a.started_at, a.submitted_at,
           a.minimum_grade_snapshot, a.max_points, a.earned_points, a.grade, a.passed,
           p.completed_at AS chapter_completed_at,
           ucl.completed_at AS level_completed_at,
           uc.completed_at AS course_completed_at
      FROM test_attempt a
      JOIN user_course_level ucl ON ucl.user_id = a.user_id AND ucl.course_level_id = a.course_level_id
      JOIN user_courses uc ON uc.user_id = a.user_id AND uc.course_id = ucl.course_id
      LEFT JOIN user_chapter_progress p ON p.user_id = a.user_id AND p.course_chapter_id = a.course_chapter_id
     WHERE a.id = p_attempt_id;

    IF v_submitted THEN
        SELECT aq.question_id, aq.question_snapshot, aq.selection_mode_snapshot, aq.max_points, aq.earned_points
          FROM test_attempt_question aq
          JOIN course_chapter_test_question q ON q.id = aq.question_id
         WHERE aq.attempt_id = p_attempt_id
         ORDER BY q.sort_order;

        SELECT aa.question_id, aa.answer_id, aa.answer_snapshot, aa.selected, aa.is_correct_snapshot, aa.weight_snapshot
          FROM test_attempt_answer aa
          JOIN test_question_answer ta ON ta.id = aa.answer_id
         WHERE aa.attempt_id = p_attempt_id
         ORDER BY aa.question_id, ta.sort_order;
    ELSE
        SELECT aq.question_id, aq.question_snapshot, aq.selection_mode_snapshot, aq.max_points
          FROM test_attempt_question aq
          JOIN course_chapter_test_question q ON q.id = aq.question_id
         WHERE aq.attempt_id = p_attempt_id
         ORDER BY q.sort_order;

        SELECT aa.question_id, aa.answer_id, aa.answer_snapshot
          FROM test_attempt_answer aa
          JOIN test_question_answer ta ON ta.id = aa.answer_id
         WHERE aa.attempt_id = p_attempt_id
         ORDER BY aa.question_id, ta.sort_order;
    END IF;
END$$

DELIMITER ;
