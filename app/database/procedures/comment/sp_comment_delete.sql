-- sp_comment_delete: el autor elimina su comentario (baja lógica con motivo "Eliminado por su
-- autor"). Si es un comentario principal con respuestas, ellas se siguen viendo y él se muestra
-- como "Comentario eliminado".
-- Devuelve el comentario con las columnas de sp_comment_list (comment NULL).
-- Errores: COMMENT_NOT_FOUND (no existe, no es suyo o ya no está activo).

DROP PROCEDURE IF EXISTS sp_comment_delete;

DELIMITER $$

CREATE PROCEDURE sp_comment_delete(
    IN p_user_id BIGINT UNSIGNED,
    IN p_comment_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_found BIGINT UNSIGNED;
    DECLARE v_owner_id BIGINT UNSIGNED;

    SELECT c.id, co.created_by INTO v_found, v_owner_id
      FROM chapter_comment c
      JOIN course_chapter ch ON ch.id = c.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course co ON co.id = l.course_id
     WHERE c.id = p_comment_id AND c.commented_by = p_user_id AND c.active = 1;

    IF v_found IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COMMENT_NOT_FOUND';
    END IF;

    UPDATE chapter_comment
       SET active = 0, deactivated_by = p_user_id, deactivated_at = NOW(6), reason = 'Eliminado por su autor'
     WHERE id = p_comment_id;

    SELECT
        c.id, c.course_chapter_id, c.comment_parent_id, c.commented_by,
        CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS author_name,
        u.avatar AS author_avatar,
        c.commented_by = v_owner_id AS author_is_instructor,
        NULL AS comment, c.commented_at, c.updated_at, c.active,
        (SELECT COUNT(*) FROM chapter_comment r WHERE r.comment_parent_id = c.id AND r.active = 1) AS reply_count
    FROM chapter_comment c
    JOIN app_user u ON u.id = c.commented_by
    WHERE c.id = p_comment_id;
END$$

DELIMITER ;
