-- sp_chapter_delete: elimina un capítulo con su contenido y vuelve a numerar los siguientes.
-- No se permite si el nivel ya tiene alumnos con acceso (se conserva su avance).
-- Resultado: media_refs con las referencias de su contenido, para borrarlas de Cloudinary.
-- Errores: CHAPTER_NOT_FOUND, HAS_ENROLLMENTS.
-- Transacción propia: bloquea el capítulo, su nivel y su curso.

DROP PROCEDURE IF EXISTS sp_chapter_delete;

DELIMITER $$

CREATE PROCEDURE sp_chapter_delete(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_chapter_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_level_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_sort INT UNSIGNED;
    DECLARE v_refs JSON;

    -- Progreso, intentos o comentarios que apunten al capítulo impiden borrarlo.
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

    SELECT ch.course_level_id, ch.sort_order
      INTO v_level_id, v_sort
      FROM course_chapter ch
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course c ON c.id = l.course_id
     WHERE ch.id = p_chapter_id AND c.created_by = p_instructor_id
       FOR UPDATE;

    IF v_level_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_FOUND';
    END IF;

    IF EXISTS (SELECT 1 FROM user_course_level WHERE course_level_id = v_level_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HAS_ENROLLMENTS';
    END IF;

    SELECT JSON_ARRAYAGG(content_uri) INTO v_refs
      FROM course_chapter_content
     WHERE course_chapter_id = p_chapter_id AND content_uri IS NOT NULL;

    DELETE FROM course_chapter_content WHERE course_chapter_id = p_chapter_id;
    DELETE FROM course_chapter WHERE id = p_chapter_id;

    -- En orden ascendente para no chocar con uq_chapter_order mientras se renumera.
    UPDATE course_chapter
       SET sort_order = sort_order - 1
     WHERE course_level_id = v_level_id AND sort_order > v_sort
     ORDER BY sort_order;

    COMMIT;

    SELECT COALESCE(v_refs, JSON_ARRAY()) AS media_refs;
END$$

DELIMITER ;
