-- sp_admin_overview: cifras del panel del admin. Cuentas activas y sin ban; cursos activos y
-- publicados de instructores activos y sin ban (los que están en el catálogo).
-- Resultado: active_students, active_instructors, published_courses.
-- Errores: NOT_ADMIN.

DROP PROCEDURE IF EXISTS sp_admin_overview;

DELIMITER $$

CREATE PROCEDURE sp_admin_overview(
    IN p_admin_id BIGINT UNSIGNED
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    SELECT
        (SELECT COUNT(*) FROM app_user WHERE role = 'student' AND account_status = 'active' AND ban = 0) AS active_students,
        (SELECT COUNT(*) FROM app_user WHERE role = 'instructor' AND account_status = 'active' AND ban = 0) AS active_instructors,
        (SELECT COUNT(*)
           FROM course c
           JOIN app_user u ON u.id = c.created_by
          WHERE c.active = 1 AND c.published_at IS NOT NULL
            AND u.account_status = 'active' AND u.ban = 0) AS published_courses;
END$$

DELIMITER ;
