-- sp_user_deactivate: el usuario da de baja su propia cuenta (PHP ya verificó su contraseña).
-- Queda account_status = 'deleted' con el evento 'deleted'; se conservan sus cursos, compras,
-- certificados y lo que publicó. Ya no inicia sesión; solo un admin la reactiva. Si es
-- instructor, sus cursos salen del catálogo. Un administrador no se da de baja así, para que
-- la plataforma no se quede sin administradores.
-- p_reason: el motivo que escribió, o el texto fijo que manda PHP si no escribió ninguno.
-- Resultado: la fila de app_user. Errores: USER_NOT_FOUND, USER_NOT_AVAILABLE (ya no está
-- activa o tiene ban), ADMIN_CANNOT_DEACTIVATE.
-- Transacción propia: bloquea la fila del usuario con FOR UPDATE.

DROP PROCEDURE IF EXISTS sp_user_deactivate;

DELIMITER $$

CREATE PROCEDURE sp_user_deactivate(
    IN p_user_id BIGINT UNSIGNED,
    IN p_reason VARCHAR(500)
)
BEGIN
    DECLARE v_role VARCHAR(16) DEFAULT NULL;
    DECLARE v_status VARCHAR(16);
    DECLARE v_ban BOOLEAN;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT role, account_status, ban INTO v_role, v_status, v_ban FROM app_user WHERE id = p_user_id FOR UPDATE;

    IF v_role IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USER_NOT_FOUND';
    END IF;
    IF v_role = 'admin' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ADMIN_CANNOT_DEACTIVATE';
    END IF;
    IF v_status <> 'active' OR v_ban THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USER_NOT_AVAILABLE';
    END IF;

    UPDATE app_user
       SET account_status = 'deleted', status_changed_at = CURRENT_TIMESTAMP(6)
     WHERE id = p_user_id;

    INSERT INTO user_account_event (user_id, actor_id, event_type, reason)
    VALUES (p_user_id, p_user_id, 'deleted', TRIM(p_reason));

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
