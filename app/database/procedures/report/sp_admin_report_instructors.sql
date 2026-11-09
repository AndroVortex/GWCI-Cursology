-- sp_admin_report_instructors: reporte de instructores con cuenta activa y sin ban, de la cuenta
-- más reciente a la más antigua.
-- Resultado: id, email, full_name, avatar, created_at (fecha de ingreso), published_courses (activos
-- y publicados) y earnings (compras completadas de todos sus cursos). Errores: NOT_ADMIN.

DROP PROCEDURE IF EXISTS sp_admin_report_instructors;

DELIMITER $$

CREATE PROCEDURE sp_admin_report_instructors(
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
        u.id, u.email,
        CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS full_name,
        u.avatar, u.created_at,
        (SELECT COUNT(*) FROM course c
          WHERE c.created_by = u.id AND c.active = 1 AND c.published_at IS NOT NULL) AS published_courses,
        (SELECT CAST(COALESCE(SUM(pu.amount), 0) AS DECIMAL(14,2))
           FROM purchase pu
           JOIN course c ON c.id = pu.course_id
          WHERE c.created_by = u.id AND pu.status = 'completed') AS earnings
    FROM app_user u
    WHERE u.role = 'instructor' AND u.account_status = 'active' AND u.ban = 0
    ORDER BY u.created_at DESC, u.id DESC;
END$$

DELIMITER ;
