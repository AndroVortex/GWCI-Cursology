-- sp_purchase_grant: USO INTERNO (lo llaman sp_enrollment_free y sp_purchase_complete dentro
-- de su transacción; no abre una propia). Da el acceso de una compra completada:
--   - inscribe al alumno en el curso (user_courses) si aún no lo estaba;
--   - purchase_scope = 'course': todos los niveles actuales del curso (los futuros los da
--     sp_level_create al crearlos);
--   - purchase_scope = 'level': ese nivel.
-- Los niveles que el alumno ya tenía conservan la compra con la que los obtuvo.

DROP PROCEDURE IF EXISTS sp_purchase_grant;

DELIMITER $$

CREATE PROCEDURE sp_purchase_grant(
    IN p_purchase_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_user_id BIGINT UNSIGNED;
    DECLARE v_course_id BIGINT UNSIGNED;
    DECLARE v_level_id BIGINT UNSIGNED;
    DECLARE v_scope VARCHAR(10);

    SELECT user_id, course_id, course_level_id, purchase_scope
      INTO v_user_id, v_course_id, v_level_id, v_scope
      FROM purchase
     WHERE id = p_purchase_id AND status = 'completed';

    IF v_user_id IS NOT NULL THEN
        INSERT INTO user_courses (user_id, course_id)
        SELECT v_user_id, v_course_id
          FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM user_courses WHERE user_id = v_user_id AND course_id = v_course_id);

        INSERT INTO user_course_level (user_id, course_level_id, course_id, purchase_id)
        SELECT v_user_id, l.id, v_course_id, p_purchase_id
          FROM course_level l
         WHERE l.course_id = v_course_id
           AND (v_scope = 'course' OR l.id = v_level_id)
           AND NOT EXISTS (
               SELECT 1 FROM user_course_level a WHERE a.user_id = v_user_id AND a.course_level_id = l.id
           );
    END IF;
END$$

DELIMITER ;
