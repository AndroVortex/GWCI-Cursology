-- sp_purchase_start: abre una compra pendiente (status 'pending') de un curso completo
-- (p_level_id NULL) o de un nivel, con la forma de pago elegida. El importe sale de la
-- base con el precio vigente (nunca del navegador) y queda congelado junto con los
-- títulos (*_snapshot). Repetir la misma p_idempotency_key devuelve la misma compra.
-- Solo se vende lo que tiene precio:
--   - curso completo: solo si full_course_price > 0 (forma "curso completo + niveles");
--   - nivel: solo si el curso no es gratis y el nivel cuesta más de 0.
-- Resultado: la fila de purchase.
-- Errores: NOT_STUDENT, COURSE_NOT_FOUND, LEVEL_NOT_FOUND, NOT_FOR_SALE, ALREADY_OWNED,
--          PAYMENT_METHOD_UNAVAILABLE.
-- Transacción propia: bloquea el curso.

DROP PROCEDURE IF EXISTS sp_purchase_start;

DELIMITER $$

CREATE PROCEDURE sp_purchase_start(
    IN p_user_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED,
    IN p_level_id BIGINT UNSIGNED,
    IN p_method_code VARCHAR(40),
    IN p_idempotency_key VARCHAR(64)
)
BEGIN
    DECLARE v_found BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_price DECIMAL(12,2);
    DECLARE v_title VARCHAR(200);
    DECLARE v_currency CHAR(3);
    DECLARE v_level_title VARCHAR(200) DEFAULT NULL;
    DECLARE v_level_cost DECIMAL(12,2) DEFAULT NULL;
    DECLARE v_method_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_method_name VARCHAR(100);
    DECLARE v_amount DECIMAL(12,2);
    DECLARE v_existing BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_purchase_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_user_id AND role = 'student' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_STUDENT';
    END IF;

    START TRANSACTION;

    SELECT id INTO v_existing
      FROM purchase
     WHERE idempotency_key = p_idempotency_key AND user_id = p_user_id;

    IF v_existing IS NULL THEN
        SELECT id, full_course_price, title, currency INTO v_found, v_price, v_title, v_currency
          FROM course
         WHERE id = p_course_id AND active = 1 AND published_at IS NOT NULL
           FOR UPDATE;
        IF v_found IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
        END IF;

        SELECT id, method_name INTO v_method_id, v_method_name
          FROM payment_method
         WHERE code = p_method_code AND active = 1;
        IF v_method_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYMENT_METHOD_UNAVAILABLE';
        END IF;

        IF p_level_id IS NULL THEN
            IF v_price IS NULL OR v_price = 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_FOR_SALE';
            END IF;
            IF EXISTS (
                SELECT 1 FROM purchase
                 WHERE user_id = p_user_id AND course_id = p_course_id
                   AND purchase_scope = 'course' AND status = 'completed'
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ALREADY_OWNED';
            END IF;
            SET v_amount = v_price;
        ELSE
            SELECT title, cost INTO v_level_title, v_level_cost
              FROM course_level
             WHERE id = p_level_id AND course_id = p_course_id;
            IF v_level_title IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LEVEL_NOT_FOUND';
            END IF;
            IF v_price = 0 OR v_level_cost = 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_FOR_SALE';
            END IF;
            IF EXISTS (SELECT 1 FROM user_course_level WHERE user_id = p_user_id AND course_level_id = p_level_id) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ALREADY_OWNED';
            END IF;
            SET v_amount = v_level_cost;
        END IF;

        INSERT INTO purchase (
            user_id, course_id, course_level_id, purchase_scope, payment_method_id, payment_method_name_snapshot,
            course_title_snapshot, level_title_snapshot, amount, currency, status, idempotency_key, provider
        ) VALUES (
            p_user_id, p_course_id, p_level_id, IF(p_level_id IS NULL, 'course', 'level'), v_method_id, v_method_name,
            v_title, v_level_title, v_amount, v_currency, 'pending', p_idempotency_key, p_method_code
        );
        SET v_existing = LAST_INSERT_ID();
    END IF;

    COMMIT;

    SELECT id, user_id, course_id, course_level_id, purchase_scope, payment_method_id, payment_method_name_snapshot,
           course_title_snapshot, level_title_snapshot, amount, currency, status, idempotency_key, provider,
           provider_transaction_id, created_at, completed_at
      FROM purchase
     WHERE id = v_existing;
END$$

DELIMITER ;
