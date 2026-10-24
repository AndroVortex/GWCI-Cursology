-- sp_course_update: actualiza los datos de un curso del instructor y reemplaza sus categorías.
-- Las categorías que se quitan quedan con active = 0 (se conserva el historial).
-- p_cover_image / p_onboarding_video NULL = conservar los actuales.
-- Modelo de cobro (p_full_course_price): NULL = por niveles, 0 = gratis, > 0 = curso
-- completo + niveles. Al pasar a gratis, todos los niveles quedan en 0. Si el curso está
-- publicado y tiene alumnos, el modelo no puede cambiar (los importes sí).
-- Resultados: 1) la fila de course; 2) media_refs: arreglo JSON con la portada y el video
-- reemplazados, para que PHP los borre de Cloudinary después del commit.
-- Errores: COURSE_NOT_FOUND (no existe o no es del instructor), CATEGORY_NOT_AVAILABLE,
-- PRICING_LOCKED.

DROP PROCEDURE IF EXISTS sp_course_update;

DELIMITER $$

CREATE PROCEDURE sp_course_update(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED,
    IN p_title VARCHAR(200),
    IN p_description TEXT,
    IN p_cover_image VARCHAR(2048),
    IN p_onboarding_video VARCHAR(2048),
    IN p_certifier_name VARCHAR(300),
    IN p_full_course_price DECIMAL(12,2),
    IN p_category_ids JSON
)
BEGIN
    DECLARE v_owner BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_old_cover VARCHAR(2048);
    DECLARE v_old_video VARCHAR(2048);
    DECLARE v_old_price DECIMAL(12,2);
    DECLARE v_published_at DATETIME(6);
    DECLARE v_requested INT DEFAULT 0;
    DECLARE v_available INT DEFAULT 0;
    DECLARE v_refs JSON;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT COUNT(DISTINCT j.id) INTO v_requested
      FROM JSON_TABLE(p_category_ids, '$[*]' COLUMNS (id BIGINT UNSIGNED PATH '$')) AS j;

    SELECT COUNT(*) INTO v_available
      FROM category c
     WHERE c.active = 1
       AND c.id IN (SELECT j.id FROM JSON_TABLE(p_category_ids, '$[*]' COLUMNS (id BIGINT UNSIGNED PATH '$')) AS j);

    IF v_requested = 0 OR v_requested <> v_available THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CATEGORY_NOT_AVAILABLE';
    END IF;

    START TRANSACTION;

    SELECT created_by, cover_image, onboarding_video, full_course_price, published_at
      INTO v_owner, v_old_cover, v_old_video, v_old_price, v_published_at
      FROM course
     WHERE id = p_course_id
       FOR UPDATE;

    IF v_owner IS NULL OR v_owner <> p_instructor_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
    END IF;

    -- El modelo es NULL (por niveles), 0 (gratis) o > 0 (curso completo): SIGN() los distingue.
    IF v_published_at IS NOT NULL
       AND NOT (COALESCE(SIGN(v_old_price), -1) <=> COALESCE(SIGN(p_full_course_price), -1))
       AND EXISTS (SELECT 1 FROM user_courses WHERE course_id = p_course_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRICING_LOCKED';
    END IF;

    UPDATE course
       SET title = p_title,
           description = p_description,
           cover_image = COALESCE(p_cover_image, cover_image),
           onboarding_video = COALESCE(p_onboarding_video, onboarding_video),
           certifier_name = p_certifier_name,
           full_course_price = p_full_course_price,
           updated_by = p_instructor_id
     WHERE id = p_course_id;

    IF p_full_course_price = 0 THEN
        UPDATE course_level SET cost = 0 WHERE course_id = p_course_id AND cost <> 0;
    END IF;

    UPDATE course_category
       SET active = 0
     WHERE course_id = p_course_id
       AND category_id NOT IN (
           SELECT j.id FROM JSON_TABLE(p_category_ids, '$[*]' COLUMNS (id BIGINT UNSIGNED PATH '$')) AS j
            WHERE j.id IS NOT NULL
       );

    INSERT INTO course_category (course_id, category_id, active)
    SELECT DISTINCT p_course_id, j.id, 1
      FROM JSON_TABLE(p_category_ids, '$[*]' COLUMNS (id BIGINT UNSIGNED PATH '$')) AS j
     WHERE j.id IS NOT NULL
    ON DUPLICATE KEY UPDATE active = 1;

    COMMIT;

    SET v_refs = JSON_ARRAY();
    IF p_cover_image IS NOT NULL AND v_old_cover COLLATE utf8mb4_bin <> p_cover_image THEN
        SET v_refs = JSON_ARRAY_APPEND(v_refs, '$', v_old_cover);
    END IF;
    IF p_onboarding_video IS NOT NULL AND v_old_video COLLATE utf8mb4_bin <> p_onboarding_video THEN
        SET v_refs = JSON_ARRAY_APPEND(v_refs, '$', v_old_video);
    END IF;

    SELECT
        id, title, description, cover_image, onboarding_video, certificate_template, certifier_name,
        full_course_price, currency, active, created_by, created_at, updated_by, updated_at,
        published_at, deactivated_at
    FROM course
    WHERE id = p_course_id;

    SELECT v_refs AS media_refs;
END$$

DELIMITER ;
