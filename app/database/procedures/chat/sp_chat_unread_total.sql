-- sp_chat_unread_total: cuántos mensajes no leídos tiene el usuario en todas sus
-- conversaciones, para el contador del menú. Devuelve una fila con unread_total.
-- No leído: mensaje activo del otro participante posterior a la última lectura del usuario.

DROP PROCEDURE IF EXISTS sp_chat_unread_total;

DELIMITER $$

CREATE PROCEDURE sp_chat_unread_total(
    IN p_user_id BIGINT UNSIGNED
)
BEGIN
    SELECT COUNT(*) AS unread_total
      FROM chat ch
      JOIN message m ON m.chat_id = ch.id
     WHERE (ch.student_id = p_user_id OR ch.instructor_id = p_user_id)
       AND m.active = 1 AND m.sent_by <> p_user_id
       AND m.sent_at > COALESCE(IF(ch.student_id = p_user_id, ch.student_last_read_at, ch.instructor_last_read_at),
                                '1000-01-01');
END$$

DELIMITER ;
