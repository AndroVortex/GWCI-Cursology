-- sp_course_access: lo que un usuario ya tiene de un curso, para la página pública y el pago.
-- Resultados: 1) enrolled (0/1), owns_course (compró o se inscribió al curso completo, 0/1)
--             2) course_level_id de cada nivel al que tiene acceso.

DROP PROCEDURE IF EXISTS sp_course_access;

DELIMITER $$

CREATE PROCEDURE sp_course_access(
    IN p_user_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED
)
BEGIN
    SELECT
        EXISTS (SELECT 1 FROM user_courses WHERE user_id = p_user_id AND course_id = p_course_id) AS enrolled,
        EXISTS (
            SELECT 1 FROM purchase
             WHERE user_id = p_user_id AND course_id = p_course_id
               AND purchase_scope = 'course' AND status = 'completed'
        ) AS owns_course;

    SELECT course_level_id
      FROM user_course_level
     WHERE user_id = p_user_id AND course_id = p_course_id;
END$$

DELIMITER ;
