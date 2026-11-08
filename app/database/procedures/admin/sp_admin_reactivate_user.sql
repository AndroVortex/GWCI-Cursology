-- sp_admin_reactivate_user: reactiva una cuenta bloqueada (3 contraseñas incorrectas) o dada de
-- baja: vuelve a 'active' con el contador de fallos en 0 y registra el evento 'reactivated'. Entra
-- con su misma contraseña. El ban no cambia: se quita con sp_admin_unban_user.
-- Resultado: la fila de app_user. Errores: NOT_ADMIN, USER_NOT_FOUND, USER_ALREADY_ACTIVE.
-- Transacción propia: bloquea la fila del usuario con FOR UPDATE.

DROP PROCEDURE IF EXISTS sp_admin_reactivate_user;

DELIMITER $$

CREATE PROCEDURE sp_admin_reactivate_user(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_user_id BIGINT UNSIGNED,
    IN p_reason VARCHAR(500)
)
BEGIN
    DECLARE v_status VARCHAR(16) DEFAULT NULL;

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

    SELECT account_status INTO v_status FROM app_user WHERE id = p_user_id FOR UPDATE;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USER_NOT_FOUND';
    END IF;
    IF v_status = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USER_ALREADY_ACTIVE';
    END IF;

    -- ck_user_locked exige cambiar estado y contador en el mismo UPDATE.
    UPDATE app_user
       SET account_status = 'active',
           consecutive_failed_logins = 0,
           status_changed_at = CURRENT_TIMESTAMP(6)
     WHERE id = p_user_id;

    INSERT INTO user_account_event (user_id, actor_id, event_type, reason)
    VALUES (p_user_id, p_admin_id, 'reactivated', TRIM(p_reason));

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
