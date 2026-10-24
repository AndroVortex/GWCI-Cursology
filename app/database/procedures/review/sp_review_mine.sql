-- sp_review_mine: lo que el alumno ya calificó y opinó de un curso, aunque su opinión esté oculta.
-- Resultados (en este orden):
--   1) enrolled, completed (si puede calificar: solo con el curso terminado)
--   2) su fila de course_rate (o ninguna)
--   3) su fila de course_opinion, con el motivo si se ocultó (o ninguna)

DROP PROCEDURE IF EXISTS sp_review_mine;

DELIMITER $$

CREATE PROCEDURE sp_review_mine(
    IN p_user_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED
)
BEGIN
    SELECT
        EXISTS (SELECT 1 FROM user_courses WHERE user_id = p_user_id AND course_id = p_course_id) AS enrolled,
        EXISTS (SELECT 1 FROM user_courses WHERE user_id = p_user_id AND course_id = p_course_id AND completed_at IS NOT NULL) AS completed;

    SELECT id, rated_by, course_id, rate, rated_at, updated_at
      FROM course_rate
     WHERE rated_by = p_user_id AND course_id = p_course_id;

    SELECT id, course_id, commented_by, opinion, multimedia_content, commented_at, updated_at,
           active, deactivated_by, deactivated_at, reason
      FROM course_opinion
     WHERE commented_by = p_user_id AND course_id = p_course_id;
END$$

DELIMITER ;
