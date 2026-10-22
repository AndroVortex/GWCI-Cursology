-- sp_admin_unlock_user: reactiva una cuenta bloqueada por 3 contraseñas incorrectas.
-- Deja el contador en 0 y registra el evento 'reactivated' en user_account_event.
--
-- p_actor_id: el administrador que la reactiva. NULL solo desde la consola
-- (scripts/unlock_account.php), para cuando el bloqueado es el único administrador.
-- Resultado: la fila de app_user ya reactivada.
-- Errores: NOT_ADMIN, USER_NOT_FOUND, USER_NOT_LOCKED.
-- Transacción propia: bloquea la fila del usuario con FOR UPDATE.

DROP PROCEDURE IF EXISTS sp_admin_unlock_user;

DELIMITER $$

CREATE PROCEDURE sp_admin_unlock_user(
    IN p_actor_id BIGINT UNSIGNED,
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

    IF p_actor_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_actor_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    START TRANSACTION;

    SELECT account_status INTO v_status FROM app_user WHERE id = p_user_id FOR UPDATE;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USER_NOT_FOUND';
    END IF;
    IF v_status <> 'locked' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USER_NOT_LOCKED';
    END IF;

    -- ck_user_locked exige cambiar estado y contador en el mismo UPDATE.
    UPDATE app_user
       SET account_status = 'active',
           consecutive_failed_logins = 0,
           status_changed_at = CURRENT_TIMESTAMP(6)
     WHERE id = p_user_id;

    INSERT INTO user_account_event (user_id, actor_id, event_type, reason)
    VALUES (p_user_id, p_actor_id, 'reactivated', p_reason);

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
