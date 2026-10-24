-- sp_purchase_get: una compra del alumno (para capturarla con PayPal o mostrar su estado).
-- Resultado: la fila de purchase. Errores: PURCHASE_NOT_FOUND (no existe o es de otra persona).

DROP PROCEDURE IF EXISTS sp_purchase_get;

DELIMITER $$

CREATE PROCEDURE sp_purchase_get(
    IN p_user_id BIGINT UNSIGNED,
    IN p_purchase_id BIGINT UNSIGNED
)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM purchase WHERE id = p_purchase_id AND user_id = p_user_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PURCHASE_NOT_FOUND';
    END IF;

    SELECT id, user_id, course_id, course_level_id, purchase_scope, payment_method_id, payment_method_name_snapshot,
           course_title_snapshot, level_title_snapshot, amount, currency, status, idempotency_key, provider,
           provider_transaction_id, created_at, completed_at
      FROM purchase
     WHERE id = p_purchase_id;
END$$

DELIMITER ;
