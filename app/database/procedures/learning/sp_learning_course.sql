-- sp_learning_course: el curso tal como lo ve un alumno inscrito en el reproductor.
-- Funciona aunque el curso se haya dado de baja después de comprarlo.
-- Resultados (en este orden):
--   1) curso + inscripción (enrolled_at, last_accessed_at, completed_at) y owns_course
--   2) niveles por orden con has_access y completed_at del alumno
--   3) capítulos por nivel y orden con su avance (posición, último acceso, terminado), la
--      duración del video principal, el número de preguntas y la mejor calificación
--   4) contenido de los capítulos, SOLO de los niveles con acceso (video principal primero)
-- Errores: NOT_ENROLLED.

DROP PROCEDURE IF EXISTS sp_learning_course;

DELIMITER $$

CREATE PROCEDURE sp_learning_course(
    IN p_user_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED
)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM user_courses WHERE user_id = p_user_id AND course_id = p_course_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ENROLLED';
    END IF;

    SELECT
        c.id, c.title, c.cover_image, c.full_course_price, c.currency, c.active,
        CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS instructor_name,
        uc.enrolled_at, uc.last_accessed_at, uc.completed_at,
        EXISTS (SELECT 1 FROM purchase pu
                 WHERE pu.user_id = p_user_id AND pu.course_id = c.id
                   AND pu.purchase_scope = 'course' AND pu.status = 'completed') AS owns_course
    FROM course c
    JOIN app_user u ON u.id = c.created_by
    JOIN user_courses uc ON uc.course_id = c.id AND uc.user_id = p_user_id
    WHERE c.id = p_course_id;

    SELECT l.id, l.title, l.description, l.sort_order, l.cost,
           ucl.user_id IS NOT NULL AS has_access, ucl.completed_at
      FROM course_level l
      LEFT JOIN user_course_level ucl ON ucl.course_level_id = l.id AND ucl.user_id = p_user_id
     WHERE l.course_id = p_course_id
     ORDER BY l.sort_order;

    SELECT
        ch.id, ch.course_level_id, ch.title, ch.sort_order, ch.is_test, ch.minimum_passing_grade,
        (SELECT ct.duration_seconds FROM course_chapter_content ct
          WHERE ct.course_chapter_id = ch.id AND ct.is_main = 1) AS duration_seconds,
        (SELECT COUNT(*) FROM course_chapter_test_question q WHERE q.course_chapter_id = ch.id) AS question_count,
        p.video_position_seconds, p.last_accessed_at, p.completed_at,
        (SELECT MAX(a.grade) FROM test_attempt a
          WHERE a.user_id = p_user_id AND a.course_chapter_id = ch.id AND a.submitted_at IS NOT NULL) AS best_grade
    FROM course_chapter ch
    JOIN course_level l ON l.id = ch.course_level_id
    LEFT JOIN user_chapter_progress p ON p.course_chapter_id = ch.id AND p.user_id = p_user_id
    WHERE l.course_id = p_course_id
    ORDER BY l.sort_order, ch.sort_order;

    SELECT ct.id, ct.course_chapter_id, ct.title, ct.content_type, ct.content_uri, ct.text_content,
           ct.mime_type, ct.original_filename, ct.size_bytes, ct.duration_seconds, ct.is_main,
           ct.main_slot, ct.created_at
      FROM course_chapter_content ct
      JOIN course_chapter ch ON ch.id = ct.course_chapter_id
      JOIN user_course_level ucl ON ucl.course_level_id = ch.course_level_id AND ucl.user_id = p_user_id
     WHERE ucl.course_id = p_course_id
     ORDER BY ct.is_main DESC, ct.id;
END$$

DELIMITER ;
