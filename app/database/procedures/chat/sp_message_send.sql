-- sp_message_send: guarda un mensaje de texto en una conversación del usuario, opcionalmente
-- como respuesta a otro mensaje activo de la misma conversación, y la marca como leída para
-- quien envía. Devuelve el mensaje con las mismas columnas que los mensajes de sp_chat_open.
-- Errores: CHAT_NOT_FOUND (no existe o el usuario no participa), MESSAGE_NOT_FOUND (p_reply_to
-- no es un mensaje activo de esta conversación), INVALID_MESSAGE (vacío o de más de 2000
-- caracteres).

DROP PROCEDURE IF EXISTS sp_message_send;

DELIMITER $$

CREATE PROCEDURE sp_message_send(
    IN p_user_id BIGINT UNSIGNED,
    IN p_chat_id BIGINT UNSIGNED,
    IN p_content TEXT,
    IN p_reply_to BIGINT UNSIGNED
)
BEGIN
    DECLARE v_is_student BOOLEAN;
    DECLARE v_content TEXT;
    DECLARE v_now DATETIME(6) DEFAULT NOW(6);
    DECLARE v_message_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT student_id = p_user_id INTO v_is_student
      FROM chat
     WHERE id = p_chat_id AND (student_id = p_user_id OR instructor_id = p_user_id);

    IF v_is_student IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAT_NOT_FOUND';
    END IF;

    SET v_content = NULLIF(TRIM(p_content), '');
    IF v_content IS NULL OR CHAR_LENGTH(v_content) > 2000 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_MESSAGE';
    END IF;

    IF p_reply_to IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM message WHERE id = p_reply_to AND chat_id = p_chat_id AND active = 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MESSAGE_NOT_FOUND';
    END IF;

    START TRANSACTION;

    INSERT INTO message (chat_id, sent_by, content, reply_to, sent_at, updated_at)
    VALUES (p_chat_id, p_user_id, v_content, p_reply_to, v_now, v_now);

    SET v_message_id = LAST_INSERT_ID();

    IF v_is_student THEN
        UPDATE chat SET student_last_read_at = v_now WHERE id = p_chat_id;
    ELSE
        UPDATE chat SET instructor_last_read_at = v_now WHERE id = p_chat_id;
    END IF;

    COMMIT;

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
    WHERE m.id = v_message_id;
END$$

DELIMITER ;
