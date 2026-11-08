-- sp_comment_list: los comentarios de un capítulo de video, para el alumno con acceso a su nivel
-- o para el instructor dueño del curso (también si el curso se dio de baja).
-- Un comentario principal eliminado u oculto solo se lista si tiene respuestas visibles, con
-- comment NULL ("Comentario eliminado"). Las respuestas eliminadas u ocultas no se listan.
-- Columnas de cada comentario: id, course_chapter_id, comment_parent_id, commented_by,
-- author_name, author_avatar, author_is_instructor, comment, commented_at, updated_at, active,
-- reply_count.
-- Resultados (en este orden):
--   1) los comentarios principales de la página (p_limit desde p_offset), del más nuevo al
--      más antiguo
--   2) sus respuestas visibles, de la más antigua a la más nueva
--   3) las reacciones de todos ellos: target_id, reaction_id, emoji, reaction_name, total y
--      mine (1 si es la de quien consulta); solo reacciones activas del catálogo
--   4) total de comentarios principales visibles y viewer_is_owner (1 si quien consulta es el
--      instructor del curso: puede ocultar comentarios)
-- Errores: CHAPTER_NOT_FOUND, NO_COMMENTS_ON_EXAM, NO_LEVEL_ACCESS.

DROP PROCEDURE IF EXISTS sp_comment_list;

DELIMITER $$

CREATE PROCEDURE sp_comment_list(
    IN p_user_id BIGINT UNSIGNED,
    IN p_chapter_id BIGINT UNSIGNED,
    IN p_limit INT UNSIGNED,
    IN p_offset INT UNSIGNED
)
BEGIN
    DECLARE v_level_id BIGINT UNSIGNED;
    DECLARE v_is_test BOOLEAN;
    DECLARE v_owner_id BIGINT UNSIGNED;

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

    SELECT
        c.id, c.course_chapter_id, c.comment_parent_id, c.commented_by,
        CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS author_name,
        u.avatar AS author_avatar,
        c.commented_by = v_owner_id AS author_is_instructor,
        IF(c.active = 1, c.comment, NULL) AS comment,
        c.commented_at, c.updated_at, c.active,
        (SELECT COUNT(*) FROM chapter_comment r WHERE r.comment_parent_id = c.id AND r.active = 1) AS reply_count
    FROM chapter_comment c
    JOIN app_user u ON u.id = c.commented_by
    WHERE c.course_chapter_id = p_chapter_id AND c.comment_parent_id IS NULL
      AND (c.active = 1 OR EXISTS (SELECT 1 FROM chapter_comment r WHERE r.comment_parent_id = c.id AND r.active = 1))
    ORDER BY c.commented_at DESC, c.id DESC
    LIMIT p_limit OFFSET p_offset;

    -- La página se repite como tabla derivada: MySQL no admite LIMIT dentro de IN.
    SELECT
        c.id, c.course_chapter_id, c.comment_parent_id, c.commented_by,
        CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS author_name,
        u.avatar AS author_avatar,
        c.commented_by = v_owner_id AS author_is_instructor,
        c.comment, c.commented_at, c.updated_at, c.active,
        0 AS reply_count
    FROM chapter_comment c
    JOIN app_user u ON u.id = c.commented_by
    JOIN (
        SELECT p.id
          FROM chapter_comment p
         WHERE p.course_chapter_id = p_chapter_id AND p.comment_parent_id IS NULL
           AND (p.active = 1 OR EXISTS (SELECT 1 FROM chapter_comment r WHERE r.comment_parent_id = p.id AND r.active = 1))
         ORDER BY p.commented_at DESC, p.id DESC
         LIMIT p_limit OFFSET p_offset
    ) page ON page.id = c.comment_parent_id
    WHERE c.active = 1
    ORDER BY c.comment_parent_id, c.commented_at, c.id;

    SELECT cr.comment_id AS target_id, re.id AS reaction_id, re.emoji, re.reaction_name,
           COUNT(*) AS total, MAX(cr.reacted_by = p_user_id) AS mine
      FROM chapter_comment_reaction cr
      JOIN reaction re ON re.id = cr.reaction_id AND re.active = 1
      JOIN chapter_comment c ON c.id = cr.comment_id AND c.active = 1
      JOIN (
          SELECT p.id
            FROM chapter_comment p
           WHERE p.course_chapter_id = p_chapter_id AND p.comment_parent_id IS NULL
             AND (p.active = 1 OR EXISTS (SELECT 1 FROM chapter_comment r WHERE r.comment_parent_id = p.id AND r.active = 1))
           ORDER BY p.commented_at DESC, p.id DESC
           LIMIT p_limit OFFSET p_offset
      ) page ON page.id = c.id OR page.id = c.comment_parent_id
     GROUP BY cr.comment_id, re.id, re.emoji, re.reaction_name
     ORDER BY cr.comment_id, re.id;

    SELECT COUNT(*) AS total, p_user_id = v_owner_id AS viewer_is_owner
      FROM chapter_comment c
     WHERE c.course_chapter_id = p_chapter_id AND c.comment_parent_id IS NULL
       AND (c.active = 1 OR EXISTS (SELECT 1 FROM chapter_comment r WHERE r.comment_parent_id = c.id AND r.active = 1));
END$$

DELIMITER ;
