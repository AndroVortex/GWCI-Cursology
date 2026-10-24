-- sp_exam_get_for_owner: un examen con sus preguntas y opciones, para que su instructor lo
-- pruebe (ExamGrader). Incluye las claves (is_correct, weight): solo para el dueño.
-- Resultados: 1) course_chapter; 2) course_chapter_test_question por orden;
--             3) test_question_answer por pregunta y orden.
-- Errores: CHAPTER_NOT_FOUND, CHAPTER_NOT_EXAM.

DROP PROCEDURE IF EXISTS sp_exam_get_for_owner;

DELIMITER $$

CREATE PROCEDURE sp_exam_get_for_owner(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_chapter_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_is_test BOOLEAN DEFAULT NULL;

    SELECT ch.is_test INTO v_is_test
      FROM course_chapter ch
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course c ON c.id = l.course_id
     WHERE ch.id = p_chapter_id AND c.created_by = p_instructor_id;

    IF v_is_test IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_FOUND';
    END IF;
    IF v_is_test = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_EXAM';
    END IF;

    SELECT id, course_level_id, title, sort_order, is_test, minimum_passing_grade, created_at, updated_at
      FROM course_chapter
     WHERE id = p_chapter_id;

    SELECT id, course_chapter_id, sort_order, question, selection_mode
      FROM course_chapter_test_question
     WHERE course_chapter_id = p_chapter_id
     ORDER BY sort_order;

    SELECT a.id, a.question_id, a.sort_order, a.answer, a.is_correct, a.weight
      FROM test_question_answer a
      JOIN course_chapter_test_question q ON q.id = a.question_id
     WHERE q.course_chapter_id = p_chapter_id
     ORDER BY q.sort_order, a.sort_order;
END$$

DELIMITER ;
