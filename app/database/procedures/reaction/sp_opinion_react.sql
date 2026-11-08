-- sp_opinion_react: pone, cambia o quita (p_reaction_id NULL) la reacción del usuario a una
-- opinión visible (activa, de un curso publicado y activo).
-- Devuelve las reacciones de la opinión: target_id, reaction_id, emoji, reaction_name, total y
-- mine; solo reacciones activas del catálogo.
-- Errores: OPINION_NOT_FOUND, REACTION_NOT_FOUND (no existe o está desactivada).

DROP PROCEDURE IF EXISTS sp_opinion_react;

DELIMITER $$

CREATE PROCEDURE sp_opinion_react(
    IN p_user_id BIGINT UNSIGNED,
    IN p_opinion_id BIGINT UNSIGNED,
    IN p_reaction_id BIGINT UNSIGNED
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM course_opinion o
          JOIN course c ON c.id = o.course_id
         WHERE o.id = p_opinion_id AND o.active = 1 AND c.active = 1 AND c.published_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OPINION_NOT_FOUND';
    END IF;

    IF p_reaction_id IS NULL THEN
        DELETE FROM opinion_reaction WHERE opinion_id = p_opinion_id AND reacted_by = p_user_id;
    ELSE
        IF NOT EXISTS (SELECT 1 FROM reaction WHERE id = p_reaction_id AND active = 1) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REACTION_NOT_FOUND';
        END IF;
        INSERT INTO opinion_reaction (opinion_id, reacted_by, reaction_id)
        VALUES (p_opinion_id, p_user_id, p_reaction_id) AS incoming
        ON DUPLICATE KEY UPDATE reaction_id = incoming.reaction_id, reacted_at = NOW(6);
    END IF;

    SELECT orr.opinion_id AS target_id, re.id AS reaction_id, re.emoji, re.reaction_name,
           COUNT(*) AS total, MAX(orr.reacted_by = p_user_id) AS mine
      FROM opinion_reaction orr
      JOIN reaction re ON re.id = orr.reaction_id AND re.active = 1
     WHERE orr.opinion_id = p_opinion_id
     GROUP BY orr.opinion_id, re.id, re.emoji, re.reaction_name
     ORDER BY re.id;
END$$

DELIMITER ;
