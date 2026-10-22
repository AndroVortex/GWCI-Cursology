-- sp_category_update: cambia nombre y descripción. Solo un administrador activo.
-- Resultado: la fila de category. Errores: NOT_ADMIN, CATEGORY_NOT_FOUND, CATEGORY_NAME_TAKEN.

DROP PROCEDURE IF EXISTS sp_category_update;

DELIMITER $$

CREATE PROCEDURE sp_category_update(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_category_id BIGINT UNSIGNED,
    IN p_name VARCHAR(100),
    IN p_description TEXT
)
BEGIN
    DECLARE EXIT HANDLER FOR 1062
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CATEGORY_NAME_TAKEN';

    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM category WHERE id = p_category_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CATEGORY_NOT_FOUND';
    END IF;

    UPDATE category
       SET name = p_name,
           description = p_description,
           updated_by = p_admin_id
     WHERE id = p_category_id;

    SELECT id, name, description, created_by, created_at, updated_by, updated_at, active
      FROM category
     WHERE id = p_category_id;
END$$

DELIMITER ;
