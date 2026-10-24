-- sp_user_update_profile: actualiza los datos personales y el email de una cuenta activa.
-- PHP ya validó los campos y, si el email cambia, la contraseña actual.
-- Resultado: la fila completa de app_user. Errores: USER_NOT_AVAILABLE, EMAIL_TAKEN.

DROP PROCEDURE IF EXISTS sp_user_update_profile;

DELIMITER $$

CREATE PROCEDURE sp_user_update_profile(
    IN p_user_id BIGINT UNSIGNED,
    IN p_name VARCHAR(100),
    IN p_father_last_name VARCHAR(100),
    IN p_mother_last_name VARCHAR(100),
    IN p_gender VARCHAR(40),
    IN p_birthday DATE,
    IN p_description TEXT,
    IN p_email VARCHAR(254)
)
BEGIN
    DECLARE EXIT HANDLER FOR 1062
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EMAIL_TAKEN';

    IF NOT EXISTS (
        SELECT 1 FROM app_user WHERE id = p_user_id AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USER_NOT_AVAILABLE';
    END IF;

    UPDATE app_user
       SET name = p_name,
           father_last_name = p_father_last_name,
           mother_last_name = p_mother_last_name,
           gender = p_gender,
           birthday = p_birthday,
           description = p_description,
           email = p_email
     WHERE id = p_user_id;

    SELECT
        id, name, father_last_name, mother_last_name, gender, birthday, avatar, email,
        password_hash, role, description, account_status, active, consecutive_failed_logins,
        status_changed_at, ban, banned_by, banned_at, ban_reason, created_at, updated_at,
        last_login_at
    FROM app_user
    WHERE id = p_user_id;
END$$

DELIMITER ;
