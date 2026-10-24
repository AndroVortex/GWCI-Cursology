-- sp_admin_create_first: crea el PRIMER administrador. Solo lo usa el script de
-- línea de comandos scripts/create_admin.php; ningún endpoint web lo llama.
-- Errores: ADMIN_EXISTS (ya hay un administrador), EMAIL_TAKEN.

DROP PROCEDURE IF EXISTS sp_admin_create_first;

DELIMITER $$

CREATE PROCEDURE sp_admin_create_first(
    IN p_name VARCHAR(100),
    IN p_father_last_name VARCHAR(100),
    IN p_mother_last_name VARCHAR(100),
    IN p_gender VARCHAR(40),
    IN p_birthday DATE,
    IN p_avatar VARCHAR(2048),
    IN p_email VARCHAR(254),
    IN p_password_hash VARCHAR(255)
)
BEGIN
    DECLARE v_user_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR 1062
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EMAIL_TAKEN';

    IF EXISTS (SELECT 1 FROM app_user WHERE role = 'admin') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ADMIN_EXISTS';
    END IF;

    INSERT INTO app_user (
        name, father_last_name, mother_last_name, gender, birthday,
        avatar, email, password_hash, role
    ) VALUES (
        p_name, p_father_last_name, p_mother_last_name, p_gender, p_birthday,
        p_avatar, p_email, p_password_hash, 'admin'
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
