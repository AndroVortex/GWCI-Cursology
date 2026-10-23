-- sp_course_unpublish: regresa un curso publicado a borrador. Solo si aún no tiene alumnos
-- (user_courses); con alumnos, lo que procede es darlo de baja (sp_course_set_active).
-- Resultado: la fila de course. Errores: COURSE_NOT_FOUND, COURSE_NOT_PUBLISHED,
-- COURSE_HAS_STUDENTS.
-- Transacción propia: bloquea el curso.

DROP PROCEDURE IF EXISTS sp_course_unpublish;

DELIMITER $$

CREATE PROCEDURE sp_course_unpublish(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_owner BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_published_at DATETIME(6);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT created_by, published_at INTO v_owner, v_published_at
      FROM course
     WHERE id = p_course_id
       FOR UPDATE;

    IF v_owner IS NULL OR v_owner <> p_instructor_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
    END IF;
    IF v_published_at IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_PUBLISHED';
    END IF;
    IF EXISTS (SELECT 1 FROM user_courses WHERE course_id = p_course_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_HAS_STUDENTS';
    END IF;

    UPDATE course
       SET published_at = NULL,
           updated_by = p_instructor_id
     WHERE id = p_course_id;

    COMMIT;

    SELECT
        id, title, description, cover_image, onboarding_video, certificate_template, certifier_name,
        full_course_price, currency, active, created_by, created_at, updated_by, updated_at,
        published_at, deactivated_at
    FROM course
    WHERE id = p_course_id;
END$$

DELIMITER ;
