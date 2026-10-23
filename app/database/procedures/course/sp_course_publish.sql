-- sp_course_publish: publica un curso del instructor. Es la última palabra sobre si se
-- puede publicar (la lista del editor solo ayuda a completarlo). Requisitos, en orden:
--   activo, aún no publicado, una categoría activa, al menos un nivel, cada nivel con al
--   menos un capítulo y cada capítulo de video con su video principal.
-- Los precios ya son coherentes con la forma de cobro: los aseguran sp_course_update y los
-- SP de nivel (curso gratis = niveles en 0) y la validación de PHP (paquete > 0).
-- Resultado: la fila de course. Errores: COURSE_NOT_FOUND, COURSE_INACTIVE,
-- COURSE_ALREADY_PUBLISHED, NO_ACTIVE_CATEGORY, NO_LEVELS, LEVEL_WITHOUT_CHAPTERS,
-- CHAPTER_WITHOUT_VIDEO.
-- Transacción propia: bloquea el curso.

DROP PROCEDURE IF EXISTS sp_course_publish;

DELIMITER $$

CREATE PROCEDURE sp_course_publish(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_owner BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_active BOOLEAN;
    DECLARE v_published_at DATETIME(6);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT created_by, active, published_at
      INTO v_owner, v_active, v_published_at
      FROM course
     WHERE id = p_course_id
       FOR UPDATE;

    IF v_owner IS NULL OR v_owner <> p_instructor_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
    END IF;
    IF v_active = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_INACTIVE';
    END IF;
    IF v_published_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_ALREADY_PUBLISHED';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM course_category cc
          JOIN category cat ON cat.id = cc.category_id
         WHERE cc.course_id = p_course_id AND cc.active = 1 AND cat.active = 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NO_ACTIVE_CATEGORY';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM course_level WHERE course_id = p_course_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NO_LEVELS';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM course_level l
         WHERE l.course_id = p_course_id
           AND NOT EXISTS (SELECT 1 FROM course_chapter ch WHERE ch.course_level_id = l.id)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LEVEL_WITHOUT_CHAPTERS';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM course_chapter ch
          JOIN course_level l ON l.id = ch.course_level_id
         WHERE l.course_id = p_course_id
           AND ch.is_test = 0
           AND NOT EXISTS (
               SELECT 1 FROM course_chapter_content ct
                WHERE ct.course_chapter_id = ch.id AND ct.is_main = 1
           )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHAPTER_WITHOUT_VIDEO';
    END IF;

    UPDATE course
       SET published_at = CURRENT_TIMESTAMP(6),
           updated_by = p_instructor_id
     WHERE id = p_course_id;

    COMMIT;

    SELECT
        id, title, description, cover_image, onboarding_video, certificate_template, certifier_name,
        full_course_price, currency, active, created_by, created_at, updated_by, updated_at,
        published_at, deactivated_at
    FROM course
    WHERE id = p_course_id;
END$$

DELIMITER ;
