-- sp_comment_hide: oculta un comentario con su motivo. Solo el instructor dueño del curso o un
-- admin; para cualquier otro el comentario "no existe" (no revela qué ids hay). Ocultarlo otra
-- vez no cambia el motivo ni la fecha.
-- Devuelve el comentario con las columnas de sp_comment_list (comment NULL).
-- Errores: COMMENT_NOT_FOUND.

DROP PROCEDURE IF EXISTS sp_comment_hide;

DELIMITER $$

CREATE PROCEDURE sp_comment_hide(
    IN p_user_id BIGINT UNSIGNED,
    IN p_comment_id BIGINT UNSIGNED,
    IN p_reason VARCHAR(500)
)
BEGIN
    DECLARE v_found BIGINT UNSIGNED;
    DECLARE v_active BOOLEAN;
    DECLARE v_owner_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT c.id, c.active, co.created_by INTO v_found, v_active, v_owner_id
      FROM chapter_comment c
      JOIN course_chapter ch ON ch.id = c.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course co ON co.id = l.course_id
      JOIN app_user actor ON actor.id = p_user_id
     WHERE c.id = p_comment_id
       AND (co.created_by = p_user_id OR actor.role = 'admin')
       FOR UPDATE;

    IF v_found IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COMMENT_NOT_FOUND';
    END IF;

    IF v_active THEN
        UPDATE chapter_comment
           SET active = 0, deactivated_by = p_user_id, deactivated_at = NOW(6), reason = TRIM(p_reason)
         WHERE id = p_comment_id;
    END IF;

    COMMIT;

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
