-- sp_question_move: sube o baja una pregunta un lugar dentro de su examen.
-- p_direction: 'up' o 'down'. En el extremo no cambia nada. Igual que sp_chapter_move, el
-- intercambio pasa por un valor temporal (máximo + 1) por uq_question_order y ck_question_order.
-- Resultado: la fila de course_chapter_test_question ya movida.
-- Errores: QUESTION_NOT_FOUND, INVALID_DIRECTION.
-- Transacción propia: bloquea la pregunta y su capítulo.

DROP PROCEDURE IF EXISTS sp_question_move;

DELIMITER $$

CREATE PROCEDURE sp_question_move(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_question_id BIGINT UNSIGNED,
    IN p_direction VARCHAR(4)
)
BEGIN
    DECLARE v_chapter_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_sort INT UNSIGNED;
    DECLARE v_other_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_other_sort INT UNSIGNED;
    DECLARE v_temp INT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_direction IS NULL OR p_direction NOT IN ('up', 'down') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_DIRECTION';
    END IF;

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

    IF p_direction = 'up' THEN
        SELECT id, sort_order INTO v_other_id, v_other_sort
          FROM course_chapter_test_question
         WHERE course_chapter_id = v_chapter_id AND sort_order < v_sort
         ORDER BY sort_order DESC
         LIMIT 1;
    ELSE
        SELECT id, sort_order INTO v_other_id, v_other_sort
          FROM course_chapter_test_question
         WHERE course_chapter_id = v_chapter_id AND sort_order > v_sort
         ORDER BY sort_order
         LIMIT 1;
    END IF;

    IF v_other_id IS NOT NULL THEN
        SELECT MAX(sort_order) + 1 INTO v_temp FROM course_chapter_test_question WHERE course_chapter_id = v_chapter_id;
        UPDATE course_chapter_test_question SET sort_order = v_temp WHERE id = p_question_id;
        UPDATE course_chapter_test_question SET sort_order = v_sort WHERE id = v_other_id;
        UPDATE course_chapter_test_question SET sort_order = v_other_sort WHERE id = p_question_id;
    END IF;

    COMMIT;

    SELECT id, course_chapter_id, sort_order, question, selection_mode
      FROM course_chapter_test_question
     WHERE id = p_question_id;
END$$

DELIMITER ;
