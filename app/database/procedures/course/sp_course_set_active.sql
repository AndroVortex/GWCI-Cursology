-- sp_course_set_active: baja lógica (p_active = 0) o reactivación (p_active = 1) de un curso
-- del instructor. Dado de baja deja de mostrarse en el catálogo, pero sus alumnos conservan
-- el acceso y su avance. Conserva published_at: al reactivarlo vuelve a como estaba.
-- ck_course_deactivated exige active = 0 con deactivated_at y active = 1 sin él.
-- Repetir la operación (dar de baja un curso ya dado de baja) no cambia nada.
-- Resultado: la fila de course. Errores: COURSE_NOT_FOUND.
-- Transacción propia: bloquea el curso.

DROP PROCEDURE IF EXISTS sp_course_set_active;

DELIMITER $$

CREATE PROCEDURE sp_course_set_active(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED,
    IN p_active BOOLEAN
)
BEGIN
    DECLARE v_owner BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_active BOOLEAN;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT created_by, active INTO v_owner, v_active
      FROM course
     WHERE id = p_course_id
       FOR UPDATE;

    IF v_owner IS NULL OR v_owner <> p_instructor_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
    END IF;

    IF v_active <> p_active THEN
        UPDATE course
           SET active = p_active,
               deactivated_at = IF(p_active, NULL, CURRENT_TIMESTAMP(6)),
               updated_by = p_instructor_id
         WHERE id = p_course_id;
    END IF;

    COMMIT;

    SELECT
        id, title, description, cover_image, onboarding_video, certificate_template, certifier_name,
        full_course_price, currency, active, created_by, created_at, updated_by, updated_at,
        published_at, deactivated_at
    FROM course
    WHERE id = p_course_id;
END$$

DELIMITER ;
