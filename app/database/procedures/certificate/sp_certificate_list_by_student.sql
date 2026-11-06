-- sp_certificate_list_by_student: los certificados del alumno (emitidos o pendientes), del curso
-- terminado más recientemente al más antiguo. Devuelve filas de certificate.

DROP PROCEDURE IF EXISTS sp_certificate_list_by_student;

DELIMITER $$

CREATE PROCEDURE sp_certificate_list_by_student(
    IN p_user_id BIGINT UNSIGNED
)
BEGIN
    SELECT id, user_id, course_id, issued_by, verification_code, student_name_snapshot, course_title_snapshot,
           certifier_name_snapshot, completed_at_snapshot, template_snapshot, status, document_uri, created_at, issued_at
      FROM certificate
     WHERE user_id = p_user_id
     ORDER BY completed_at_snapshot DESC, id DESC;
END$$

DELIMITER ;
