-- sp_admin_unban_user: quita la suspensión (ban) de una cuenta y registra el evento 'unbanned'.
-- Si además estaba bloqueada o dada de baja, sigue así: eso se reactiva aparte.
-- p_reason: el texto del evento (PHP manda uno fijo si el admin no escribe motivo).
-- Resultado: la fila de app_user. Errores: NOT_ADMIN, USER_NOT_FOUND, NOT_BANNED.
-- Transacción propia: bloquea la fila del usuario con FOR UPDATE.

DROP PROCEDURE IF EXISTS sp_admin_unban_user;

DELIMITER $$

CREATE PROCEDURE sp_admin_unban_user(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_user_id BIGINT UNSIGNED,
    IN p_reason VARCHAR(500)
)
BEGIN
    DECLARE v_ban BOOLEAN DEFAULT NULL;

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

    SELECT ban INTO v_ban FROM app_user WHERE id = p_user_id FOR UPDATE;

    IF v_ban IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USER_NOT_FOUND';
    END IF;
    IF NOT v_ban THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_BANNED';
    END IF;

    UPDATE app_user
       SET ban = 0, banned_by = NULL, banned_at = NULL, ban_reason = NULL
     WHERE id = p_user_id;

    INSERT INTO user_account_event (user_id, actor_id, event_type, reason)
    VALUES (p_user_id, p_admin_id, 'unbanned', TRIM(p_reason));

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
