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

-- Usuarios y perfil
SOURCE app/database/procedures/user/sp_user_get_by_id.sql;
SOURCE app/database/procedures/user/sp_user_update_profile.sql;
SOURCE app/database/procedures/user/sp_user_update_avatar.sql;
SOURCE app/database/procedures/user/sp_user_change_password.sql;

-- Administración
SOURCE app/database/procedures/admin/sp_admin_create_first.sql;
SOURCE app/database/procedures/admin/sp_admin_list_locked_users.sql;
SOURCE app/database/procedures/admin/sp_admin_unlock_user.sql;

-- Categorías
SOURCE app/database/procedures/category/sp_category_list.sql;
SOURCE app/database/procedures/category/sp_category_create.sql;
SOURCE app/database/procedures/category/sp_category_update.sql;
SOURCE app/database/procedures/category/sp_category_set_active.sql;

-- Cursos (borrador)
SOURCE app/database/procedures/course/sp_course_create.sql;
SOURCE app/database/procedures/course/sp_course_update.sql;
SOURCE app/database/procedures/course/sp_course_list_by_instructor.sql;
SOURCE app/database/procedures/course/sp_course_get_for_owner.sql;

-- Publicación y baja lógica
SOURCE app/database/procedures/course/sp_course_publish.sql;
SOURCE app/database/procedures/course/sp_course_unpublish.sql;
SOURCE app/database/procedures/course/sp_course_set_active.sql;

-- Niveles, capítulos y contenido
SOURCE app/database/procedures/course/sp_level_create.sql;
SOURCE app/database/procedures/course/sp_level_update.sql;
SOURCE app/database/procedures/course/sp_level_delete.sql;
SOURCE app/database/procedures/course/sp_level_move.sql;
SOURCE app/database/procedures/course/sp_chapter_create.sql;
SOURCE app/database/procedures/course/sp_chapter_update.sql;
SOURCE app/database/procedures/course/sp_chapter_delete.sql;
SOURCE app/database/procedures/course/sp_chapter_move.sql;
SOURCE app/database/procedures/course/sp_content_add.sql;
SOURCE app/database/procedures/course/sp_content_delete.sql;

-- Exámenes (preguntas y opciones)
SOURCE app/database/procedures/course/sp_question_create.sql;
SOURCE app/database/procedures/course/sp_question_update.sql;
SOURCE app/database/procedures/course/sp_question_delete.sql;
SOURCE app/database/procedures/course/sp_question_move.sql;
SOURCE app/database/procedures/course/sp_exam_get_for_owner.sql;

-- Catálogo público
SOURCE app/database/procedures/catalog/sp_catalog_search.sql;
SOURCE app/database/procedures/catalog/sp_catalog_course.sql;
