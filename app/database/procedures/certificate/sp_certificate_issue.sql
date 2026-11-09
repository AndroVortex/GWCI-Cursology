-- sp_certificate_issue: el certificado del alumno en un curso terminado. Si no existe, lo crea
-- 'pending' con los datos congelados (nombre del alumno, título del curso, certificador y fecha
-- en que terminó); PHP genera el PDF y lo marca emitido con sp_certificate_mark_issued.
-- El certificador es course.certifier_name o, si está vacío, el nombre del instructor, que
-- también queda como issued_by. Dos peticiones a la vez no duplican nada: uq_certificate_enrollment
-- hace que la segunda inserción no cambie la fila (ON DUPLICATE KEY UPDATE sin cambios).
-- Devuelve la fila de certificate. Errores: NOT_ENROLLED, COURSE_NOT_COMPLETED.

DROP PROCEDURE IF EXISTS sp_certificate_issue;

DELIMITER $$

CREATE PROCEDURE sp_certificate_issue(
    IN p_user_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_enrolled BOOLEAN DEFAULT FALSE;
    DECLARE v_completed_at DATETIME(6);

    SELECT TRUE, completed_at INTO v_enrolled, v_completed_at
      FROM user_courses
     WHERE user_id = p_user_id AND course_id = p_course_id;

    IF NOT v_enrolled THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_ENROLLED';
    END IF;
    IF v_completed_at IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_COMPLETED';
    END IF;

    INSERT INTO certificate (user_id, course_id, issued_by, verification_code, student_name_snapshot,
                             course_title_snapshot, certifier_name_snapshot, completed_at_snapshot, template_snapshot)
    SELECT p_user_id, c.id, c.created_by, UUID(),
           CONCAT_WS(' ', s.name, s.father_last_name, s.mother_last_name),
           c.title,
           COALESCE(NULLIF(TRIM(c.certifier_name), ''), CONCAT_WS(' ', i.name, i.father_last_name, i.mother_last_name)),
           v_completed_at, 'cursology-v1'
      FROM course c
      JOIN app_user i ON i.id = c.created_by
      JOIN app_user s ON s.id = p_user_id
     WHERE c.id = p_course_id
    ON DUPLICATE KEY UPDATE certificate.id = certificate.id;

    SELECT id, user_id, course_id, issued_by, verification_code, student_name_snapshot, course_title_snapshot,
           certifier_name_snapshot, completed_at_snapshot, template_snapshot, status, document_uri, created_at, issued_at
      FROM certificate
     WHERE user_id = p_user_id AND course_id = p_course_id;
END$$

DELIMITER ;
