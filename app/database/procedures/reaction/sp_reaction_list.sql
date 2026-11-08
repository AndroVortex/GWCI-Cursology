-- sp_reaction_list: el catálogo de reacciones en el orden en que se crearon (es el orden en que
-- se ofrecen). Con p_only_active = 1 solo las activas (para el selector); con 0, todas (admin).
-- Resultado: filas de reaction.

DROP PROCEDURE IF EXISTS sp_reaction_list;

DELIMITER $$

CREATE PROCEDURE sp_reaction_list(
    IN p_only_active BOOLEAN
)
BEGIN
    SELECT id, emoji, reaction_name, created_by, created_at, updated_by, updated_at, active
      FROM reaction
     WHERE p_only_active = 0 OR active = 1
     ORDER BY id;
END$$

DELIMITER ;
