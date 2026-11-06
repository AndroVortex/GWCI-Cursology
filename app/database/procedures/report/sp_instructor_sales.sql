-- sp_instructor_sales: ventas del instructor por curso. Solo cursos que alguna vez se publicaron
-- (los borradores nunca tienen alumnos).
-- Filtros (NULL = sin filtro):
--   p_from / p_to   fecha de creación del curso (días completos, inclusive)
--   p_category_id   cursos con esa categoría asignada
--   p_only_active   1 = sin los cursos dados de baja
-- Resultados (en este orden):
--   1) por curso, del más reciente al más antiguo: id, title, cover_image, active, created_at,
--      currency, student_count, avg_progress (promedio entre sus alumnos de capítulos terminados /
--      capítulos del curso * 100, 1 decimal; 0 sin alumnos o sin capítulos), revenue (compras
--      completadas), rating_avg y rating_count (sin las estrellas de opiniones ocultas)
--   2) por forma de pago (solo compras completadas de más de $0): method_name, purchases, total
--   3) revenue_total de los cursos filtrados
-- Errores: NOT_INSTRUCTOR.

DROP PROCEDURE IF EXISTS sp_instructor_sales;

DELIMITER $$

CREATE PROCEDURE sp_instructor_sales(
    IN p_instructor_id BIGINT UNSIGNED,
    IN p_from DATE,
    IN p_to DATE,
    IN p_category_id BIGINT UNSIGNED,
    IN p_only_active BOOLEAN
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user
         WHERE id = p_instructor_id AND role = 'instructor' AND account_status = 'active' AND ban = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOT_INSTRUCTOR';
    END IF;

    SELECT
        c.id, c.title, c.cover_image, c.active, c.created_at, c.currency,
        (SELECT COUNT(*) FROM user_courses uc WHERE uc.course_id = c.id) AS student_count,
        (SELECT CAST(COALESCE(ROUND(AVG(LEAST(100, 100 * (
                    SELECT COUNT(*)
                      FROM user_chapter_progress p
                      JOIN course_level pl ON pl.id = p.course_level_id
                     WHERE p.user_id = uc.user_id AND pl.course_id = c.id AND p.completed_at IS NOT NULL
                ) / NULLIF((
                    SELECT COUNT(*)
                      FROM course_chapter ch
                      JOIN course_level cl ON cl.id = ch.course_level_id
                     WHERE cl.course_id = c.id
                ), 0))), 1), 0) AS DECIMAL(5,1))
           FROM user_courses uc
          WHERE uc.course_id = c.id) AS avg_progress,
        (SELECT CAST(COALESCE(SUM(pu.amount), 0) AS DECIMAL(14,2))
           FROM purchase pu
          WHERE pu.course_id = c.id AND pu.status = 'completed') AS revenue,
        (SELECT CAST(ROUND(AVG(r.rate), 1) AS DECIMAL(3,1))
           FROM course_rate r
           LEFT JOIN course_opinion o ON o.commented_by = r.rated_by AND o.course_id = r.course_id
          WHERE r.course_id = c.id AND (o.id IS NULL OR o.active = 1)) AS rating_avg,
        (SELECT COUNT(*)
           FROM course_rate r
           LEFT JOIN course_opinion o ON o.commented_by = r.rated_by AND o.course_id = r.course_id
          WHERE r.course_id = c.id AND (o.id IS NULL OR o.active = 1)) AS rating_count
    FROM course c
    WHERE c.created_by = p_instructor_id
      AND c.published_at IS NOT NULL
      AND (p_from IS NULL OR c.created_at >= p_from)
      AND (p_to IS NULL OR c.created_at < p_to + INTERVAL 1 DAY)
      AND (p_category_id IS NULL OR EXISTS (
            SELECT 1 FROM course_category cc
             WHERE cc.course_id = c.id AND cc.category_id = p_category_id AND cc.active = 1))
      AND (NOT p_only_active OR c.active = 1)
    ORDER BY c.created_at DESC, c.id DESC;

    SELECT pu.payment_method_name_snapshot AS method_name,
           COUNT(*) AS purchases,
           CAST(SUM(pu.amount) AS DECIMAL(14,2)) AS total
      FROM purchase pu
      JOIN course c ON c.id = pu.course_id
     WHERE c.created_by = p_instructor_id
       AND c.published_at IS NOT NULL
       AND (p_from IS NULL OR c.created_at >= p_from)
       AND (p_to IS NULL OR c.created_at < p_to + INTERVAL 1 DAY)
       AND (p_category_id IS NULL OR EXISTS (
             SELECT 1 FROM course_category cc
              WHERE cc.course_id = c.id AND cc.category_id = p_category_id AND cc.active = 1))
       AND (NOT p_only_active OR c.active = 1)
       AND pu.status = 'completed' AND pu.amount > 0
     GROUP BY pu.payment_method_name_snapshot
     ORDER BY total DESC, method_name;

    SELECT CAST(COALESCE(SUM(pu.amount), 0) AS DECIMAL(14,2)) AS revenue_total
      FROM purchase pu
      JOIN course c ON c.id = pu.course_id
     WHERE c.created_by = p_instructor_id
       AND c.published_at IS NOT NULL
       AND (p_from IS NULL OR c.created_at >= p_from)
       AND (p_to IS NULL OR c.created_at < p_to + INTERVAL 1 DAY)
       AND (p_category_id IS NULL OR EXISTS (
             SELECT 1 FROM course_category cc
              WHERE cc.course_id = c.id AND cc.category_id = p_category_id AND cc.active = 1))
       AND (NOT p_only_active OR c.active = 1)
       AND pu.status = 'completed';
END$$

DELIMITER ;
