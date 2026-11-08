-- sp_instructor_recent_comments: los últimos comentarios principales activos que los alumnos
-- dejaron en los capítulos de los cursos del instructor, para su panel.
-- Resultado: id, course_id, course_title, chapter_id, chapter_title, author_name, author_avatar,
-- comment, commented_at, answered (1 si ya tiene una respuesta activa del instructor).
-- Errores: NOT_INSTRUCTOR.

DROP PROCEDURE IF EXISTS sp_instructor_recent_comments;

DELIMITER $$

CREATE PROCEDURE sp_instructor_recent_comments(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_limit INT UNSIGNED
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_instructor_id AND role = 'instructor' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_INSTRUCTOR';
    END IF;

    SELECT c.id, co.id AS course_id, co.title AS course_title, ch.id AS chapter_id, ch.title AS chapter_title,
           CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS author_name,
           u.avatar AS author_avatar, c.comment, c.commented_at,
           EXISTS (SELECT 1 FROM chapter_comment r
                    WHERE r.comment_parent_id = c.id AND r.active = 1 AND r.commented_by = p_instructor_id) AS answered
      FROM chapter_comment c
      JOIN app_user u ON u.id = c.commented_by
      JOIN course_chapter ch ON ch.id = c.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course co ON co.id = l.course_id
     WHERE co.created_by = p_instructor_id AND c.comment_parent_id IS NULL AND c.active = 1
       AND c.commented_by <> p_instructor_id
     ORDER BY c.commented_at DESC, c.id DESC
     LIMIT p_limit;
END$$

DELIMITER ;
