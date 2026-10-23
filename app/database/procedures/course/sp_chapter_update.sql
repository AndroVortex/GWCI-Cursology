-- sp_chapter_update: cambia el título de un capítulo y, si es examen, su calificación
-- mínima para aprobar (p_minimum_passing_grade NULL = conservarla). En un capítulo de
-- video p_minimum_passing_grade se ignora. Los intentos ya hechos conservan el mínimo
-- con el que se presentaron (test_attempt.minimum_grade_snapshot).
-- Resultado: la fila de course_chapter. Errores: CHAPTER_NOT_FOUND, INVALID_PASSING_GRADE.

DROP PROCEDURE IF EXISTS sp_chapter_update;

DELIMITER $$

CREATE PROCEDURE sp_chapter_update(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_chapter_id BIGINT UNSIGNED,
    IN p_title VARCHAR(200),
    IN p_minimum_passing_grade DECIMAL(5,2)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM course_chapter ch
          JOIN course_level l ON l.id = ch.course_level_id
          JOIN course c ON c.id = l.course_id
         WHERE ch.id = p_chapter_id AND c.created_by = p_instructor_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_NOT_FOUND';
    END IF;

    IF p_minimum_passing_grade IS NOT NULL AND (p_minimum_passing_grade <= 0 OR p_minimum_passing_grade > 100) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_PASSING_GRADE';
    END IF;

    UPDATE course_chapter
       SET title = p_title,
           minimum_passing_grade = IF(is_test, COALESCE(p_minimum_passing_grade, minimum_passing_grade), NULL)
     WHERE id = p_chapter_id;

    SELECT id, course_level_id, title, sort_order, is_test, minimum_passing_grade, created_at, updated_at
      FROM course_chapter
     WHERE id = p_chapter_id;
END$$

DELIMITER ;
