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
- **CSRF:** toda petición que modifica (`POST`, `PUT`, `PATCH`, `DELETE`) exige el header `X-CSRF-Token`.
  - En las páginas, el token está en `<meta name="csrf-token">`.
  - Fuera del navegador (Postman, curl) se obtiene con `GET /api/auth/csrf`, conservando la cookie de sesión.
  - El token cambia al iniciar sesión.
- **CORS:** solo los orígenes listados en `APP_ALLOWED_ORIGINS` (`.env`, separados por coma) reciben cabeceras CORS (`Allow-Credentials`, métodos `GET, POST, PUT, PATCH, DELETE, OPTIONS`, headers `Accept, Content-Type, X-CSRF-Token`). Vacío significa que solo el propio sitio puede leer las respuestas. Las preflight `OPTIONS` responden `204`.

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

### Rutas con `{id}`, roles y reglas de negocio

- `{id}` solo acepta enteros positivos de hasta 20 dígitos; cualquier otra cosa es `404 NOT_FOUND`.
- Las rutas `/api/admin/*` exigen rol admin y las `/api/instructor/*` y `/api/media/*`, rol instructor: `403 FORBIDDEN` si no.
- Las reglas de negocio que señala un SP llegan como `{"error": {"code", "message", "fields"?}}` con su estado (`404` no existe, `409` conflicto, `422` dato inválido). Algunos códigos se atribuyen a un campo del formulario (`fields`).
- **Recursos de otro instructor:** responden `404` (`COURSE_NOT_FOUND`, `LEVEL_NOT_FOUND`, `CHAPTER_NOT_FOUND`, `CONTENT_NOT_FOUND`), igual que si no existieran, para no revelar qué ids existen.

### Perfil (`/api/users/me`)

Requieren sesión y `X-CSRF-Token`.

| Endpoint | Cuerpo | Respuesta |
|---|---|---|
| `PATCH /api/users/me` | JSON: `nombre, apellido_paterno, apellido_materno, genero, fecha_nacimiento, descripcion, email` y, si cambia el email, `current_password` | `200 {"data": {"user"}}` |
| `POST /api/users/me/avatar` | `multipart/form-data`, campo `foto` (JPG, PNG o WEBP, 5 MB) | `200 {"data": {"user"}}`; la foto anterior se borra de Cloudinary |
| `DELETE /api/users/me/avatar` | — | `200 {"data": {"user"}}` con el avatar por defecto |
| `PUT /api/users/me/password` | JSON: `current_password, new_password, confirm_password` | `204`; la sesión se regenera |

Errores: `422 VALIDATION_FAILED` por campo (contraseña actual incorrecta va en `current_password`), `409 EMAIL_TAKEN` en `email`, `502 MEDIA_UPLOAD_FAILED` / `503 MEDIA_UNAVAILABLE` si Cloudinary falla o no está configurado.

### Categorías

| Endpoint | Acceso | Cuerpo | Respuesta |
|---|---|---|---|
| `GET /api/categories` | Público | — | `{"data": {"categories": [...]}}`, solo activas |
| `GET /api/admin/categories` | Admin | — | Todas, activas e inactivas |
| `POST /api/admin/categories` | Admin + CSRF | `{nombre, descripcion?}` | `201 {"data": {"category"}}` |
| `PATCH /api/admin/categories/{id}` | Admin + CSRF | `{nombre, descripcion}` y/o `{activa: bool}` | `200 {"data": {"category"}}` |

Cada categoría: `{id, name, description, active, updatedAt}`. Errores: `409 CATEGORY_NAME_TAKEN` (en `nombre`), `404 CATEGORY_NOT_FOUND`, `409 CATEGORY_IN_USE` (desactivarla dejaría un curso publicado sin categoría activa).

### Subidas a Cloudinary (`POST /api/media/upload-ticket`)

Instructor + CSRF. Cuerpo `{"purpose": "course_cover"}`. Responde los campos firmados para que el navegador suba el archivo directo a Cloudinary:

```json
{"data": {"upload_url": "https://api.cloudinary.com/v1_1/<cloud>/video/upload",
  "fields": {"folder": "cursology/courses/u7/content", "type": "authenticated", "timestamp": "...", "api_key": "...", "signature": "..."},
  "resource_type": "video", "max_bytes": 104857600, "formats": ["mp4", "webm", "mov"]}}
```

Después, el navegador manda al endpoint del curso `{public_id, version, signature, original_filename}` tal como respondió Cloudinary, y el servidor lo verifica. Propósitos, límites y formatos: [media.md](media.md).

### Cursos del instructor (`/api/instructor/...`)

Instructor; los que modifican, además con CSRF. Los archivos se mandan como el objeto de la subida verificada (ver arriba).

| Endpoint | Cuerpo | Respuesta |
|---|---|---|
| `GET /api/instructor/courses` | — | `{"data": {"courses": [...]}}`: `id, title, coverImage, pricingMode, fullCoursePrice, levelPriceTotal, currency, status, levels, chapters, students, updatedAt` |
| `POST /api/instructor/courses` | `titulo, descripcion, certificador?, categorias[], precio_modo, precio?, portada, video` | `201 {"data": {"course"}}` como borrador |
| `GET /api/instructor/courses/{id}` | — | El curso con `categoryIds` y `levels[].chapters[]` (cada capítulo con `mainVideo` y `resources`) |
| `PATCH /api/instructor/courses/{id}` | Igual que al crear; `portada`/`video` `null` = conservar | `200 {"data": {"course"}}` |
| `POST /api/instructor/courses/{id}/publish` | — | `200` con `status: "published"` |
| `POST /api/instructor/courses/{id}/unpublish` | — | `200` con `status: "draft"` |
| `PATCH /api/instructor/courses/{id}/status` | `{activo: bool}` | `200` con `status: "inactive"` o como estaba al reactivarlo |
| `POST /api/instructor/courses/{id}/levels` | `titulo, descripcion, costo, video` | `201 {"data": {"level"}}` al final del curso |
| `PATCH /api/instructor/levels/{id}` | `titulo, descripcion, costo, video?` (`null` = conservar) | `200 {"data": {"level"}}` |
| `DELETE /api/instructor/levels/{id}` | — | `204` (solo sin capítulos) |
| `POST /api/instructor/levels/{id}/move` | `{direccion: "arriba" \| "abajo"}` | `200 {"data": {"level"}}` |
| `POST /api/instructor/levels/{id}/chapters` | `{titulo}` | `201 {"data": {"chapter"}}` al final del nivel |
| `PATCH /api/instructor/chapters/{id}` | `{titulo}` | `200 {"data": {"chapter"}}` |
| `DELETE /api/instructor/chapters/{id}` | — | `204`; borra su contenido y sus archivos |
| `POST /api/instructor/chapters/{id}/move` | `{direccion: "arriba" \| "abajo"}` | `200 {"data": {"chapter"}}` |
| `POST /api/instructor/chapters/{id}/contents` | `tipo` y, según el tipo, `archivo`, `url` o `texto`; `titulo` | `201 {"data": {"content"}}` con su `url` (temporal si es protegido) |
| `DELETE /api/instructor/contents/{id}` | — | `204`; borra el archivo de Cloudinary |

**Forma de cobro (`precio_modo`):**

| Valor | `fullCoursePrice` | Niveles |
|---|---|---|
| `gratis` | `"0.00"` | Todos en 0; el `costo` que llegue se ignora |
| `niveles` | `null` | Cada uno con su precio; puede haber niveles de 0 (muestra gratis) |
| `paquete` | Importe mayor a 0 (`precio`) | Cada uno con su precio; el paquete incluye los niveles que se agreguen después |

- Pasar a `gratis` pone en 0 los niveles existentes.
- Con el curso publicado y con alumnos, la forma de cobro ya no cambia (`409 PRICING_LOCKED`, en `precio_modo`); los importes sí.

**Tipos de contenido (`tipo`):** `video_principal` (uno por capítulo, `409 MAIN_VIDEO_EXISTS`), `documento`, `imagen`, `audio`, `archivo` (con `archivo`), `enlace` (con `url` http/https y `titulo`) y `texto` (con `texto` y `titulo`). En los archivos, sin `titulo` se usa el nombre original.

**Publicar:** el curso necesita, en este orden, estar activo (`409 COURSE_INACTIVE`), no estar publicado (`409 COURSE_ALREADY_PUBLISHED`), una categoría activa (`422 NO_ACTIVE_CATEGORY`), al menos un nivel (`422 NO_LEVELS`), cada nivel con al menos un capítulo (`422 LEVEL_WITHOUT_CHAPTERS`) y cada capítulo con su video principal (`422 CHAPTER_WITHOUT_VIDEO`). Volver a borrador solo sin alumnos (`409 COURSE_HAS_STUDENTS`; si los tiene, se da de baja). Un borrador no se "despublica" (`409 COURSE_NOT_PUBLISHED`).

**Otros errores:** `409 LEVEL_NOT_EMPTY` (borrar un nivel con capítulos), `409 HAS_ENROLLMENTS` (borrar un nivel o capítulo al que ya tienen acceso alumnos), `422 CATEGORY_NOT_AVAILABLE` (en `categorias`), `422 VALIDATION_FAILED` (`direccion`, `activo` y los campos del formulario).

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

Si las subidas responden `502` (`MEDIA_UPLOAD_FAILED`) en la red de la empresa, revisa [media.md](media.md), sección "Certificados detrás de un proxy (Zscaler)".

## Probar

```powershell
# Sin base de datos ni red
php tests/verify_model_mapping.php
php tests/verify_auth_unit.php
php tests/verify_profile_courses_unit.php

# Contra la base local (con los SP instalados; crean cuentas, categorías y cursos de prueba por corrida)
php tests/verify_auth_db.php
php tests/verify_profile_courses_db.php

# Contra Cloudinary real (opcional; sube y borra archivos de prueba)
php tests/verify_cloudinary_live.php
```

Con curl.exe y el servidor levantado (`php -S localhost:8000 -t public`):

```powershell
curl.exe -s -c jar.txt -b jar.txt http://localhost:8000/api/auth/csrf
# copia data.token y úsalo en X-CSRF-Token:
curl.exe -s -c jar.txt -b jar.txt -H "X-CSRF-Token: <token>" -F rol=student -F nombre=Ana -F apellido_paterno=Lopez -F genero=femenino -F fecha_nacimiento=2000-05-17 -F email=ana@example.com -F "password=Segura#2026" http://localhost:8000/api/auth/register
curl.exe -s -c jar.txt -b jar.txt http://localhost:8000/api/auth/me
```
