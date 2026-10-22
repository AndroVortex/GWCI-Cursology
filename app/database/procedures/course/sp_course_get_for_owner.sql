-- sp_course_get_for_owner: el curso completo para el editor de su instructor.
-- Resultados (en este orden, aunque alguno venga vacío):
--   1) course                  4) course_chapter (por nivel y orden)
--   2) course_category activas 5) course_chapter_content (video principal primero)
--   3) course_level (por orden)
-- Errores: COURSE_NOT_FOUND (no existe o no es del instructor).

DROP PROCEDURE IF EXISTS sp_course_get_for_owner;

DELIMITER $$

CREATE PROCEDURE sp_course_get_for_owner(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED
)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM course WHERE id = p_course_id AND created_by = p_instructor_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
    END IF;

    SELECT
        id, title, description, cover_image, onboarding_video, certificate_template, certifier_name,
        full_course_price, currency, active, created_by, created_at, updated_by, updated_at,
        published_at, deactivated_at
    FROM course
    WHERE id = p_course_id;

    SELECT course_id, category_id, active
      FROM course_category
     WHERE course_id = p_course_id AND active = 1;

    SELECT id, course_id, title, description, onboarding_video, sort_order, cost, created_at, updated_at
      FROM course_level
     WHERE course_id = p_course_id
     ORDER BY sort_order;

    SELECT ch.id, ch.course_level_id, ch.title, ch.sort_order, ch.is_test, ch.minimum_passing_grade,
           ch.created_at, ch.updated_at
      FROM course_chapter ch
      JOIN course_level l ON l.id = ch.course_level_id
     WHERE l.course_id = p_course_id
     ORDER BY l.sort_order, ch.sort_order;

    SELECT ct.id, ct.course_chapter_id, ct.title, ct.content_type, ct.content_uri, ct.text_content,
           ct.mime_type, ct.original_filename, ct.size_bytes, ct.duration_seconds, ct.is_main,
           ct.main_slot, ct.created_at
      FROM course_chapter_content ct
      JOIN course_chapter ch ON ch.id = ct.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
     WHERE l.course_id = p_course_id
     ORDER BY ct.is_main DESC, ct.id;
END$$

DELIMITER ;
