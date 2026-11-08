-- sp_moderation_content: todos los comentarios y opiniones de la plataforma, del más reciente al
-- más antiguo, para que el admin los revise aunque nadie los haya reportado.
-- Filtros (NULL = sin filtrar):
--   p_type: 'comment' u 'opinion'
--   p_state: 'visible' (activos) u 'hidden' (ocultos o eliminados por su autor)
--   p_search: patrón LIKE ya escapado por PHP (con % alrededor); busca en el texto, el nombre y el
--             email del autor
-- Resultados (en este orden):
--   1) target_type, target_id, content, active, created_at, author_id, author_name, author_email,
--      author_avatar, course_id, course_title, chapter_id, chapter_title, deactivated_at,
--      deactivation_reason, deactivated_by_name, removed_by_author, restorable y report_count
--   2) total con los mismos filtros
-- Errores: NOT_ADMIN.

DROP PROCEDURE IF EXISTS sp_moderation_content;

DELIMITER $$

CREATE PROCEDURE sp_moderation_content(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_type VARCHAR(10),
    IN p_state VARCHAR(10),
    IN p_search VARCHAR(310),
    IN p_limit INT UNSIGNED,
    IN p_offset INT UNSIGNED
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    SELECT feed.*
      FROM (
        SELECT 'comment' AS target_type, c.id AS target_id, c.comment AS content, c.active, c.commented_at AS created_at,
               u.id AS author_id, CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS author_name,
               u.email AS author_email, u.avatar AS author_avatar,
               co.id AS course_id, co.title AS course_title, ch.id AS chapter_id, ch.title AS chapter_title,
               c.deactivated_at, c.reason AS deactivation_reason,
               IF(d.id IS NULL, NULL, CONCAT_WS(' ', d.name, d.father_last_name, d.mother_last_name)) AS deactivated_by_name,
               c.active = 0 AND c.deactivated_by = c.commented_by AS removed_by_author,
               c.active = 0 AND c.deactivated_by <> c.commented_by AS restorable,
               (SELECT COUNT(*) FROM content_report r WHERE r.comment_id = c.id) AS report_count
          FROM chapter_comment c
          JOIN app_user u ON u.id = c.commented_by
          JOIN course_chapter ch ON ch.id = c.course_chapter_id
          JOIN course_level l ON l.id = ch.course_level_id
          JOIN course co ON co.id = l.course_id
          LEFT JOIN app_user d ON d.id = c.deactivated_by
         WHERE (p_type IS NULL OR p_type = 'comment')
           AND (p_state IS NULL OR (p_state = 'visible' AND c.active = 1) OR (p_state = 'hidden' AND c.active = 0))
           AND (p_search IS NULL OR c.comment LIKE p_search OR u.email LIKE p_search
                OR CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) LIKE p_search)
        UNION ALL
        SELECT 'opinion', o.id, o.opinion, o.active, o.commented_at,
               u.id, CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name), u.email, u.avatar,
               co.id, co.title, NULL, NULL,
               o.deactivated_at, o.reason,
               IF(d.id IS NULL, NULL, CONCAT_WS(' ', d.name, d.father_last_name, d.mother_last_name)),
               0,
               o.active = 0,
               (SELECT COUNT(*) FROM content_report r WHERE r.opinion_id = o.id)
          FROM course_opinion o
          JOIN app_user u ON u.id = o.commented_by
          JOIN course co ON co.id = o.course_id
          LEFT JOIN app_user d ON d.id = o.deactivated_by
         WHERE (p_type IS NULL OR p_type = 'opinion')
           AND (p_state IS NULL OR (p_state = 'visible' AND o.active = 1) OR (p_state = 'hidden' AND o.active = 0))
           AND (p_search IS NULL OR o.opinion LIKE p_search OR u.email LIKE p_search
                OR CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) LIKE p_search)
      ) feed
     ORDER BY feed.created_at DESC, feed.target_type, feed.target_id DESC
     LIMIT p_limit OFFSET p_offset;

    SELECT
        (SELECT COUNT(*)
           FROM chapter_comment c
           JOIN app_user u ON u.id = c.commented_by
          WHERE (p_type IS NULL OR p_type = 'comment')
            AND (p_state IS NULL OR (p_state = 'visible' AND c.active = 1) OR (p_state = 'hidden' AND c.active = 0))
            AND (p_search IS NULL OR c.comment LIKE p_search OR u.email LIKE p_search
                 OR CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) LIKE p_search))
        + (SELECT COUNT(*)
             FROM course_opinion o
             JOIN app_user u ON u.id = o.commented_by
            WHERE (p_type IS NULL OR p_type = 'opinion')
              AND (p_state IS NULL OR (p_state = 'visible' AND o.active = 1) OR (p_state = 'hidden' AND o.active = 0))
              AND (p_search IS NULL OR o.opinion LIKE p_search OR u.email LIKE p_search
                   OR CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) LIKE p_search)) AS total;
END$$

DELIMITER ;
