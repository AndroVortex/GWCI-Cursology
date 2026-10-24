-- sp_certificate_mark_issued: registra el PDF ya guardado (referencia "cloudinary:...") y marca el
-- certificado como emitido. Si ya estaba emitido no cambia nada (un reintento no mueve issued_at).
-- Devuelve la fila de certificate. Errores: CERTIFICATE_NOT_FOUND (no existe o no es del alumno).

DROP PROCEDURE IF EXISTS sp_certificate_mark_issued;

DELIMITER $$

CREATE PROCEDURE sp_certificate_mark_issued(
    IN p_user_id BIGINT UNSIGNED,
    IN p_certificate_id BIGINT UNSIGNED,
    IN p_document_uri VARCHAR(2048)
)
BEGIN
    DECLARE v_status VARCHAR(12);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT status INTO v_status
      FROM certificate
     WHERE id = p_certificate_id AND user_id = p_user_id
       FOR UPDATE;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CERTIFICATE_NOT_FOUND';
    END IF;

    IF v_status <> 'issued' THEN
        UPDATE certificate
           SET status = 'issued', document_uri = p_document_uri, issued_at = NOW(6)
         WHERE id = p_certificate_id;
    END IF;

    COMMIT;

    SELECT id, user_id, course_id, issued_by, verification_code, student_name_snapshot, course_title_snapshot,
           certifier_name_snapshot, completed_at_snapshot, template_snapshot, status, document_uri, created_at, issued_at
      FROM certificate
     WHERE id = p_certificate_id;
END$$

DELIMITER ;
