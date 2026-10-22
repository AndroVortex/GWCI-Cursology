-- sp_user_update_avatar: cambia la foto de perfil (URL de Cloudinary o el avatar por defecto).
-- Resultado: la fila completa de app_user. Errores: USER_NOT_AVAILABLE.

DROP PROCEDURE IF EXISTS sp_user_update_avatar;

DELIMITER $$

CREATE PROCEDURE sp_user_update_avatar(
    IN p_user_id BIGINT UNSIGNED,
    IN p_avatar VARCHAR(2048)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user WHERE id = p_user_id AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USER_NOT_AVAILABLE';
    END IF;

    UPDATE app_user SET avatar = p_avatar WHERE id = p_user_id;

    SELECT
        id, name, father_last_name, mother_last_name, gender, birthday, avatar, email,
        password_hash, role, description, account_status, active, consecutive_failed_logins,
        status_changed_at, ban, banned_by, banned_at, ban_reason, created_at, updated_at,
        last_login_at
    FROM app_user
    WHERE id = p_user_id;
END$$

DELIMITER ;
