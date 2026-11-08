-- Migración 2026_11_chat_read: última lectura de cada participante de una conversación, para
-- los mensajes no leídos. Solo hace falta en bases creadas antes de noviembre de 2026; el
-- esquema (Cursology_modelo_fisico_mysql.sql) ya trae estas columnas.
--
-- Idempotente: MySQL no tiene ADD COLUMN IF NOT EXISTS, así que cada columna se agrega solo si
-- information_schema dice que falta. Se puede ejecutar las veces que haga falta:
--
--   mysql -u root -p cursology
--   SOURCE app/database/migrations/2026_11_chat_read.sql;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat' AND COLUMN_NAME = 'student_last_read_at'
);
SET @statement = IF(@column_exists = 0,
    'ALTER TABLE chat ADD COLUMN student_last_read_at DATETIME(6) NULL AFTER created_at',
    'DO 0');
PREPARE migration_step FROM @statement;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat' AND COLUMN_NAME = 'instructor_last_read_at'
);
SET @statement = IF(@column_exists = 0,
    'ALTER TABLE chat ADD COLUMN instructor_last_read_at DATETIME(6) NULL AFTER student_last_read_at',
    'DO 0');
PREPARE migration_step FROM @statement;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;
