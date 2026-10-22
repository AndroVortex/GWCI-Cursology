-- sp_content_add: agrega el video principal (p_is_main = 1) o un recurso a un capítulo.
-- PHP ya verificó el archivo en Cloudinary; content_type 'text' usa p_text_content y
-- el resto p_content_uri (los CHECK del DDL lo vuelven a exigir).
-- Resultado: la fila de course_chapter_content.
-- Errores: CHAPTER_NOT_FOUND, MAIN_VIDEO_EXISTS (uq_content_main: un solo video principal).

DROP PROCEDURE IF EXISTS sp_content_add;

DELIMITER $$

CREATE PROCEDURE sp_content_add(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_chapter_id BIGINT UNSIGNED,
    IN p_title VARCHAR(200),
    IN p_content_type VARCHAR(16),
    IN p_content_uri VARCHAR(2048),
    IN p_text_content LONGTEXT,
    IN p_mime_type VARCHAR(127),
    IN p_original_filename VARCHAR(255),
    IN p_size_bytes BIGINT UNSIGNED,
    IN p_duration_seconds INT UNSIGNED,
    IN p_is_main BOOLEAN
)
BEGIN
    DECLARE v_content_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR 1062
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MAIN_VIDEO_EXISTS';

    IF NOT EXISTS (
        SELECT 1
          FROM course_chapter ch
          JOIN course_level l ON l.id = ch.course_level_id
          JOIN course c ON c.id = l.course_id
         WHERE ch.id = p_chapter_id AND c.created_by = p_instructor_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_FOUND';
    END IF;

    INSERT INTO course_chapter_content (
        course_chapter_id, title, content_type, content_uri, text_content,
        mime_type, original_filename, size_bytes, duration_seconds, is_main
    ) VALUES (
        p_chapter_id, p_title, p_content_type, p_content_uri, p_text_content,
        p_mime_type, p_original_filename, p_size_bytes, p_duration_seconds, p_is_main
    );

    SET v_content_id = LAST_INSERT_ID();

    SELECT id, course_chapter_id, title, content_type, content_uri, text_content, mime_type,
           original_filename, size_bytes, duration_seconds, is_main, main_slot, created_at
      FROM course_chapter_content
     WHERE id = v_content_id;
END$$

DELIMITER ;
