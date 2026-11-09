-- sp_maintenance_purge: borra de verdad las cuentas de prueba o de demostración y todo lo que
-- depende de ellas (sus cursos con su estructura, inscripciones, compras, progreso, exámenes,
-- certificados, opiniones, comentarios, reacciones, chats y reportes). También se borra lo que
-- otras cuentas tengan ligado a esos datos: su inscripción a un curso de prueba o su respuesta
-- a un comentario de prueba.
--   p_scope = 'test': cuentas ...@cursology.test (dominio reservado para pruebas), más las
--                     categorías y reacciones "Prueba <marca>" de las pruebas que nadie use.
--   p_scope = 'demo': cuentas ...@demo.cursology.local (scripts/seed_demo.php).
-- Los patrones están fijos aquí: el SP no puede borrar otras cuentas y nunca toca a un admin.
-- Con p_apply = 0 borra, cuenta y al final hace ROLLBACK: un ensayo con cifras exactas.
-- Resultado: una fila con las filas borradas por tabla, las cuentas que quedan por rol y applied.
-- Errores: INVALID_SCOPE. Transacción propia: si algo falla, no se borra nada.

DROP PROCEDURE IF EXISTS sp_maintenance_purge;

DELIMITER $$

CREATE PROCEDURE sp_maintenance_purge(
    IN p_scope VARCHAR(10),
    IN p_apply TINYINT
)
BEGIN
    DECLARE v_pattern VARCHAR(40) DEFAULT NULL;
    DECLARE v_marker VARCHAR(60) DEFAULT '^Prueba [0-9]{14}-[0-9a-f]{6}( |$)';
    DECLARE v_reports, v_reactions, v_messages, v_chats, v_comments, v_opinions, v_rates,
            v_certificates, v_attempts, v_progress, v_access, v_purchases, v_enrollments,
            v_questions, v_contents, v_chapters, v_levels, v_courses, v_login_attempts,
            v_account_events, v_users, v_categories, v_test_reactions INT DEFAULT 0;
    DECLARE v_students, v_instructors, v_admins INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET v_pattern = CASE p_scope
        WHEN 'test' THEN '%@cursology.test'
        WHEN 'demo' THEN '%@demo.cursology.local'
    END;
    IF v_pattern IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_SCOPE';
    END IF;

    START TRANSACTION;

    -- Reportes de las cuentas, sobre su contenido o sobre las respuestas a sus comentarios.
    DELETE r FROM content_report r
      LEFT JOIN chapter_comment cm ON cm.id = r.comment_id
      LEFT JOIN chapter_comment parent ON parent.id = cm.comment_parent_id
      LEFT JOIN course_opinion o ON o.id = r.opinion_id
     WHERE r.reported_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR r.resolved_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR cm.commented_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR parent.commented_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR cm.course_chapter_id IN (
            SELECT ch.id FROM course_chapter ch
              JOIN course_level l ON l.id = ch.course_level_id
              JOIN course c ON c.id = l.course_id
              JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern)
        OR o.commented_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR o.course_id IN (
            SELECT c.id FROM course c JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_reports = ROW_COUNT();

    -- Reacciones: las de las cuentas y las que recibió el contenido que se va a borrar.
    DELETE x FROM chapter_comment_reaction x
      JOIN chapter_comment cm ON cm.id = x.comment_id
      LEFT JOIN chapter_comment parent ON parent.id = cm.comment_parent_id
     WHERE x.reacted_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR cm.commented_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR parent.commented_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR cm.course_chapter_id IN (
            SELECT ch.id FROM course_chapter ch
              JOIN course_level l ON l.id = ch.course_level_id
              JOIN course c ON c.id = l.course_id
              JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_reactions = ROW_COUNT();

    DELETE x FROM opinion_reaction x
      JOIN course_opinion o ON o.id = x.opinion_id
     WHERE x.reacted_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR o.commented_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR o.course_id IN (
            SELECT c.id FROM course c JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_reactions = v_reactions + ROW_COUNT();

    DELETE x FROM message_reaction x
      JOIN message m ON m.id = x.message_id
      JOIN chat h ON h.id = m.chat_id
     WHERE x.reacted_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR h.student_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR h.instructor_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR h.course_id IN (
            SELECT c.id FROM course c JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_reactions = v_reactions + ROW_COUNT();

    -- Chats completos: fk_message_reply obliga a soltar las respuestas antes de borrar.
    UPDATE message m
      JOIN chat h ON h.id = m.chat_id
       SET m.reply_to = NULL
     WHERE m.reply_to IS NOT NULL
       AND (h.student_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR h.instructor_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR h.course_id IN (
            SELECT c.id FROM course c JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern));

    DELETE m FROM message m
      JOIN chat h ON h.id = m.chat_id
     WHERE h.student_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR h.instructor_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR h.course_id IN (
            SELECT c.id FROM course c JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_messages = ROW_COUNT();

    DELETE FROM chat
     WHERE student_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR instructor_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR course_id IN (
            SELECT c.id FROM course c JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_chats = ROW_COUNT();

    -- Comentarios: las respuestas siempre cuelgan del comentario raíz (sp_comment_create), así
    -- que primero van todas las respuestas a comentarios que se borran y luego el resto.
    DELETE reply FROM chapter_comment reply
      JOIN chapter_comment parent ON parent.id = reply.comment_parent_id
     WHERE parent.commented_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR parent.course_chapter_id IN (
            SELECT ch.id FROM course_chapter ch
              JOIN course_level l ON l.id = ch.course_level_id
              JOIN course c ON c.id = l.course_id
              JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_comments = ROW_COUNT();

    DELETE FROM chapter_comment
     WHERE commented_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR course_chapter_id IN (
            SELECT ch.id FROM course_chapter ch
              JOIN course_level l ON l.id = ch.course_level_id
              JOIN course c ON c.id = l.course_id
              JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_comments = v_comments + ROW_COUNT();

    DELETE FROM course_opinion
     WHERE commented_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR course_id IN (
            SELECT c.id FROM course c JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_opinions = ROW_COUNT();

    DELETE FROM course_rate
     WHERE rated_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR course_id IN (
            SELECT c.id FROM course c JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_rates = ROW_COUNT();

    DELETE FROM certificate
     WHERE user_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR issued_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR course_id IN (
            SELECT c.id FROM course c JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_certificates = ROW_COUNT();

    -- Intentos de examen: respuestas, preguntas copiadas y el intento.
    DELETE a FROM test_attempt_answer a
      JOIN test_attempt t ON t.id = a.attempt_id
     WHERE t.user_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR t.course_chapter_id IN (
            SELECT ch.id FROM course_chapter ch
              JOIN course_level l ON l.id = ch.course_level_id
              JOIN course c ON c.id = l.course_id
              JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);

    DELETE q FROM test_attempt_question q
      JOIN test_attempt t ON t.id = q.attempt_id
     WHERE t.user_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR t.course_chapter_id IN (
            SELECT ch.id FROM course_chapter ch
              JOIN course_level l ON l.id = ch.course_level_id
              JOIN course c ON c.id = l.course_id
              JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);

    DELETE FROM test_attempt
     WHERE user_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR course_chapter_id IN (
            SELECT ch.id FROM course_chapter ch
              JOIN course_level l ON l.id = ch.course_level_id
              JOIN course c ON c.id = l.course_id
              JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_attempts = ROW_COUNT();

    DELETE FROM user_chapter_progress
     WHERE user_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR course_chapter_id IN (
            SELECT ch.id FROM course_chapter ch
              JOIN course_level l ON l.id = ch.course_level_id
              JOIN course c ON c.id = l.course_id
              JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_progress = ROW_COUNT();

    DELETE FROM user_course_level
     WHERE user_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR course_id IN (
            SELECT c.id FROM course c JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_access = ROW_COUNT();

    DELETE FROM purchase
     WHERE user_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR course_id IN (
            SELECT c.id FROM course c JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_purchases = ROW_COUNT();

    DELETE FROM user_courses
     WHERE user_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR course_id IN (
            SELECT c.id FROM course c JOIN app_user u ON u.id = c.created_by
             WHERE u.role <> 'admin' AND u.email LIKE v_pattern);
    SET v_enrollments = ROW_COUNT();

    -- Estructura de los cursos de las cuentas, de las hojas a la raíz.
    DELETE a FROM test_question_answer a
      JOIN course_chapter_test_question q ON q.id = a.question_id
      JOIN course_chapter ch ON ch.id = q.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course c ON c.id = l.course_id
     WHERE c.created_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern);

    DELETE q FROM course_chapter_test_question q
      JOIN course_chapter ch ON ch.id = q.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course c ON c.id = l.course_id
     WHERE c.created_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern);
    SET v_questions = ROW_COUNT();

    DELETE x FROM course_chapter_content x
      JOIN course_chapter ch ON ch.id = x.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course c ON c.id = l.course_id
     WHERE c.created_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern);
    SET v_contents = ROW_COUNT();

    DELETE ch FROM course_chapter ch
      JOIN course_level l ON l.id = ch.course_level_id
      JOIN course c ON c.id = l.course_id
     WHERE c.created_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern);
    SET v_chapters = ROW_COUNT();

    DELETE l FROM course_level l
      JOIN course c ON c.id = l.course_id
     WHERE c.created_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern);
    SET v_levels = ROW_COUNT();

    DELETE cc FROM course_category cc
      JOIN course c ON c.id = cc.course_id
     WHERE c.created_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern);

    DELETE FROM course
     WHERE created_by IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern);
    SET v_courses = ROW_COUNT();

    -- Las cuentas y su historial.
    DELETE FROM login_attempt
     WHERE user_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern);
    SET v_login_attempts = ROW_COUNT();

    DELETE FROM user_account_event
     WHERE user_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern)
        OR actor_id IN (SELECT id FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern);
    SET v_account_events = ROW_COUNT();

    DELETE FROM app_user WHERE role <> 'admin' AND email LIKE v_pattern;
    SET v_users = ROW_COUNT();

    -- Catálogo que dejan las pruebas ("Prueba <AAAAMMDDhhmmss>-<hex>"), si ya nadie lo usa.
    IF p_scope = 'test' THEN
        DELETE FROM category
         WHERE REGEXP_LIKE(name, v_marker, 'c')
           AND NOT EXISTS (SELECT 1 FROM course_category cc WHERE cc.category_id = category.id);
        SET v_categories = ROW_COUNT();

        DELETE FROM reaction
         WHERE REGEXP_LIKE(reaction_name, v_marker, 'c')
           AND NOT EXISTS (SELECT 1 FROM chapter_comment_reaction x WHERE x.reaction_id = reaction.id)
           AND NOT EXISTS (SELECT 1 FROM opinion_reaction x WHERE x.reaction_id = reaction.id)
           AND NOT EXISTS (SELECT 1 FROM message_reaction x WHERE x.reaction_id = reaction.id);
        SET v_test_reactions = ROW_COUNT();
    END IF;

    SELECT SUM(role = 'student'), SUM(role = 'instructor'), SUM(role = 'admin')
      INTO v_students, v_instructors, v_admins
      FROM app_user;

    IF p_apply = 1 THEN
        COMMIT;
    ELSE
        ROLLBACK;
    END IF;

    SELECT
        v_users AS users, v_login_attempts AS login_attempts, v_account_events AS account_events,
        v_courses AS courses, v_levels AS levels, v_chapters AS chapters, v_contents AS contents,
        v_questions AS questions, v_enrollments AS enrollments, v_purchases AS purchases,
        v_access AS access, v_progress AS progress, v_attempts AS attempts,
        v_certificates AS certificates, v_rates AS rates, v_opinions AS opinions,
        v_comments AS comments, v_reactions AS reactions, v_chats AS chats, v_messages AS messages,
        v_reports AS reports, v_categories AS categories, v_test_reactions AS test_reactions,
        COALESCE(v_students, 0) AS remaining_students, COALESCE(v_instructors, 0) AS remaining_instructors,
        COALESCE(v_admins, 0) AS remaining_admins, IF(p_apply = 1, 1, 0) AS applied;
END$$

DELIMITER ;
