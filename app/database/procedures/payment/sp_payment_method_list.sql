-- sp_payment_method_list: formas de pago registradas, por nombre. Con p_only_active = 1
-- solo las activas (las que se ofrecen en el pago).
-- Resultado: filas de payment_method.

DROP PROCEDURE IF EXISTS sp_payment_method_list;

DELIMITER $$

CREATE PROCEDURE sp_payment_method_list(
    IN p_only_active BOOLEAN
)
BEGIN
    SELECT id, code, method_name, created_by, created_at, updated_by, updated_at, active
      FROM payment_method
     WHERE p_only_active = 0 OR active = 1
     ORDER BY method_name;
END$$

DELIMITER ;
