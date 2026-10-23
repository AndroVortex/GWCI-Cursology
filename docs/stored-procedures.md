# Procedimientos almacenados (SP)

Todo el acceso a datos de negocio de Cursology pasa por SP. PHP nunca ejecuta `SELECT`, `INSERT`, `UPDATE` ni `DELETE` directamente sobre las tablas: llama al SP del caso de uso y convierte sus filas en entidades (`fromRow()`) o DTO.

## Convenciones

- **Un archivo por SP.** Los archivos van en `app/database/procedures/<modulo>/<nombre>.sql`. Cada uno hace `DROP PROCEDURE IF EXISTS` + `CREATE` con `DELIMITER $$`, para poder reinstalarlo sin errores.
- **Nombres por caso de uso**, no por tabla: `sp_<modulo>_<accion>` (por ejemplo, `sp_auth_register`, no `sp_app_user_insert`). Los parámetros llevan el prefijo `p_` y las variables locales `v_`.
- **Resultados.** El SP termina con un `SELECT`:
  - Si devuelve una fila que se hidrata como entidad, incluye **todas** sus columnas con el nombre exacto del DDL y en su orden (lista explícita, no `SELECT *`).
  - Si devuelve otra cosa (un resultado, un reporte), sus columnas se documentan aquí y PHP la lee con un DTO.
  - No se usan parámetros `OUT`.
- **Errores de negocio:** `SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CODIGO'`, con un código estable en inglés y mayúsculas (`EMAIL_TAKEN`). `StoredProcedures` lo convierte en `ProcedureException::$businessCode`; el mensaje de MySQL nunca llega al cliente.
- **Transacciones: el SP es el responsable.** Si un caso de uso modifica varias filas, el SP hace `START TRANSACTION`/`COMMIT` y declara un `EXIT HANDLER FOR SQLEXCEPTION` que hace `ROLLBACK` y `RESIGNAL`. PHP no abre transacciones salvo que se documente aquí una excepción. Nunca se hace una llamada de red (Cloudinary, PayPal) con una transacción abierta.
- **Tipos en los parámetros** (`App\Database\StoredProcedures` los enlaza así):
  - IDs y `DECIMAL`: cadena.
  - `bool`: `0`/`1`.
  - `DateTimeImmutable`: UTC con formato `Y-m-d H:i:s.u`.
  - `null`: `PDO::PARAM_NULL`.
  - Enums: su valor.
  - Los `float` se rechazan.
- **Colación en comparaciones con columnas `ascii_bin`.** Los parámetros sin charset explícito usan la colación de la base (`utf8mb4_unicode_ci`, que no distingue mayúsculas). Para validar códigos como `role` se compara con `COLLATE utf8mb4_bin`.

## Instalación

Desde la raíz del repositorio, con MySQL encendido:

```
mysql -u root -p cursology
SOURCE app/database/procedures/install.sql;
SHOW PROCEDURE STATUS WHERE Db = 'cursology';
```

`install.sql` incluye todos los archivos. Cada SP nuevo se agrega ahí.

## Catálogo

### Autenticación

**`sp_auth_register(p_name, p_father_last_name, p_mother_last_name, p_gender, p_birthday, p_avatar, p_email, p_password_hash, p_role)`**

- Crea una cuenta `student` o `instructor`.
- `p_password_hash` es el resultado de `password_hash()`: el SP nunca recibe la contraseña.
- **Resultado:** la fila completa de `app_user` (22 columnas), que PHP lee con `AppUser::fromRow()`.
- **Errores:**
  - `INVALID_ROLE`: un rol distinto de `student`/`instructor`, incluido `admin`.
  - `EMAIL_TAKEN`: el email ya existe (error 1062 de `uq_user_email`).
- No abre transacción: es un único `INSERT`.

**`sp_auth_get_login_data(p_email)`**

- **Resultado:** 0 o 1 fila completa de `app_user`, incluido `password_hash`, para que PHP ejecute `password_verify()`.
- No modifica nada.

**`sp_auth_record_login(p_user_id, p_password_ok)`**

Registra el resultado de un intento de login en una transacción propia, después de que PHP ejecuta `password_verify()`.

- **`p_user_id` NULL** (email inexistente): inserta `login_attempt` con `unknown_user`.
- **Con usuario:** bloquea la fila con `SELECT ... FOR UPDATE` y luego:
  - Si `account_status <> 'active'` o `ban = 1`: registra `blocked`. Una contraseña correcta **no** reactiva la cuenta.
  - Si la contraseña es correcta: deja `consecutive_failed_logins = 0`, actualiza `last_login_at` y registra `success`.
  - Si es incorrecta: suma 1 al contador. Al llegar a 3, en el mismo `UPDATE` cambia `account_status = 'locked'` y actualiza `status_changed_at`, e inserta `user_account_event` (`locked`, sin actor). Registra `wrong_password` con el contador nuevo.
- **Resultado:** una fila `outcome, consecutive_failures, account_status` (`account_status` es NULL para `unknown_user`). PHP la lee con `App\Models\Dto\LoginResult`.
- **Error:** `USER_NOT_FOUND`, si el id no existe.

### Administración de cuentas

**`sp_admin_list_locked_users(p_admin_id)`**

- Solo un admin activo y sin ban; si no, `NOT_ADMIN`.
- **Resultado:** las cuentas con `account_status = 'locked'`, de la más reciente a la más antigua: `id, name, father_last_name, mother_last_name, email, role, avatar, ban, status_changed_at` (desde cuándo está bloqueada) y `last_attempt_at` (último intento en `login_attempt`). PHP las lee con `App\Models\Dto\LockedAccount`.
- No modifica nada.

**`sp_admin_unlock_user(p_actor_id, p_user_id, p_reason)`**

- Reactiva una cuenta bloqueada en una transacción propia, con la fila del usuario bloqueada por `SELECT ... FOR UPDATE`.
- Un solo `UPDATE` deja `account_status = 'active'`, `consecutive_failed_logins = 0` y actualiza `status_changed_at`. Tiene que ser un solo `UPDATE` porque así lo exige `ck_user_locked`.
- Inserta `user_account_event` (`reactivated`, con `p_actor_id` y `p_reason`). La contraseña no cambia.
- **`p_actor_id`:** el admin que la reactiva. NULL solo desde `scripts/unlock_account.php`, para cuando el bloqueado es el único admin.
- **Resultado:** la fila completa de `app_user` ya reactivada.
- **Errores:** `NOT_ADMIN`, `USER_NOT_FOUND` y `USER_NOT_LOCKED` (la cuenta no estaba bloqueada).

**`sp_admin_create_first(p_name, p_father_last_name, p_mother_last_name, p_gender, p_birthday, p_avatar, p_email, p_password_hash)`**

- Crea el **primer** administrador. Solo lo llama `scripts/create_admin.php`; ningún endpoint web.
- **Resultado:** la fila completa de `app_user`.
- **Errores:** `ADMIN_EXISTS` (ya hay un admin) y `EMAIL_TAKEN`.

### Usuarios

**`sp_user_get_by_id(p_user_id)`**

- **Resultado:** 0 o 1 fila completa de `app_user`.
- El middleware lo usa en cada petición con sesión para comprobar que la cuenta sigue `active` y sin ban.

**`sp_user_update_profile(p_user_id, p_name, p_father_last_name, p_mother_last_name, p_gender, p_birthday, p_description, p_email)`**

- Actualiza los datos personales y el email. PHP ya verificó la contraseña actual si el email cambió.
- **Resultado:** la fila completa de `app_user`.
- **Errores:** `EMAIL_TAKEN` y `USER_NOT_AVAILABLE` (la cuenta ya no está activa).

**`sp_user_update_avatar(p_user_id, p_avatar)`** y **`sp_user_change_password(p_user_id, p_password_hash)`**

- Cambian la foto (URL de Cloudinary o el avatar por defecto) o el hash de la contraseña. PHP borra la foto anterior y verifica la contraseña actual.
- **Resultado:** la fila completa de `app_user`.
- **Error:** `USER_NOT_AVAILABLE`.

### Categorías

Las que escriben solo las puede usar un admin activo y sin ban (si no, `NOT_ADMIN`).

| SP | Qué hace | Errores |
|---|---|---|
| `sp_category_list(p_include_inactive)` | Categorías por nombre; con `0`, solo las activas | — |
| `sp_category_create(p_admin_id, p_name, p_description)` | Crea una categoría activa | `NOT_ADMIN`, `CATEGORY_NAME_TAKEN` |
| `sp_category_update(p_admin_id, p_category_id, p_name, p_description)` | Cambia nombre y descripción | `NOT_ADMIN`, `CATEGORY_NOT_FOUND`, `CATEGORY_NAME_TAKEN` |
| `sp_category_set_active(p_admin_id, p_category_id, p_active)` | Activa o desactiva | `NOT_ADMIN`, `CATEGORY_NOT_FOUND`, `CATEGORY_IN_USE` |

- `CATEGORY_NAME_TAKEN` no distingue mayúsculas: viene de la colación de `uq_category_name`.
- `CATEGORY_IN_USE`: no se puede desactivar si algún curso publicado y activo se quedaría sin categoría activa.
- **Resultado:** la fila de `category`.

### Cursos

Todos comprueban que el curso sea del instructor. Si no, responden igual que si no existiera (`COURSE_NOT_FOUND`, `LEVEL_NOT_FOUND`, `CHAPTER_NOT_FOUND` o `CONTENT_NOT_FOUND`), para no revelar qué ids existen.

**`sp_course_create(p_instructor_id, p_title, p_description, p_cover_image, p_onboarding_video, p_certifier_name, p_full_course_price, p_category_ids)`**

- Crea el curso en **borrador** (`published_at` NULL) con sus categorías, en una transacción.
- `p_category_ids` es un arreglo JSON que se lee con `JSON_TABLE`.
- **Errores:** `NOT_INSTRUCTOR` y `CATEGORY_NOT_AVAILABLE` (alguna no existe o está inactiva).

**`sp_course_update(...)`** (mismos parámetros más `p_course_id`)

- `p_cover_image` / `p_onboarding_video` NULL = conservar los actuales.
- Las categorías que se quitan quedan con `active = 0`, para conservar el historial.
- **Forma de cobro** (`p_full_course_price`): NULL = por niveles, 0 = gratis, mayor a 0 = curso completo + niveles.
  - Al pasar a gratis, pone `cost = 0` en todos los niveles.
  - Si el curso está publicado y tiene alumnos, el modelo no puede cambiar (`PRICING_LOCKED`); los importes sí.
- **Resultados:** 1) la fila de `course`; 2) `media_refs` con la portada y el video reemplazados.
- **Errores:** `COURSE_NOT_FOUND`, `CATEGORY_NOT_AVAILABLE` y `PRICING_LOCKED`.

**`sp_course_list_by_instructor(p_instructor_id)`**

- Una fila por curso (borrador, publicado o dado de baja), que lee `App\Models\Dto\InstructorCourseSummary`.
- Trae `level_count`, `chapter_count`, `student_count` y `level_price_total` (la suma de los precios de los niveles).

**`sp_course_get_for_owner(p_instructor_id, p_course_id)`**

Cinco result sets, que agrupa `App\Models\Dto\CourseOutline`:
1. el curso, con `student_count` y `active_category_count`;
2. sus `course_category` activas;
3. los niveles por orden;
4. los capítulos por nivel y orden;
5. el contenido, con el video principal primero.

**`sp_course_publish(p_instructor_id, p_course_id)`**

- Es la última palabra sobre si se puede publicar; la lista del editor (`PublishChecklist`) solo guía.
- Comprueba, en orden:
  - `COURSE_INACTIVE` (dado de baja) y `COURSE_ALREADY_PUBLISHED`;
  - `NO_ACTIVE_CATEGORY`;
  - `NO_LEVELS`;
  - `LEVEL_WITHOUT_CHAPTERS`;
  - `CHAPTER_WITHOUT_VIDEO`.
- Pone `published_at = NOW(6)`.
- **Resultado:** la fila de `course`.

**`sp_course_unpublish(p_instructor_id, p_course_id)`**

- Regresa el curso a borrador (`published_at` NULL).
- **Errores:** `COURSE_NOT_PUBLISHED` y `COURSE_HAS_STUDENTS` (con alumnos no se puede; se da de baja).

**`sp_course_set_active(p_instructor_id, p_course_id, p_active)`**

- Baja lógica (`active = 0`, `deactivated_at`) o reactivación, como exige `ck_course_deactivated`.
- Conserva `published_at`, así que al reactivarlo vuelve a como estaba.
- Los alumnos conservan su acceso.
- Repetir la operación no cambia nada.

### Niveles, capítulos y contenido

- **Numeración:** los que crean asignan `sort_order = máximo + 1`; los que borran renumeran para que no queden huecos.
- **Bloqueos:** los de niveles bloquean el curso y los de capítulos bloquean el nivel, con `FOR UPDATE`.
- **`media_refs`:** los que borran o reemplazan archivos lo devuelven, y PHP borra esos archivos de Cloudinary después del commit (ver [media.md](media.md)).

| SP | Qué hace | Errores |
|---|---|---|
| `sp_level_create(p_instructor_id, p_course_id, p_title, p_description, p_onboarding_video, p_cost)` | Agrega un nivel al final (ver notas abajo) | `COURSE_NOT_FOUND` |
| `sp_level_update(p_instructor_id, p_level_id, p_title, p_description, p_onboarding_video, p_cost)` | Video NULL = conservar; en curso gratis, `cost = 0`. Devuelve el nivel y `media_refs` | `LEVEL_NOT_FOUND` |
| `sp_level_delete(p_instructor_id, p_level_id)` | Solo si está vacío. Devuelve `media_refs` | `LEVEL_NOT_FOUND`, `LEVEL_NOT_EMPTY`, `HAS_ENROLLMENTS` |
| `sp_level_move(p_instructor_id, p_level_id, p_direction)` | Sube o baja un lugar (`'up'` / `'down'`) | `LEVEL_NOT_FOUND`, `INVALID_DIRECTION` |
| `sp_chapter_create(p_instructor_id, p_level_id, p_title)` | Capítulo de video al final del nivel | `LEVEL_NOT_FOUND` |
| `sp_chapter_update(p_instructor_id, p_chapter_id, p_title)` | Cambia el título | `CHAPTER_NOT_FOUND` |
| `sp_chapter_delete(p_instructor_id, p_chapter_id)` | Borra el capítulo y su contenido. Devuelve `media_refs` | `CHAPTER_NOT_FOUND`, `HAS_ENROLLMENTS` |
| `sp_chapter_move(p_instructor_id, p_chapter_id, p_direction)` | Sube o baja un lugar dentro del nivel | `CHAPTER_NOT_FOUND`, `INVALID_DIRECTION` |
| `sp_content_add(p_instructor_id, p_chapter_id, p_title, p_content_type, p_content_uri, p_text_content, p_mime_type, p_original_filename, p_size_bytes, p_duration_seconds, p_is_main)` | Agrega el video principal o un recurso | `CHAPTER_NOT_FOUND`, `MAIN_VIDEO_EXISTS` |
| `sp_content_delete(p_instructor_id, p_content_id)` | Quita el contenido. Devuelve `media_refs` | `CONTENT_NOT_FOUND` |

- **`sp_level_create`:** en un curso gratis guarda `cost = 0`. En un curso publicado, da acceso al nivel nuevo (`user_course_level`) a quien tenga una compra `purchase_scope = 'course'` completada, porque el paquete incluye los niveles futuros.
- **`*_move`:** intercambian el lugar con el vecino pasando por un valor temporal (máximo + 1), porque `uq_*_order` no admite órdenes repetidos y `ck_*_order` exige que sean al menos 1. En el extremo no cambian nada.
- **`MAIN_VIDEO_EXISTS`:** viene de `uq_content_main`, que permite un solo video principal por capítulo.
