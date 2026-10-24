-- sp_student_purchases: las compras del alumno, de la más reciente a la más antigua (incluye
-- los registros de $0 de las inscripciones gratis y las compras fallidas o canceladas).
-- Devuelve las columnas de purchase.

DROP PROCEDURE IF EXISTS sp_student_purchases;

DELIMITER $$

CREATE PROCEDURE sp_student_purchases(
    IN p_user_id BIGINT UNSIGNED
)
BEGIN
    SELECT id, user_id, course_id, course_level_id, purchase_scope, payment_method_id,
           payment_method_name_snapshot, course_title_snapshot, level_title_snapshot, amount, currency,
           status, idempotency_key, provider, provider_transaction_id, created_at, completed_at
      FROM purchase
     WHERE user_id = p_user_id
     ORDER BY created_at DESC, id DESC;
END$$

DELIMITER ;
