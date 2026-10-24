-- sp_purchase_complete: marca como completada una compra pendiente, después de que el
-- proveedor confirmó el pago (captura de PayPal Sandbox o tarjeta simulada aprobada), y da
-- el acceso (sp_purchase_grant) en la misma transacción.
-- Verifica que el importe y la moneda cobrados sean los de la compra, y que la transacción
-- sea la misma que se guardó al crear la orden (si se guardó).
-- Idempotente: repetirlo con la misma transacción sobre una compra ya completada no cambia nada.
-- Resultado: la fila de purchase.
-- Errores: PURCHASE_NOT_FOUND, PURCHASE_NOT_PENDING, PAYMENT_MISMATCH.
-- Transacción propia: bloquea la compra.

DROP PROCEDURE IF EXISTS sp_purchase_complete;

DELIMITER $$

CREATE PROCEDURE sp_purchase_complete(
    IN p_user_id BIGINT UNSIGNED,
    IN p_purchase_id BIGINT UNSIGNED,
    IN p_transaction_id VARCHAR(191),
    IN p_amount DECIMAL(12,2),
    IN p_currency CHAR(3)
)
BEGIN
    DECLARE v_status VARCHAR(12) DEFAULT NULL;
    DECLARE v_amount DECIMAL(12,2);
    DECLARE v_currency CHAR(3);
    DECLARE v_transaction VARCHAR(191);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT status, amount, currency, provider_transaction_id
      INTO v_status, v_amount, v_currency, v_transaction
      FROM purchase
     WHERE id = p_purchase_id AND user_id = p_user_id
       FOR UPDATE;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PURCHASE_NOT_FOUND';
    END IF;

    IF v_status = 'completed' THEN
        IF NOT (v_transaction <=> p_transaction_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PURCHASE_NOT_PENDING';
        END IF;
    ELSE
        IF v_status <> 'pending' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PURCHASE_NOT_PENDING';
        END IF;
        IF p_amount IS NULL OR p_amount <> v_amount OR p_currency COLLATE utf8mb4_bin <> v_currency
           OR (v_transaction IS NOT NULL AND v_transaction COLLATE utf8mb4_bin <> p_transaction_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYMENT_MISMATCH';
        END IF;

        UPDATE purchase
           SET status = 'completed',
               completed_at = CURRENT_TIMESTAMP(6),
               provider_transaction_id = p_transaction_id
         WHERE id = p_purchase_id;

        CALL sp_purchase_grant(p_purchase_id);
    END IF;

    COMMIT;

    SELECT id, user_id, course_id, course_level_id, purchase_scope, payment_method_id, payment_method_name_snapshot,
           course_title_snapshot, level_title_snapshot, amount, currency, status, idempotency_key, provider,
           provider_transaction_id, created_at, completed_at
      FROM purchase
     WHERE id = p_purchase_id;
END$$

DELIMITER ;
