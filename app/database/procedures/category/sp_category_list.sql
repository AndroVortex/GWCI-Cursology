-- sp_category_list: categorías ordenadas por nombre. Con p_include_inactive = 0 solo
-- las activas (para instructores y visitantes); con 1, todas (para el admin).

DROP PROCEDURE IF EXISTS sp_category_list;

DELIMITER $$

CREATE PROCEDURE sp_category_list(
    IN p_include_inactive BOOLEAN
)
BEGIN
    SELECT id, name, description, created_by, created_at, updated_by, updated_at, active
      FROM category
     WHERE p_include_inactive = 1 OR active = 1
     ORDER BY name;
END$$

DELIMITER ;
