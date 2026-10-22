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

### Usuarios

**`sp_user_get_by_id(p_user_id)`**

- **Resultado:** 0 o 1 fila completa de `app_user`.
- El middleware lo usa en cada petición con sesión para comprobar que la cuenta sigue `active` y sin ban.
