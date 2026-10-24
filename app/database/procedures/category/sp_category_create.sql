-- sp_category_create: crea una categoría activa. Solo un administrador activo.
-- Resultado: la fila de category. Errores: NOT_ADMIN, CATEGORY_NAME_TAKEN.
-- uq_category_name usa utf8mb4_unicode_ci: "Diseño" y "diseno" cuentan como el mismo nombre.

DROP PROCEDURE IF EXISTS sp_category_create;

DELIMITER $$

CREATE PROCEDURE sp_category_create(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_name VARCHAR(100),
    IN p_description TEXT
)
BEGIN
    DECLARE v_category_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR 1062
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CATEGORY_NAME_TAKEN';

    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    INSERT INTO category (name, description, created_by)
    VALUES (p_name, p_description, p_admin_id);

    SET v_category_id = LAST_INSERT_ID();

    SELECT id, name, description, created_by, created_at, updated_by, updated_at, active
      FROM category
     WHERE id = v_category_id;
END$$

DELIMITER ;
