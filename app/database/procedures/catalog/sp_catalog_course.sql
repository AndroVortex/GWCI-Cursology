-- sp_catalog_course: la página pública de un curso publicado y activo.
-- Solo datos públicos: nunca las URIs del contenido de los capítulos (los videos de
-- presentación del curso y de los niveles sí son públicos).
-- Resultados (en este orden):
--   1) el curso con su instructor (nombre completo, avatar y descripción)
--   2) categorías activas (id, name)
--   3) niveles por orden (id, title, description, onboarding_video, sort_order, cost)
--   4) capítulos por nivel y orden (id, course_level_id, title, sort_order, is_test,
--      duration_seconds del video principal, question_count de los exámenes)
-- Errores: COURSE_NOT_FOUND (no existe, es borrador o está dado de baja).

DROP PROCEDURE IF EXISTS sp_catalog_course;

DELIMITER $$

CREATE PROCEDURE sp_catalog_course(
    IN p_course_id BIGINT UNSIGNED
)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM course WHERE id = p_course_id AND active = 1 AND published_at IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
    END IF;

    SELECT
        c.id, c.title, c.description, c.cover_image, c.onboarding_video, c.full_course_price, c.currency,
        c.published_at, c.updated_at,
        u.id AS instructor_id,
        CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS instructor_name,
        u.avatar AS instructor_avatar,
        u.description AS instructor_description
    FROM course c
    JOIN app_user u ON u.id = c.created_by
    WHERE c.id = p_course_id;

    SELECT cat.id, cat.name
      FROM course_category cc
      JOIN category cat ON cat.id = cc.category_id
     WHERE cc.course_id = p_course_id AND cc.active = 1 AND cat.active = 1
     ORDER BY cat.name;

    SELECT id, title, description, onboarding_video, sort_order, cost
      FROM course_level
     WHERE course_id = p_course_id
     ORDER BY sort_order;

    SELECT
        ch.id, ch.course_level_id, ch.title, ch.sort_order, ch.is_test,
        (SELECT ct.duration_seconds FROM course_chapter_content ct
          WHERE ct.course_chapter_id = ch.id AND ct.is_main = 1) AS duration_seconds,
        (SELECT COUNT(*) FROM course_chapter_test_question q WHERE q.course_chapter_id = ch.id) AS question_count
    FROM course_chapter ch
    JOIN course_level l ON l.id = ch.course_level_id
    WHERE l.course_id = p_course_id
    ORDER BY l.sort_order, ch.sort_order;
END$$

DELIMITER ;
