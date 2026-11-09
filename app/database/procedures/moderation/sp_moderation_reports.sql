-- sp_moderation_reports: la cola de moderación del admin. Un renglón por contenido con reportes
-- pendientes, del reporte más reciente al más antiguo.
-- Resultados (en este orden):
--   1) target_type, target_id, content, active, created_at, author_id, author_name, author_email,
--      author_avatar, course_id, course_title, chapter_id y chapter_title (NULL los dos en las
--      opiniones), report_count, spam_count, offensive_count, answers_count, other_count,
--      first_reported_at, last_reported_at y last_details (el detalle más reciente, si hay)
--   2) total (contenidos en la cola) y report_total (reportes pendientes)
-- Errores: NOT_ADMIN.

DROP PROCEDURE IF EXISTS sp_moderation_reports;

DELIMITER $$

CREATE PROCEDURE sp_moderation_reports(
    IN p_admin_id BIGINT UNSIGNED,
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

    SELECT queue.*
      FROM (
        SELECT 'comment' AS target_type, c.id AS target_id, c.comment AS content, c.active, c.commented_at AS created_at,
               u.id AS author_id, CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS author_name,
               u.email AS author_email, u.avatar AS author_avatar,
               co.id AS course_id, co.title AS course_title, ch.id AS chapter_id, ch.title AS chapter_title,
               COUNT(*) AS report_count,
               COUNT(CASE WHEN r.reason_code = 'spam' THEN 1 END) AS spam_count,
               COUNT(CASE WHEN r.reason_code = 'offensive' THEN 1 END) AS offensive_count,
               COUNT(CASE WHEN r.reason_code = 'answers' THEN 1 END) AS answers_count,
               COUNT(CASE WHEN r.reason_code = 'other' THEN 1 END) AS other_count,
               MIN(r.reported_at) AS first_reported_at, MAX(r.reported_at) AS last_reported_at,
               (SELECT r2.details FROM content_report r2
                 WHERE r2.comment_id = c.id AND r2.status = 'pending' AND r2.details IS NOT NULL
                 ORDER BY r2.reported_at DESC, r2.id DESC LIMIT 1) AS last_details
          FROM content_report r
          JOIN chapter_comment c ON c.id = r.comment_id
          JOIN app_user u ON u.id = c.commented_by
          JOIN course_chapter ch ON ch.id = c.course_chapter_id
          JOIN course_level l ON l.id = ch.course_level_id
          JOIN course co ON co.id = l.course_id
         WHERE r.status = 'pending' AND r.target_type = 'comment'
         GROUP BY c.id, c.comment, c.active, c.commented_at, u.id, u.name, u.father_last_name, u.mother_last_name,
                  u.email, u.avatar, co.id, co.title, ch.id, ch.title
        UNION ALL
        SELECT 'opinion', o.id, o.opinion, o.active, o.commented_at,
               u.id, CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name), u.email, u.avatar,
               co.id, co.title, NULL, NULL,
               COUNT(*),
               COUNT(CASE WHEN r.reason_code = 'spam' THEN 1 END),
               COUNT(CASE WHEN r.reason_code = 'offensive' THEN 1 END),
               COUNT(CASE WHEN r.reason_code = 'answers' THEN 1 END),
               COUNT(CASE WHEN r.reason_code = 'other' THEN 1 END),
               MIN(r.reported_at), MAX(r.reported_at),
               (SELECT r2.details FROM content_report r2
                 WHERE r2.opinion_id = o.id AND r2.status = 'pending' AND r2.details IS NOT NULL
                 ORDER BY r2.reported_at DESC, r2.id DESC LIMIT 1)
          FROM content_report r
          JOIN course_opinion o ON o.id = r.opinion_id
          JOIN app_user u ON u.id = o.commented_by
          JOIN course co ON co.id = o.course_id
         WHERE r.status = 'pending' AND r.target_type = 'opinion'
         GROUP BY o.id, o.opinion, o.active, o.commented_at, u.id, u.name, u.father_last_name, u.mother_last_name,
                  u.email, u.avatar, co.id, co.title
      ) queue
     ORDER BY queue.last_reported_at DESC, queue.target_type, queue.target_id DESC
     LIMIT p_limit OFFSET p_offset;

    SELECT
        (SELECT COUNT(DISTINCT comment_id) FROM content_report WHERE status = 'pending' AND target_type = 'comment')
        + (SELECT COUNT(DISTINCT opinion_id) FROM content_report WHERE status = 'pending' AND target_type = 'opinion') AS total,
        (SELECT COUNT(*) FROM content_report WHERE status = 'pending') AS report_total;
END$$

DELIMITER ;
