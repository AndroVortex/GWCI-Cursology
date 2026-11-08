-- sp_reaction_update: cambia el emoji y el nombre de una reacción. Solo un administrador activo.
-- Las reacciones que ya se dieron con ella muestran el emoji nuevo.
-- Resultado: la fila de reaction. Errores: NOT_ADMIN, REACTION_NOT_FOUND, REACTION_NAME_TAKEN.

DROP PROCEDURE IF EXISTS sp_reaction_update;

DELIMITER $$

CREATE PROCEDURE sp_reaction_update(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_reaction_id BIGINT UNSIGNED,
    IN p_emoji VARCHAR(32),
    IN p_name VARCHAR(60)
)
BEGIN
    DECLARE EXIT HANDLER FOR 1062
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REACTION_NAME_TAKEN';

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
       SET emoji = p_emoji,
           reaction_name = p_name,
           updated_by = p_admin_id
     WHERE id = p_reaction_id;

    SELECT id, emoji, reaction_name, created_by, created_at, updated_by, updated_at, active
      FROM reaction
     WHERE id = p_reaction_id;
END$$

DELIMITER ;
