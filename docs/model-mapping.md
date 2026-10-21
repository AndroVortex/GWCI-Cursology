# Mapeo de modelos de Cursology

Este documento describe cómo las 29 tablas de la base `cursology` se representan como clases PHP, y qué falta para conectarlas con procedimientos almacenados (SP).

> **Estado:** las entidades se construyen a partir de filas que se les entregan; no abren conexiones ni ejecutan consultas. El acceso a datos vive en los repositorios, que llaman a SP mediante `App\Database\StoredProcedures`. El primer caso conectado es registro/login: ver [stored-procedures.md](stored-procedures.md) y [api.md](api.md).

Fuente de verdad del esquema: [`app/database/schema/Cursology_modelo_fisico_mysql.sql`](../app/database/schema/Cursology_modelo_fisico_mysql.sql) (copia sin cambios del DDL entregado).

## Estructura

| Ruta | Namespace | Contenido |
|---|---|---|
| `app/autoload.php` | — | Autoload mínimo con `spl_autoload_register` (sin Composer). |
| `app/models/entities/` | `App\Models\Entities` | Una entidad por tabla (29 clases). |
| `app/models/enums/` | `App\Models\Enums` | Enums con los valores exactos de los `CHECK ... IN`. |
| `app/models/mapping/` | `App\Models\Mapping` | `RowReader`, `HydrationException`, metadatos y `EntityMap`. |
| `tests/verify_model_mapping.php` | — | Verificación CLI sin base de datos. |

El autoload busca los segmentos del namespace en carpetas en minúsculas (convención del repo) y conserva el nombre de la clase en el archivo: `App\Models\Entities\AppUser` → `app/models/entities/AppUser.php`. Así funciona igual en Windows y en Linux. `public/index.php` ya lo carga.

La capa de modelo de MVC se completa con `app/repositories/` (persistencia mediante SP), `app/services/` (casos de uso) y `app/models/dto/` (resultados que no son filas completas). Los antiguos placeholders vacíos `user.php` se reemplazaron por esas clases.

## Correspondencia tabla → clase

| Tabla MySQL | Clase | Clave primaria |
|---|---|---|
| `app_user` | `AppUser` | `id` |
| `login_attempt` | `LoginAttempt` | `id` |
| `user_account_event` | `UserAccountEvent` | `id` |
| `category` | `Category` | `id` |
| `reaction` | `Reaction` | `id` |
| `payment_method` | `PaymentMethod` | `id` |
| `course` | `Course` | `id` |
| `course_category` | `CourseCategory` | `course_id, category_id` |
| `course_level` | `CourseLevel` | `id` |
| `course_chapter` | `CourseChapter` | `id` |
| `course_chapter_content` | `CourseChapterContent` | `id` |
| `course_chapter_test_question` | `CourseChapterTestQuestion` | `id` |
| `test_question_answer` | `TestQuestionAnswer` | `id` |
| `user_courses` | `UserCourse` | `user_id, course_id` |
| `purchase` | `Purchase` | `id` |
| `user_course_level` | `UserCourseLevel` | `user_id, course_level_id` |
| `user_chapter_progress` | `UserChapterProgress` | `user_id, course_chapter_id` |
| `test_attempt` | `TestAttempt` | `id` |
| `test_attempt_question` | `TestAttemptQuestion` | `attempt_id, question_id` |
| `test_attempt_answer` | `TestAttemptAnswer` | `attempt_id, question_id, answer_id` |
| `certificate` | `Certificate` | `id` |
| `course_rate` | `CourseRate` | `id` |
| `course_opinion` | `CourseOpinion` | `id` |
| `chapter_comment` | `ChapterComment` | `id` |
| `chapter_comment_reaction` | `ChapterCommentReaction` | `id` |
| `opinion_reaction` | `OpinionReaction` | `id` |
| `chat` | `Chat` | `id` |
| `message` | `Message` | `id` |
| `message_reaction` | `MessageReaction` | `id` |

`EntityMap::all()` devuelve esta misma correspondencia y `EntityMap::classFor('app_user')` resuelve una tabla.

**Nombres.** Cada columna conserva su nombre exacto en MySQL. La propiedad y el método de acceso usan camelCase: `father_last_name` → `fatherLastName()`. Esa correspondencia está declarada de forma explícita en `metadata()` con `new Column('father_last_name', 'fatherLastName', ...)`.

## Cómo se usa

```php
require BASE_PATH . '/app/autoload.php';

use App\Models\Entities\CourseLevel;

// Fila sintética, igual a la que entregaría PDO con FETCH_ASSOC.
$level = CourseLevel::fromRow([
    'id' => '15',
    'course_id' => '3',
    'title' => 'Fundamentos',
    'description' => 'Primer nivel del curso.',
    'onboarding_video' => 'videos/nivel-1.mp4',
    'sort_order' => 1,
    'cost' => '149.90',
    'created_at' => '2026-10-18 12:00:00.000000',
    'updated_at' => '2026-10-18 12:00:00.000000',
]);

$level->id();        // '15'      (string: BIGINT UNSIGNED sin pérdida)
$level->sortOrder(); // 1         (int)
$level->cost();      // '149.90'  (string decimal exacta, nunca float)
$level->createdAt(); // DateTimeImmutable en UTC con microsegundos

CourseLevel::metadata()->uniqueKeys;  // uq_level_order (course_id, sort_order), uq_level_course (id, course_id)
```

Reglas de la hidratación:

- **Fila completa:** `fromRow()` exige todas las columnas de la entidad. Una clave ausente es un error distinto de una clave presente con `null`.
- **Columnas adicionales:** se ignoran. Un resultado parcial o de reporte no es una entidad; tendrá su propio DTO.
- **Construcción:** el constructor es privado. Solo `fromRow()` crea instancias, así que no existen entidades "pendientes de guardar" con `id = 0`.
- **Inmutabilidad:** las propiedades son `readonly`. No hay setters, `save()` ni `delete()`.
- **Errores:** cualquier problema lanza `HydrationException`. Su mensaje nombra la entidad y la columna (`AppUser.birthday: ...`) y trae las propiedades `$entity` y `$column`. Nunca incluye el valor recibido ni el resto de la fila.

## Política de tipos

Las conversiones están centralizadas en `App\Models\Mapping\RowReader`.

| Tipo MySQL | PHP | Reglas |
|---|---|---|
| `BIGINT UNSIGNED` | `string` / `?string` | Acepta un `int` no negativo o una cadena decimal canónica de hasta `18446744073709551615`. Valida el rango sin pasar por `float`. En PK y FK el mínimo es 1; `size_bytes` admite 0. |
| `INT UNSIGNED` | `int` / `?int` | Rango `0..4294967295`. Requiere PHP de 64 bits; `RowReader` lo comprueba y Laragon lo es. |
| `TINYINT UNSIGNED` | `int` | Rango `0..255`. No se trata como booleano. |
| `TINYINT` (`main_slot`) | `?int` | Solo `1` o `NULL`, el dominio de su expresión generada. |
| `BOOLEAN` | `bool` / `?bool` | Solo acepta `true`, `false`, `0`, `1`, `'0'` y `'1'`; `'false'`, `'true'`, `2`, `''` y los float se rechazan. |
| `DECIMAL(p,s)` | `string` / `?string` | Se normaliza a exactamente `s` decimales (`7` → `'7.00'`). Rechaza float, exceso de escala y exceso de enteros; nunca redondea. |
| `VARCHAR(n)` / `CHAR(n)` | `string` / `?string` | UTF-8 válido y como máximo `n` caracteres. No se recorta ni se sanea. |
| `TEXT` / `LONGTEXT` | `string` / `?string` | UTF-8 válido. `TEXT` admite hasta 65,535 bytes. |
| `VARCHAR` con `CHECK IN` | enum | Coincidencia exacta, sensible a mayúsculas (`'Student'` se rechaza). |
| `DATE` | `string` | Formato `Y-m-d` validado con `checkdate()`, sin zona horaria. |
| `DATETIME(6)` | `DateTimeImmutable` | Interpretado en UTC, con microsegundos. Rechaza fechas inexistentes, horas como `24:00` y más de 6 decimales. |

Algunos casos con atención especial:

- **`NULL`:** el `NULL` real queda como `null`. La cadena `'NULL'` es texto y no es un `NULL` de PDO.
- **Defaults:** los `DEFAULT` del DDL describen la creación de filas. Nunca rellenan una columna que falte al hidratar.
- **Lo que no se valida:** las reglas `CHECK` entre columnas y los rangos de negocio (por ejemplo, `rate BETWEEN 1 AND 5` o `amount >= 0`) los garantiza MySQL; el mapeo valida el tipo físico. Presentar fechas en `America/Monterrey` o formatear importes con moneda pertenece a otra capa.

Enums (`app/models/enums/`): `UserRole`, `AccountStatus`, `LoginOutcome`, `AccountEventType`, `ContentType`, `SelectionMode` (usado en `selection_mode` y `selection_mode_snapshot`), `PurchaseScope`, `PurchaseStatus` y `CertificateStatus`.

## Metadatos: PK, FK y UNIQUE

Cada entidad expone `metadata(): TableMetadata`. Incluye:

- **Columnas:** van en el orden del DDL. Cada una es un objeto `Column` con nombre, propiedad, tipo SQL, nulabilidad, `AUTO_INCREMENT`, si es generada, `DEFAULT` y `ON UPDATE` (como expresión SQL literal) y su enum.
- **Clave primaria:** `primaryKey`. Las seis PK compuestas no tienen un `id` artificial.
- **Restricciones:** `uniqueKeys` (28 objetos `UniqueKey`) y `foreignKeys` (61 objetos `ForeignKey`). Cada restricción compuesta es **un solo grupo** que conserva el orden de sus columnas. Por ejemplo, `fk_access_purchase (purchase_id, user_id, course_id) → purchase (id, user_id, course_id)` es una FK con tres pares, no tres FK. Las 61 FK suman 79 pares de columnas.
- **Columnas ocultas:** `redactedColumns`, las que se ocultan en los volcados.
- **Ayudas:** `autoIncrementColumns()`, `generatedColumns()`, `defaults()`, `onUpdateColumns()` y `mysqlManagedColumns()`.

Las relaciones se documentan solo con IDs, tipos y metadatos. Ninguna entidad carga objetos relacionados, consulta en constructores o getters, ni arma árboles de comentarios (`comment_parent_id`) o de mensajes (`reply_to`).

## Columnas generadas y datos administrados por MySQL

| Columna | PHP | Tratamiento |
|---|---|---|
| `app_user.active` | `bool` | Derivada de `account_status = 'active'`. El DDL no la declara `NOT NULL`, pero `account_status` es obligatoria, así que nunca es NULL; un `NULL` se rechaza. |
| `course_chapter_content.main_slot` | `?int` | `1` o `NULL`; sostiene `uq_content_main`. |
| `test_attempt.passed` | `?bool` | `NULL` antes de entregar el intento. No se recalcula en PHP. |

Las tres son de solo lectura. Como no existen campos de escritura en esta fase, no aparecen en ningún contrato de entrada. Los `AUTO_INCREMENT`, `DEFAULT CURRENT_TIMESTAMP(6)` y `ON UPDATE CURRENT_TIMESTAMP(6)` quedan registrados como administrados por MySQL.

Los campos `*_snapshot` de `purchase`, `test_attempt*` y `certificate` se mapean como columnas reales. Conservan los datos históricos; no se reemplazan por valores calculados con los nombres o precios actuales.

## Límites de exposición

Las entidades representan datos internos, no respuestas HTTP:

- **Visibilidad:** las propiedades son `protected readonly` y no hay `jsonSerialize()`, `toArray()` ni `__toString()`. `json_encode($entidad)` produce `{}`.
- **Volcados:** `var_dump()` y `print_r()` muestran `[redacted]` en lugar de `password_hash`, `is_correct`, `weight`, `is_correct_snapshot` y `weight_snapshot`. `var_export()` y el cast `(array)` no respetan esa protección; no los uses con entidades.
- **Serialización:** `serialize()` lanza `LogicException`, para que una entidad no termine en `$_SESSION`, en caché o en archivos. Guarda solo el id.
- **Datos internos:** el hash de contraseña, las claves y pesos del examen, y los datos de auditoría, pago, moderación y participantes de chat solo se leen con su getter explícito.
- **Respuestas:** cada caso de uso tendrá su **DTO de respuesta**, que decide qué se publica según el usuario y el estado (por ejemplo, las claves del examen solo después de entregar el intento). No hay asignación masiva desde `$_POST` o JSON.

## Configuración modificada

- **`app/database/conn.php`:** es solo configuración (lee el `.env` y declara `$DATABASES`). Si `DB_LOCAL_NAME` o `DB_PROD_NAME` están vacíos, el nombre de la base es `cursology`.
- **`app/database/Connection.php`:** abre el PDO. Cada conexión ejecuta `SET time_zone = '+00:00'` y el mismo `sql_mode` estricto del DDL, mediante `PDO::MYSQL_ATTR_INIT_COMMAND`. Es necesario porque `CURRENT_TIMESTAMP(6)` usa la zona de la sesión, y el mapeo interpreta las fechas como UTC.
- **`public/index.php`:** carga `app/autoload.php`, `conn.php` y `app/bootstrap.php` (la raíz de composición que crea e inyecta las dependencias).
- **`app/database/schema/Cursology_modelo_fisico_mysql.sql`:** copia del DDL, idéntica byte a byte (mismo hash). La verificación la usa. El DDL se ejecuta por separado; esta fase no crea ni altera la base.

## Verificación

Desde la raíz del repositorio (en Laragon: *Menu → Terminal*):

```powershell
# Sintaxis de todos los PHP del mapeo (PHP 8.3 acepta varios archivos)
php -l app/autoload.php app/models/mapping/*.php app/models/enums/*.php app/models/entities/*.php tests/verify_model_mapping.php

# Verificación completa, contra la copia del DDL del repo
php tests/verify_model_mapping.php

# ...o contra otro archivo DDL
php tests/verify_model_mapping.php C:/ruta/Cursology_modelo_fisico_mysql.sql
```

En PowerShell, si el comodín no se expande, usa: `php -l (Get-ChildItem app/models -Recurse -Filter *.php).FullName`.

El script no necesita MySQL ni dependencias. Termina con código `0` si todo pasa y `1` si algo falla, y no usa `assert()`. Comprueba lo siguiente:

1. **Archivos:** que las 29 entidades y 9 enums carguen con el autoload real, y que los nombres de carpetas sean compatibles con Linux.
2. **Inventario del DDL:** 29 tablas, 254 columnas, 61 FK (79 pares), 28 UNIQUE y 6 PK compuestas.
3. **Metadatos contra el DDL:** columnas, orden, tipo, nulabilidad, `AUTO_INCREMENT`, columnas generadas, `DEFAULT`, `ON UPDATE`, PK, UNIQUE y FK.
4. **Enums:** que coincidan exactamente con los `CHECK`.
5. **FK:** que cada una apunte a la PK o a un UNIQUE completo, con tipos iguales.
6. **Encapsulamiento:** clases `final`, constructor privado, sin propiedades públicas ni métodos de persistencia o serialización, y nulabilidad PHP coherente con el DDL.
7. **Hidratación por entidad:** una completa por cada una; una clave ausente y un NULL por cada columna; y casos de conversión por tipo para las 254 columnas.
8. **Casos críticos:** `'0'`/`'1'`, `NULL` real frente a `'NULL'`, campo ausente, `'false'`, `18446744073709551615` y su overflow, `'123.45'`, exceso de escala y de rango, float, fecha inexistente, microsegundos, y mensajes de error sin valores.
9. **Exposición:** `json_encode`, `serialize`, `print_r` y `var_dump` sin el hash ni las claves del examen.
10. **Sin base de datos:** que no se cargue `conn.php` ni ninguna conexión, y que no haya PDO ni SQL CRUD en el código del mapeo.

Resultado actual: `RESULTADO: OK (7233 comprobaciones)`.

## Conexión con los SP

La integración es: controlador → servicio (caso de uso) → repositorio → `StoredProcedures` → `CALL` de SP → filas asociativas → entidades o DTO de resultado. Las reglas completas están en [stored-procedures.md](stored-procedures.md). Ya resuelto:

- **Ejecución:** `App\Database\StoredProcedures`:
  - valida el nombre del SP;
  - enlaza los valores con `?` y su tipo (IDs y `DECIMAL` como cadena, `bool` como 0/1, fechas en UTC con microsegundos, `NULL` con `PDO::PARAM_NULL`; los `float` se rechazan);
  - devuelve **todos** los result sets y cierra el cursor (evita el error 2014).
- **Errores:** un `SIGNAL SQLSTATE '45000'` llega como `ProcedureException::$businessCode`, y el repositorio lo traduce a una excepción de dominio. El mensaje de MySQL no llega al cliente.
- **Transacciones:** el SP es el responsable de su transacción. PHP no abre transacciones salvo que se documente una excepción, y nunca hay una abierta durante una llamada de red (Cloudinary, PayPal).
- **Resultados que no son entidades:** se leen con su propio DTO (por ejemplo, `LoginResult`).
- **Respuestas HTTP:** usan un DTO por caso de uso (por ejemplo, `UserResponse`).

Sigue pendiente para los demás módulos:

1. **Contratos por caso de uso, no CRUD por tabla.** Compras, avance y exámenes tocan varias tablas; los reportes (Kardex, ventas, alumnos e instructores) devuelven proyecciones con su propio DTO, no entidades parciales.
2. **Alias compatibles con los mappers.** Si un result set se hidrata como entidad, trae todas sus columnas con el nombre exacto del DDL. Si combina tablas, se usan alias que no choquen con `FETCH_ASSOC`.
3. **Orden de bloqueos uniforme** en los SP con varias filas bloqueadas (compras, finalización de cursos).
4. **Presentación en los DTO de respuesta:** zona `America/Monterrey` y formato de fechas e importes de la rúbrica.
5. **Recomendación:** antes de producción, un usuario MySQL de la aplicación con permiso `EXECUTE` sobre los SP y sin permisos directos sobre las tablas de negocio.

## Observaciones sobre el DDL

No se encontraron conflictos estructurales: todas las FK apuntan a una PK o a un UNIQUE completo con tipos iguales, y los totales coinciden con la guía.

Hay una diferencia solo de texto. El encabezado del DDL dice *"Si cursology ya existe, elegir otro nombre"*, mientras que la guía de decisiones indica no cambiar el nombre acordado y preparar una migración. Se siguió la guía, y el DDL no se modificó.
