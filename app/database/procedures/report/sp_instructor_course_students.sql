-- sp_instructor_course_students: el detalle de ventas de un curso del instructor.
-- Resultados (en este orden):
--   1) el curso: id, title, cover_image, active, published_at, currency, chapter_count,
--      student_count, avg_progress y revenue (mismas reglas que sp_instructor_sales)
--   2) sus alumnos, del inscrito más reciente al primero: user_id, full_name, email, avatar,
--      enrolled_at, completed_at, progress (capítulos terminados / capítulos * 100, 1 decimal),
--      paid (suma de sus compras completadas del curso) y payment_methods (formas de pago de
--      sus compras de más de $0, separadas por '|'; NULL si todo fue gratis)
-- Errores: COURSE_NOT_FOUND (no existe o no es del instructor).

DROP PROCEDURE IF EXISTS sp_instructor_course_students;

DELIMITER $$

CREATE PROCEDURE sp_instructor_course_students(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_chapter_count INT UNSIGNED DEFAULT 0;

    IF NOT EXISTS (SELECT 1 FROM course WHERE id = p_course_id AND created_by = p_instructor_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
    END IF;

    SELECT COUNT(*) INTO v_chapter_count
      FROM course_chapter ch
      JOIN course_level l ON l.id = ch.course_level_id
     WHERE l.course_id = p_course_id;

    SELECT
        c.id, c.title, c.cover_image, c.active, c.published_at, c.currency,
        v_chapter_count AS chapter_count,
        (SELECT COUNT(*) FROM user_courses uc WHERE uc.course_id = c.id) AS student_count,
        (SELECT CAST(COALESCE(ROUND(AVG(LEAST(100, 100 * (
                    SELECT COUNT(*)
                      FROM user_chapter_progress p
                      JOIN course_level pl ON pl.id = p.course_level_id
                     WHERE p.user_id = uc.user_id AND pl.course_id = c.id AND p.completed_at IS NOT NULL
                ) / NULLIF(v_chapter_count, 0))), 1), 0) AS DECIMAL(5,1))
           FROM user_courses uc
          WHERE uc.course_id = c.id) AS avg_progress,
        (SELECT CAST(COALESCE(SUM(pu.amount), 0) AS DECIMAL(14,2))
           FROM purchase pu
          WHERE pu.course_id = c.id AND pu.status = 'completed') AS revenue
    FROM course c
    WHERE c.id = p_course_id;

    SELECT
        u.id AS user_id,
        CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS full_name,
        u.email, u.avatar, uc.enrolled_at, uc.completed_at,
        CAST(COALESCE(ROUND(LEAST(100, 100 * (
            SELECT COUNT(*)
              FROM user_chapter_progress p
              JOIN course_level pl ON pl.id = p.course_level_id
             WHERE p.user_id = uc.user_id AND pl.course_id = uc.course_id AND p.completed_at IS NOT NULL
        ) / NULLIF(v_chapter_count, 0)), 1), 0) AS DECIMAL(5,1)) AS progress,
        (SELECT CAST(COALESCE(SUM(pu.amount), 0) AS DECIMAL(14,2))
           FROM purchase pu
          WHERE pu.user_id = uc.user_id AND pu.course_id = uc.course_id AND pu.status = 'completed') AS paid,
        (SELECT GROUP_CONCAT(DISTINCT pu.payment_method_name_snapshot ORDER BY pu.payment_method_name_snapshot SEPARATOR '|')
           FROM purchase pu
          WHERE pu.user_id = uc.user_id AND pu.course_id = uc.course_id AND pu.status = 'completed' AND pu.amount > 0) AS payment_methods
    FROM user_courses uc
    JOIN app_user u ON u.id = uc.user_id
    WHERE uc.course_id = p_course_id
    ORDER BY uc.enrolled_at DESC, u.id DESC;
END$$

DELIMITER ;
