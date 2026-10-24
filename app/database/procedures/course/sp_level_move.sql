-- sp_level_move: sube o baja un nivel un lugar, intercambiándolo con su vecino.
-- p_direction: 'up' o 'down'. En el extremo (subir el primero, bajar el último) no cambia nada.
-- uq_level_order no admite dos niveles con el mismo orden y ck_level_order exige >= 1,
-- así que el intercambio pasa por un valor temporal (máximo + 1).
-- Resultado: la fila de course_level ya movida. Errores: LEVEL_NOT_FOUND, INVALID_DIRECTION.
-- Transacción propia: bloquea el curso, como sp_level_create y sp_level_delete.

DROP PROCEDURE IF EXISTS sp_level_move;

DELIMITER $$

CREATE PROCEDURE sp_level_move(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_level_id BIGINT UNSIGNED,
    IN p_direction VARCHAR(4)
)
BEGIN
    DECLARE v_course_id BIGINT UNSIGNED DEFAULT NULL;
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

    SELECT c.id INTO v_course_id
      FROM course_level l
      JOIN course c ON c.id = l.course_id
     WHERE l.id = p_level_id AND c.created_by = p_instructor_id
       FOR UPDATE;

    IF v_course_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LEVEL_NOT_FOUND';
    END IF;

    SELECT sort_order INTO v_sort FROM course_level WHERE id = p_level_id;

    IF p_direction = 'up' THEN
        SELECT id, sort_order INTO v_other_id, v_other_sort
          FROM course_level
         WHERE course_id = v_course_id AND sort_order < v_sort
         ORDER BY sort_order DESC
         LIMIT 1;
    ELSE
        SELECT id, sort_order INTO v_other_id, v_other_sort
          FROM course_level
         WHERE course_id = v_course_id AND sort_order > v_sort
         ORDER BY sort_order
         LIMIT 1;
    END IF;

    IF v_other_id IS NOT NULL THEN
        SELECT MAX(sort_order) + 1 INTO v_temp FROM course_level WHERE course_id = v_course_id;
        UPDATE course_level SET sort_order = v_temp WHERE id = p_level_id;
        UPDATE course_level SET sort_order = v_sort WHERE id = v_other_id;
        UPDATE course_level SET sort_order = v_other_sort WHERE id = p_level_id;
    END IF;

    COMMIT;

    SELECT id, course_id, title, description, onboarding_video, sort_order, cost, created_at, updated_at
      FROM course_level
     WHERE id = p_level_id;
END$$

DELIMITER ;
