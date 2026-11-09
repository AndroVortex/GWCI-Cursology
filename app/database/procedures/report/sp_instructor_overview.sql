-- sp_instructor_overview: cifras del panel del instructor, de todos sus cursos.
--   revenue_total  suma de las compras completadas (las inscripciones de $0 no suman)
--   student_total  inscripciones a sus cursos
--   rating_avg     promedio de estrellas (1 decimal, NULL sin calificaciones) y rating_count;
--                  no cuentan las estrellas de quien tiene su opinión oculta (como el catálogo)
-- Errores: NOT_INSTRUCTOR.

DROP PROCEDURE IF EXISTS sp_instructor_overview;

DELIMITER $$

CREATE PROCEDURE sp_instructor_overview(
    IN p_instructor_id BIGINT UNSIGNED
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_instructor_id AND role = 'instructor' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_INSTRUCTOR';
    END IF;

    SELECT
        (SELECT CAST(COALESCE(SUM(pu.amount), 0) AS DECIMAL(14,2))
           FROM purchase pu
           JOIN course c ON c.id = pu.course_id
          WHERE c.created_by = p_instructor_id AND pu.status = 'completed') AS revenue_total,
        (SELECT COUNT(*)
           FROM user_courses uc
           JOIN course c ON c.id = uc.course_id
          WHERE c.created_by = p_instructor_id) AS student_total,
        (SELECT CAST(ROUND(AVG(r.rate), 1) AS DECIMAL(3,1))
           FROM course_rate r
           JOIN course c ON c.id = r.course_id
           LEFT JOIN course_opinion o ON o.commented_by = r.rated_by AND o.course_id = r.course_id
          WHERE c.created_by = p_instructor_id AND (o.id IS NULL OR o.active = 1)) AS rating_avg,
        (SELECT COUNT(*)
           FROM course_rate r
           JOIN course c ON c.id = r.course_id
           LEFT JOIN course_opinion o ON o.commented_by = r.rated_by AND o.course_id = r.course_id
          WHERE c.created_by = p_instructor_id AND (o.id IS NULL OR o.active = 1)) AS rating_count;
END$$

DELIMITER ;
