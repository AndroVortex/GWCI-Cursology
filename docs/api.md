# API de Cursology

API JSON propia, servida por `public/index.php`. Todo lo que empieza con `/api/` lo atiende el router; el resto son vistas.

- **Con `php -S localhost:8000 -t public`:** las rutas `/api/...` llegan solas a `index.php`.
- **Con Apache (Laragon):** lo hace `public/.htaccess`.

Si la app vive en una subcarpeta, las rutas son relativas a ella; el frontend usa URLs relativas (`api/auth/login`).

## Convenciones

- **Respuestas:** `Content-Type: application/json; charset=utf-8`, con `Cache-Control: no-store`.
  - Éxito: `{"data": ...}`.
  - Error: `{"error": {"code": "CODIGO", "message": "Texto para mostrar", "fields": {"campo": "mensaje"}}}`. `fields` solo aparece en errores de campos, con los mismos nombres que el formulario.
  - `204`: sin cuerpo.
- **Sesión:** cookie `CURSOLOGYSESSID` (`HttpOnly`, `SameSite=Lax`, `Secure` con HTTPS). La sesión solo guarda el id del usuario; en cada petición se vuelve a comprobar que la cuenta siga activa y sin ban.
- **CSRF:** toda petición `POST` exige el header `X-CSRF-Token`.
  - En las páginas, el token está en `<meta name="csrf-token">`.
  - Fuera del navegador (Postman, curl) se obtiene con `GET /api/auth/csrf`, conservando la cookie de sesión.
  - El token cambia al iniciar sesión.
- **CORS:** solo los orígenes listados en `APP_ALLOWED_ORIGINS` (`.env`, separados por coma) reciben cabeceras CORS (`Allow-Credentials`, métodos `GET, POST, OPTIONS`, headers `Accept, Content-Type, X-CSRF-Token`). Vacío significa que solo el propio sitio puede leer las respuestas. Las preflight `OPTIONS` responden `204`.

### Errores comunes

| Código HTTP | `error.code` | Cuándo |
|---|---|---|
| 400 | `INVALID_JSON` | El cuerpo no es un objeto JSON válido. |
| 403 | `CSRF_INVALID` | Falta `X-CSRF-Token` o no coincide con la sesión. |
| 404 | `NOT_FOUND` | La ruta no existe. |
| 405 | `METHOD_NOT_ALLOWED` | El método no aplica a la ruta (incluye header `Allow`). |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | El `Content-Type` no es el que pide el endpoint. |
| 500 | `INTERNAL_ERROR` | Error inesperado. El detalle va al log del servidor, nunca a la respuesta. |

## Endpoints

### `GET /api/auth/csrf`

Devuelve el token CSRF de la sesión actual (la crea si no existe).

```json
200 {"data": {"token": "9f2c...64 caracteres hexadecimales"}}
```

### `POST /api/auth/register`

Crea una cuenta y deja la sesión iniciada. `multipart/form-data` (por la foto). Requiere `X-CSRF-Token`.

| Campo | Regla |
|---|---|
| `rol` | `student` o `instructor` (nunca `admin`). |
| `nombre`, `apellido_paterno` | Obligatorios, hasta 100 caracteres. |
| `apellido_materno` | Opcional, hasta 100 caracteres. |
| `genero` | `masculino`, `femenino` u `otro`. |
| `fecha_nacimiento` | `Y-m-d`, existente, no futura, desde 1900. |
| `email` | Válido, hasta 254 caracteres. Se guarda en minúsculas. |
| `password` | 8 o más caracteres, una mayúscula, un número y un carácter especial (máximo 72 bytes). |
| `foto` | Opcional. JPG, PNG o WEBP real (se revisa el contenido), máximo 5 MB. Sin foto se usa `img/avatar-default.svg`. |

Respuestas:

```json
201 {"data": {"user": {"id": "12", "name": "Ana", "fatherLastName": "López", "motherLastName": null,
                       "fullName": "Ana López", "email": "ana@example.com", "role": "student",
                       "avatar": "img/avatar-default.svg"}}}
422 {"error": {"code": "VALIDATION_FAILED", "message": "Revisa los campos marcados.",
               "fields": {"password": "La contraseña necesita: un número.", "rol": "Elige si quieres aprender o enseñar."}}}
409 {"error": {"code": "EMAIL_TAKEN", "message": "Ya existe una cuenta con ese email.", "fields": {"email": "..."}}}
503 {"error": {"code": "MEDIA_UNAVAILABLE", ...}}    (Cloudinary sin configurar y se envió foto)
502 {"error": {"code": "MEDIA_UPLOAD_FAILED", ...}}  (Cloudinary rechazó o no respondió)
```

### `POST /api/auth/login`

`application/json`. Requiere `X-CSRF-Token`.

```json
{"email": "ana@example.com", "password": "Segura#2026"}
```

| Código | `error.code` | Significado |
|---|---|---|
| 200 | — | `{"data": {"user": {...}}}` y sesión iniciada. |
| 401 | `INVALID_CREDENTIALS` | Email inexistente o contraseña incorrecta (mismo mensaje y mismo tiempo de respuesta). |
| 422 | `VALIDATION_FAILED` | Falta el email o la contraseña. |
| 423 | `ACCOUNT_LOCKED` | La cuenta se bloqueó por 3 contraseñas incorrectas **consecutivas** (en este intento o antes). Solo un administrador la reactiva. |
| 403 | `ACCOUNT_UNAVAILABLE` | La cuenta está dada de baja o baneada. |

Un login correcto reinicia el contador de fallos. Todos los intentos quedan en `login_attempt`, y el bloqueo, en `user_account_event`.

### `POST /api/auth/logout`

Cierra la sesión. Requiere `X-CSRF-Token`.

- `204`: sin cuerpo.

### `GET /api/auth/me`

Requiere sesión (middleware `RequireAuth`).

- `200`: `{"data": {"user": {...}}}`.
- `401` (`UNAUTHENTICATED`): sin sesión, o si la cuenta se bloqueó o se baneó después de iniciar sesión.

### `GET /api/admin/users/locked`

Cuentas bloqueadas por 3 contraseñas incorrectas consecutivas, de la más reciente a la más antigua. Requiere sesión de **admin** (`RequireAuth` + `RequireRole`).

- `200`:

```json
{"data": {"accounts": [{
  "id": "13", "fullName": "Ana López", "email": "ana@example.com", "role": "student",
  "avatar": "img/avatar-default.svg", "banned": false,
  "lockedAt": "2026-10-21T21:28:38+00:00", "lastAttemptAt": "2026-10-21T21:30:02+00:00"
}]}}
```

- `401` sin sesión; `403` (`FORBIDDEN`) si no es admin.

### `POST /api/admin/users/{id}/unlock`

Reactiva la cuenta: vuelve a `active` con el contador de fallos en 0 y entra con **su misma contraseña**. Queda el evento `reactivated` en `user_account_event`, con el admin como actor. Requiere sesión de admin y `X-CSRF-Token`; no lleva cuerpo.

| Estado | `code` | Cuándo |
|---|---|---|
| 200 | — | `{"data": {"user": {...}}}` con la cuenta reactivada. |
| 403 | `FORBIDDEN` / `CSRF_INVALID` | No es admin, o falta el token. |
| 404 | `USER_NOT_FOUND` | El id no existe. |
| 409 | `USER_NOT_LOCKED` | La cuenta ya no estaba bloqueada. |

En el panel del admin (`?view=dashboard`), la tabla "Cuentas bloqueadas" usa este endpoint con el botón **Reactivar**.

Si la cuenta bloqueada es la del único admin, nadie puede reactivarla desde el panel; se hace desde la consola:

```powershell
php scripts/unlock_account.php --email=admin@cursology.local
```

## Configuración (`.env`)

```
CLOUDINARY_CLOUD_NAME=
CLOUDINARY_API_KEY=
CLOUDINARY_API_SECRET=
APP_ALLOWED_ORIGINS=
```

Sin las credenciales de Cloudinary, el registro sin foto funciona y el registro con foto responde `503`. Las fotos se suben a la carpeta `cursology/avatars` con una petición firmada desde el servidor; el secret nunca llega al navegador.

### Certificados detrás de un proxy (Zscaler)

Si las subidas responden `502` (`MEDIA_UPLOAD_FAILED`, "No se pudo procesar el archivo") y en `C:\laragon\tmp\php_errors.log` aparece `unable to get local issuer certificate (20)`, un proxy que inspecciona HTTPS (como Zscaler en la red de la empresa) está volviendo a firmar el certificado de Cloudinary con su propia raíz.

- **El navegador sí confía en esa raíz** porque está instalada en Windows; por eso la subida directa desde el navegador funciona.
- **El `cacert.pem` de PHP no la trae**, así que fallaban las llamadas del servidor: subir el avatar y verificar la portada y los videos con la Admin API.
- **Corrección:** `CloudinaryClient::tlsOptions()` agrega `CURLSSLOPT_NATIVE_CA` en Windows, para que curl use también los certificados de Windows. **La verificación SSL sigue activa:** nunca se desactiva `CURLOPT_SSL_VERIFYPEER`. En Linux (producción) no cambia nada.
- **Comprobarlo:** `php tests/verify_cloudinary_live.php` sube, verifica y borra archivos reales.

## Probar

```powershell
# Sin base de datos
php tests/verify_auth_unit.php

# Contra la base local (con los SP instalados; crea 2 cuentas de prueba por corrida)
php tests/verify_auth_db.php
```

Con curl.exe y el servidor levantado (`php -S localhost:8000 -t public`):

```powershell
curl.exe -s -c jar.txt -b jar.txt http://localhost:8000/api/auth/csrf
# copia data.token y úsalo en X-CSRF-Token:
curl.exe -s -c jar.txt -b jar.txt -H "X-CSRF-Token: <token>" -F rol=student -F nombre=Ana -F apellido_paterno=Lopez -F genero=femenino -F fecha_nacimiento=2000-05-17 -F email=ana@example.com -F "password=Segura#2026" http://localhost:8000/api/auth/register
curl.exe -s -c jar.txt -b jar.txt http://localhost:8000/api/auth/me
```
