-- sp_auth_register: crea una cuenta student o instructor y devuelve su fila de app_user.
-- p_password_hash es el resultado de password_hash(); el SP nunca recibe la contraseña.
-- Errores: INVALID_ROLE, EMAIL_TAKEN.

DROP PROCEDURE IF EXISTS sp_auth_register;

DELIMITER $$

CREATE PROCEDURE sp_auth_register(
    IN p_name VARCHAR(100),
    IN p_father_last_name VARCHAR(100),
    IN p_mother_last_name VARCHAR(100),
    IN p_gender VARCHAR(40),
    IN p_birthday DATE,
    IN p_avatar VARCHAR(2048),
    IN p_email VARCHAR(254),
    IN p_password_hash VARCHAR(255),
    IN p_role VARCHAR(16)
)
BEGIN
    DECLARE v_user_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR 1062
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EMAIL_TAKEN';

    -- role es ascii_bin: se compara sin ignorar mayúsculas.
    IF p_role IS NULL OR p_role COLLATE utf8mb4_bin NOT IN ('student', 'instructor') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_ROLE';
    END IF;

    INSERT INTO app_user (
        name, father_last_name, mother_last_name, gender, birthday,
        avatar, email, password_hash, role
    ) VALUES (
        p_name, p_father_last_name, p_mother_last_name, p_gender, p_birthday,
        p_avatar, p_email, p_password_hash, p_role
    );

    SET v_user_id = LAST_INSERT_ID();

    SELECT
        id, name, father_last_name, mother_last_name, gender, birthday, avatar, email,
        password_hash, role, description, account_status, active, consecutive_failed_logins,
        status_changed_at, ban, banned_by, banned_at, ban_reason, created_at, updated_at,
        last_login_at
    FROM app_user
    WHERE id = v_user_id;
END$$

DELIMITER ;
