-- sp_admin_user_events: el historial de una cuenta (user_account_event), del más reciente al más
-- antiguo. Solo un administrador activo.
-- Resultado: id, event_type, reason, created_at, actor_id y actor_name (NULL si lo hizo el
-- sistema, como el bloqueo automático).
-- Errores: NOT_ADMIN, USER_NOT_FOUND.

DROP PROCEDURE IF EXISTS sp_admin_user_events;

DELIMITER $$

CREATE PROCEDURE sp_admin_user_events(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_user_id BIGINT UNSIGNED
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM app_user WHERE id = p_user_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USER_NOT_FOUND';
    END IF;

    SELECT e.id, e.event_type, e.reason, e.created_at, e.actor_id,
           IF(a.id IS NULL, NULL, CONCAT_WS(' ', a.name, a.father_last_name, a.mother_last_name)) AS actor_name
      FROM user_account_event e
      LEFT JOIN app_user a ON a.id = e.actor_id
     WHERE e.user_id = p_user_id
     ORDER BY e.created_at DESC, e.id DESC;
END$$

DELIMITER ;
