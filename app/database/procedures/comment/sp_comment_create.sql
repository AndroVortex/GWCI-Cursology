-- sp_comment_create: publica un comentario de texto en un capítulo de video, o una respuesta.
-- Pueden comentar el alumno con acceso al nivel del capítulo y el instructor dueño del curso.
-- Las respuestas son de un solo nivel: responder a una respuesta la cuelga del mismo comentario
-- principal. p_parent_id debe ser un comentario activo del mismo capítulo.
-- Devuelve el comentario con las columnas de sp_comment_list.
-- Errores: CHAPTER_NOT_FOUND, NO_COMMENTS_ON_EXAM, NO_LEVEL_ACCESS, COMMENT_NOT_FOUND
-- (p_parent_id), INVALID_COMMENT (vacío o de más de 2000 caracteres).

DROP PROCEDURE IF EXISTS sp_comment_create;

DELIMITER $$

CREATE PROCEDURE sp_comment_create(
    IN p_user_id BIGINT UNSIGNED,
    IN p_chapter_id BIGINT UNSIGNED,
    IN p_parent_id BIGINT UNSIGNED,
    IN p_content TEXT
)
BEGIN
    DECLARE v_level_id BIGINT UNSIGNED;
    DECLARE v_is_test BOOLEAN;
    DECLARE v_owner_id BIGINT UNSIGNED;
    DECLARE v_content TEXT;
    DECLARE v_target_id BIGINT UNSIGNED;
    DECLARE v_target_parent BIGINT UNSIGNED;
    DECLARE v_root_id BIGINT UNSIGNED;
    DECLARE v_comment_id BIGINT UNSIGNED;

    SELECT ch.course_level_id, ch.is_test, co.created_by INTO v_level_id, v_is_test, v_owner_id
      FROM course_chapter ch
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course co ON co.id = l.course_id
     WHERE ch.id = p_chapter_id;

    IF v_level_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_FOUND';
    END IF;
    IF v_is_test THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NO_COMMENTS_ON_EXAM';
    END IF;
    IF p_user_id <> v_owner_id
       AND NOT EXISTS (SELECT 1 FROM user_course_level WHERE user_id = p_user_id AND course_level_id = v_level_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NO_LEVEL_ACCESS';
    END IF;

    SET v_content = NULLIF(TRIM(p_content), '');
    IF v_content IS NULL OR CHAR_LENGTH(v_content) > 2000 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_COMMENT';
    END IF;

    IF p_parent_id IS NOT NULL THEN
        SELECT id, comment_parent_id INTO v_target_id, v_target_parent
          FROM chapter_comment
         WHERE id = p_parent_id AND course_chapter_id = p_chapter_id AND active = 1;
        IF v_target_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COMMENT_NOT_FOUND';
        END IF;
        SET v_root_id = COALESCE(v_target_parent, v_target_id);
    END IF;

    INSERT INTO chapter_comment (course_chapter_id, comment_parent_id, commented_by, comment)
    VALUES (p_chapter_id, v_root_id, p_user_id, v_content);

    SET v_comment_id = LAST_INSERT_ID();

    SELECT
        c.id, c.course_chapter_id, c.comment_parent_id, c.commented_by,
        CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS author_name,
        u.avatar AS author_avatar,
        c.commented_by = v_owner_id AS author_is_instructor,
        c.comment, c.commented_at, c.updated_at, c.active,
        0 AS reply_count
    FROM chapter_comment c
    JOIN app_user u ON u.id = c.commented_by
    WHERE c.id = v_comment_id;
END$$

DELIMITER ;
