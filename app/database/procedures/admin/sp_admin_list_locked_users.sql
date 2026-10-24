-- sp_admin_list_locked_users: cuentas bloqueadas por 3 contraseñas incorrectas
-- consecutivas, de la más reciente a la más antigua. Solo un administrador activo.
-- Resultado: id, nombre, email, rol, avatar, ban, status_changed_at (desde cuándo está
-- bloqueada) y last_attempt_at (último intento de login).
-- Errores: NOT_ADMIN.

DROP PROCEDURE IF EXISTS sp_admin_list_locked_users;

DELIMITER $$

CREATE PROCEDURE sp_admin_list_locked_users(
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
        u.id, u.name, u.father_last_name, u.mother_last_name, u.email, u.role, u.avatar, u.ban,
        u.status_changed_at,
        (SELECT MAX(a.attempted_at) FROM login_attempt a WHERE a.user_id = u.id) AS last_attempt_at
    FROM app_user u
    WHERE u.account_status = 'locked'
    ORDER BY u.status_changed_at DESC, u.id DESC;
END$$

DELIMITER ;
