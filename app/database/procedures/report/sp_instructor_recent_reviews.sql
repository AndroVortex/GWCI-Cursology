-- sp_instructor_recent_reviews: las últimas opiniones de los cursos del instructor, incluidas las
-- ocultas (active = 0, con su motivo), para que las revise o modere desde la página del curso.
-- Resultado: id, course_id, course_title, opinion, commented_at, updated_at, active, reason, rate,
-- author_name, author_avatar. Errores: NOT_INSTRUCTOR.

DROP PROCEDURE IF EXISTS sp_instructor_recent_reviews;

DELIMITER $$

CREATE PROCEDURE sp_instructor_recent_reviews(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_limit INT UNSIGNED
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_instructor_id AND role = 'instructor' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_INSTRUCTOR';
    END IF;

    SELECT o.id, o.course_id, c.title AS course_title, o.opinion, o.commented_at, o.updated_at,
           o.active, o.reason, r.rate,
           CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS author_name,
           u.avatar AS author_avatar
      FROM course_opinion o
      JOIN course c ON c.id = o.course_id
      JOIN app_user u ON u.id = o.commented_by
      LEFT JOIN course_rate r ON r.rated_by = o.commented_by AND r.course_id = o.course_id
     WHERE c.created_by = p_instructor_id
     ORDER BY o.commented_at DESC, o.id DESC
     LIMIT p_limit;
END$$

DELIMITER ;
