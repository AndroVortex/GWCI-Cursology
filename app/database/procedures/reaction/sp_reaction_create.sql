-- sp_reaction_create: agrega una reacción activa al catálogo. Solo un administrador activo.
-- Resultado: la fila de reaction. Errores: NOT_ADMIN, REACTION_NAME_TAKEN.
-- uq_reaction_name usa utf8mb4_unicode_ci: "Útil" y "util" cuentan como el mismo nombre.

DROP PROCEDURE IF EXISTS sp_reaction_create;

DELIMITER $$

CREATE PROCEDURE sp_reaction_create(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_emoji VARCHAR(32),
    IN p_name VARCHAR(60)
)
BEGIN
    DECLARE v_reaction_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR 1062
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REACTION_NAME_TAKEN';

    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    INSERT INTO reaction (emoji, reaction_name, created_by)
    VALUES (p_emoji, p_name, p_admin_id);

    SET v_reaction_id = LAST_INSERT_ID();

    SELECT id, emoji, reaction_name, created_by, created_at, updated_by, updated_at, active
      FROM reaction
     WHERE id = v_reaction_id;
END$$

DELIMITER ;
