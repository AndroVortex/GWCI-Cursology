-- sp_level_delete: elimina un nivel vacío y vuelve a numerar los siguientes (1..N).
-- Solo si no tiene capítulos ni alumnos con acceso.
-- Resultado: media_refs con el video de introducción, para borrarlo de Cloudinary.
-- Errores: LEVEL_NOT_FOUND, LEVEL_NOT_EMPTY, HAS_ENROLLMENTS.
-- Transacción propia: bloquea el curso y el nivel.

DROP PROCEDURE IF EXISTS sp_level_delete;

DELIMITER $$

CREATE PROCEDURE sp_level_delete(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_level_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_course_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_sort INT UNSIGNED;
    DECLARE v_video VARCHAR(2048);

    -- Una FK que aún apunte al nivel (p. ej. una compra) impide borrarlo.
    DECLARE EXIT HANDLER FOR 1451
    BEGIN
        ROLLBACK;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HAS_ENROLLMENTS';
    END;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT l.course_id, l.sort_order, l.onboarding_video
      INTO v_course_id, v_sort, v_video
      FROM course_level l
      JOIN course c ON c.id = l.course_id
     WHERE l.id = p_level_id AND c.created_by = p_instructor_id
       FOR UPDATE;

    IF v_course_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LEVEL_NOT_FOUND';
    END IF;

    IF EXISTS (SELECT 1 FROM course_chapter WHERE course_level_id = p_level_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LEVEL_NOT_EMPTY';
    END IF;

    IF EXISTS (SELECT 1 FROM user_course_level WHERE course_level_id = p_level_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HAS_ENROLLMENTS';
    END IF;

    DELETE FROM course_level WHERE id = p_level_id;

    -- En orden ascendente para no chocar con uq_level_order mientras se renumera.
    UPDATE course_level
       SET sort_order = sort_order - 1
     WHERE course_id = v_course_id AND sort_order > v_sort
     ORDER BY sort_order;

    COMMIT;

    SELECT JSON_ARRAY(v_video) AS media_refs;
END$$

DELIMITER ;
