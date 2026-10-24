-- sp_payment_method_set_active: activa o desactiva una forma de pago. La crea la primera vez.
-- Solo un administrador activo. Los códigos válidos son los que la app sabe procesar
-- (App\Services\PaymentProvider): 'paypal' (PayPal Sandbox) y 'simulated_card'.
-- Las compras ya hechas conservan el nombre de su forma de pago (payment_method_name_snapshot).
-- Resultado: la fila de payment_method. Errores: NOT_ADMIN, UNKNOWN_PAYMENT_METHOD.

DROP PROCEDURE IF EXISTS sp_payment_method_set_active;

DELIMITER $$

CREATE PROCEDURE sp_payment_method_set_active(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_code VARCHAR(40),
    IN p_name VARCHAR(100),
    IN p_active BOOLEAN
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    IF p_code IS NULL OR p_code COLLATE utf8mb4_bin NOT IN ('paypal', 'simulated_card') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'UNKNOWN_PAYMENT_METHOD';
    END IF;

    INSERT INTO payment_method (code, method_name, created_by, active)
    VALUES (p_code, p_name, p_admin_id, p_active) AS incoming
    ON DUPLICATE KEY UPDATE
        active = incoming.active,
        method_name = incoming.method_name,
        updated_by = p_admin_id;

    SELECT id, code, method_name, created_by, created_at, updated_by, updated_at, active
      FROM payment_method
     WHERE code = p_code;
END$$

DELIMITER ;
