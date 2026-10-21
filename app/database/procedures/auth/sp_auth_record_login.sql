-- sp_auth_record_login: registra el resultado de un intento de login (después de
-- password_verify() en PHP) y aplica el bloqueo por 3 fallos CONSECUTIVOS.
--
-- p_user_id NULL = el email no existe (unknown_user).
-- Resultado: outcome, consecutive_failures, account_status (NULL si unknown_user).
-- Errores: USER_NOT_FOUND.
-- Transacción propia: bloquea la fila del usuario con FOR UPDATE.

DROP PROCEDURE IF EXISTS sp_auth_record_login;

DELIMITER $$

CREATE PROCEDURE sp_auth_record_login(
    IN p_user_id BIGINT UNSIGNED,
    IN p_password_ok BOOLEAN
)
BEGIN
    DECLARE v_status VARCHAR(16) DEFAULT NULL;
    DECLARE v_ban BOOLEAN DEFAULT NULL;
    DECLARE v_failures TINYINT UNSIGNED DEFAULT 0;
    DECLARE v_outcome VARCHAR(20);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_user_id IS NULL THEN
        INSERT INTO login_attempt (user_id, outcome, consecutive_failures)
        VALUES (NULL, 'unknown_user', 0);

        SELECT 'unknown_user' AS outcome, 0 AS consecutive_failures, NULL AS account_status;
    ELSE
        START TRANSACTION;

        SELECT account_status, ban, consecutive_failed_logins
          INTO v_status, v_ban, v_failures
          FROM app_user
         WHERE id = p_user_id
           FOR UPDATE;

        IF v_status IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USER_NOT_FOUND';
        END IF;

        IF v_status <> 'active' OR v_ban = 1 THEN
            -- Cuenta bloqueada, dada de baja o baneada: una contraseña correcta no la reactiva.
            SET v_outcome = 'blocked';
            INSERT INTO login_attempt (user_id, outcome, consecutive_failures)
            VALUES (p_user_id, 'blocked', v_failures);

        ELSEIF p_password_ok THEN
            SET v_outcome = 'success';
            SET v_failures = 0;
            UPDATE app_user
               SET consecutive_failed_logins = 0,
                   last_login_at = CURRENT_TIMESTAMP(6)
             WHERE id = p_user_id;
            INSERT INTO login_attempt (user_id, outcome, consecutive_failures)
            VALUES (p_user_id, 'success', 0);

        ELSE
            SET v_outcome = 'wrong_password';
            SET v_failures = v_failures + 1;

            IF v_failures >= 3 THEN
                -- ck_user_locked exige cambiar estado y contador en el mismo UPDATE.
                SET v_failures = 3;
                SET v_status = 'locked';
                UPDATE app_user
                   SET consecutive_failed_logins = 3,
                       account_status = 'locked',
                       status_changed_at = CURRENT_TIMESTAMP(6)
                 WHERE id = p_user_id;
                INSERT INTO user_account_event (user_id, actor_id, event_type, reason)
                VALUES (p_user_id, NULL, 'locked', 'Bloqueo automatico: 3 contrasenas incorrectas consecutivas');
            ELSE
                UPDATE app_user
                   SET consecutive_failed_logins = v_failures
                 WHERE id = p_user_id;
            END IF;

            INSERT INTO login_attempt (user_id, outcome, consecutive_failures)
            VALUES (p_user_id, 'wrong_password', v_failures);
        END IF;

        COMMIT;

        SELECT v_outcome AS outcome, v_failures AS consecutive_failures, v_status AS account_status;
    END IF;
END$$

DELIMITER ;
