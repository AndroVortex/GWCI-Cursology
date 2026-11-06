-- sp_review_hide: oculta una opinión con su motivo. Solo el instructor del curso o un admin;
-- para cualquier otro la opinión "no existe" (no revela qué ids hay). Ocultarla otra vez no
-- cambia el motivo ni la fecha. Con la opinión oculta, sus estrellas dejan de contar.
-- Devuelve la fila de course_opinion. Errores: OPINION_NOT_FOUND.

DROP PROCEDURE IF EXISTS sp_review_hide;

DELIMITER $$

CREATE PROCEDURE sp_review_hide(
    IN p_actor_id BIGINT UNSIGNED,
    IN p_opinion_id BIGINT UNSIGNED,
    IN p_reason VARCHAR(500)
)
BEGIN
    DECLARE v_found BIGINT UNSIGNED;
    DECLARE v_active BOOLEAN;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT o.id, o.active INTO v_found, v_active
      FROM course_opinion o
      JOIN course c ON c.id = o.course_id
      JOIN app_user actor ON actor.id = p_actor_id
     WHERE o.id = p_opinion_id
       AND (c.created_by = p_actor_id OR actor.role = 'admin')
       FOR UPDATE;

    IF v_found IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OPINION_NOT_FOUND';
    END IF;

    IF v_active THEN
        UPDATE course_opinion
           SET active = 0, deactivated_by = p_actor_id, deactivated_at = NOW(6), reason = TRIM(p_reason)
         WHERE id = p_opinion_id;
    END IF;

    COMMIT;

    SELECT id, course_id, commented_by, opinion, multimedia_content, commented_at, updated_at,
           active, deactivated_by, deactivated_at, reason
      FROM course_opinion
     WHERE id = p_opinion_id;
END$$

DELIMITER ;
