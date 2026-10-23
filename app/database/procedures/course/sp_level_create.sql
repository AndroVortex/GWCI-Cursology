-- sp_level_create: agrega un nivel al final del curso (sort_order = máximo + 1).
-- En un curso gratis (full_course_price = 0) el nivel queda en 0 sin importar p_cost.
-- En un curso publicado, quien compró el curso completo (purchase_scope = 'course',
-- completada) recibe acceso al nivel nuevo: el paquete incluye los niveles futuros.
-- Resultado: la fila de course_level. Errores: COURSE_NOT_FOUND.
-- Transacción propia: bloquea el curso para numerar sin colisiones.

DROP PROCEDURE IF EXISTS sp_level_create;

DELIMITER $$

CREATE PROCEDURE sp_level_create(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED,
    IN p_title VARCHAR(200),
    IN p_description TEXT,
    IN p_onboarding_video VARCHAR(2048),
    IN p_cost DECIMAL(12,2)
)
BEGIN
    DECLARE v_owner BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_price DECIMAL(12,2);
    DECLARE v_published_at DATETIME(6);
    DECLARE v_next INT UNSIGNED;
    DECLARE v_level_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT created_by, full_course_price, published_at
      INTO v_owner, v_price, v_published_at
      FROM course
     WHERE id = p_course_id
       FOR UPDATE;
    IF v_owner IS NULL OR v_owner <> p_instructor_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
    END IF;

    SELECT COALESCE(MAX(sort_order), 0) + 1 INTO v_next FROM course_level WHERE course_id = p_course_id;

    INSERT INTO course_level (course_id, title, description, onboarding_video, sort_order, cost)
    VALUES (p_course_id, p_title, p_description, p_onboarding_video, v_next, IF(v_price = 0, 0, p_cost));

    SET v_level_id = LAST_INSERT_ID();

    IF v_published_at IS NOT NULL THEN
        INSERT INTO user_course_level (user_id, course_level_id, course_id, purchase_id)
        SELECT p.user_id, v_level_id, p_course_id, MIN(p.id)
          FROM purchase p
          JOIN user_courses uc ON uc.user_id = p.user_id AND uc.course_id = p.course_id
         WHERE p.course_id = p_course_id
           AND p.purchase_scope = 'course'
           AND p.status = 'completed'
         GROUP BY p.user_id;
    END IF;

    COMMIT;

    SELECT id, course_id, title, description, onboarding_video, sort_order, cost, created_at, updated_at
      FROM course_level
     WHERE id = v_level_id;
END$$

DELIMITER ;
