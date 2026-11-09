-- sp_admin_list_users: todas las cuentas para la vista "Cuentas" del admin, de la más reciente a
-- la más antigua. Solo un administrador activo.
-- Filtros (NULL = sin filtrar):
--   p_search: patrón LIKE ya escapado por PHP (con % alrededor), en el email o el nombre completo
--   p_role: 'student', 'instructor' o 'admin'
--   p_state: 'active' (activa y sin ban), 'locked', 'deleted' o 'banned' (con ban)
-- Resultados (en este orden):
--   1) id, name, father_last_name, mother_last_name, email, avatar, role, account_status, ban,
--      ban_reason, banned_at, created_at, last_login_at, status_changed_at, last_event_type,
--      last_event_at y last_event_reason (el último de user_account_event, NULL si no hay)
--   2) total con los mismos filtros
-- Errores: NOT_ADMIN.

DROP PROCEDURE IF EXISTS sp_admin_list_users;

DELIMITER $$

CREATE PROCEDURE sp_admin_list_users(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_search VARCHAR(310),
    IN p_role VARCHAR(16),
    IN p_state VARCHAR(16),
    IN p_limit INT UNSIGNED,
    IN p_offset INT UNSIGNED
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    SELECT
        u.id, u.name, u.father_last_name, u.mother_last_name, u.email, u.avatar, u.role,
        u.account_status, u.ban, u.ban_reason, u.banned_at, u.created_at, u.last_login_at, u.status_changed_at,
        ev.event_type AS last_event_type, ev.created_at AS last_event_at, ev.reason AS last_event_reason
    FROM app_user u
    LEFT JOIN user_account_event ev ON ev.id = (
        SELECT e.id FROM user_account_event e WHERE e.user_id = u.id ORDER BY e.created_at DESC, e.id DESC LIMIT 1)
    WHERE (p_search IS NULL OR u.email LIKE p_search
           OR CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) LIKE p_search)
      AND (p_role IS NULL OR u.role = p_role COLLATE utf8mb4_bin)
      AND (p_state IS NULL
           OR (p_state = 'active' AND u.account_status = 'active' AND u.ban = 0)
           OR (p_state = 'locked' AND u.account_status = 'locked')
           OR (p_state = 'deleted' AND u.account_status = 'deleted')
           OR (p_state = 'banned' AND u.ban = 1))
    ORDER BY u.created_at DESC, u.id DESC
    LIMIT p_limit OFFSET p_offset;

    SELECT COUNT(*) AS total
      FROM app_user u
     WHERE (p_search IS NULL OR u.email LIKE p_search
            OR CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) LIKE p_search)
       AND (p_role IS NULL OR u.role = p_role COLLATE utf8mb4_bin)
       AND (p_state IS NULL
            OR (p_state = 'active' AND u.account_status = 'active' AND u.ban = 0)
            OR (p_state = 'locked' AND u.account_status = 'locked')
            OR (p_state = 'deleted' AND u.account_status = 'deleted')
            OR (p_state = 'banned' AND u.ban = 1));
END$$

DELIMITER ;
