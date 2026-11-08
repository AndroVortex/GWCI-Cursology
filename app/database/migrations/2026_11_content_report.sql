-- Migración 2026_11_content_report: la tabla de reportes de comentarios y opiniones (cola de
-- moderación). Solo hace falta en bases creadas antes de la fase 3; el esquema
-- (Cursology_modelo_fisico_mysql.sql) ya la trae.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS no hace nada si la tabla ya existe. Se puede ejecutar
-- las veces que haga falta:
--
--   mysql -u root -p cursology
--   SOURCE app/database/migrations/2026_11_content_report.sql;

CREATE TABLE IF NOT EXISTS `content_report` (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  comment_id BIGINT UNSIGNED NULL,
  opinion_id BIGINT UNSIGNED NULL,
  reported_by BIGINT UNSIGNED NOT NULL,
  reason_code VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  details VARCHAR(500) NULL,
  reported_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  resolved_by BIGINT UNSIGNED NULL,
  resolved_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_comment_user (comment_id, reported_by),
  UNIQUE KEY uq_report_opinion_user (opinion_id, reported_by),
  KEY ix_report_status (status, reported_at),
  CONSTRAINT fk_report_comment FOREIGN KEY (comment_id) REFERENCES chapter_comment (id),
  CONSTRAINT fk_report_opinion FOREIGN KEY (opinion_id) REFERENCES course_opinion (id),
  CONSTRAINT fk_report_user FOREIGN KEY (reported_by) REFERENCES app_user (id),
  CONSTRAINT fk_report_resolver FOREIGN KEY (resolved_by) REFERENCES app_user (id),
  CONSTRAINT ck_report_target CHECK (
    (target_type = 'comment' AND comment_id IS NOT NULL AND opinion_id IS NULL)
    OR (target_type = 'opinion' AND opinion_id IS NOT NULL AND comment_id IS NULL)),
  CONSTRAINT ck_report_reason CHECK (reason_code IN ('spam', 'offensive', 'answers', 'other')),
  CONSTRAINT ck_report_details CHECK (
    (details IS NULL OR CHAR_LENGTH(TRIM(details)) > 0)
    AND (reason_code <> 'other' OR details IS NOT NULL)),
  CONSTRAINT ck_report_status CHECK (status IN ('pending', 'resolved', 'dismissed')),
  CONSTRAINT ck_report_resolution CHECK (
    (status = 'pending' AND resolved_by IS NULL AND resolved_at IS NULL)
    OR (status <> 'pending' AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL))
) ENGINE=InnoDB;
