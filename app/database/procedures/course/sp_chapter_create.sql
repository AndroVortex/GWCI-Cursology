-- sp_chapter_create: agrega un capítulo al final del nivel (sort_order = máximo + 1).
-- p_is_test = 0: capítulo de video (p_minimum_passing_grade se ignora).
-- p_is_test = 1: examen, con la calificación mínima para aprobar (más de 0 y hasta 100).
-- El tipo no cambia después.
-- Resultado: la fila de course_chapter. Errores: LEVEL_NOT_FOUND, INVALID_PASSING_GRADE.
-- Transacción propia: bloquea el nivel para numerar sin colisiones.

DROP PROCEDURE IF EXISTS sp_chapter_create;

DELIMITER $$

CREATE PROCEDURE sp_chapter_create(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_level_id BIGINT UNSIGNED,
    IN p_title VARCHAR(200),
    IN p_is_test BOOLEAN,
    IN p_minimum_passing_grade DECIMAL(5,2)
)
BEGIN
    DECLARE v_owner BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_next INT UNSIGNED;
    DECLARE v_chapter_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_is_test AND (p_minimum_passing_grade IS NULL OR p_minimum_passing_grade <= 0 OR p_minimum_passing_grade > 100) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_PASSING_GRADE';
    END IF;

    START TRANSACTION;

    SELECT c.created_by INTO v_owner
      FROM course_level l
      JOIN course c ON c.id = l.course_id
     WHERE l.id = p_level_id
       FOR UPDATE;

    IF v_owner IS NULL OR v_owner <> p_instructor_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LEVEL_NOT_FOUND';
    END IF;

    SELECT COALESCE(MAX(sort_order), 0) + 1 INTO v_next FROM course_chapter WHERE course_level_id = p_level_id;

    INSERT INTO course_chapter (course_level_id, title, sort_order, is_test, minimum_passing_grade)
    VALUES (p_level_id, p_title, v_next, p_is_test, IF(p_is_test, p_minimum_passing_grade, NULL));

    SET v_chapter_id = LAST_INSERT_ID();

    COMMIT;

    SELECT id, course_level_id, title, sort_order, is_test, minimum_passing_grade, created_at, updated_at
      FROM course_chapter
     WHERE id = v_chapter_id;
END$$

DELIMITER ;
