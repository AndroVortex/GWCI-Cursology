-- sp_message_react: pone, cambia o quita (p_reaction_id NULL) la reacción del usuario a un
-- mensaje activo de una conversación en la que participa.
-- Devuelve las reacciones del mensaje: target_id, reaction_id, emoji, reaction_name, total y
-- mine; solo reacciones activas del catálogo.
-- Errores: MESSAGE_NOT_FOUND (no existe, no está activo o el usuario no participa),
-- REACTION_NOT_FOUND (no existe o está desactivada).

DROP PROCEDURE IF EXISTS sp_message_react;

DELIMITER $$

CREATE PROCEDURE sp_message_react(
    IN p_user_id BIGINT UNSIGNED,
    IN p_message_id BIGINT UNSIGNED,
    IN p_reaction_id BIGINT UNSIGNED
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM message m
          JOIN chat ch ON ch.id = m.chat_id
         WHERE m.id = p_message_id AND m.active = 1
           AND (ch.student_id = p_user_id OR ch.instructor_id = p_user_id)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MESSAGE_NOT_FOUND';
    END IF;

    IF p_reaction_id IS NULL THEN
        DELETE FROM message_reaction WHERE message_id = p_message_id AND reacted_by = p_user_id;
    ELSE
        IF NOT EXISTS (SELECT 1 FROM reaction WHERE id = p_reaction_id AND active = 1) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REACTION_NOT_FOUND';
        END IF;
        INSERT INTO message_reaction (message_id, reacted_by, reaction_id)
        VALUES (p_message_id, p_user_id, p_reaction_id) AS incoming
        ON DUPLICATE KEY UPDATE reaction_id = incoming.reaction_id, reacted_at = NOW(6);
    END IF;

    SELECT mr.message_id AS target_id, re.id AS reaction_id, re.emoji, re.reaction_name,
           COUNT(*) AS total, MAX(mr.reacted_by = p_user_id) AS mine
      FROM message_reaction mr
      JOIN reaction re ON re.id = mr.reaction_id AND re.active = 1
     WHERE mr.message_id = p_message_id
     GROUP BY mr.message_id, re.id, re.emoji, re.reaction_name
     ORDER BY re.id;
END$$

DELIMITER ;
