-- sp_review_save: guarda (o cambia) la calificación de 1 a 5 y la opinión del alumno sobre un
-- curso que terminó. La opinión es opcional: NULL o vacía la quita.
-- Una opinión ocultada por el instructor o el admin ya no cambia: si llega otro texto,
-- OPINION_HIDDEN (con el mismo texto sí se guardan las estrellas).
-- Devuelve lo mismo que sp_review_mine.
-- Errores: NOT_ENROLLED, COURSE_NOT_COMPLETED, INVALID_RATE, OPINION_HIDDEN.

DROP PROCEDURE IF EXISTS sp_review_save;

DELIMITER $$

CREATE PROCEDURE sp_review_save(
    IN p_user_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED,
    IN p_rate TINYINT UNSIGNED,
    IN p_opinion TEXT
)
BEGIN
    DECLARE v_enrolled BOOLEAN DEFAULT FALSE;
    DECLARE v_completed_at DATETIME(6);
    DECLARE v_opinion TEXT;
    DECLARE v_opinion_id BIGINT UNSIGNED;
    DECLARE v_active BOOLEAN;
    DECLARE v_current TEXT;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT TRUE, completed_at INTO v_enrolled, v_completed_at
      FROM user_courses
     WHERE user_id = p_user_id AND course_id = p_course_id;

    IF NOT v_enrolled THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ENROLLED';
    END IF;
    IF v_completed_at IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_COMPLETED';
    END IF;
    IF p_rate IS NULL OR p_rate NOT BETWEEN 1 AND 5 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_RATE';
    END IF;

    SET v_opinion = NULLIF(TRIM(p_opinion), '');

    START TRANSACTION;

    SELECT id, active, opinion INTO v_opinion_id, v_active, v_current
      FROM course_opinion
     WHERE commented_by = p_user_id AND course_id = p_course_id
       FOR UPDATE;

    IF v_opinion_id IS NOT NULL AND NOT v_active AND NOT (v_current <=> v_opinion) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OPINION_HIDDEN';
    END IF;

    INSERT INTO course_rate (rated_by, course_id, rate)
    VALUES (p_user_id, p_course_id, p_rate) AS incoming
    ON DUPLICATE KEY UPDATE rate = incoming.rate;

    IF v_opinion_id IS NULL AND v_opinion IS NOT NULL THEN
        INSERT INTO course_opinion (course_id, commented_by, opinion)
        VALUES (p_course_id, p_user_id, v_opinion);
    ELSEIF v_opinion_id IS NOT NULL AND v_active AND v_opinion IS NULL THEN
        DELETE FROM course_opinion WHERE id = v_opinion_id;
    ELSEIF v_opinion_id IS NOT NULL AND v_active AND NOT (v_current <=> v_opinion) THEN
        UPDATE course_opinion SET opinion = v_opinion WHERE id = v_opinion_id;
    END IF;

    COMMIT;

    CALL sp_review_mine(p_user_id, p_course_id);
END$$

DELIMITER ;
