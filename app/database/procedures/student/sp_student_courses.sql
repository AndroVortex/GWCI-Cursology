-- sp_student_courses: el kardex del alumno, del curso visto más recientemente al más antiguo.
-- Filtros (NULL = sin filtro):
--   p_from / p_to   fecha de inscripción (días completos, inclusive)
--   p_category_id   cursos con esa categoría asignada
--   p_status        'in_progress' (sin terminar), 'completed' (terminados) o 'active'
--                   (cursos que siguen a la venta)
-- Por curso: datos del curso y sus categorías, inscripción, último acceso, terminado, el
-- avance (capítulos terminados / capítulos del curso), los niveles con acceso y el último
-- capítulo visto.
-- Errores: INVALID_KARDEX_STATUS.

DROP PROCEDURE IF EXISTS sp_student_courses;

DELIMITER $$

CREATE PROCEDURE sp_student_courses(
    IN p_user_id BIGINT UNSIGNED,
    IN p_from DATE,
    IN p_to DATE,
    IN p_category_id BIGINT UNSIGNED,
    IN p_status VARCHAR(12)
)
BEGIN
    IF p_status IS NOT NULL AND p_status COLLATE utf8mb4_bin NOT IN ('in_progress', 'completed', 'active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_KARDEX_STATUS';
    END IF;

    SELECT
        c.id AS course_id, c.title, c.cover_image, c.active,
        (SELECT GROUP_CONCAT(cat.name ORDER BY cat.name SEPARATOR '|')
           FROM course_category cc
           JOIN category cat ON cat.id = cc.category_id
          WHERE cc.course_id = c.id AND cc.active = 1) AS category_names,
        uc.enrolled_at, uc.last_accessed_at, uc.completed_at,
        (SELECT COUNT(*) FROM course_chapter ch JOIN course_level l ON l.id = ch.course_level_id
          WHERE l.course_id = c.id) AS chapter_count,
        (SELECT COUNT(*) FROM user_chapter_progress p JOIN course_level l ON l.id = p.course_level_id
          WHERE p.user_id = p_user_id AND l.course_id = c.id AND p.completed_at IS NOT NULL) AS chapters_done,
        (SELECT COUNT(*) FROM course_level l WHERE l.course_id = c.id) AS level_count,
        (SELECT COUNT(*) FROM user_course_level ucl WHERE ucl.user_id = p_user_id AND ucl.course_id = c.id) AS levels_owned,
        last_seen.course_chapter_id AS last_chapter_id,
        last_seen.title AS last_chapter_title
    FROM user_courses uc
    JOIN course c ON c.id = uc.course_id
    LEFT JOIN LATERAL (
        SELECT p.course_chapter_id, ch.title
          FROM user_chapter_progress p
          JOIN course_level l ON l.id = p.course_level_id
          JOIN course_chapter ch ON ch.id = p.course_chapter_id
         WHERE p.user_id = p_user_id AND l.course_id = c.id
         ORDER BY p.last_accessed_at DESC
         LIMIT 1
    ) AS last_seen ON TRUE
    WHERE uc.user_id = p_user_id
      AND (p_from IS NULL OR uc.enrolled_at >= p_from)
      AND (p_to IS NULL OR uc.enrolled_at < p_to + INTERVAL 1 DAY)
      AND (p_category_id IS NULL OR EXISTS (
            SELECT 1 FROM course_category cc
             WHERE cc.course_id = c.id AND cc.category_id = p_category_id AND cc.active = 1))
      AND (p_status IS NULL
           OR (p_status = 'in_progress' AND uc.completed_at IS NULL)
           OR (p_status = 'completed' AND uc.completed_at IS NOT NULL)
           OR (p_status = 'active' AND c.active = 1))
    ORDER BY COALESCE(uc.last_accessed_at, uc.enrolled_at) DESC, c.id DESC;
END$$

DELIMITER ;
