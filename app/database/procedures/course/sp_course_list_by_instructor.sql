-- sp_course_list_by_instructor: cursos del instructor (borradores y publicados) con
-- conteos para el panel. Resultado: una fila por curso, que PHP lee con
-- App\Models\Dto\InstructorCourseSummary.

DROP PROCEDURE IF EXISTS sp_course_list_by_instructor;

DELIMITER $$

CREATE PROCEDURE sp_course_list_by_instructor(
    IN p_instructor_id BIGINT UNSIGNED
)
BEGIN
    SELECT
        c.id,
        c.title,
        c.cover_image,
        c.full_course_price,
        c.currency,
        c.active,
        c.published_at,
        c.created_at,
        c.updated_at,
        (SELECT COUNT(*) FROM course_level l WHERE l.course_id = c.id) AS level_count,
        (SELECT COUNT(*)
           FROM course_chapter ch
           JOIN course_level l ON l.id = ch.course_level_id
          WHERE l.course_id = c.id) AS chapter_count,
        (SELECT COUNT(*) FROM user_courses uc WHERE uc.course_id = c.id) AS student_count
    FROM course c
    WHERE c.created_by = p_instructor_id
    ORDER BY c.updated_at DESC, c.id DESC;
END$$

DELIMITER ;
