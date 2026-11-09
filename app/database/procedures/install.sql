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
SOURCE app/database/procedures/user/sp_user_deactivate.sql;

-- Administración
SOURCE app/database/procedures/admin/sp_admin_create_first.sql;
SOURCE app/database/procedures/admin/sp_admin_list_locked_users.sql;
SOURCE app/database/procedures/admin/sp_admin_unlock_user.sql;
SOURCE app/database/procedures/admin/sp_admin_list_users.sql;
SOURCE app/database/procedures/admin/sp_admin_ban_user.sql;
SOURCE app/database/procedures/admin/sp_admin_unban_user.sql;
SOURCE app/database/procedures/admin/sp_admin_reactivate_user.sql;
SOURCE app/database/procedures/admin/sp_admin_user_events.sql;

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

-- Formas de pago
SOURCE app/database/procedures/payment/sp_payment_method_list.sql;
SOURCE app/database/procedures/payment/sp_payment_method_set_active.sql;

-- Inscripción y compras (sp_purchase_grant va primero: lo llaman los demás)
SOURCE app/database/procedures/purchase/sp_purchase_grant.sql;
SOURCE app/database/procedures/purchase/sp_enrollment_free.sql;
SOURCE app/database/procedures/purchase/sp_purchase_start.sql;
SOURCE app/database/procedures/purchase/sp_purchase_attach_provider.sql;
SOURCE app/database/procedures/purchase/sp_purchase_get.sql;
SOURCE app/database/procedures/purchase/sp_purchase_complete.sql;
SOURCE app/database/procedures/purchase/sp_purchase_fail.sql;
SOURCE app/database/procedures/purchase/sp_course_access.sql;

-- Reproductor del alumno (sp_learning_recalculate va primero: lo llaman los demás)
SOURCE app/database/procedures/learning/sp_learning_recalculate.sql;
SOURCE app/database/procedures/learning/sp_learning_course.sql;
SOURCE app/database/procedures/learning/sp_learning_progress.sql;

-- Intentos de examen (sp_attempt_get va primero: lo llaman start y submit)
SOURCE app/database/procedures/exam/sp_attempt_get.sql;
SOURCE app/database/procedures/exam/sp_attempt_start.sql;
SOURCE app/database/procedures/exam/sp_attempt_submit.sql;
SOURCE app/database/procedures/exam/sp_attempt_list.sql;

-- Certificados
SOURCE app/database/procedures/certificate/sp_certificate_issue.sql;
SOURCE app/database/procedures/certificate/sp_certificate_mark_issued.sql;
SOURCE app/database/procedures/certificate/sp_certificate_list_by_student.sql;
SOURCE app/database/procedures/certificate/sp_certificate_verify.sql;

-- Calificaciones y opiniones (sp_review_mine va primero: lo llama sp_review_save)
SOURCE app/database/procedures/review/sp_review_mine.sql;
SOURCE app/database/procedures/review/sp_review_save.sql;
SOURCE app/database/procedures/review/sp_review_list.sql;
SOURCE app/database/procedures/review/sp_review_hide.sql;

-- Reportes (paneles del instructor y del admin)
SOURCE app/database/procedures/report/sp_instructor_overview.sql;
SOURCE app/database/procedures/report/sp_instructor_sales.sql;
SOURCE app/database/procedures/report/sp_instructor_course_students.sql;
SOURCE app/database/procedures/report/sp_instructor_recent_reviews.sql;
SOURCE app/database/procedures/report/sp_admin_overview.sql;
SOURCE app/database/procedures/report/sp_admin_report_instructors.sql;
SOURCE app/database/procedures/report/sp_admin_report_students.sql;

-- Mensajes entre alumno e instructor
SOURCE app/database/procedures/chat/sp_chat_start.sql;
SOURCE app/database/procedures/chat/sp_chat_list.sql;
SOURCE app/database/procedures/chat/sp_chat_open.sql;
SOURCE app/database/procedures/chat/sp_message_send.sql;
SOURCE app/database/procedures/chat/sp_chat_unread_total.sql;

-- Comentarios por capítulo
SOURCE app/database/procedures/comment/sp_comment_list.sql;
SOURCE app/database/procedures/comment/sp_comment_create.sql;
SOURCE app/database/procedures/comment/sp_comment_update.sql;
SOURCE app/database/procedures/comment/sp_comment_delete.sql;
SOURCE app/database/procedures/comment/sp_comment_hide.sql;
SOURCE app/database/procedures/comment/sp_comment_react.sql;
SOURCE app/database/procedures/comment/sp_instructor_course_comments.sql;
SOURCE app/database/procedures/comment/sp_instructor_recent_comments.sql;

-- Reacciones (catálogo del admin y reacciones a opiniones y mensajes)
SOURCE app/database/procedures/reaction/sp_reaction_list.sql;
SOURCE app/database/procedures/reaction/sp_reaction_create.sql;
SOURCE app/database/procedures/reaction/sp_reaction_update.sql;
SOURCE app/database/procedures/reaction/sp_reaction_set_active.sql;
SOURCE app/database/procedures/reaction/sp_opinion_react.sql;
SOURCE app/database/procedures/reaction/sp_message_react.sql;

-- Moderación (reportes, cola del admin y restaurar)
SOURCE app/database/procedures/moderation/sp_report_create.sql;
SOURCE app/database/procedures/moderation/sp_report_dismiss.sql;
SOURCE app/database/procedures/moderation/sp_moderation_reports.sql;
SOURCE app/database/procedures/moderation/sp_moderation_content.sql;
SOURCE app/database/procedures/moderation/sp_comment_restore.sql;
SOURCE app/database/procedures/moderation/sp_opinion_restore.sql;

-- Mi aprendizaje (kardex y compras)
SOURCE app/database/procedures/student/sp_student_courses.sql;
SOURCE app/database/procedures/student/sp_student_purchases.sql;

-- Catálogo público
SOURCE app/database/procedures/catalog/sp_catalog_search.sql;
SOURCE app/database/procedures/catalog/sp_catalog_course.sql;

-- Mantenimiento (borrar las cuentas de prueba o de demostración: scripts/purge_test_data.php)
SOURCE app/database/procedures/maintenance/sp_maintenance_purge.sql;
