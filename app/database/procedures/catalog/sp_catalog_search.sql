-- sp_catalog_search: catálogo público. Solo cursos publicados y activos (no dados de baja).
-- p_query: texto a buscar en el título y en el nombre del instructor (NULL = todos). PHP ya
--          escapó % y _ con '\'; la colación de la base no distingue mayúsculas ni acentos.
-- p_category_id: solo cursos con esa categoría activa (NULL = todas).
-- p_sort: 'recientes' (publicación más nueva), 'titulo' (A-Z) o 'precio' (menor primero;
--         el del curso completo o, si se vende por niveles, la suma de sus niveles).
-- Resultados: 1) las tarjetas de la página (p_limit desde p_offset); 2) total.

DROP PROCEDURE IF EXISTS sp_catalog_search;

DELIMITER $$

CREATE PROCEDURE sp_catalog_search(
    IN p_query VARCHAR(100),
    IN p_category_id BIGINT UNSIGNED,
    IN p_sort VARCHAR(10),
    IN p_limit INT UNSIGNED,
    IN p_offset INT UNSIGNED
)
BEGIN
    DECLARE v_pattern VARCHAR(110) DEFAULT NULL;

    IF p_query IS NOT NULL AND CHAR_LENGTH(TRIM(p_query)) > 0 THEN
        SET v_pattern = CONCAT('%', TRIM(p_query), '%');
    END IF;

    SELECT
        c.id,
        c.title,
        c.cover_image,
        c.full_course_price,
        c.currency,
        c.published_at,
        CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) AS instructor_name,
        (SELECT GROUP_CONCAT(cat.name ORDER BY cat.name SEPARATOR '|')
           FROM course_category cc
           JOIN category cat ON cat.id = cc.category_id
          WHERE cc.course_id = c.id AND cc.active = 1 AND cat.active = 1) AS category_names,
        (SELECT COUNT(*) FROM course_level l WHERE l.course_id = c.id) AS level_count,
        (SELECT COUNT(*)
           FROM course_chapter ch
           JOIN course_level l ON l.id = ch.course_level_id
          WHERE l.course_id = c.id) AS chapter_count,
        (SELECT CAST(COALESCE(SUM(l.cost), 0) AS DECIMAL(14,2))
           FROM course_level l WHERE l.course_id = c.id) AS level_price_total
    FROM course c
    JOIN app_user u ON u.id = c.created_by
    WHERE c.active = 1
      AND c.published_at IS NOT NULL
      AND (v_pattern IS NULL
           OR c.title LIKE v_pattern
           OR CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) LIKE v_pattern)
      AND (p_category_id IS NULL OR EXISTS (
           SELECT 1
             FROM course_category cc
             JOIN category cat ON cat.id = cc.category_id
            WHERE cc.course_id = c.id AND cc.category_id = p_category_id AND cc.active = 1 AND cat.active = 1))
    ORDER BY
        CASE WHEN p_sort = 'titulo' THEN c.title END ASC,
        CASE WHEN p_sort = 'precio' THEN COALESCE(
            c.full_course_price,
            (SELECT COALESCE(SUM(l.cost), 0) FROM course_level l WHERE l.course_id = c.id)
        ) END ASC,
        c.published_at DESC,
        c.id DESC
    LIMIT p_limit OFFSET p_offset;

    SELECT COUNT(*) AS total
    FROM course c
    JOIN app_user u ON u.id = c.created_by
    WHERE c.active = 1
      AND c.published_at IS NOT NULL
      AND (v_pattern IS NULL
           OR c.title LIKE v_pattern
           OR CONCAT_WS(' ', u.name, u.father_last_name, u.mother_last_name) LIKE v_pattern)
      AND (p_category_id IS NULL OR EXISTS (
           SELECT 1
             FROM course_category cc
             JOIN category cat ON cat.id = cc.category_id
            WHERE cc.course_id = c.id AND cc.category_id = p_category_id AND cc.active = 1 AND cat.active = 1));
END$$

DELIMITER ;
