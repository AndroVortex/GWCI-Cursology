-- sp_learning_recalculate (interno): marca terminado el nivel cuando todos sus capítulos lo
-- están, y el curso cuando todos sus niveles están terminados (el alumno necesita acceso a
-- todos). Lo terminado no se desmarca si después se agregan capítulos o niveles.
-- Se llama dentro de la transacción de sp_learning_progress y sp_attempt_submit.

DROP PROCEDURE IF EXISTS sp_learning_recalculate;

DELIMITER $$

CREATE PROCEDURE sp_learning_recalculate(
    IN p_user_id BIGINT UNSIGNED,
    IN p_level_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_course_id BIGINT UNSIGNED;
    DECLARE v_total INT UNSIGNED DEFAULT 0;
    DECLARE v_done INT UNSIGNED DEFAULT 0;

    SELECT course_id INTO v_course_id FROM course_level WHERE id = p_level_id;

    SELECT COUNT(*), COALESCE(SUM(p.completed_at IS NOT NULL), 0) INTO v_total, v_done
      FROM course_chapter ch
      LEFT JOIN user_chapter_progress p ON p.course_chapter_id = ch.id AND p.user_id = p_user_id
     WHERE ch.course_level_id = p_level_id;

    IF v_total > 0 AND v_done = v_total THEN
        UPDATE user_course_level
           SET completed_at = NOW(6)
         WHERE user_id = p_user_id AND course_level_id = p_level_id AND completed_at IS NULL;
    END IF;

    SELECT COUNT(*), COALESCE(SUM(ucl.completed_at IS NOT NULL), 0) INTO v_total, v_done
      FROM course_level l
      LEFT JOIN user_course_level ucl ON ucl.course_level_id = l.id AND ucl.user_id = p_user_id
     WHERE l.course_id = v_course_id;

    IF v_total > 0 AND v_done = v_total THEN
        UPDATE user_courses
           SET completed_at = NOW(6)
         WHERE user_id = p_user_id AND course_id = v_course_id AND completed_at IS NULL;
    END IF;
END$$

DELIMITER ;
