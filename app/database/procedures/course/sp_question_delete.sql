-- sp_question_delete: elimina una pregunta con sus opciones y vuelve a numerar las siguientes.
-- No se permite si algún alumno ya presentó el examen con esta pregunta.
-- Resultado: course_chapter_id del examen. Errores: QUESTION_NOT_FOUND, EXAM_HAS_ATTEMPTS.
-- Transacción propia: bloquea la pregunta y su capítulo.

DROP PROCEDURE IF EXISTS sp_question_delete;

DELIMITER $$

CREATE PROCEDURE sp_question_delete(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_question_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_chapter_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_sort INT UNSIGNED;

    DECLARE EXIT HANDLER FOR 1451
    BEGIN
        ROLLBACK;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EXAM_HAS_ATTEMPTS';
    END;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT q.course_chapter_id, q.sort_order INTO v_chapter_id, v_sort
      FROM course_chapter_test_question q
      JOIN course_chapter ch ON ch.id = q.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course c ON c.id = l.course_id
     WHERE q.id = p_question_id AND c.created_by = p_instructor_id
       FOR UPDATE;

    IF v_chapter_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QUESTION_NOT_FOUND';
    END IF;
    IF EXISTS (SELECT 1 FROM test_attempt_question WHERE question_id = p_question_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EXAM_HAS_ATTEMPTS';
    END IF;

    DELETE FROM test_question_answer WHERE question_id = p_question_id;
    DELETE FROM course_chapter_test_question WHERE id = p_question_id;

    -- En orden ascendente para no chocar con uq_question_order mientras se renumera.
    UPDATE course_chapter_test_question
       SET sort_order = sort_order - 1
     WHERE course_chapter_id = v_chapter_id AND sort_order > v_sort
     ORDER BY sort_order;

    COMMIT;

    SELECT v_chapter_id AS course_chapter_id;
END$$

DELIMITER ;
