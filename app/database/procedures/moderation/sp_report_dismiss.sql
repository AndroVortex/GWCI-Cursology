-- sp_report_dismiss: el admin revisó un contenido reportado y lo deja visible. Sus reportes
-- pendientes pasan a dismissed. Devuelve dismissed (cuántos se descartaron; 0 si ya no había).
-- Errores: NOT_ADMIN, INVALID_REPORT (tipo desconocido).

DROP PROCEDURE IF EXISTS sp_report_dismiss;

DELIMITER $$

CREATE PROCEDURE sp_report_dismiss(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_target_type VARCHAR(10),
    IN p_target_id BIGINT UNSIGNED
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;
    IF p_target_type IS NULL OR p_target_type COLLATE utf8mb4_bin NOT IN ('comment', 'opinion') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_REPORT';
    END IF;

    UPDATE content_report
       SET status = 'dismissed', resolved_by = p_admin_id, resolved_at = NOW(6)
     WHERE status = 'pending'
       AND ((p_target_type = 'comment' AND comment_id = p_target_id)
            OR (p_target_type = 'opinion' AND opinion_id = p_target_id));

    SELECT ROW_COUNT() AS dismissed;
END$$

DELIMITER ;
