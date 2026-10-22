-- sp_content_delete: quita el video principal o un recurso de un capítulo.
-- Resultado: media_refs con su referencia, para borrar el archivo de Cloudinary.
-- Errores: CONTENT_NOT_FOUND.

DROP PROCEDURE IF EXISTS sp_content_delete;

DELIMITER $$

CREATE PROCEDURE sp_content_delete(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_content_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_found BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_ref VARCHAR(2048);

    SELECT ct.id, ct.content_uri INTO v_found, v_ref
      FROM course_chapter_content ct
      JOIN course_chapter ch ON ch.id = ct.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course c ON c.id = l.course_id
     WHERE ct.id = p_content_id AND c.created_by = p_instructor_id;

    IF v_found IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CONTENT_NOT_FOUND';
    END IF;

    DELETE FROM course_chapter_content WHERE id = p_content_id;

    SELECT IF(v_ref IS NULL, JSON_ARRAY(), JSON_ARRAY(v_ref)) AS media_refs;
END$$

DELIMITER ;
