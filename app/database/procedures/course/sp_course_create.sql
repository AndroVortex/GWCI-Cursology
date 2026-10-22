-- sp_course_create: crea un curso en BORRADOR (published_at NULL) con sus categorías.
-- p_category_ids es un arreglo JSON de ids (p. ej. ["3","7"]); todas deben existir y estar activas.
-- p_full_course_price: NULL = suma de los niveles, 0.00 = gratis, > 0 = precio del paquete.
-- Resultado: la fila de course. Errores: NOT_INSTRUCTOR, CATEGORY_NOT_AVAILABLE.
-- Transacción propia: el curso y sus course_category se guardan juntos.

DROP PROCEDURE IF EXISTS sp_course_create;

DELIMITER $$

CREATE PROCEDURE sp_course_create(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_title VARCHAR(200),
    IN p_description TEXT,
    IN p_cover_image VARCHAR(2048),
    IN p_onboarding_video VARCHAR(2048),
    IN p_certifier_name VARCHAR(300),
    IN p_full_course_price DECIMAL(12,2),
    IN p_category_ids JSON
)
BEGIN
    DECLARE v_course_id BIGINT UNSIGNED;
    DECLARE v_requested INT DEFAULT 0;
    DECLARE v_available INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_instructor_id AND role = 'instructor' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_INSTRUCTOR';
    END IF;

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

    INSERT INTO course (
        title, description, cover_image, onboarding_video, certifier_name, full_course_price, created_by
    ) VALUES (
        p_title, p_description, p_cover_image, p_onboarding_video, p_certifier_name, p_full_course_price, p_instructor_id
    );

    SET v_course_id = LAST_INSERT_ID();

    INSERT INTO course_category (course_id, category_id, active)
    SELECT DISTINCT v_course_id, j.id, 1
      FROM JSON_TABLE(p_category_ids, '$[*]' COLUMNS (id BIGINT UNSIGNED PATH '$')) AS j
     WHERE j.id IS NOT NULL;

    COMMIT;

    SELECT
        id, title, description, cover_image, onboarding_video, certificate_template, certifier_name,
        full_course_price, currency, active, created_by, created_at, updated_by, updated_at,
        published_at, deactivated_at
    FROM course
    WHERE id = v_course_id;
END$$

DELIMITER ;
