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

Siete result sets, que agrupa `App\Models\Dto\CourseOutline`:
1. el curso, con `student_count` y `active_category_count`;
2. sus `course_category` activas;
3. los niveles por orden;
4. los capítulos por nivel y orden;
5. el contenido, con el video principal primero;
6. las preguntas de los exámenes, por orden;
7. sus opciones, con las claves (`is_correct`, `weight`). Este SP es solo para el dueño.

**`sp_course_publish(p_instructor_id, p_course_id)`**

- Es la última palabra sobre si se puede publicar; la lista del editor (`PublishChecklist`) solo guía.
- Comprueba, en orden:
  - `COURSE_INACTIVE` (dado de baja) y `COURSE_ALREADY_PUBLISHED`;
  - `NO_ACTIVE_CATEGORY`;
  - `NO_LEVELS`;
  - `LEVEL_WITHOUT_CHAPTERS`;
  - `CHAPTER_WITHOUT_VIDEO` (solo capítulos de video);
  - `EXAM_WITHOUT_QUESTIONS`.
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
| `sp_chapter_create(p_instructor_id, p_level_id, p_title, p_is_test, p_minimum_passing_grade)` | Capítulo de video o examen (con su mínimo de más de 0 a 100) al final del nivel | `LEVEL_NOT_FOUND`, `INVALID_PASSING_GRADE` |
| `sp_chapter_update(p_instructor_id, p_chapter_id, p_title, p_minimum_passing_grade)` | Cambia el título y, en un examen, el mínimo (NULL = conservarlo) | `CHAPTER_NOT_FOUND`, `INVALID_PASSING_GRADE` |
| `sp_chapter_delete(p_instructor_id, p_chapter_id)` | Borra el capítulo y su contenido. Devuelve `media_refs` | `CHAPTER_NOT_FOUND`, `HAS_ENROLLMENTS` |
| `sp_chapter_move(p_instructor_id, p_chapter_id, p_direction)` | Sube o baja un lugar dentro del nivel | `CHAPTER_NOT_FOUND`, `INVALID_DIRECTION` |
| `sp_content_add(p_instructor_id, p_chapter_id, p_title, p_content_type, p_content_uri, p_text_content, p_mime_type, p_original_filename, p_size_bytes, p_duration_seconds, p_is_main)` | Agrega el video principal o un recurso | `CHAPTER_NOT_FOUND`, `CHAPTER_IS_EXAM`, `MAIN_VIDEO_EXISTS` |
| `sp_content_delete(p_instructor_id, p_content_id)` | Quita el contenido. Devuelve `media_refs` | `CONTENT_NOT_FOUND` |

- **`sp_level_create`:** en un curso gratis guarda `cost = 0`. En un curso publicado, da acceso al nivel nuevo (`user_course_level`) a quien tenga una compra `purchase_scope = 'course'` completada, porque el paquete incluye los niveles futuros.
- **`*_move`:** intercambian el lugar con el vecino pasando por un valor temporal (máximo + 1), porque `uq_*_order` no admite órdenes repetidos y `ck_*_order` exige que sean al menos 1. En el extremo no cambian nada.
- **`MAIN_VIDEO_EXISTS`:** viene de `uq_content_main`, que permite un solo video principal por capítulo.
- **`sp_chapter_delete`:** en un examen también borra sus preguntas y opciones. Si algún alumno ya lo presentó, el 1451 se convierte en `HAS_ENROLLMENTS`.

### Exámenes

Los exámenes son capítulos con `is_test = 1`. Sus preguntas (`course_chapter_test_question`) y opciones (`test_question_answer`) solo se manejan con estos SP, que bloquean el examen con `FOR UPDATE`.

| SP | Qué hace | Errores |
|---|---|---|
| `sp_question_create(p_instructor_id, p_chapter_id, p_question, p_selection_mode, p_answers)` | Agrega la pregunta al final del examen con sus opciones. Devuelve 1) la pregunta y 2) sus opciones | `CHAPTER_NOT_FOUND`, `CHAPTER_NOT_EXAM`, `INVALID_ANSWERS` |
| `sp_question_update(p_instructor_id, p_question_id, p_question, p_selection_mode, p_answers)` | Reemplaza el texto, el modo y las opciones | `QUESTION_NOT_FOUND`, `INVALID_ANSWERS`, `EXAM_HAS_ATTEMPTS` |
| `sp_question_delete(p_instructor_id, p_question_id)` | Borra la pregunta y sus opciones y renumera. Devuelve `course_chapter_id` | `QUESTION_NOT_FOUND`, `EXAM_HAS_ATTEMPTS` |
| `sp_question_move(p_instructor_id, p_question_id, p_direction)` | Sube o baja un lugar (valor temporal, como `sp_chapter_move`) | `QUESTION_NOT_FOUND`, `INVALID_DIRECTION` |
| `sp_exam_get_for_owner(p_instructor_id, p_chapter_id)` | 1) el capítulo, 2) sus preguntas, 3) sus opciones, para "Probar examen" (`ExamGrader`) | `CHAPTER_NOT_FOUND`, `CHAPTER_NOT_EXAM` |

- **`p_answers`:** arreglo JSON `[{"answer", "is_correct": 0|1, "weight"}]`, en el orden en que se muestran. Se lee con `JSON_TABLE` (`FOR ORDINALITY` da el `sort_order`).
- **Reglas que el SP vuelve a exigir:** de 2 a 8 opciones con texto; `single` con exactamente una correcta y `multiple` con al menos una; peso mayor a 0 en las correctas y 0 en las incorrectas, como pide `ck_answer_weight`. El modo se compara con `COLLATE utf8mb4_bin`.
- **`EXAM_HAS_ATTEMPTS`:** si la pregunta ya está en `test_attempt_question`, no se cambia ni se borra; las opciones las referencia `test_attempt_answer`.

### Catálogo público

Solo cursos con `active = 1` y `published_at` con fecha. No exponen las URIs del contenido de los capítulos ni las preguntas de los exámenes.

**`sp_catalog_search(p_query, p_category_id, p_sort, p_limit, p_offset)`**
- `p_query`: busca con `LIKE` en el título y en el nombre completo del instructor. PHP ya escapó `%`, `_` y `\`. La colación de la base no distingue mayúsculas ni acentos. NULL o vacío = todos.
- `p_category_id`: solo los que tienen esa categoría activa. NULL = todas.
- `p_sort`: `recientes` (publicación más nueva), `titulo` o `precio` (`full_course_price` o, si es NULL, la suma de los niveles).
- **Resultados:** 1) las tarjetas de la página (`id, title, cover_image, full_course_price, currency, published_at, instructor_name, category_names` separadas por `|`, `level_count, chapter_count, level_price_total`), que lee `App\Models\Dto\CatalogCourse`; 2) `total`.

**`sp_catalog_course(p_course_id)`**

Cuatro result sets, que lee `App\Models\Dto\PublicCourse`:
1. el curso con `instructor_name`, `instructor_avatar` e `instructor_description`;
2. las categorías activas;
3. los niveles por orden, con su precio y su video de presentación;
4. los capítulos, con `is_test`, `duration_seconds` del video principal y `question_count`.

**Error:** `COURSE_NOT_FOUND` si no existe, es borrador o está dado de baja.

### Formas de pago

| SP | Qué hace | Errores |
|---|---|---|
| `sp_payment_method_list(p_only_active)` | Filas de `payment_method` (todas o solo las activas) | — |
| `sp_payment_method_set_active(p_admin_id, p_code, p_name, p_active)` | Activa o desactiva una forma de pago; la crea la primera vez (`INSERT ... AS incoming ON DUPLICATE KEY UPDATE`). Devuelve la fila | `NOT_ADMIN`, `UNKNOWN_PAYMENT_METHOD` |

Solo existen los códigos `paypal` (PayPal Sandbox) y `simulated_card` (tarjeta simulada). Que PayPal además tenga credenciales lo decide PHP (`PaymentMethodService`).

### Inscripción y compras

Una compra (`purchase`) es por el curso completo (`purchase_scope = 'course'`) o por un nivel (`'level'`). El acceso real es `user_course_level`; `user_courses` es la inscripción. **El importe siempre lo pone el SP** (del curso o del nivel), nunca PHP.

| SP | Qué hace | Errores |
|---|---|---|
| `sp_purchase_grant(p_purchase_id)` | **Interno** (dentro de la transacción del que llama): para una compra completada, inscribe al alumno si hace falta y le da acceso al nivel, o a todos los niveles si es por el curso | — |
| `sp_enrollment_free(p_user_id, p_course_id)` | Curso gratis: compra de $0 por el curso completo. Curso de paga: compras de $0 por cada nivel gratis que le falte. Devuelve `course_id, levels_granted, enrolled` | `NOT_STUDENT`, `COURSE_NOT_FOUND`, `NOT_FREE`, `ALREADY_OWNED` |
| `sp_purchase_start(p_user_id, p_course_id, p_level_id, p_method_code, p_idempotency_key)` | Abre la compra pendiente con su importe y los nombres congelados (`*_snapshot`). Con una clave que ya usó el alumno, devuelve esa misma compra. `p_level_id` NULL = curso completo | `NOT_STUDENT`, `COURSE_NOT_FOUND`, `LEVEL_NOT_FOUND`, `PAYMENT_METHOD_UNAVAILABLE`, `NOT_FOR_SALE`, `ALREADY_OWNED` |
| `sp_purchase_attach_provider(p_user_id, p_purchase_id, p_transaction_id)` | Guarda el id de la orden de PayPal en la compra pendiente | `PURCHASE_NOT_FOUND`, `PURCHASE_NOT_PENDING` |
| `sp_purchase_get(p_user_id, p_purchase_id)` | La compra, solo si es del alumno | `PURCHASE_NOT_FOUND` |
| `sp_purchase_complete(p_user_id, p_purchase_id, p_transaction_id, p_amount, p_currency)` | Con la compra bloqueada (`FOR UPDATE`): exige que el importe y la moneda cobrados sean los de la compra, la completa y llama a `sp_purchase_grant`. Repetirla con la misma transacción no cambia nada | `PURCHASE_NOT_FOUND`, `PURCHASE_NOT_PENDING`, `PAYMENT_MISMATCH` |
| `sp_purchase_fail(p_user_id, p_purchase_id, p_status)` | Marca una compra pendiente como `failed` o `cancelled` | `PURCHASE_NOT_FOUND`, `PURCHASE_NOT_PENDING` |
| `sp_course_access(p_user_id, p_course_id)` | 1) `enrolled, owns_course`; 2) `course_level_id` de cada nivel con acceso (lo lee `CourseAccess`) | — |

- **Qué se vende:** solo cursos activos y publicados. Curso completo solo si `full_course_price > 0`; un nivel solo si el curso no es gratis y el nivel cuesta más de 0. Un curso dado de baja ya no se vende, pero quien lo compró conserva el acceso.
- **Niveles futuros:** `sp_level_create` da acceso al nivel nuevo a quien tenga una compra completada por el curso completo (incluidas las inscripciones a cursos gratis).
- **PayPal:** PHP llama a PayPal **sin transacción abierta**: `sp_purchase_start` → orden en Sandbox → `sp_purchase_attach_provider` → el comprador aprueba → captura → `sp_purchase_complete` con el importe que reportó PayPal.
- **Tarjeta simulada:** `provider = 'simulated_card'` y la transacción `SIM-<12 hex>-<últimos 4>`; nunca se guarda el número completo.
- `uq_purchase_provider (provider, provider_transaction_id)` impide usar la misma transacción en dos compras.

### Reproductor del alumno

| SP | Qué hace | Errores |
|---|---|---|
| `sp_learning_course(p_user_id, p_course_id)` | El curso para el reproductor (lo lee `LearningCourse`), aunque se haya dado de baja | `NOT_ENROLLED` |
| `sp_learning_progress(p_user_id, p_chapter_id, p_position_seconds, p_mark_done)` | Guarda la posición y el último acceso; termina el capítulo al 90% o con `p_mark_done` | `CHAPTER_NOT_FOUND`, `NO_LEVEL_ACCESS`, `EXAM_NOT_MARKABLE` |
| `sp_learning_recalculate(p_user_id, p_level_id)` | **Interno:** termina el nivel si todos sus capítulos lo están, y el curso si todos sus niveles lo están | — |

**`sp_learning_course`** devuelve cuatro result sets:
1. el curso con `instructor_name`, la inscripción (`enrolled_at, last_accessed_at, completed_at`) y `owns_course`;
2. los niveles con `has_access` y su `completed_at`;
3. los capítulos con `duration_seconds` del video principal, `question_count`, el avance del alumno (`video_position_seconds, last_accessed_at, completed_at`) y `best_grade`;
4. el contenido (columnas de `course_chapter_content`) **solo de los niveles con acceso**.

**`sp_learning_progress`:**
- La posición se recorta a la duración del video guardada en la base (no a la que diga el navegador).
- Visto = `posición × 10 ≥ duración × 9`, la misma regla que `LearningService::isWatched()`.
- Un examen solo registra la visita.
- Lo terminado no se desmarca.
- Devuelve `course_chapter_id, video_position_seconds, completed_at, level_completed_at, course_completed_at`.

**`sp_learning_recalculate`:** para terminar el curso, el alumno necesita acceso a todos los niveles. Si el instructor agrega un nivel después, el curso ya terminado no se desmarca.

### Intentos de examen

| SP | Qué hace | Errores |
|---|---|---|
| `sp_attempt_start(p_user_id, p_chapter_id)` | Abre un intento (o retoma el abierto) copiando las preguntas y **todas** las opciones a `test_attempt_question` / `test_attempt_answer` | `CHAPTER_NOT_FOUND`, `CHAPTER_NOT_EXAM`, `NO_LEVEL_ACCESS`, `EXAM_WITHOUT_QUESTIONS` |
| `sp_attempt_submit(p_user_id, p_attempt_id, p_selected)` | Marca las opciones elegidas, califica con la regla A y, si aprueba, termina el capítulo y llama a `sp_learning_recalculate` | `ATTEMPT_NOT_FOUND`, `ATTEMPT_ALREADY_SUBMITTED`, `INVALID_SELECTION` |
| `sp_attempt_get(p_user_id, p_attempt_id)` | El intento desde sus copias. Lo llaman `start` y `submit` para devolver siempre la misma forma (lo lee `ExamAttempt`) | `ATTEMPT_NOT_FOUND` |
| `sp_attempt_list(p_user_id, p_chapter_id)` | Los intentos del alumno, del más reciente al primero | `CHAPTER_NOT_FOUND`, `CHAPTER_NOT_EXAM`, `NO_LEVEL_ACCESS` |

- **Claves ocultas:** mientras el intento está abierto, `sp_attempt_get` **no devuelve** `is_correct_snapshot`, `weight_snapshot` ni `earned_points`; después de entregarlo, sí.
- **Uno abierto a la vez:** `sp_attempt_start` bloquea el acceso del alumno al nivel (`FOR UPDATE`) antes de buscar o crear el intento.
- **`p_selected`:** arreglo JSON con los ids de las opciones marcadas (puede estar vacío). Se lee con `JSON_TABLE` (`NULL ON ERROR`); un id ajeno o repetido, o dos opciones en una pregunta `single`, es `INVALID_SELECTION` y no se guarda nada.
- **Calificación (regla A, en SQL):**
  - por pregunta, `0` si se marcó alguna incorrecta; si no, la suma de `weight_snapshot` de las correctas marcadas;
  - `grade = ROUND(100 * earned_points / max_points, 2)`, la misma expresión que exige `ck_attempt_result`;
  - `passed` es la columna generada `grade >= minimum_grade_snapshot`;
  - `tests/verify_learning_db.php` comprueba que coincide con `ExamGrader`.

### Mi aprendizaje

**`sp_student_courses(p_user_id, p_from, p_to, p_category_id, p_status)`**

El kardex (lo lee `KardexEntry`), del curso visto más recientemente al más antiguo.

| Filtro | Valor |
|---|---|
| `p_from` / `p_to` | Fecha de inscripción, días completos |
| `p_category_id` | Cursos con esa categoría asignada |
| `p_status` | `in_progress`, `completed` o `active` (cursos que siguen a la venta) |

NULL en cualquier filtro = sin filtrar.

Columnas:
- `course_id, title, cover_image, active` y `category_names` (separadas por `|`);
- `enrolled_at, last_accessed_at, completed_at`;
- `chapter_count, chapters_done, level_count, levels_owned`;
- `last_chapter_id, last_chapter_title`: el capítulo con el `last_accessed_at` más reciente (`LEFT JOIN LATERAL`).

Error: `INVALID_KARDEX_STATUS`.

**`sp_student_purchases(p_user_id)`:** las columnas de `purchase`, de la más reciente a la más antigua. Incluye las de $0 y las rechazadas o canceladas.
