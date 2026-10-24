-- sp_purchase_fail: cierra una compra pendiente que no se pagó: 'failed' (el proveedor la
-- rechazó, p. ej. la tarjeta simulada de prueba) o 'cancelled' (el comprador la canceló).
-- Una compra completada no se puede marcar así.
-- Resultado: la fila de purchase. Errores: PURCHASE_NOT_FOUND, PURCHASE_NOT_PENDING.

DROP PROCEDURE IF EXISTS sp_purchase_fail;

DELIMITER $$

CREATE PROCEDURE sp_purchase_fail(
    IN p_user_id BIGINT UNSIGNED,
    IN p_purchase_id BIGINT UNSIGNED,
    IN p_status VARCHAR(12)
)
BEGIN
    DECLARE v_status VARCHAR(12) DEFAULT NULL;

    SELECT status INTO v_status FROM purchase WHERE id = p_purchase_id AND user_id = p_user_id;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PURCHASE_NOT_FOUND';
    END IF;
    IF v_status <> 'pending' OR p_status COLLATE utf8mb4_bin NOT IN ('failed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PURCHASE_NOT_PENDING';
    END IF;

    UPDATE purchase SET status = p_status WHERE id = p_purchase_id;

    SELECT id, user_id, course_id, course_level_id, purchase_scope, payment_method_id, payment_method_name_snapshot,
           course_title_snapshot, level_title_snapshot, amount, currency, status, idempotency_key, provider,
           provider_transaction_id, created_at, completed_at
      FROM purchase
     WHERE id = p_purchase_id;
END$$

DELIMITER ;
