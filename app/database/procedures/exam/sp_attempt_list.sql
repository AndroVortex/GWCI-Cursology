-- sp_attempt_list: los intentos del alumno en un examen, del más reciente al primero.
-- Errores: CHAPTER_NOT_FOUND, CHAPTER_NOT_EXAM, NO_LEVEL_ACCESS.

DROP PROCEDURE IF EXISTS sp_attempt_list;

DELIMITER $$

CREATE PROCEDURE sp_attempt_list(
    IN p_user_id BIGINT UNSIGNED,
    IN p_chapter_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_level_id BIGINT UNSIGNED;
    DECLARE v_is_test BOOLEAN;

    SELECT course_level_id, is_test INTO v_level_id, v_is_test
      FROM course_chapter
     WHERE id = p_chapter_id;

    IF v_level_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_FOUND';
    END IF;
    IF NOT v_is_test THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_EXAM';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM user_course_level WHERE user_id = p_user_id AND course_level_id = v_level_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NO_LEVEL_ACCESS';
    END IF;

    SELECT id, attempt_number, started_at, submitted_at, minimum_grade_snapshot, max_points,
           earned_points, grade, passed
      FROM test_attempt
     WHERE user_id = p_user_id AND course_chapter_id = p_chapter_id
     ORDER BY attempt_number DESC;
END$$

DELIMITER ;
