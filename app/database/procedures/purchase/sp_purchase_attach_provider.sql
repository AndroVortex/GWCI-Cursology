-- sp_purchase_attach_provider: guarda en una compra pendiente el id de la transacción del
-- proveedor (p. ej. el id de la orden de PayPal Sandbox), antes de que el comprador la apruebe.
-- Resultado: la fila de purchase. Errores: PURCHASE_NOT_FOUND, PURCHASE_NOT_PENDING.

DROP PROCEDURE IF EXISTS sp_purchase_attach_provider;

DELIMITER $$

CREATE PROCEDURE sp_purchase_attach_provider(
    IN p_user_id BIGINT UNSIGNED,
    IN p_purchase_id BIGINT UNSIGNED,
    IN p_transaction_id VARCHAR(191)
)
BEGIN
    DECLARE v_status VARCHAR(12) DEFAULT NULL;

    SELECT status INTO v_status FROM purchase WHERE id = p_purchase_id AND user_id = p_user_id;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PURCHASE_NOT_FOUND';
    END IF;
    IF v_status <> 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PURCHASE_NOT_PENDING';
    END IF;

    UPDATE purchase SET provider_transaction_id = p_transaction_id WHERE id = p_purchase_id;

    SELECT id, user_id, course_id, course_level_id, purchase_scope, payment_method_id, payment_method_name_snapshot,
           course_title_snapshot, level_title_snapshot, amount, currency, status, idempotency_key, provider,
           provider_transaction_id, created_at, completed_at
      FROM purchase
     WHERE id = p_purchase_id;
END$$

DELIMITER ;
