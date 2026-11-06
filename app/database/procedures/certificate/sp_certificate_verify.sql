-- sp_certificate_verify: verificación pública por código. Solo encuentra certificados emitidos.
-- Devuelve la fila de certificate (PHP solo publica los datos congelados y las fechas).
-- Errores: CERTIFICATE_NOT_FOUND.

DROP PROCEDURE IF EXISTS sp_certificate_verify;

DELIMITER $$

CREATE PROCEDURE sp_certificate_verify(
    IN p_code CHAR(36) CHARACTER SET ascii
)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM certificate WHERE verification_code = p_code AND status = 'issued') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CERTIFICATE_NOT_FOUND';
    END IF;

    SELECT id, user_id, course_id, issued_by, verification_code, student_name_snapshot, course_title_snapshot,
           certifier_name_snapshot, completed_at_snapshot, template_snapshot, status, document_uri, created_at, issued_at
      FROM certificate
     WHERE verification_code = p_code AND status = 'issued';
END$$

DELIMITER ;
