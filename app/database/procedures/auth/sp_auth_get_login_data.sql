-- sp_auth_get_login_data: devuelve 0 o 1 fila completa de app_user por email,
-- incluido password_hash para que PHP ejecute password_verify(). No modifica nada.

DROP PROCEDURE IF EXISTS sp_auth_get_login_data;

DELIMITER $$

CREATE PROCEDURE sp_auth_get_login_data(
    IN p_email VARCHAR(254)
)
BEGIN
    SELECT
        id, name, father_last_name, mother_last_name, gender, birthday, avatar, email,
        password_hash, role, description, account_status, active, consecutive_failed_logins,
        status_changed_at, ban, banned_by, banned_at, ban_reason, created_at, updated_at,
        last_login_at
    FROM app_user
    WHERE email = p_email;
END$$

DELIMITER ;
