-- sp_category_set_active: activa o desactiva una categoría. Solo un administrador activo.
-- No permite desactivarla si algún curso publicado se quedaría sin categoría activa
-- (la guía pide reasignarlos antes). Las relaciones históricas se conservan.
-- Resultado: la fila de category. Errores: NOT_ADMIN, CATEGORY_NOT_FOUND, CATEGORY_IN_USE.
-- Transacción propia: bloquea la categoría con FOR UPDATE.

DROP PROCEDURE IF EXISTS sp_category_set_active;

DELIMITER $$

CREATE PROCEDURE sp_category_set_active(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_category_id BIGINT UNSIGNED,
    IN p_active BOOLEAN
)
BEGIN
    DECLARE v_found BIGINT UNSIGNED DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    START TRANSACTION;

    SELECT id INTO v_found FROM category WHERE id = p_category_id FOR UPDATE;
    IF v_found IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CATEGORY_NOT_FOUND';
    END IF;

    IF p_active = 0 AND EXISTS (
        SELECT 1
          FROM course_category cc
          JOIN course c ON c.id = cc.course_id
         WHERE cc.category_id = p_category_id
           AND cc.active = 1
           AND c.active = 1
           AND c.published_at IS NOT NULL
           AND NOT EXISTS (
               SELECT 1
                 FROM course_category other
                 JOIN category oc ON oc.id = other.category_id
                WHERE other.course_id = c.id
                  AND other.category_id <> p_category_id
                  AND other.active = 1
                  AND oc.active = 1
           )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CATEGORY_IN_USE';
    END IF;

    UPDATE category
       SET active = p_active,
           updated_by = p_admin_id
     WHERE id = p_category_id;

    COMMIT;

    SELECT id, name, description, created_by, created_at, updated_by, updated_at, active
      FROM category
     WHERE id = p_category_id;
END$$

DELIMITER ;
