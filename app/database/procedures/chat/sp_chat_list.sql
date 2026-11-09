-- sp_chat_list: las conversaciones del usuario, como alumno o como instructor, de la de
-- actividad más reciente (último mensaje o, si no tiene, su creación) a la más antigua.
-- Columnas: id, course_id, course_title, course_cover, course_active, viewer_role
-- ('student' o 'instructor'), other_id, other_name, other_avatar, last_message_id,
-- last_message_content, last_message_at, last_message_mine (NULL los cuatro si no hay
-- mensajes), unread_count, created_at y last_activity_at.
-- No leído: lo envió el otro participante después de la última lectura de quien consulta.
-- Solo cuentan los mensajes activos.

DROP PROCEDURE IF EXISTS sp_chat_list;

DELIMITER $$

CREATE PROCEDURE sp_chat_list(
    IN p_user_id BIGINT UNSIGNED
)
BEGIN
    SELECT
        ch.id, ch.course_id,
        c.title AS course_title, c.cover_image AS course_cover, c.active AS course_active,
        IF(ch.student_id = p_user_id, 'student', 'instructor') AS viewer_role,
        o.id AS other_id,
        CONCAT_WS(' ', o.name, o.father_last_name, o.mother_last_name) AS other_name,
        o.avatar AS other_avatar,
        lm.id AS last_message_id,
        lm.content AS last_message_content,
        lm.sent_at AS last_message_at,
        lm.sent_by = p_user_id AS last_message_mine,
        (SELECT COUNT(*)
           FROM message m
          WHERE m.chat_id = ch.id AND m.active = 1 AND m.sent_by <> p_user_id
            AND m.sent_at > COALESCE(IF(ch.student_id = p_user_id, ch.student_last_read_at, ch.instructor_last_read_at),
                                     '1000-01-01')) AS unread_count,
        ch.created_at,
        COALESCE(lm.sent_at, ch.created_at) AS last_activity_at
    FROM chat ch
    JOIN course c ON c.id = ch.course_id
    JOIN app_user o ON o.id = IF(ch.student_id = p_user_id, ch.instructor_id, ch.student_id)
    LEFT JOIN message lm ON lm.id = (
        SELECT m.id
          FROM message m
         WHERE m.chat_id = ch.id AND m.active = 1
         ORDER BY m.id DESC
         LIMIT 1)
    WHERE ch.student_id = p_user_id OR ch.instructor_id = p_user_id
    ORDER BY last_activity_at DESC, ch.id DESC;
END$$

DELIMITER ;
