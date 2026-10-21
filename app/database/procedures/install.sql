-- Instala (o reinstala) todos los procedimientos almacenados de Cursology.
-- Cada archivo hace DROP PROCEDURE IF EXISTS + CREATE, así que se puede ejecutar
-- las veces que haga falta. Las rutas de SOURCE son relativas a la carpeta desde
-- la que se abrió el cliente mysql: ábrelo en la raíz del repositorio.
--
--   cd C:\ruta\GWCI-Cursology
--   mysql -u root -p cursology
--   SOURCE app/database/procedures/install.sql;

USE cursology;

-- Autenticación
SOURCE app/database/procedures/auth/sp_auth_register.sql;
SOURCE app/database/procedures/auth/sp_auth_get_login_data.sql;
SOURCE app/database/procedures/auth/sp_auth_record_login.sql;

-- Usuarios
SOURCE app/database/procedures/user/sp_user_get_by_id.sql;
