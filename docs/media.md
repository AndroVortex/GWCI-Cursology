# Multimedia en Cloudinary

Toda la multimedia (avatares, portadas, videos y archivos de los cursos) vive en Cloudinary. La base de datos solo guarda una **referencia**. El cliente es [`app/integrations/CloudinaryClient.php`](../app/integrations/CloudinaryClient.php) (sin SDK) y las reglas están en [`app/services/MediaService.php`](../app/services/MediaService.php).

## Dos formas de subir

- **Desde el servidor (avatar).** El formulario manda la foto a PHP (`multipart/form-data`). PHP valida el tipo real con `finfo` y la sube con una petición firmada. Va a la carpeta `cursology/avatars`.
- **Directo desde el navegador (cursos).** Los videos pueden pesar hasta 100 MB, así que no pasan por PHP:
  1. El navegador pide un ticket: `POST /api/media/upload-ticket {purpose}`. El servidor firma `folder`, `timestamp`, `type` y, salvo en `raw`, `allowed_formats`.
  2. El navegador sube el archivo a Cloudinary con esos campos (`media-upload.js`, con barra de progreso).
  3. El navegador manda al servidor `{public_id, version, signature, original_filename}`.
  4. `MediaService::confirm()` comprueba:
     - que el `public_id` esté en la carpeta del usuario (`cursology/courses/u<id>/...`), para que nadie reclame un archivo ajeno;
     - la firma de la respuesta: `sha1("public_id=<id>&version=<versión>" + secret)`;
     - con la Admin API, el formato, el tamaño y la duración reales.

     Si algo no cuadra, borra el archivo y responde `422`.

El API secret nunca sale del servidor.

## Propósitos

| `purpose` | Carpeta | Tipo de recurso | Entrega | Formatos | Máximo |
|---|---|---|---|---|---|
| `course_cover` | `covers` | `image` | pública (`upload`) | jpg, jpeg, png, webp | 5 MB |
| `course_video` | `promos` | `video` | pública | mp4, webm, mov | 100 MB |
| `level_video` | `levels` | `video` | pública | mp4, webm, mov | 100 MB |
| `chapter_video` | `content` | `video` | protegida (`authenticated`) | mp4, webm, mov | 100 MB |
| `chapter_document` | `content` | `raw` | protegida | pdf, doc(x), ppt(x), xls(x), txt | 10 MB |
| `chapter_image` | `content` | `image` | protegida | jpg, jpeg, png, webp, gif | 10 MB |
| `chapter_audio` | `content` | `video` | protegida | mp3, wav, m4a, ogg | 50 MB |
| `chapter_archive` | `content` | `raw` | protegida | zip, rar, 7z | 10 MB |

- **Públicos:** la portada y los videos de presentación los ve cualquiera en el catálogo.
- **Protegidos:** el contenido de los capítulos solo lo deben ver el instructor y los alumnos con acceso.
- **Formatos en `raw`:** Cloudinary no filtra `allowed_formats` en `raw`, así que el formato se revisa en `confirm()`.

## Qué se guarda en la base

- **Público:** la `secure_url` (`https://res.cloudinary.com/<cloud>/image/upload/v.../cursology/...`).
- **Protegido:** `cloudinary:<type>/<resource_type>/<public_id>`, por ejemplo `cloudinary:authenticated/video/cursology/courses/u7/content/abc123`. Nunca es una URL.
- **En `course_chapter_content` se guardan además** el MIME (según el formato real), el nombre original sin rutas, el tamaño en bytes y la duración en segundos. Todos los da Cloudinary, no el navegador.

Para mostrar algo protegido, `MediaService::viewUrl()` genera una URL temporal y firmada (`/download?...&expires_at=...`, válida 1 hora). Se genera en el servidor solo después de comprobar el acceso.

## Duración de videos y audios

La Admin API solo devuelve `duration` si se pide `media_metadata=true`. `CloudinaryClient::resource()` lo agrega para `resource_type = video`, que incluye los audios.

El contenido subido antes de esta corrección tiene la duración en NULL (se muestra "—") hasta que se vuelva a subir.

## Borrar archivos

Los SP que borran o reemplazan contenido devuelven `media_refs`, un arreglo JSON con las referencias de los archivos que dejaron de usarse:

- `sp_course_update`, `sp_level_update`: la portada o el video reemplazados;
- `sp_level_delete`, `sp_chapter_delete`, `sp_content_delete`: los archivos del contenido borrado.

PHP los borra de Cloudinary **después** del commit, con `MediaService::discard()`. Solo borra archivos bajo `cursology/courses/`; un enlace externo se ignora.

Si un SP falla después de subir un archivo nuevo, se borra lo recién subido para no dejar huérfanos.

## Certificados detrás de un proxy (Zscaler)

**Síntoma:** las subidas responden `502` (`MEDIA_UPLOAD_FAILED`, "No se pudo procesar el archivo") y `C:\laragon\tmp\php_errors.log` muestra `unable to get local issuer certificate (20)`.

**Causa:** un proxy que inspecciona HTTPS, como Zscaler en la red de la empresa, vuelve a firmar el certificado de Cloudinary con su propia raíz.
- **El navegador sí confía en esa raíz** porque está instalada en Windows; por eso la subida directa funciona.
- **El `cacert.pem` de PHP no la trae**, así que fallan las llamadas del servidor: subir el avatar y verificar la portada y los videos con la Admin API.

**Corrección:** `CloudinaryClient::tlsOptions()` agrega `CURLSSLOPT_NATIVE_CA` en Windows, para que curl use también los certificados de Windows.
- **La verificación SSL sigue activa:** nunca se desactiva `CURLOPT_SSL_VERIFYPEER`.
- En Linux (producción) no cambia nada.
- Si curl falla por certificado, el mensaje del log incluye una pista.

**Comprobarlo:** `php tests/verify_cloudinary_live.php` sube, verifica y borra archivos reales, incluido un video para la duración.
