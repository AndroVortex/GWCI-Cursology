-- sp_learning_progress: guarda el avance del alumno en un capítulo y actualiza su último acceso.
-- - Video: guarda la posición (recortada a la duración del video, que se toma de la base) y lo
--   marca terminado al llegar al 90% (posición * 10 >= duración * 9) o con p_mark_done = 1.
-- - Examen: solo registra la visita; se termina al aprobar un intento (sp_attempt_submit).
-- Al terminar un capítulo recalcula el nivel y el curso (sp_learning_recalculate).
-- Devuelve: course_chapter_id, video_position_seconds, completed_at, level_completed_at,
-- course_completed_at.
-- Errores: CHAPTER_NOT_FOUND, NO_LEVEL_ACCESS, EXAM_NOT_MARKABLE.

DROP PROCEDURE IF EXISTS sp_learning_progress;

DELIMITER $$

CREATE PROCEDURE sp_learning_progress(
    IN p_user_id BIGINT UNSIGNED,
    IN p_chapter_id BIGINT UNSIGNED,
    IN p_position_seconds INT UNSIGNED,
    IN p_mark_done BOOLEAN
)
BEGIN
    DECLARE v_level_id BIGINT UNSIGNED;
    DECLARE v_course_id BIGINT UNSIGNED;
    DECLARE v_is_test BOOLEAN;
    DECLARE v_duration INT UNSIGNED;
    DECLARE v_position INT UNSIGNED;
    DECLARE v_done BOOLEAN DEFAULT FALSE;
    DECLARE v_was_done BOOLEAN DEFAULT FALSE;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT ch.course_level_id, l.course_id, ch.is_test,
           (SELECT ct.duration_seconds FROM course_chapter_content ct
             WHERE ct.course_chapter_id = ch.id AND ct.is_main = 1)
      INTO v_level_id, v_course_id, v_is_test, v_duration
      FROM course_chapter ch
      JOIN course_level l ON l.id = ch.course_level_id
     WHERE ch.id = p_chapter_id;

    IF v_level_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_FOUND';
    END IF;

    START TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM user_course_level
                    WHERE user_id = p_user_id AND course_level_id = v_level_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NO_LEVEL_ACCESS';
    END IF;
    IF v_is_test AND p_mark_done THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EXAM_NOT_MARKABLE';
    END IF;

    SET v_position = IF(v_is_test, 0, LEAST(p_position_seconds, COALESCE(v_duration, p_position_seconds)));
    SET v_done = NOT v_is_test AND (p_mark_done OR (v_duration IS NOT NULL AND v_position * 10 >= v_duration * 9));

    SELECT completed_at IS NOT NULL INTO v_was_done
      FROM user_chapter_progress
     WHERE user_id = p_user_id AND course_chapter_id = p_chapter_id;

    INSERT INTO user_chapter_progress (user_id, course_chapter_id, course_level_id, video_position_seconds, completed_at)
    VALUES (p_user_id, p_chapter_id, v_level_id, v_position, IF(v_done, NOW(6), NULL)) AS incoming
    ON DUPLICATE KEY UPDATE
        video_position_seconds = incoming.video_position_seconds,
        last_accessed_at = NOW(6),
        completed_at = COALESCE(user_chapter_progress.completed_at, incoming.completed_at);

    UPDATE user_course_level SET last_accessed_at = NOW(6)
     WHERE user_id = p_user_id AND course_level_id = v_level_id;
    UPDATE user_courses SET last_accessed_at = NOW(6)
     WHERE user_id = p_user_id AND course_id = v_course_id;

    IF v_done AND NOT COALESCE(v_was_done, FALSE) THEN
        CALL sp_learning_recalculate(p_user_id, v_level_id);
    END IF;

    COMMIT;

    SELECT p.course_chapter_id, p.video_position_seconds, p.completed_at,
           ucl.completed_at AS level_completed_at, uc.completed_at AS course_completed_at
      FROM user_chapter_progress p
      JOIN user_course_level ucl ON ucl.user_id = p.user_id AND ucl.course_level_id = p.course_level_id
      JOIN user_courses uc ON uc.user_id = p.user_id AND uc.course_id = v_course_id
     WHERE p.user_id = p_user_id AND p.course_chapter_id = p_chapter_id;
END$$

DELIMITER ;
