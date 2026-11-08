-- sp_opinion_restore: el admin vuelve a mostrar una opinión que se ocultó (por el instructor o
-- por un admin); sus estrellas vuelven a contar en el promedio.
-- Devuelve la fila de course_opinion.
-- Errores: NOT_ADMIN, OPINION_NOT_FOUND, NOT_RESTORABLE (ya está visible).

DROP PROCEDURE IF EXISTS sp_opinion_restore;

DELIMITER $$

CREATE PROCEDURE sp_opinion_restore(
    IN p_admin_id BIGINT UNSIGNED,
    IN p_opinion_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_active BOOLEAN;

    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_admin_id AND role = 'admin' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ADMIN';
    END IF;

    SELECT active INTO v_active FROM course_opinion WHERE id = p_opinion_id;

    IF v_active IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OPINION_NOT_FOUND';
    END IF;
    IF v_active THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_RESTORABLE';
    END IF;

    -- ck_opinion_deletion: activa sin datos de baja.
    UPDATE course_opinion
       SET active = 1, deactivated_by = NULL, deactivated_at = NULL, reason = NULL
     WHERE id = p_opinion_id;

    SELECT id, course_id, commented_by, opinion, multimedia_content, commented_at, updated_at,
           active, deactivated_by, deactivated_at, reason
      FROM course_opinion
     WHERE id = p_opinion_id;
END$$

DELIMITER ;
