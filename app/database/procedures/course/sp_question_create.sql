-- sp_question_create: agrega una pregunta, con sus opciones, al final de un examen.
-- p_selection_mode: 'single' (exactamente una correcta) o 'multiple' (al menos una).
-- p_answers: arreglo JSON [{"answer": "...", "is_correct": 0|1, "weight": "1.00"}], de 2 a 8
-- opciones, en el orden en que se muestran. Las correctas llevan peso > 0 y las incorrectas 0
-- (ck_answer_weight). PHP ya validó todo; el SP lo vuelve a exigir.
-- Resultados: 1) la fila de course_chapter_test_question; 2) sus test_question_answer.
-- Errores: CHAPTER_NOT_FOUND, CHAPTER_NOT_EXAM, INVALID_ANSWERS.
-- Transacción propia: bloquea el capítulo para numerar sin colisiones.

DROP PROCEDURE IF EXISTS sp_question_create;

DELIMITER $$

CREATE PROCEDURE sp_question_create(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_chapter_id BIGINT UNSIGNED,
    IN p_question TEXT,
    IN p_selection_mode VARCHAR(10),
    IN p_answers JSON
)
BEGIN
    DECLARE v_is_test BOOLEAN DEFAULT NULL;
    DECLARE v_next INT UNSIGNED;
    DECLARE v_question_id BIGINT UNSIGNED;
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_correct INT DEFAULT 0;
    DECLARE v_bad INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT ch.is_test INTO v_is_test
      FROM course_chapter ch
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course c ON c.id = l.course_id
     WHERE ch.id = p_chapter_id AND c.created_by = p_instructor_id
       FOR UPDATE;

    IF v_is_test IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_FOUND';
    END IF;
    IF v_is_test = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_EXAM';
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

    SELECT COALESCE(MAX(sort_order), 0) + 1 INTO v_next
      FROM course_chapter_test_question
     WHERE course_chapter_id = p_chapter_id;

    INSERT INTO course_chapter_test_question (course_chapter_id, sort_order, question, selection_mode)
    VALUES (p_chapter_id, v_next, p_question, p_selection_mode);

    SET v_question_id = LAST_INSERT_ID();

    INSERT INTO test_question_answer (question_id, sort_order, answer, is_correct, weight)
    SELECT v_question_id, j.position, j.answer, j.is_correct, j.weight
      FROM JSON_TABLE(p_answers, '$[*]' COLUMNS (
               position FOR ORDINALITY,
               answer VARCHAR(1000) PATH '$.answer',
               is_correct TINYINT PATH '$.is_correct',
               weight DECIMAL(10,2) PATH '$.weight'
           )) AS j;

    COMMIT;

    SELECT id, course_chapter_id, sort_order, question, selection_mode
      FROM course_chapter_test_question
     WHERE id = v_question_id;

    SELECT id, question_id, sort_order, answer, is_correct, weight
      FROM test_question_answer
     WHERE question_id = v_question_id
     ORDER BY sort_order;
END$$

DELIMITER ;
