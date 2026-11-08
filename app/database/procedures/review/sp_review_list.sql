-- sp_review_list: calificaciones y opiniones públicas de un curso publicado y activo.
-- Las estrellas de quien tiene la opinión oculta no cuentan.
-- p_viewer_id es quien consulta (NULL para visitantes): solo sirve para marcar su reacción.
-- Resultados (en este orden):
--   1) rating_avg (1 decimal, NULL sin calificaciones), rating_count y stars_1 ... stars_5
--   2) opiniones visibles, de la más reciente a la más antigua (p_limit desde p_offset):
--      id, opinion, commented_at, updated_at, rate, author_name, author_avatar
--   3) total de opiniones visibles
--   4) las reacciones de las opiniones de la página: target_id, reaction_id, emoji,
--      reaction_name, total y mine; solo reacciones activas del catálogo
-- Errores: COURSE_NOT_FOUND.

DROP PROCEDURE IF EXISTS sp_review_list;

DELIMITER $$

CREATE PROCEDURE sp_review_list(
    IN p_course_id BIGINT UNSIGNED,
    IN p_limit INT UNSIGNED,
    IN p_offset INT UNSIGNED,
    IN p_viewer_id BIGINT UNSIGNED
)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM course WHERE id = p_course_id AND active = 1 AND published_at IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
    END IF;

    SELECT
        CAST(ROUND(AVG(r.rate), 1) AS DECIMAL(3,1)) AS rating_avg,
        COUNT(*) AS rating_count,
        COALESCE(SUM(r.rate = 1), 0) AS stars_1,
        COALESCE(SUM(r.rate = 2), 0) AS stars_2,
        COALESCE(SUM(r.rate = 3), 0) AS stars_3,
        COALESCE(SUM(r.rate = 4), 0) AS stars_4,
        COALESCE(SUM(r.rate = 5), 0) AS stars_5
      FROM course_rate r
      LEFT JOIN course_opinion o ON o.commented_by = r.rated_by AND o.course_id = r.course_id
     WHERE r.course_id = p_course_id AND (o.id IS NULL OR o.active = 1);

    SELECT o.id, o.opinion, o.commented_at, o.updated_at, r.rate,
           CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS author_name,
           u.avatar AS author_avatar
      FROM course_opinion o
      JOIN app_user u ON u.id = o.commented_by
      LEFT JOIN course_rate r ON r.rated_by = o.commented_by AND r.course_id = o.course_id
     WHERE o.course_id = p_course_id AND o.active = 1
     ORDER BY o.commented_at DESC, o.id DESC
     LIMIT p_limit OFFSET p_offset;

    SELECT COUNT(*) AS total
      FROM course_opinion
     WHERE course_id = p_course_id AND active = 1;

    -- La página se repite como tabla derivada: MySQL no admite LIMIT dentro de IN.
    SELECT orr.opinion_id AS target_id, re.id AS reaction_id, re.emoji, re.reaction_name,
           COUNT(*) AS total, COALESCE(MAX(orr.reacted_by = p_viewer_id), 0) AS mine
      FROM opinion_reaction orr
      JOIN reaction re ON re.id = orr.reaction_id AND re.active = 1
      JOIN (
          SELECT o.id
            FROM course_opinion o
           WHERE o.course_id = p_course_id AND o.active = 1
           ORDER BY o.commented_at DESC, o.id DESC
           LIMIT p_limit OFFSET p_offset
      ) page ON page.id = orr.opinion_id
     GROUP BY orr.opinion_id, re.id, re.emoji, re.reaction_name
     ORDER BY orr.opinion_id, re.id;
END$$

DELIMITER ;
