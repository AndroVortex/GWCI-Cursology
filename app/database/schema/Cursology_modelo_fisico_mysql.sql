-- Cursology: modelo fisico propuesto para MySQL 8.0.16+ / MySQL 8.4.
-- Base NUEVA. No es una migracion del ERD original. No contiene DROP ni datos.
-- Ejecutar una sola vez. Si cursology ya existe, elegir otro nombre antes de ejecutar.
-- No se desactivan las FK ni los CHECK. Todas las tablas usan InnoDB.
-- IMPORTANTE: las reglas entre filas y de autorizacion se implementan en PHP
-- mediante los futuros SP y transacciones; consultar Cursology_decisiones_y_reglas.txt.
-- Las FK usan RESTRICT/NO ACTION implicito: los historicos no se borran en cascada.
-- Fechas en UTC; convertir y formatear al presentar en PHP.
-- No insertar en las columnas generadas: app_user.active,
-- course_chapter_content.main_slot ni test_attempt.passed.

SET NAMES utf8mb4;
SET SESSION time_zone = '+00:00';
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE DATABASE cursology CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cursology;

-- app_user
-- active se deriva de account_status. Para permitir login: active=1 AND ban=0.
CREATE TABLE `app_user` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  father_last_name VARCHAR(100) NOT NULL,
  mother_last_name VARCHAR(100) NULL,
  gender VARCHAR(40) NOT NULL,
  birthday DATE NOT NULL,
  avatar VARCHAR(2048) NOT NULL,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'student',
  description TEXT NULL,
  account_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  active BOOLEAN GENERATED ALWAYS AS (account_status = 'active') STORED,
  consecutive_failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status_changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  ban BOOLEAN NOT NULL DEFAULT FALSE,
  banned_by BIGINT UNSIGNED NULL,
  banned_at DATETIME(6) NULL,
  ban_reason VARCHAR(500) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  last_login_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_email (email),
  KEY ix_user_report (role, account_status, ban, created_at),
  CONSTRAINT fk_user_banned_by FOREIGN KEY (banned_by) REFERENCES app_user (id),
  CONSTRAINT ck_user_role CHECK (role IN ('student', 'instructor', 'admin')),
  CONSTRAINT ck_user_status CHECK (account_status IN ('active', 'locked', 'deleted')),
  CONSTRAINT ck_user_failed CHECK (consecutive_failed_logins BETWEEN 0 AND 3),
  CONSTRAINT ck_user_locked CHECK (
    (account_status = 'locked' AND consecutive_failed_logins = 3)
    OR (account_status = 'active' AND consecutive_failed_logins < 3)
    OR account_status = 'deleted'),
  CONSTRAINT ck_user_ban CHECK (ban IN (0,1)),
  CONSTRAINT ck_user_ban_metadata CHECK (
    (ban = 0 AND banned_by IS NULL AND banned_at IS NULL AND ban_reason IS NULL)
    OR (ban = 1 AND banned_by IS NOT NULL AND banned_at IS NOT NULL
        AND ban_reason IS NOT NULL AND CHAR_LENGTH(TRIM(ban_reason)) > 0)),
  CONSTRAINT ck_user_fields CHECK (CHAR_LENGTH(TRIM(name)) > 0
    AND CHAR_LENGTH(TRIM(father_last_name)) > 0 AND CHAR_LENGTH(TRIM(gender)) > 0
    AND CHAR_LENGTH(TRIM(avatar)) > 0 AND CHAR_LENGTH(TRIM(email)) > 0
    AND CHAR_LENGTH(password_hash) > 0)
) ENGINE=InnoDB;

-- login_attempt
-- Auditoria; el contador operativo y su reinicio viven en app_user y la transaccion PHP.
CREATE TABLE `login_attempt` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  attempted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  outcome VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  consecutive_failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_login_user_time (user_id, attempted_at),
  CONSTRAINT fk_login_user FOREIGN KEY (user_id) REFERENCES app_user (id),
  CONSTRAINT ck_login_outcome CHECK (outcome IN ('success','wrong_password','blocked','unknown_user')),
  CONSTRAINT ck_login_count CHECK (consecutive_failures BETWEEN 0 AND 3),
  CONSTRAINT ck_login_success CHECK (outcome <> 'success' OR consecutive_failures = 0),
  CONSTRAINT ck_login_failure CHECK (outcome <> 'wrong_password' OR consecutive_failures BETWEEN 1 AND 3)
) ENGINE=InnoDB;

-- user_account_event
CREATE TABLE `user_account_event` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason VARCHAR(500) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY ix_account_event (user_id, created_at),
  CONSTRAINT fk_account_event_user FOREIGN KEY (user_id) REFERENCES app_user (id),
  CONSTRAINT fk_account_event_actor FOREIGN KEY (actor_id) REFERENCES app_user (id),
  CONSTRAINT ck_account_event_type CHECK (event_type IN ('locked','reactivated','deleted','banned','unbanned')),
  CONSTRAINT ck_account_event_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0)
) ENGINE=InnoDB;

-- category
CREATE TABLE `category` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_category_name (name),
  CONSTRAINT fk_category_creator FOREIGN KEY (created_by) REFERENCES app_user (id),
  CONSTRAINT fk_category_updater FOREIGN KEY (updated_by) REFERENCES app_user (id),
  CONSTRAINT ck_category_active CHECK (active IN (0,1)),
  CONSTRAINT ck_category_name CHECK (CHAR_LENGTH(TRIM(name)) > 0)
) ENGINE=InnoDB;

-- reaction
CREATE TABLE `reaction` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  emoji VARCHAR(32) NOT NULL,
  reaction_name VARCHAR(60) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reaction_name (reaction_name),
  CONSTRAINT fk_reaction_creator FOREIGN KEY (created_by) REFERENCES app_user (id),
  CONSTRAINT fk_reaction_updater FOREIGN KEY (updated_by) REFERENCES app_user (id),
  CONSTRAINT ck_reaction_active CHECK (active IN (0,1)),
  CONSTRAINT ck_reaction_name CHECK (CHAR_LENGTH(TRIM(reaction_name)) > 0),
  CONSTRAINT ck_reaction_emoji CHECK (CHAR_LENGTH(TRIM(emoji)) > 0)
) ENGINE=InnoDB;

-- payment_method
CREATE TABLE `payment_method` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  method_name VARCHAR(100) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_method_name (code),
  CONSTRAINT fk_payment_method_creator FOREIGN KEY (created_by) REFERENCES app_user (id),
  CONSTRAINT fk_payment_method_updater FOREIGN KEY (updated_by) REFERENCES app_user (id),
  CONSTRAINT ck_payment_method_active CHECK (active IN (0,1)),
  CONSTRAINT ck_payment_method_name CHECK (CHAR_LENGTH(TRIM(code)) > 0)
) ENGINE=InnoDB;

-- course
-- published_at NULL = borrador. full_course_price NULL = suma de niveles; 0 = gratis.
CREATE TABLE `course` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  cover_image VARCHAR(2048) NOT NULL,
  onboarding_video VARCHAR(2048) NOT NULL,
  certificate_template VARCHAR(2048) NULL,
  certifier_name VARCHAR(300) NULL,
  full_course_price DECIMAL(12,2) NULL,
  currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'MXN',
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  published_at DATETIME(6) NULL,
  deactivated_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  KEY ix_course_public (active, published_at),
  KEY ix_course_instructor (created_by, created_at),
  KEY ix_course_title (title),
  CONSTRAINT fk_course_creator FOREIGN KEY (created_by) REFERENCES app_user (id),
  CONSTRAINT fk_course_updater FOREIGN KEY (updated_by) REFERENCES app_user (id),
  CONSTRAINT ck_course_fields CHECK (CHAR_LENGTH(TRIM(title)) > 0
    AND CHAR_LENGTH(TRIM(description)) > 0 AND CHAR_LENGTH(TRIM(cover_image)) > 0
    AND CHAR_LENGTH(TRIM(onboarding_video)) > 0),
  CONSTRAINT ck_course_template CHECK (certificate_template IS NULL OR CHAR_LENGTH(TRIM(certificate_template)) > 0),
  CONSTRAINT ck_course_certifier CHECK (certifier_name IS NULL OR CHAR_LENGTH(TRIM(certifier_name)) > 0),
  CONSTRAINT ck_course_price CHECK (full_course_price IS NULL OR full_course_price >= 0),
  CONSTRAINT ck_course_currency CHECK (CHAR_LENGTH(currency) = 3),
  CONSTRAINT ck_course_active CHECK (active IN (0,1)),
  CONSTRAINT ck_course_deactivated CHECK ((active = 1 AND deactivated_at IS NULL)
    OR (active = 0 AND deactivated_at IS NOT NULL))
) ENGINE=InnoDB;

-- course_category
CREATE TABLE `course_category` (
course_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (course_id, category_id),
  KEY ix_course_category_filter (category_id, active, course_id),
  CONSTRAINT fk_course_category_course FOREIGN KEY (course_id) REFERENCES course (id),
  CONSTRAINT fk_course_category_category FOREIGN KEY (category_id) REFERENCES category (id),
  CONSTRAINT ck_course_category_active CHECK (active IN (0,1))
) ENGINE=InnoDB;

-- course_level
-- Sin active independiente: la estructura se conserva para progreso y certificados.
CREATE TABLE `course_level` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  onboarding_video VARCHAR(2048) NOT NULL,
  sort_order INT UNSIGNED NOT NULL,
  cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_level_order (course_id, sort_order),
  UNIQUE KEY uq_level_course (id, course_id),
  CONSTRAINT fk_level_course FOREIGN KEY (course_id) REFERENCES course (id),
  CONSTRAINT ck_level_order CHECK (sort_order >= 1),
  CONSTRAINT ck_level_cost CHECK (cost >= 0),
  CONSTRAINT ck_level_fields CHECK (CHAR_LENGTH(TRIM(title)) > 0
    AND CHAR_LENGTH(TRIM(description)) > 0 AND CHAR_LENGTH(TRIM(onboarding_video)) > 0)
) ENGINE=InnoDB;

-- course_chapter
CREATE TABLE `course_chapter` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_level_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  sort_order INT UNSIGNED NOT NULL,
  is_test BOOLEAN NOT NULL DEFAULT FALSE,
  minimum_passing_grade DECIMAL(5,2) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_chapter_order (course_level_id, sort_order),
  UNIQUE KEY uq_chapter_level (id, course_level_id),
  CONSTRAINT fk_chapter_level FOREIGN KEY (course_level_id) REFERENCES course_level (id),
  CONSTRAINT ck_chapter_order CHECK (sort_order >= 1),
  CONSTRAINT ck_chapter_title CHECK (CHAR_LENGTH(TRIM(title)) > 0),
  CONSTRAINT ck_chapter_test CHECK (is_test IN (0,1)),
  CONSTRAINT ck_chapter_grade CHECK (
    (is_test = 0 AND minimum_passing_grade IS NULL)
    OR (is_test = 1 AND minimum_passing_grade IS NOT NULL AND minimum_passing_grade > 0
        AND minimum_passing_grade <= 100))
) ENGINE=InnoDB;

-- course_chapter_content
-- UNIQUE con main_slot permite maximo un video principal; PHP exige exactamente uno al publicar.
CREATE TABLE `course_chapter_content` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_chapter_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  content_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  content_uri VARCHAR(2048) NULL,
  text_content LONGTEXT NULL,
  mime_type VARCHAR(127) NULL,
  original_filename VARCHAR(255) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  duration_seconds INT UNSIGNED NULL,
  is_main BOOLEAN NOT NULL DEFAULT FALSE,
  main_slot TINYINT GENERATED ALWAYS AS (CASE WHEN is_main = 1 THEN 1 ELSE NULL END) STORED,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_content_main (course_chapter_id, main_slot),
  CONSTRAINT fk_content_chapter FOREIGN KEY (course_chapter_id) REFERENCES course_chapter (id),
  CONSTRAINT ck_content_title CHECK (CHAR_LENGTH(TRIM(title)) > 0),
  CONSTRAINT ck_content_type CHECK (content_type IN ('video','document','image','audio','archive','link','text','other')),
  CONSTRAINT ck_content_main CHECK (is_main IN (0,1) AND (is_main = 0 OR content_type = 'video')),
  CONSTRAINT ck_content_payload CHECK (
    (content_type = 'text' AND text_content IS NOT NULL AND CHAR_LENGTH(TRIM(text_content)) > 0 AND content_uri IS NULL)
    OR (content_type <> 'text' AND content_uri IS NOT NULL AND CHAR_LENGTH(TRIM(content_uri)) > 0 AND text_content IS NULL)),
  CONSTRAINT ck_content_duration CHECK (duration_seconds IS NULL OR duration_seconds > 0)
) ENGINE=InnoDB;

-- course_chapter_test_question
CREATE TABLE `course_chapter_test_question` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_chapter_id BIGINT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL,
  question TEXT NOT NULL,
  selection_mode VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'single',
  PRIMARY KEY (id),
  UNIQUE KEY uq_question_order (course_chapter_id, sort_order),
  UNIQUE KEY uq_question_chapter (id, course_chapter_id),
  CONSTRAINT fk_question_chapter FOREIGN KEY (course_chapter_id) REFERENCES course_chapter (id),
  CONSTRAINT ck_question_order CHECK (sort_order >= 1),
  CONSTRAINT ck_question_text CHECK (CHAR_LENGTH(TRIM(question)) > 0),
  CONSTRAINT ck_question_mode CHECK (selection_mode IN ('single','multiple'))
) ENGINE=InnoDB;

-- test_question_answer
CREATE TABLE `test_question_answer` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id BIGINT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL,
  answer TEXT NOT NULL,
  is_correct BOOLEAN NOT NULL DEFAULT FALSE,
  weight DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  UNIQUE KEY uq_answer_order (question_id, sort_order),
  UNIQUE KEY uq_answer_question (id, question_id),
  CONSTRAINT fk_answer_question FOREIGN KEY (question_id) REFERENCES course_chapter_test_question (id),
  CONSTRAINT ck_answer_order CHECK (sort_order >= 1),
  CONSTRAINT ck_answer_text CHECK (CHAR_LENGTH(TRIM(answer)) > 0),
  CONSTRAINT ck_answer_correct CHECK (is_correct IN (0,1)),
  CONSTRAINT ck_answer_weight CHECK ((is_correct = 1 AND weight > 0) OR (is_correct = 0 AND weight = 0))
) ENGINE=InnoDB;

-- user_courses
CREATE TABLE `user_courses` (
user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  enrolled_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_accessed_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  PRIMARY KEY (user_id, course_id),
  KEY ix_enrollment_course (course_id, enrolled_at),
  KEY ix_enrollment_user_date (user_id, enrolled_at),
  CONSTRAINT fk_enrollment_user FOREIGN KEY (user_id) REFERENCES app_user (id),
  CONSTRAINT fk_enrollment_course FOREIGN KEY (course_id) REFERENCES course (id),
  CONSTRAINT ck_enrollment_completed CHECK (completed_at IS NULL OR completed_at >= enrolled_at),
  CONSTRAINT ck_enrollment_access CHECK (last_accessed_at IS NULL OR last_accessed_at >= enrolled_at)
) ENGINE=InnoDB;

-- purchase
-- Una compra corresponde a un curso completo o un nivel. Un registro de importe cero documenta el acceso gratuito.
CREATE TABLE `purchase` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  course_level_id BIGINT UNSIGNED NULL,
  purchase_scope VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payment_method_id BIGINT UNSIGNED NULL,
  payment_method_name_snapshot VARCHAR(100) NULL,
  course_title_snapshot VARCHAR(200) NOT NULL,
  level_title_snapshot VARCHAR(200) NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'MXN',
  status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  idempotency_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  provider VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
  provider_transaction_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  completed_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchase_idempotency (idempotency_key),
  UNIQUE KEY uq_purchase_provider (provider, provider_transaction_id),
  UNIQUE KEY uq_purchase_owner (id, user_id, course_id),
  KEY ix_purchase_sales (course_id, status, completed_at),
  KEY ix_purchase_user (user_id, course_id, status),
  CONSTRAINT fk_purchase_user FOREIGN KEY (user_id) REFERENCES app_user (id),
  CONSTRAINT fk_purchase_course FOREIGN KEY (course_id) REFERENCES course (id),
  CONSTRAINT fk_purchase_level FOREIGN KEY (course_level_id, course_id) REFERENCES course_level (id, course_id),
  CONSTRAINT fk_purchase_method FOREIGN KEY (payment_method_id) REFERENCES payment_method (id),
  CONSTRAINT ck_purchase_scope CHECK (
    (purchase_scope = 'course' AND course_level_id IS NULL AND level_title_snapshot IS NULL)
    OR (purchase_scope = 'level' AND course_level_id IS NOT NULL
        AND level_title_snapshot IS NOT NULL AND CHAR_LENGTH(TRIM(level_title_snapshot)) > 0)),
  CONSTRAINT ck_purchase_amount CHECK (amount >= 0),
  CONSTRAINT ck_purchase_currency CHECK (CHAR_LENGTH(currency) = 3),
  CONSTRAINT ck_purchase_method CHECK (
    (amount = 0 AND payment_method_id IS NULL AND payment_method_name_snapshot IS NULL)
    OR (amount > 0 AND payment_method_id IS NOT NULL AND payment_method_name_snapshot IS NOT NULL
        AND CHAR_LENGTH(TRIM(payment_method_name_snapshot)) > 0)),
  CONSTRAINT ck_purchase_status CHECK (status IN ('pending','completed','failed','cancelled')),
  CONSTRAINT ck_purchase_completion CHECK (
    (status = 'completed' AND completed_at IS NOT NULL AND completed_at >= created_at)
    OR (status <> 'completed' AND completed_at IS NULL)),
  CONSTRAINT ck_purchase_fields CHECK (CHAR_LENGTH(TRIM(course_title_snapshot)) > 0 AND CHAR_LENGTH(TRIM(idempotency_key)) > 0),
  CONSTRAINT ck_purchase_provider CHECK (provider_transaction_id IS NULL
    OR (provider IS NOT NULL AND CHAR_LENGTH(TRIM(provider)) > 0 AND CHAR_LENGTH(TRIM(provider_transaction_id)) > 0))
) ENGINE=InnoDB;

-- user_course_level
-- Derecho de acceso. PHP comprueba compra completada y alcance antes de concederlo.
CREATE TABLE `user_course_level` (
user_id BIGINT UNSIGNED NOT NULL,
  course_level_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  purchase_id BIGINT UNSIGNED NOT NULL,
  granted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_accessed_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  PRIMARY KEY (user_id, course_level_id),
  KEY ix_access_course (user_id, course_id),
  CONSTRAINT fk_access_enrollment FOREIGN KEY (user_id, course_id) REFERENCES user_courses (user_id, course_id),
  CONSTRAINT fk_access_level FOREIGN KEY (course_level_id, course_id) REFERENCES course_level (id, course_id),
  CONSTRAINT fk_access_purchase FOREIGN KEY (purchase_id, user_id, course_id) REFERENCES purchase (id, user_id, course_id),
  CONSTRAINT ck_access_completed CHECK (completed_at IS NULL OR completed_at >= granted_at),
  CONSTRAINT ck_access_last CHECK (last_accessed_at IS NULL OR last_accessed_at >= granted_at)
) ENGINE=InnoDB;

-- user_chapter_progress
CREATE TABLE `user_chapter_progress` (
user_id BIGINT UNSIGNED NOT NULL,
  course_chapter_id BIGINT UNSIGNED NOT NULL,
  course_level_id BIGINT UNSIGNED NOT NULL,
  started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_accessed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  video_position_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  completed_at DATETIME(6) NULL,
  PRIMARY KEY (user_id, course_chapter_id),
  CONSTRAINT fk_progress_access FOREIGN KEY (user_id, course_level_id) REFERENCES user_course_level (user_id, course_level_id),
  CONSTRAINT fk_progress_chapter FOREIGN KEY (course_chapter_id, course_level_id) REFERENCES course_chapter (id, course_level_id),
  CONSTRAINT ck_progress_completed CHECK (completed_at IS NULL OR completed_at >= started_at),
  CONSTRAINT ck_progress_last CHECK (last_accessed_at >= started_at)
) ENGINE=InnoDB;

-- test_attempt
CREATE TABLE `test_attempt` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  course_chapter_id BIGINT UNSIGNED NOT NULL,
  course_level_id BIGINT UNSIGNED NOT NULL,
  attempt_number INT UNSIGNED NOT NULL,
  started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  submitted_at DATETIME(6) NULL,
  minimum_grade_snapshot DECIMAL(5,2) NOT NULL,
  max_points DECIMAL(14,2) NOT NULL,
  earned_points DECIMAL(14,2) NULL,
  grade DECIMAL(5,2) NULL,
  passed BOOLEAN GENERATED ALWAYS AS (CASE WHEN submitted_at IS NULL THEN NULL ELSE grade >= minimum_grade_snapshot END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attempt_number (user_id, course_chapter_id, attempt_number),
  UNIQUE KEY uq_attempt_chapter (id, course_chapter_id),
  CONSTRAINT fk_attempt_access FOREIGN KEY (user_id, course_level_id) REFERENCES user_course_level (user_id, course_level_id),
  CONSTRAINT fk_attempt_chapter FOREIGN KEY (course_chapter_id, course_level_id) REFERENCES course_chapter (id, course_level_id),
  CONSTRAINT ck_attempt_number CHECK (attempt_number >= 1),
  CONSTRAINT ck_attempt_min CHECK (minimum_grade_snapshot > 0 AND minimum_grade_snapshot <= 100),
  CONSTRAINT ck_attempt_max CHECK (max_points > 0),
  CONSTRAINT ck_attempt_result CHECK (
    (submitted_at IS NULL AND earned_points IS NULL AND grade IS NULL)
    OR (submitted_at IS NOT NULL AND submitted_at >= started_at AND earned_points IS NOT NULL
        AND earned_points BETWEEN 0 AND max_points AND grade IS NOT NULL AND grade BETWEEN 0 AND 100
        AND grade = ROUND(100 * earned_points / max_points, 2)))
) ENGINE=InnoDB;

-- test_attempt_question
CREATE TABLE `test_attempt_question` (
attempt_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  course_chapter_id BIGINT UNSIGNED NOT NULL,
  question_snapshot TEXT NOT NULL,
  selection_mode_snapshot VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  max_points DECIMAL(14,2) NOT NULL,
  earned_points DECIMAL(14,2) NULL,
  PRIMARY KEY (attempt_id, question_id),
  CONSTRAINT fk_attempt_question_attempt FOREIGN KEY (attempt_id, course_chapter_id) REFERENCES test_attempt (id, course_chapter_id),
  CONSTRAINT fk_attempt_question_question FOREIGN KEY (question_id, course_chapter_id) REFERENCES course_chapter_test_question (id, course_chapter_id),
  CONSTRAINT ck_attempt_question_mode CHECK (selection_mode_snapshot IN ('single','multiple')),
  CONSTRAINT ck_attempt_question_text CHECK (CHAR_LENGTH(TRIM(question_snapshot)) > 0),
  CONSTRAINT ck_attempt_question_score CHECK (max_points > 0 AND (earned_points IS NULL OR earned_points BETWEEN 0 AND max_points))
) ENGINE=InnoDB;

-- test_attempt_answer
-- Al iniciar se copian TODAS las opciones. selected identifica las elegidas; no exponer soluciones al cliente.
CREATE TABLE `test_attempt_answer` (
attempt_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  answer_id BIGINT UNSIGNED NOT NULL,
  answer_snapshot TEXT NOT NULL,
  is_correct_snapshot BOOLEAN NOT NULL,
  weight_snapshot DECIMAL(10,2) NOT NULL,
  selected BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (attempt_id, question_id, answer_id),
  CONSTRAINT fk_attempt_answer_question FOREIGN KEY (attempt_id, question_id) REFERENCES test_attempt_question (attempt_id, question_id),
  CONSTRAINT fk_attempt_answer_answer FOREIGN KEY (answer_id, question_id) REFERENCES test_question_answer (id, question_id),
  CONSTRAINT ck_attempt_answer_flags CHECK (is_correct_snapshot IN (0,1) AND selected IN (0,1)),
  CONSTRAINT ck_attempt_answer_text CHECK (CHAR_LENGTH(TRIM(answer_snapshot)) > 0),
  CONSTRAINT ck_attempt_answer_weight CHECK ((is_correct_snapshot = 1 AND weight_snapshot > 0)
    OR (is_correct_snapshot = 0 AND weight_snapshot = 0))
) ENGINE=InnoDB;

-- certificate
-- Datos congelados para emision idempotente; el archivo se genera desde PHP.
CREATE TABLE `certificate` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  issued_by BIGINT UNSIGNED NOT NULL,
  verification_code CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  student_name_snapshot VARCHAR(350) NOT NULL,
  course_title_snapshot VARCHAR(200) NOT NULL,
  certifier_name_snapshot VARCHAR(300) NOT NULL,
  completed_at_snapshot DATETIME(6) NOT NULL,
  template_snapshot VARCHAR(2048) NOT NULL,
  status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  document_uri VARCHAR(2048) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  issued_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_certificate_enrollment (user_id, course_id),
  UNIQUE KEY uq_certificate_code (verification_code),
  CONSTRAINT fk_certificate_enrollment FOREIGN KEY (user_id, course_id) REFERENCES user_courses (user_id, course_id),
  CONSTRAINT fk_certificate_issuer FOREIGN KEY (issued_by) REFERENCES app_user (id),
  CONSTRAINT ck_certificate_status CHECK (status IN ('pending','issued','failed')),
  CONSTRAINT ck_certificate_issued CHECK (
    (status = 'issued' AND issued_at IS NOT NULL AND issued_at >= completed_at_snapshot
      AND document_uri IS NOT NULL AND CHAR_LENGTH(TRIM(document_uri)) > 0)
    OR (status <> 'issued' AND issued_at IS NULL AND document_uri IS NULL)),
  CONSTRAINT ck_certificate_fields CHECK (CHAR_LENGTH(TRIM(verification_code)) > 0
    AND CHAR_LENGTH(TRIM(student_name_snapshot)) > 0 AND CHAR_LENGTH(TRIM(course_title_snapshot)) > 0
    AND CHAR_LENGTH(TRIM(certifier_name_snapshot)) > 0 AND CHAR_LENGTH(TRIM(template_snapshot)) > 0)
) ENGINE=InnoDB;

-- course_rate
-- Una calificacion editable por alumno y curso; PHP exige curso completado.
CREATE TABLE `course_rate` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rated_by BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  rate TINYINT UNSIGNED NOT NULL,
  rated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_course_rate_user (rated_by, course_id),
  KEY ix_course_rate_average (course_id, rate),
  CONSTRAINT fk_rate_enrollment FOREIGN KEY (rated_by, course_id) REFERENCES user_courses (user_id, course_id),
  CONSTRAINT ck_course_rate CHECK (rate BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- course_opinion
CREATE TABLE `course_opinion` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  commented_by BIGINT UNSIGNED NOT NULL,
  opinion TEXT NULL,
  multimedia_content VARCHAR(2048) NULL,

  commented_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  active BOOLEAN NOT NULL DEFAULT TRUE,
  deactivated_by BIGINT UNSIGNED NULL,
  deactivated_at DATETIME(6) NULL,
  reason VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_opinion_user (commented_by, course_id),
  KEY ix_opinion_course (course_id, commented_at),
  CONSTRAINT fk_opinion_enrollment FOREIGN KEY (commented_by, course_id) REFERENCES user_courses (user_id, course_id),

  CONSTRAINT fk_opinion_deactivator FOREIGN KEY (deactivated_by) REFERENCES app_user (id),
  CONSTRAINT ck_opinion_active CHECK (active IN (0,1)),
  CONSTRAINT ck_opinion_body CHECK (COALESCE(CHAR_LENGTH(TRIM(opinion)),0) > 0
    OR COALESCE(CHAR_LENGTH(TRIM(multimedia_content)),0) > 0),
  CONSTRAINT ck_opinion_deletion CHECK (
    (active = 1 AND deactivated_by IS NULL AND deactivated_at IS NULL AND reason IS NULL)
    OR (active = 0 AND deactivated_by IS NOT NULL AND deactivated_at IS NOT NULL
        AND reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0))
) ENGINE=InnoDB;

-- chapter_comment
CREATE TABLE `chapter_comment` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_chapter_id BIGINT UNSIGNED NOT NULL,
  comment_parent_id BIGINT UNSIGNED NULL,
  commented_by BIGINT UNSIGNED NOT NULL,
  comment TEXT NULL,
  multimedia_content VARCHAR(2048) NULL,

  commented_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  active BOOLEAN NOT NULL DEFAULT TRUE,
  deactivated_by BIGINT UNSIGNED NULL,
  deactivated_at DATETIME(6) NULL,
  reason VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_comment_chapter (id, course_chapter_id),
  KEY ix_comment_chapter_date (course_chapter_id, commented_at),
  CONSTRAINT fk_comment_chapter FOREIGN KEY (course_chapter_id) REFERENCES course_chapter (id),
  CONSTRAINT fk_comment_author FOREIGN KEY (commented_by) REFERENCES app_user (id),
  CONSTRAINT fk_comment_parent FOREIGN KEY (comment_parent_id, course_chapter_id) REFERENCES chapter_comment (id, course_chapter_id),

  CONSTRAINT fk_comment_deactivator FOREIGN KEY (deactivated_by) REFERENCES app_user (id),
  CONSTRAINT ck_comment_active CHECK (active IN (0,1)),
  CONSTRAINT ck_comment_body CHECK (COALESCE(CHAR_LENGTH(TRIM(comment)),0) > 0
    OR COALESCE(CHAR_LENGTH(TRIM(multimedia_content)),0) > 0),
  CONSTRAINT ck_comment_deletion CHECK (
    (active = 1 AND deactivated_by IS NULL AND deactivated_at IS NULL AND reason IS NULL)
    OR (active = 0 AND deactivated_by IS NOT NULL AND deactivated_at IS NOT NULL
        AND reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0))
) ENGINE=InnoDB;

-- chapter_comment_reaction
-- Una sola reaccion por usuario y comentario; cambiarla con UPDATE o quitarla con DELETE.
CREATE TABLE `chapter_comment_reaction` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  comment_id BIGINT UNSIGNED NOT NULL,
  reacted_by BIGINT UNSIGNED NOT NULL,
  reaction_id BIGINT UNSIGNED NOT NULL,
  reacted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_chapter_comment_reaction_user (comment_id, reacted_by),
  CONSTRAINT fk_chapter_comment_reaction_target FOREIGN KEY (comment_id) REFERENCES chapter_comment (id),
  CONSTRAINT fk_chapter_comment_reaction_user FOREIGN KEY (reacted_by) REFERENCES app_user (id),
  CONSTRAINT fk_chapter_comment_reaction_reaction FOREIGN KEY (reaction_id) REFERENCES reaction (id)
) ENGINE=InnoDB;

-- opinion_reaction
-- Una sola reaccion por usuario y opinion.
CREATE TABLE `opinion_reaction` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  opinion_id BIGINT UNSIGNED NOT NULL,
  reacted_by BIGINT UNSIGNED NOT NULL,
  reaction_id BIGINT UNSIGNED NOT NULL,
  reacted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_opinion_reaction_user (opinion_id, reacted_by),
  CONSTRAINT fk_opinion_reaction_target FOREIGN KEY (opinion_id) REFERENCES course_opinion (id),
  CONSTRAINT fk_opinion_reaction_user FOREIGN KEY (reacted_by) REFERENCES app_user (id),
  CONSTRAINT fk_opinion_reaction_reaction FOREIGN KEY (reaction_id) REFERENCES reaction (id)
) ENGINE=InnoDB;

-- chat
-- Conversacion por curso entre estudiante e instructor propietario; PHP valida los roles.
CREATE TABLE `chat` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  instructor_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_chat_participants (course_id, instructor_id, student_id),
  KEY ix_chat_student (student_id, created_at),
  KEY ix_chat_instructor (instructor_id, created_at),
  CONSTRAINT fk_chat_course FOREIGN KEY (course_id) REFERENCES course (id),
  CONSTRAINT fk_chat_instructor FOREIGN KEY (instructor_id) REFERENCES app_user (id),
  CONSTRAINT fk_chat_student FOREIGN KEY (student_id) REFERENCES app_user (id),
  CONSTRAINT ck_chat_distinct CHECK (instructor_id <> student_id)
) ENGINE=InnoDB;

-- message
CREATE TABLE `message` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  chat_id BIGINT UNSIGNED NOT NULL,
  sent_by BIGINT UNSIGNED NOT NULL,
  content TEXT NULL,
  multimedia_content VARCHAR(2048) NULL,
  reply_to BIGINT UNSIGNED NULL,
  sent_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  active BOOLEAN NOT NULL DEFAULT TRUE,
  deactivated_by BIGINT UNSIGNED NULL,
  deactivated_at DATETIME(6) NULL,
  reason VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_message_chat (id, chat_id),
  KEY ix_message_chat_date (chat_id, sent_at),
  CONSTRAINT fk_message_chat FOREIGN KEY (chat_id) REFERENCES chat (id),
  CONSTRAINT fk_message_sender FOREIGN KEY (sent_by) REFERENCES app_user (id),
  CONSTRAINT fk_message_reply FOREIGN KEY (reply_to, chat_id) REFERENCES message (id, chat_id),

  CONSTRAINT fk_message_deactivator FOREIGN KEY (deactivated_by) REFERENCES app_user (id),
  CONSTRAINT ck_message_active CHECK (active IN (0,1)),
  CONSTRAINT ck_message_body CHECK (COALESCE(CHAR_LENGTH(TRIM(content)),0) > 0
    OR COALESCE(CHAR_LENGTH(TRIM(multimedia_content)),0) > 0),
  CONSTRAINT ck_message_deletion CHECK (
    (active = 1 AND deactivated_by IS NULL AND deactivated_at IS NULL AND reason IS NULL)
    OR (active = 0 AND deactivated_by IS NOT NULL AND deactivated_at IS NOT NULL
        AND reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0))
) ENGINE=InnoDB;

-- message_reaction
-- Una sola reaccion por usuario y mensaje.
CREATE TABLE `message_reaction` (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id BIGINT UNSIGNED NOT NULL,
  reacted_by BIGINT UNSIGNED NOT NULL,
  reaction_id BIGINT UNSIGNED NOT NULL,
  reacted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_message_reaction_user (message_id, reacted_by),
  CONSTRAINT fk_message_reaction_target FOREIGN KEY (message_id) REFERENCES message (id),
  CONSTRAINT fk_message_reaction_user FOREIGN KEY (reacted_by) REFERENCES app_user (id),
  CONSTRAINT fk_message_reaction_reaction FOREIGN KEY (reaction_id) REFERENCES reaction (id)
) ENGINE=InnoDB;

-- Fin del esquema. Catalogos y primer administrador se crean desde un bootstrap PHP.
