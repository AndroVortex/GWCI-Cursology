-- sp_reaction_set_active: activa o desactiva una reacción. Solo un administrador activo.
-- Una desactivada deja de ofrecerse y de contarse; las reacciones que ya se dieron con ella se
-- conservan y vuelven a contar si se reactiva.
-- Resultado: la fila de reaction. Errores: NOT_ADMIN, REACTION_NOT_FOUND.

DROP PROCEDURE IF EXISTS sp_reaction_set_active;

DELIMITER $$

CREATE PROCEDURE sp_reaction_set_active(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_reaction_id BIGINT UNSIGNED,
    IN p_active BOOLEAN
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM reaction WHERE id = p_reaction_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REACTION_NOT_FOUND';
    END IF;

    UPDATE reaction
       SET active = p_active,
           updated_by = p_admin_id
     WHERE id = p_reaction_id;

    SELECT id, emoji, reaction_name, created_by, created_at, updated_by, updated_at, active
      FROM reaction
     WHERE id = p_reaction_id;
END$$

DELIMITER ;
