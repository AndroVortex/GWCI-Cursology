-- sp_level_update: cambia título, descripción, precio y (opcional) el video de un nivel.
-- p_onboarding_video NULL = conservar el actual.
-- En un curso gratis (full_course_price = 0) el nivel queda en 0 sin importar p_cost.
-- Resultados: 1) la fila de course_level; 2) media_refs con el video reemplazado.
-- Errores: LEVEL_NOT_FOUND (no existe o el curso no es del instructor).

DROP PROCEDURE IF EXISTS sp_level_update;

DELIMITER $$

CREATE PROCEDURE sp_level_update(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_level_id BIGINT UNSIGNED,
    IN p_title VARCHAR(200),
    IN p_description TEXT,
    IN p_onboarding_video VARCHAR(2048),
    IN p_cost DECIMAL(12,2)
)
BEGIN
    DECLARE v_found BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_old_video VARCHAR(2048);
    DECLARE v_price DECIMAL(12,2);

    SELECT l.id, l.onboarding_video, c.full_course_price INTO v_found, v_old_video, v_price
      FROM course_level l
      JOIN course c ON c.id = l.course_id
     WHERE l.id = p_level_id AND c.created_by = p_instructor_id;

    IF v_found IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LEVEL_NOT_FOUND';
    END IF;

    UPDATE course_level
       SET title = p_title,
           description = p_description,
           onboarding_video = COALESCE(p_onboarding_video, onboarding_video),
           cost = IF(v_price = 0, 0, p_cost)
     WHERE id = p_level_id;

    SELECT id, course_id, title, description, onboarding_video, sort_order, cost, created_at, updated_at
      FROM course_level
     WHERE id = p_level_id;

    SELECT IF(
        p_onboarding_video IS NOT NULL AND v_old_video COLLATE utf8mb4_bin <> p_onboarding_video,
        JSON_ARRAY(v_old_video),
        JSON_ARRAY()
    ) AS media_refs;
END$$

DELIMITER ;
