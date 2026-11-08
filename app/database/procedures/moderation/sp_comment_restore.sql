-- sp_comment_restore: el admin vuelve a mostrar un comentario que se ocultó (por el instructor o
-- por un admin). Lo que eliminó su propio autor no se restaura.
-- Devuelve el comentario con las columnas de sp_comment_list (sin reported_by_me).
-- Errores: NOT_ADMIN, COMMENT_NOT_FOUND, NOT_RESTORABLE (está visible o lo eliminó su autor).

DROP PROCEDURE IF EXISTS sp_comment_restore;

DELIMITER $$

CREATE PROCEDURE sp_comment_restore(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_comment_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_found BIGINT UNSIGNED;
    DECLARE v_restorable BOOLEAN;
    DECLARE v_owner_id BIGINT UNSIGNED;

    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    SELECT c.id, c.active = 0 AND c.deactivated_by <> c.commented_by, co.created_by
      INTO v_found, v_restorable, v_owner_id
      FROM chapter_comment c
      JOIN course_chapter ch ON ch.id = c.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course co ON co.id = l.course_id
     WHERE c.id = p_comment_id;

    IF v_found IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COMMENT_NOT_FOUND';
    END IF;
    IF NOT v_restorable THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_RESTORABLE';
    END IF;

    -- ck_comment_deletion: activo sin datos de baja.
    UPDATE chapter_comment
       SET active = 1, deactivated_by = NULL, deactivated_at = NULL, reason = NULL
     WHERE id = p_comment_id;

    SELECT
        c.id, c.course_chapter_id, c.comment_parent_id, c.commented_by,
        CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS author_name,
        u.avatar AS author_avatar,
        c.commented_by = v_owner_id AS author_is_instructor,
        c.comment, c.commented_at, c.updated_at, c.active,
        (SELECT COUNT(*) FROM chapter_comment r WHERE r.comment_parent_id = c.id AND r.active = 1) AS reply_count
    FROM chapter_comment c
    JOIN app_user u ON u.id = c.commented_by
    WHERE c.id = p_comment_id;
END$$

DELIMITER ;
