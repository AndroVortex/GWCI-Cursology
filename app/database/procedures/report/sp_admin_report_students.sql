-- sp_admin_report_students: reporte de alumnos con cuenta activa y sin ban, de la cuenta más
-- reciente a la más antigua.
-- Resultado: id, email, full_name, avatar, created_at (fecha de ingreso), enrolled_courses,
-- completed_courses y completed_percent (terminados / inscritos * 100, 1 decimal; 0 sin cursos).
-- Errores: NOT_ADMIN.

DROP PROCEDURE IF EXISTS sp_admin_report_students;

DELIMITER $$

CREATE PROCEDURE sp_admin_report_students(
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
        s.id, s.email, s.full_name, s.avatar, s.created_at, s.enrolled_courses, s.completed_courses,
        CAST(COALESCE(ROUND(100 * s.completed_courses / NULLIF(s.enrolled_courses, 0), 1), 0) AS DECIMAL(5,1)) AS completed_percent
    FROM (
        SELECT
            u.id, u.email,
            CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS full_name,
            u.avatar, u.created_at,
            (SELECT COUNT(*) FROM user_courses uc WHERE uc.user_id = u.id) AS enrolled_courses,
            (SELECT COUNT(*) FROM user_courses uc WHERE uc.user_id = u.id AND uc.completed_at IS NOT NULL) AS completed_courses
        FROM app_user u
        WHERE u.role = 'student' AND u.account_status = 'active' AND u.ban = 0
    ) AS s
    ORDER BY s.created_at DESC, s.id DESC;
END$$

DELIMITER ;
