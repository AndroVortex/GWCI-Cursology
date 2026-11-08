-- sp_report_create: un alumno o instructor reporta un comentario o una opinión de otro.
--   - Comentario: debe estar activo y el usuario debe poder verlo (acceso al nivel del capítulo o
--     dueño del curso).
--   - Opinión: debe estar visible (activa, de un curso publicado y activo cuyo instructor esté
--     activo y sin ban).
-- Un reporte por usuario y contenido: reportar otra vez no cambia nada (created = 0).
-- p_reason_code: spam, offensive, answers u other; "other" exige p_details (de hasta 500).
-- Devuelve: target_type, target_id y created.
-- Errores: INVALID_REPORT, COMMENT_NOT_FOUND, OPINION_NOT_FOUND, CANNOT_REPORT_OWN.

DROP PROCEDURE IF EXISTS sp_report_create;

DELIMITER $$

CREATE PROCEDURE sp_report_create(
    IN p_user_id BIGINT UNSIGNED,
    IN p_target_type VARCHAR(10),
    IN p_target_id BIGINT UNSIGNED,
    IN p_reason_code VARCHAR(12),
    IN p_details VARCHAR(500)
)
BEGIN
    DECLARE v_details VARCHAR(500);
    DECLARE v_author_id BIGINT UNSIGNED;
    DECLARE v_created BOOLEAN DEFAULT FALSE;

    SET v_details = NULLIF(TRIM(p_details), '');
    IF p_target_type IS NULL OR p_target_type COLLATE utf8mb4_bin NOT IN ('comment', 'opinion')
       OR p_reason_code IS NULL OR p_reason_code COLLATE utf8mb4_bin NOT IN ('spam', 'offensive', 'answers', 'other')
       OR (p_reason_code = 'other' AND v_details IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_REPORT';
    END IF;

    IF p_target_type = 'comment' THEN
        SELECT c.commented_by INTO v_author_id
          FROM chapter_comment c
          JOIN course_chapter ch ON ch.id = c.course_chapter_id
          JOIN course_level l ON l.id = ch.course_level_id
          JOIN course co ON co.id = l.course_id
         WHERE c.id = p_target_id AND c.active = 1
           AND (co.created_by = p_user_id
                OR EXISTS (SELECT 1 FROM user_course_level a WHERE a.user_id = p_user_id AND a.course_level_id = l.id));
        IF v_author_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COMMENT_NOT_FOUND';
        END IF;
    ELSE
        SELECT o.commented_by INTO v_author_id
          FROM course_opinion o
          JOIN course co ON co.id = o.course_id
          JOIN app_user owner ON owner.id = co.created_by
         WHERE o.id = p_target_id AND o.active = 1 AND co.active = 1 AND co.published_at IS NOT NULL
           AND owner.account_status = 'active' AND owner.ban = 0;
        IF v_author_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OPINION_NOT_FOUND';
        END IF;
    END IF;

    IF v_author_id = p_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CANNOT_REPORT_OWN';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM content_report
         WHERE reported_by = p_user_id
           AND ((p_target_type = 'comment' AND comment_id = p_target_id)
                OR (p_target_type = 'opinion' AND opinion_id = p_target_id))
    ) THEN
        BEGIN
            -- Otra petición del mismo usuario pudo crearlo entre el SELECT y el INSERT.
            DECLARE CONTINUE HANDLER FOR 1062 SET v_created = FALSE;

            SET v_created = TRUE;
            INSERT INTO content_report (target_type, comment_id, opinion_id, reported_by, reason_code, details)
            VALUES (p_target_type,
                    IF(p_target_type = 'comment', p_target_id, NULL),
                    IF(p_target_type = 'opinion', p_target_id, NULL),
                    p_user_id, p_reason_code, v_details);
        END;
    END IF;

    SELECT p_target_type AS target_type, p_target_id AS target_id, v_created AS created;
END$$

DELIMITER ;
