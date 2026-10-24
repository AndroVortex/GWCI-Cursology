-- sp_enrollment_free: inscripción sin pago a un curso publicado y activo.
--   - Curso gratis (full_course_price = 0): una compra de $0 con purchase_scope = 'course'
--     (incluye los niveles que se agreguen después).
--   - Curso de pago: una compra de $0 por cada nivel de $0 que el alumno aún no tenga.
-- Las compras de $0 no llevan forma de pago (ck_purchase_method) y quedan completadas.
-- Resultado: course_id, levels_granted (niveles nuevos con acceso) y enrolled (siempre 1).
-- Errores: NOT_STUDENT, COURSE_NOT_FOUND, ALREADY_OWNED, NOT_FREE (no hay nada gratis).
-- Transacción propia: bloquea el curso.

DROP PROCEDURE IF EXISTS sp_enrollment_free;

DELIMITER $$

CREATE PROCEDURE sp_enrollment_free(
    IN p_user_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_price DECIMAL(12,2);
    DECLARE v_title VARCHAR(200);
    DECLARE v_currency CHAR(3);
    DECLARE v_found BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_before INT DEFAULT 0;
    DECLARE v_after INT DEFAULT 0;
    DECLARE v_purchase_id BIGINT UNSIGNED;
    DECLARE v_done BOOLEAN DEFAULT FALSE;

    DECLARE c_free_levels CURSOR FOR
        SELECT p.id FROM purchase p
         WHERE p.user_id = p_user_id AND p.course_id = p_course_id
           AND p.status = 'completed' AND p.amount = 0 AND p.purchase_scope = 'level';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = TRUE;

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

    SELECT id, full_course_price, title, currency INTO v_found, v_price, v_title, v_currency
      FROM course
     WHERE id = p_course_id AND active = 1 AND published_at IS NOT NULL
       FOR UPDATE;

    IF v_found IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
    END IF;

    SELECT COUNT(*) INTO v_before FROM user_course_level WHERE user_id = p_user_id AND course_id = p_course_id;

    IF v_price = 0 THEN
        IF EXISTS (
            SELECT 1 FROM purchase
             WHERE user_id = p_user_id AND course_id = p_course_id
               AND purchase_scope = 'course' AND status = 'completed'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ALREADY_OWNED';
        END IF;

        INSERT INTO purchase (
            user_id, course_id, course_level_id, purchase_scope, course_title_snapshot,
            amount, currency, status, idempotency_key, completed_at
        ) VALUES (
            p_user_id, p_course_id, NULL, 'course', v_title,
            0, v_currency, 'completed', CONCAT('free-course-', p_user_id, '-', p_course_id), CURRENT_TIMESTAMP(6)
        );
        CALL sp_purchase_grant(LAST_INSERT_ID());
    ELSE
        IF NOT EXISTS (SELECT 1 FROM course_level WHERE course_id = p_course_id AND cost = 0) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_FREE';
        END IF;

        INSERT INTO purchase (
            user_id, course_id, course_level_id, purchase_scope, course_title_snapshot, level_title_snapshot,
            amount, currency, status, idempotency_key, completed_at
        )
        SELECT p_user_id, p_course_id, l.id, 'level', v_title, l.title,
               0, v_currency, 'completed', CONCAT('free-level-', p_user_id, '-', l.id), CURRENT_TIMESTAMP(6)
          FROM course_level l
         WHERE l.course_id = p_course_id AND l.cost = 0
           AND NOT EXISTS (SELECT 1 FROM user_course_level a WHERE a.user_id = p_user_id AND a.course_level_id = l.id)
           AND NOT EXISTS (SELECT 1 FROM purchase x WHERE x.idempotency_key = CONCAT('free-level-', p_user_id, '-', l.id));

        SET v_done = FALSE;
        OPEN c_free_levels;
        grant_loop: LOOP
            FETCH c_free_levels INTO v_purchase_id;
            IF v_done THEN
                LEAVE grant_loop;
            END IF;
            CALL sp_purchase_grant(v_purchase_id);
        END LOOP;
        CLOSE c_free_levels;
    END IF;

    SELECT COUNT(*) INTO v_after FROM user_course_level WHERE user_id = p_user_id AND course_id = p_course_id;

    -- Curso de pago cuyos niveles gratis ya tenía: no hubo nada nuevo.
    IF v_after = v_before THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ALREADY_OWNED';
    END IF;

    COMMIT;

    SELECT p_course_id AS course_id, v_after - v_before AS levels_granted, 1 AS enrolled;
END$$

DELIMITER ;
