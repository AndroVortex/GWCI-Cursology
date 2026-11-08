-- sp_admin_ban_user: suspende una cuenta (ban) con su motivo y registra el evento 'banned'.
-- La cuenta ya no inicia sesión y sus sesiones abiertas se cortan en la siguiente petición; si
-- es instructor, sus cursos salen del catálogo. No se suspende a un administrador (tampoco a
-- uno mismo).
-- Resultado: la fila de app_user. Errores: NOT_ADMIN, USER_NOT_FOUND, CANNOT_BAN_ADMIN,
-- ALREADY_BANNED.
-- Transacción propia: bloquea la fila del usuario con FOR UPDATE.

DROP PROCEDURE IF EXISTS sp_admin_ban_user;

DELIMITER $$

CREATE PROCEDURE sp_admin_ban_user(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_user_id BIGINT UNSIGNED,
    IN p_reason VARCHAR(500)
)
BEGIN
    DECLARE v_role VARCHAR(16) DEFAULT NULL;
    DECLARE v_ban BOOLEAN;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    START TRANSACTION;

    SELECT role, ban INTO v_role, v_ban FROM app_user WHERE id = p_user_id FOR UPDATE;

    IF v_role IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USER_NOT_FOUND';
    END IF;
    IF v_role = 'admin' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CANNOT_BAN_ADMIN';
    END IF;
    IF v_ban THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ALREADY_BANNED';
    END IF;

    -- ck_user_ban_metadata: el ban va con quién, cuándo y por qué.
    UPDATE app_user
       SET ban = 1, banned_by = p_admin_id, banned_at = NOW(6), ban_reason = TRIM(p_reason)
     WHERE id = p_user_id;

    INSERT INTO user_account_event (user_id, actor_id, event_type, reason)
    VALUES (p_user_id, p_admin_id, 'banned', TRIM(p_reason));

    COMMIT;

    SELECT
        id, name, father_last_name, mother_last_name, gender, birthday, avatar, email,
        password_hash, role, description, account_status, active, consecutive_failed_logins,
        status_changed_at, ban, banned_by, banned_at, ban_reason, created_at, updated_at,
        last_login_at
    FROM app_user
    WHERE id = p_user_id;
END$$

DELIMITER ;
