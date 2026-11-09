-- sp_chat_open: abre una conversación del usuario y la marca como leída para él.
-- p_after_id NULL trae los últimos p_limit mensajes; con un id, solo los posteriores a ese
-- (hasta p_limit), para preguntar por los nuevos cada cierto tiempo.
-- Resultados (en este orden):
--   1) la conversación: id, course_id, course_title, course_cover, course_active,
--      viewer_role ('student' o 'instructor'), student_id, student_name, student_avatar,
--      instructor_id, instructor_name, instructor_avatar y created_at
--   2) los mensajes activos, del más antiguo al más nuevo: id, sent_by, sender_name,
--      sender_avatar, content, sent_at, reply_to, reply_sender_name y reply_content (NULL los
--      tres si no responde a otro; reply_content también es NULL si el mensaje citado se dio
--      de baja)
--   3) unread_total: los no leídos de todas sus conversaciones, ya sin los de esta
--   4) las reacciones de los mensajes de 2): target_id, reaction_id, emoji, reaction_name,
--      total y mine; solo reacciones activas del catálogo
-- Errores: CHAT_NOT_FOUND (no existe o el usuario no participa).

DROP PROCEDURE IF EXISTS sp_chat_open;

DELIMITER $$

CREATE PROCEDURE sp_chat_open(
    IN p_user_id BIGINT UNSIGNED,
    IN p_chat_id BIGINT UNSIGNED,
    IN p_after_id BIGINT UNSIGNED,
    IN p_limit INT UNSIGNED
)
BEGIN
    DECLARE v_is_student BOOLEAN;

    SELECT student_id = p_user_id INTO v_is_student
      FROM chat
     WHERE id = p_chat_id AND (student_id = p_user_id OR instructor_id = p_user_id);

    IF v_is_student IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAT_NOT_FOUND';
    END IF;

    -- Solo escribe si hay algo nuevo del otro: el sondeo de cada 10 s no reescribe la fila.
    IF v_is_student THEN
        UPDATE chat ch
           SET ch.student_last_read_at = NOW(6)
         WHERE ch.id = p_chat_id
           AND EXISTS (SELECT 1 FROM message m
                        WHERE m.chat_id = ch.id AND m.sent_by <> p_user_id
                          AND m.sent_at > COALESCE(ch.student_last_read_at, '1000-01-01'));
    ELSE
        UPDATE chat ch
           SET ch.instructor_last_read_at = NOW(6)
         WHERE ch.id = p_chat_id
           AND EXISTS (SELECT 1 FROM message m
                        WHERE m.chat_id = ch.id AND m.sent_by <> p_user_id
                          AND m.sent_at > COALESCE(ch.instructor_last_read_at, '1000-01-01'));
    END IF;

    SELECT
        ch.id, ch.course_id,
        c.title AS course_title, c.cover_image AS course_cover, c.active AS course_active,
        IF(v_is_student, 'student', 'instructor') AS viewer_role,
        s.id AS student_id,
        CONCAT_WS(' ', s.name, s.father_last_name, s.mother_last_name) AS student_name,
        s.avatar AS student_avatar,
        i.id AS instructor_id,
        CONCAT_WS(' ', i.name, i.father_last_name, i.mother_last_name) AS instructor_name,
        i.avatar AS instructor_avatar,
        ch.created_at
    FROM chat ch
    JOIN course c ON c.id = ch.course_id
    JOIN app_user s ON s.id = ch.student_id
    JOIN app_user i ON i.id = ch.instructor_id
    WHERE ch.id = p_chat_id;

    SELECT page.*
      FROM (
        SELECT
            m.id, m.sent_by,
            CONCAT_WS(' ', a.name, a.father_last_name, a.mother_last_name) AS sender_name,
            a.avatar AS sender_avatar,
            m.content, m.sent_at, m.reply_to,
            CASE WHEN r.id IS NOT NULL
                 THEN CONCAT_WS(' ', ra.name, ra.father_last_name, ra.mother_last_name) END AS reply_sender_name,
            CASE WHEN r.active = 1 THEN LEFT(r.content, 300) END AS reply_content
        FROM message m
        JOIN app_user a ON a.id = m.sent_by
        LEFT JOIN message r ON r.id = m.reply_to
        LEFT JOIN app_user ra ON ra.id = r.sent_by
        WHERE m.chat_id = p_chat_id AND m.active = 1
          AND (p_after_id IS NULL OR m.id > p_after_id)
        ORDER BY
            CASE WHEN p_after_id IS NULL THEN m.id END DESC,
            m.id ASC
        LIMIT p_limit
      ) page
     ORDER BY page.id;

    SELECT COUNT(*) AS unread_total
      FROM chat ch
      JOIN message m ON m.chat_id = ch.id
     WHERE (ch.student_id = p_user_id OR ch.instructor_id = p_user_id)
       AND m.active = 1 AND m.sent_by <> p_user_id
       AND m.sent_at > COALESCE(IF(ch.student_id = p_user_id, ch.student_last_read_at, ch.instructor_last_read_at),
                                '1000-01-01');

    -- La página de 2) se repite como tabla derivada: MySQL no admite LIMIT dentro de IN.
    SELECT mr.message_id AS target_id, re.id AS reaction_id, re.emoji, re.reaction_name,
           COUNT(*) AS total, MAX(mr.reacted_by = p_user_id) AS mine
      FROM message_reaction mr
      JOIN reaction re ON re.id = mr.reaction_id AND re.active = 1
      JOIN (
          SELECT m.id
            FROM message m
           WHERE m.chat_id = p_chat_id AND m.active = 1
             AND (p_after_id IS NULL OR m.id > p_after_id)
           ORDER BY
               CASE WHEN p_after_id IS NULL THEN m.id END DESC,
               m.id ASC
           LIMIT p_limit
      ) page ON page.id = mr.message_id
     GROUP BY mr.message_id, re.id, re.emoji, re.reaction_name
     ORDER BY mr.message_id, re.id;
END$$

DELIMITER ;
