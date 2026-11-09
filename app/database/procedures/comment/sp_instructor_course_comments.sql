-- sp_instructor_course_comments: los comentarios de los capítulos de video de un curso del
-- instructor, para responderlos desde su vista. Mismas reglas de visibilidad que sp_comment_list.
-- Filtros: p_chapter_id (NULL = todos los capítulos) y p_unanswered = 1, que deja solo los
-- comentarios principales activos de alumnos sin respuesta activa del instructor.
-- Resultados (en este orden):
--   1) el curso: id, title, active
--   2) sus capítulos de video, por nivel y orden: id, title, level_sort, chapter_sort,
--      comment_count (principales visibles) y unanswered_count
--   3) los comentarios principales de la página (del más nuevo al más antiguo), con las
--      columnas de sp_comment_list más chapter_title, level_sort, chapter_sort y answered
--   4) sus respuestas visibles, de la más antigua a la más nueva
--   5) las reacciones de todos ellos (target_id, reaction_id, emoji, reaction_name, total, mine)
--   6) total de comentarios principales con los filtros
-- Errores: COURSE_NOT_FOUND (no existe o es de otro instructor).

DROP PROCEDURE IF EXISTS sp_instructor_course_comments;

DELIMITER $$

CREATE PROCEDURE sp_instructor_course_comments(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_course_id BIGINT UNSIGNED,
    IN p_chapter_id BIGINT UNSIGNED,
    IN p_unanswered BOOLEAN,
    IN p_limit INT UNSIGNED,
    IN p_offset INT UNSIGNED
)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM course WHERE id = p_course_id AND created_by = p_instructor_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'COURSE_NOT_FOUND';
    END IF;

    SELECT id, title, active FROM course WHERE id = p_course_id;

    SELECT ch.id, ch.title, l.sort_order AS level_sort, ch.sort_order AS chapter_sort,
           (SELECT COUNT(*) FROM chapter_comment c
             WHERE c.course_chapter_id = ch.id AND c.comment_parent_id IS NULL
               AND (c.active = 1 OR EXISTS (SELECT 1 FROM chapter_comment r WHERE r.comment_parent_id = c.id AND r.active = 1))) AS comment_count,
           (SELECT COUNT(*) FROM chapter_comment c
             WHERE c.course_chapter_id = ch.id AND c.comment_parent_id IS NULL AND c.active = 1
               AND c.commented_by <> p_instructor_id
               AND NOT EXISTS (SELECT 1 FROM chapter_comment r
                                WHERE r.comment_parent_id = c.id AND r.active = 1 AND r.commented_by = p_instructor_id)) AS unanswered_count
      FROM course_chapter ch
      JOIN course_level l ON l.id = ch.course_level_id
     WHERE l.course_id = p_course_id AND ch.is_test = 0
     ORDER BY l.sort_order, ch.sort_order;

    SELECT
        c.id, c.course_chapter_id, c.comment_parent_id, c.commented_by,
        CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS author_name,
        u.avatar AS author_avatar,
        c.commented_by = p_instructor_id AS author_is_instructor,
        IF(c.active = 1, c.comment, NULL) AS comment,
        c.commented_at, c.updated_at, c.active,
        (SELECT COUNT(*) FROM chapter_comment r WHERE r.comment_parent_id = c.id AND r.active = 1) AS reply_count,
        ch.title AS chapter_title, l.sort_order AS level_sort, ch.sort_order AS chapter_sort,
        EXISTS (SELECT 1 FROM chapter_comment r
                 WHERE r.comment_parent_id = c.id AND r.active = 1 AND r.commented_by = p_instructor_id) AS answered
    FROM chapter_comment c
    JOIN app_user u ON u.id = c.commented_by
    JOIN course_chapter ch ON ch.id = c.course_chapter_id
    JOIN course_level l ON l.id = ch.course_level_id
    WHERE l.course_id = p_course_id AND ch.is_test = 0 AND c.comment_parent_id IS NULL
      AND (p_chapter_id IS NULL OR c.course_chapter_id = p_chapter_id)
      AND (c.active = 1 OR EXISTS (SELECT 1 FROM chapter_comment r WHERE r.comment_parent_id = c.id AND r.active = 1))
      AND (NOT p_unanswered OR (c.active = 1 AND c.commented_by <> p_instructor_id
           AND NOT EXISTS (SELECT 1 FROM chapter_comment r
                            WHERE r.comment_parent_id = c.id AND r.active = 1 AND r.commented_by = p_instructor_id)))
    ORDER BY c.commented_at DESC, c.id DESC
    LIMIT p_limit OFFSET p_offset;

    -- La página se repite como tabla derivada: MySQL no admite LIMIT dentro de IN.
    SELECT
        c.id, c.course_chapter_id, c.comment_parent_id, c.commented_by,
        CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS author_name,
        u.avatar AS author_avatar,
        c.commented_by = p_instructor_id AS author_is_instructor,
        c.comment, c.commented_at, c.updated_at, c.active,
        0 AS reply_count
    FROM chapter_comment c
    JOIN app_user u ON u.id = c.commented_by
    JOIN (
        SELECT p.id
          FROM chapter_comment p
          JOIN course_chapter ch ON ch.id = p.course_chapter_id
          JOIN course_level l ON l.id = ch.course_level_id
         WHERE l.course_id = p_course_id AND ch.is_test = 0 AND p.comment_parent_id IS NULL
           AND (p_chapter_id IS NULL OR p.course_chapter_id = p_chapter_id)
           AND (p.active = 1 OR EXISTS (SELECT 1 FROM chapter_comment r WHERE r.comment_parent_id = p.id AND r.active = 1))
           AND (NOT p_unanswered OR (p.active = 1 AND p.commented_by <> p_instructor_id
                AND NOT EXISTS (SELECT 1 FROM chapter_comment r
                                 WHERE r.comment_parent_id = p.id AND r.active = 1 AND r.commented_by = p_instructor_id)))
         ORDER BY p.commented_at DESC, p.id DESC
         LIMIT p_limit OFFSET p_offset
    ) page ON page.id = c.comment_parent_id
    WHERE c.active = 1
    ORDER BY c.comment_parent_id, c.commented_at, c.id;

    SELECT cr.comment_id AS target_id, re.id AS reaction_id, re.emoji, re.reaction_name,
           COUNT(*) AS total, MAX(cr.reacted_by = p_instructor_id) AS mine
      FROM chapter_comment_reaction cr
      JOIN reaction re ON re.id = cr.reaction_id AND re.active = 1
      JOIN chapter_comment c ON c.id = cr.comment_id AND c.active = 1
      JOIN (
          SELECT p.id
            FROM chapter_comment p
            JOIN course_chapter ch ON ch.id = p.course_chapter_id
            JOIN course_level l ON l.id = ch.course_level_id
           WHERE l.course_id = p_course_id AND ch.is_test = 0 AND p.comment_parent_id IS NULL
             AND (p_chapter_id IS NULL OR p.course_chapter_id = p_chapter_id)
             AND (p.active = 1 OR EXISTS (SELECT 1 FROM chapter_comment r WHERE r.comment_parent_id = p.id AND r.active = 1))
             AND (NOT p_unanswered OR (p.active = 1 AND p.commented_by <> p_instructor_id
                  AND NOT EXISTS (SELECT 1 FROM chapter_comment r
                                   WHERE r.comment_parent_id = p.id AND r.active = 1 AND r.commented_by = p_instructor_id)))
           ORDER BY p.commented_at DESC, p.id DESC
           LIMIT p_limit OFFSET p_offset
      ) page ON page.id = c.id OR page.id = c.comment_parent_id
     GROUP BY cr.comment_id, re.id, re.emoji, re.reaction_name
     ORDER BY cr.comment_id, re.id;

    SELECT COUNT(*) AS total
      FROM chapter_comment c
      JOIN course_chapter ch ON ch.id = c.course_chapter_id
      JOIN course_level l ON l.id = ch.course_level_id
     WHERE l.course_id = p_course_id AND ch.is_test = 0 AND c.comment_parent_id IS NULL
       AND (p_chapter_id IS NULL OR c.course_chapter_id = p_chapter_id)
       AND (c.active = 1 OR EXISTS (SELECT 1 FROM chapter_comment r WHERE r.comment_parent_id = c.id AND r.active = 1))
       AND (NOT p_unanswered OR (c.active = 1 AND c.commented_by <> p_instructor_id
            AND NOT EXISTS (SELECT 1 FROM chapter_comment r
                             WHERE r.comment_parent_id = c.id AND r.active = 1 AND r.commented_by = p_instructor_id)));
END$$

DELIMITER ;
