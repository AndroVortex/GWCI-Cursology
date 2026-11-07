-- sp_chat_start: busca o crea la conversación de un curso entre su instructor y un alumno inscrito.
--   - El alumno la inicia con p_student_id NULL: debe estar inscrito al curso.
--   - El instructor dueño del curso la inicia pasando el alumno, que debe estar inscrito.
-- Funciona aunque el curso esté dado de baja: los inscritos conservan la conversación.
-- Devuelve la fila de chat y created (1 si se creó en esta llamada, 0 si ya existía).
-- Errores: COURSE_NOT_FOUND, NOT_ENROLLED (el alumno que la inicia no está inscrito),
-- STUDENT_NOT_ENROLLED (el alumno que eligió el instructor no está inscrito), NOT_ALLOWED
-- (quien llama no es el alumno ni el dueño del curso).

DROP PROCEDURE IF EXISTS sp_chat_start;

DELIMITER $$

CREATE PROCEDURE sp_chat_start(
    IN p_user_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED,
    IN p_student_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_owner_id BIGINT UNSIGNED;
    DECLARE v_student_id BIGINT UNSIGNED;
    DECLARE v_chat_id BIGINT UNSIGNED;
    DECLARE v_created BOOLEAN DEFAULT FALSE;

    SELECT created_by INTO v_owner_id FROM course WHERE id = p_course_id;

    IF v_owner_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
    END IF;

    IF p_student_id IS NULL THEN
        IF p_user_id = v_owner_id THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ALLOWED';
        END IF;
        SET v_student_id = p_user_id;
    ELSE
        IF p_user_id <> v_owner_id OR p_student_id = v_owner_id THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ALLOWED';
        END IF;
        SET v_student_id = p_student_id;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM user_courses WHERE user_id = v_student_id AND course_id = p_course_id) THEN
        IF p_student_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ENROLLED';
        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'STUDENT_NOT_ENROLLED';
        END IF;
    END IF;

    SELECT id INTO v_chat_id
      FROM chat
     WHERE course_id = p_course_id AND instructor_id = v_owner_id AND student_id = v_student_id;

    IF v_chat_id IS NULL THEN
        BEGIN
            -- Otra petición pudo crearla entre el SELECT y el INSERT: entonces ya existe.
            DECLARE CONTINUE HANDLER FOR 1062 SET v_created = FALSE;

            SET v_created = TRUE;
            INSERT INTO chat (course_id, instructor_id, student_id)
            VALUES (p_course_id, v_owner_id, v_student_id);
        END;

        SELECT id INTO v_chat_id
          FROM chat
         WHERE course_id = p_course_id AND instructor_id = v_owner_id AND student_id = v_student_id;
    END IF;

    SELECT id, course_id, instructor_id, student_id, created_at,
           student_last_read_at, instructor_last_read_at, v_created AS created
      FROM chat
     WHERE id = v_chat_id;
END$$

DELIMITER ;
