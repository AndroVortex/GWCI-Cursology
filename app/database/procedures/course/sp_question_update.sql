-- sp_question_update: reemplaza el texto, el modo y las opciones de una pregunta.
-- Mismas reglas que sp_question_create. Si algún alumno ya presentó el examen con esta
-- pregunta (test_attempt_question), no se puede cambiar: los intentos guardan referencias
-- a las opciones.
-- Resultados: 1) la fila de course_chapter_test_question; 2) sus test_question_answer.
-- Errores: QUESTION_NOT_FOUND, INVALID_ANSWERS, EXAM_HAS_ATTEMPTS.
-- Transacción propia: bloquea la pregunta y su capítulo.

DROP PROCEDURE IF EXISTS sp_question_update;

DELIMITER $$

CREATE PROCEDURE sp_question_update(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_question_id BIGINT UNSIGNED,
    IN p_question TEXT,
    IN p_selection_mode VARCHAR(10),
    IN p_answers JSON
)
BEGIN
    DECLARE v_found BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_correct INT DEFAULT 0;
    DECLARE v_bad INT DEFAULT 0;

    -- Opciones referenciadas por test_attempt_answer: hay intentos.
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

    SELECT q.id INTO v_found
      FROM course_chapter_test_question q
      JOIN course_chapter ch ON ch.id = q.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course c ON c.id = l.course_id
     WHERE q.id = p_question_id AND c.created_by = p_instructor_id
       FOR UPDATE;

    IF v_found IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QUESTION_NOT_FOUND';
    END IF;
    IF EXISTS (SELECT 1 FROM test_attempt_question WHERE question_id = p_question_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EXAM_HAS_ATTEMPTS';
    END IF;

    SELECT COUNT(*),
           COALESCE(SUM(j.is_correct = 1), 0),
           COALESCE(SUM(
               j.answer IS NULL OR CHAR_LENGTH(TRIM(j.answer)) = 0 OR j.is_correct IS NULL OR j.weight IS NULL
               OR (j.is_correct = 1 AND j.weight <= 0) OR (j.is_correct = 0 AND j.weight <> 0)
           ), 0)
      INTO v_count, v_correct, v_bad
      FROM JSON_TABLE(p_answers, '$[*]' COLUMNS (
               answer VARCHAR(1000) PATH '$.answer',
               is_correct TINYINT PATH '$.is_correct',
               weight DECIMAL(10,2) PATH '$.weight'
           )) AS j;

    IF p_selection_mode IS NULL OR p_selection_mode COLLATE utf8mb4_bin NOT IN ('single', 'multiple')
       OR v_count < 2 OR v_count > 8 OR v_bad > 0 OR v_correct < 1
       OR (p_selection_mode = 'single' AND v_correct <> 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_ANSWERS';
    END IF;

    UPDATE course_chapter_test_question
       SET question = p_question,
           selection_mode = p_selection_mode
     WHERE id = p_question_id;

    DELETE FROM test_question_answer WHERE question_id = p_question_id;

    INSERT INTO test_question_answer (question_id, sort_order, answer, is_correct, weight)
    SELECT p_question_id, j.position, j.answer, j.is_correct, j.weight
      FROM JSON_TABLE(p_answers, '$[*]' COLUMNS (
               position FOR ORDINALITY,
               answer VARCHAR(1000) PATH '$.answer',
               is_correct TINYINT PATH '$.is_correct',
               weight DECIMAL(10,2) PATH '$.weight'
           )) AS j;

    COMMIT;

    SELECT id, course_chapter_id, sort_order, question, selection_mode
      FROM course_chapter_test_question
     WHERE id = p_question_id;

    SELECT id, question_id, sort_order, answer, is_correct, weight
      FROM test_question_answer
     WHERE question_id = p_question_id
     ORDER BY sort_order;
END$$

DELIMITER ;
