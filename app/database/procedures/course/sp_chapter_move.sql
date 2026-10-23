-- sp_chapter_move: sube o baja un capítulo un lugar dentro de su nivel.
-- p_direction: 'up' o 'down'. En el extremo (subir el primero, bajar el último) no cambia nada.
-- uq_chapter_order no admite dos capítulos con el mismo orden y ck_chapter_order exige >= 1,
-- así que el intercambio pasa por un valor temporal (máximo + 1).
-- Resultado: la fila de course_chapter ya movida. Errores: CHAPTER_NOT_FOUND, INVALID_DIRECTION.
-- Transacción propia: bloquea el nivel, como sp_chapter_create y sp_chapter_delete.

DROP PROCEDURE IF EXISTS sp_chapter_move;

DELIMITER $$

CREATE PROCEDURE sp_chapter_move(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_chapter_id BIGINT UNSIGNED,
    IN p_direction VARCHAR(4)
)
BEGIN
    DECLARE v_level_id BIGINT UNSIGNED DEFAULT NULL;
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

    SELECT l.id INTO v_level_id
      FROM course_chapter ch
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course c ON c.id = l.course_id
     WHERE ch.id = p_chapter_id AND c.created_by = p_instructor_id
       FOR UPDATE;

    IF v_level_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_FOUND';
    END IF;

    SELECT sort_order INTO v_sort FROM course_chapter WHERE id = p_chapter_id;

    IF p_direction = 'up' THEN
        SELECT id, sort_order INTO v_other_id, v_other_sort
          FROM course_chapter
         WHERE course_level_id = v_level_id AND sort_order < v_sort
         ORDER BY sort_order DESC
         LIMIT 1;
    ELSE
        SELECT id, sort_order INTO v_other_id, v_other_sort
          FROM course_chapter
         WHERE course_level_id = v_level_id AND sort_order > v_sort
         ORDER BY sort_order
         LIMIT 1;
    END IF;

    IF v_other_id IS NOT NULL THEN
        SELECT MAX(sort_order) + 1 INTO v_temp FROM course_chapter WHERE course_level_id = v_level_id;
        UPDATE course_chapter SET sort_order = v_temp WHERE id = p_chapter_id;
        UPDATE course_chapter SET sort_order = v_sort WHERE id = v_other_id;
        UPDATE course_chapter SET sort_order = v_other_sort WHERE id = p_chapter_id;
    END IF;

    COMMIT;

    SELECT id, course_level_id, title, sort_order, is_test, minimum_passing_grade, created_at, updated_at
      FROM course_chapter
     WHERE id = p_chapter_id;
END$$

DELIMITER ;
