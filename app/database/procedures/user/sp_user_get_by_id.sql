-- sp_user_get_by_id: devuelve 0 o 1 fila completa de app_user. Lo usa el middleware
-- en cada petición con sesión para comprobar que la cuenta sigue activa y sin ban.

DROP PROCEDURE IF EXISTS sp_user_get_by_id;

DELIMITER $$

CREATE PROCEDURE sp_user_get_by_id(
    IN p_user_id BIGINT UNSIGNED
)
BEGIN
    SELECT
        id, name, father_last_name, mother_last_name, gender, birthday, avatar, email,
        password_hash, role, description, account_status, active, consecutive_failed_logins,
        status_changed_at, ban, banned_by, banned_at, ban_reason, created_at, updated_at,
        last_login_at
    FROM app_user
    WHERE id = p_user_id;
END$$

DELIMITER ;
