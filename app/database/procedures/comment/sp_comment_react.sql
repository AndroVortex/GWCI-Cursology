-- sp_comment_react: pone, cambia o quita (p_reaction_id NULL) la reacción del usuario a un
-- comentario activo. Reacciona quien puede ver los comentarios del capítulo: el alumno con
-- acceso a su nivel o el instructor dueño del curso.
-- Devuelve las reacciones del comentario: target_id, reaction_id, emoji, reaction_name, total
-- y mine (1 si es la del usuario); solo reacciones activas del catálogo.
-- Errores: COMMENT_NOT_FOUND (no existe, no está activo o el usuario no lo puede ver),
-- REACTION_NOT_FOUND (no existe o está desactivada).

DROP PROCEDURE IF EXISTS sp_comment_react;

DELIMITER $$

CREATE PROCEDURE sp_comment_react(
    IN p_user_id BIGINT UNSIGNED,
    IN p_comment_id BIGINT UNSIGNED,
    IN p_reaction_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_found BIGINT UNSIGNED;

    SELECT c.id INTO v_found
      FROM chapter_comment c
      JOIN course_chapter ch ON ch.id = c.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course co ON co.id = l.course_id
     WHERE c.id = p_comment_id AND c.active = 1
       AND (co.created_by = p_user_id
            OR EXISTS (SELECT 1 FROM user_course_level a WHERE a.user_id = p_user_id AND a.course_level_id = l.id));

    IF v_found IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COMMENT_NOT_FOUND';
    END IF;

    IF p_reaction_id IS NULL THEN
        DELETE FROM chapter_comment_reaction WHERE comment_id = p_comment_id AND reacted_by = p_user_id;
    ELSE
        IF NOT EXISTS (SELECT 1 FROM reaction WHERE id = p_reaction_id AND active = 1) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REACTION_NOT_FOUND';
        END IF;
        INSERT INTO chapter_comment_reaction (comment_id, reacted_by, reaction_id)
        VALUES (p_comment_id, p_user_id, p_reaction_id) AS incoming
        ON DUPLICATE KEY UPDATE reaction_id = incoming.reaction_id, reacted_at = NOW(6);
    END IF;

    SELECT cr.comment_id AS target_id, re.id AS reaction_id, re.emoji, re.reaction_name,
           COUNT(*) AS total, MAX(cr.reacted_by = p_user_id) AS mine
      FROM chapter_comment_reaction cr
      JOIN reaction re ON re.id = cr.reaction_id AND re.active = 1
     WHERE cr.comment_id = p_comment_id
     GROUP BY cr.comment_id, re.id, re.emoji, re.reaction_name
     ORDER BY re.id;
END$$

DELIMITER ;
