# GWCI-Cursology

Andrés Emmanuel Calderón Quiroz   1877274
Aldo Javier Zamora Santiago              1917600

Propuesta de API: Stripe (https://stripe.com/docs/api)

## Instalación en una PC nueva

**Requisitos:** [Laragon](https://laragon.org) con PHP 8.3 y MySQL 8.4 (MySQL 8.0.19 o más reciente). Los comandos de abajo se corren desde la raíz del repositorio, en la **terminal de Laragon** (botón *Terminal*), que ya tiene `php` y `mysql` en el PATH. Para usarlos en cualquier otra terminal (PowerShell, o la de Cursor o VS Code), agrégalos al PATH.

### Agregar el PHP y el MySQL de Laragon al PATH

**Opción A, desde Laragon:** clic derecho en la ventana de Laragon → **Tools → Path → Add Laragon to Path**. Agrega las versiones de PHP y MySQL que Laragon tiene seleccionadas.

**Opción B, a mano:**

1. Busca las carpetas que contienen `php.exe` y `mysql.exe`; el nombre cambia según la versión instalada. Por ejemplo:
   - `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64`
   - `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin`
2. En el menú Inicio, busca **"Editar las variables de entorno de esta cuenta"**.
3. Selecciona **Path** → **Editar** → **Nuevo**, y pega la primera carpeta. Repite **Nuevo** con la segunda.
4. Si ya tenías otro PHP en el PATH (XAMPP, por ejemplo), sube las de Laragon con **Subir** hasta arriba: Windows usa el primero que encuentra.
5. Acepta todas las ventanas.

**Después de cualquiera de las dos opciones,** cierra y vuelve a abrir la terminal. Si usas la de Cursor o VS Code, cierra la aplicación completa: solo toma el PATH nuevo al arrancar. Para comprobarlo:

```powershell
php -v
mysql --version
where.exe php
```

`php -v` debe decir 8.3. `where.exe php` muestra primero la carpeta de Laragon.

### 1. Clonar y crear el `.env`

```powershell
git clone <url-del-repositorio>
cd GWCI-Cursology
copy .env.example .env
```

Llena el `.env` con las credenciales de Cloudinary y de PayPal Sandbox. El `.env` está en `.gitignore`: **no lo subas**, y comparte las credenciales por un medio privado.

`APP_URL` es opcional: es la dirección pública de la app (por ejemplo, `http://localhost:8000`) que se imprime en los certificados. Si la dejas vacía, se usa la dirección con la que se abrió la página. Ver [docs/certificates.md](docs/certificates.md).

### 2. Crear la base y las tablas (una sola vez)

Abre el cliente de MySQL **desde la raíz del repositorio**: las rutas de `SOURCE` son relativas a esa carpeta. En Laragon, root no tiene contraseña; cuando pida `Enter password:`, presiona Enter.

```
mysql -u root -p
SOURCE app/database/schema/Cursology_modelo_fisico_mysql.sql;
```

El archivo crea la base `cursology` y todas sus tablas. No tiene `DROP`: si la base ya existe, falla en lugar de borrarla.

### 3. Instalar los procedimientos almacenados

En el mismo cliente de MySQL:

```
SOURCE app/database/procedures/install.sql;
SHOW PROCEDURE STATUS WHERE Db = 'cursology';
```

Deben aparecer 104 procedimientos. Detalle de cada uno: [docs/stored-procedures.md](docs/stored-procedures.md).

### 4. Crear el primer administrador

La base empieza vacía:

```powershell
php scripts/create_admin.php --password="<contraseña>"
```

Crea `admin@cursology.local` (cámbialo con `--email=...`). La contraseña necesita mínimo 8 caracteres, una mayúscula, un número y un carácter especial. Sin `--password`, genera una y la muestra una sola vez.

### 5. Levantar el servidor

```powershell
php -S localhost:8000 -t public
```

Abre http://localhost:8000. También funciona con Apache de Laragon apuntando a la carpeta `public` (usa `public/.htaccess`).

### 6. Configurar desde la app

Entra como administrador:

- crea las categorías;
- activa las formas de pago en **Formas de pago**;
- en **Reacciones**, usa "Agregar las sugeridas" (o crea las tuyas): sin reacciones en el catálogo no se puede reaccionar a comentarios, opiniones ni mensajes.

Guías:

- [docs/payments.md](docs/payments.md): PayPal Sandbox y la tarjeta simulada.
- [docs/media.md](docs/media.md): Cloudinary.
- [docs/certificates.md](docs/certificates.md): certificados en PDF y su verificación.

## Actualizar los procedimientos almacenados

Después de un `git pull` que cambie archivos en `app/database/procedures/`, vuelve a ejecutar desde la raíz del repositorio:

```
mysql -u root -p cursology
SOURCE app/database/procedures/install.sql;
```

Cada archivo hace `DROP PROCEDURE IF EXISTS` + `CREATE`: reescribe los que cambiaron y crea los nuevos. Hay tres cosas que no hace:

- **Un SP nuevo debe agregarse a `install.sql`** con su línea `SOURCE`; si no, no se instala.
- **Un SP renombrado o eliminado se queda en la base.** Bórralo a mano con `DROP PROCEDURE nombre;`.
- **No cambia las tablas.** Un cambio al esquema (columna o tabla nueva) se aplica con su migración; ver la sección siguiente. El archivo del esquema no se vuelve a ejecutar.

## Migraciones

Una base creada antes de un cambio al esquema necesita su migración. Las migraciones viven en `app/database/migrations/` y se ejecutan en orden de nombre, desde la raíz del repositorio y **antes** de reinstalar los SP (los SP nuevos usan las columnas nuevas):

```
mysql -u root -p cursology
SOURCE app/database/migrations/2026_11_chat_read.sql;
SOURCE app/database/migrations/2026_11_content_report.sql;
SOURCE app/database/procedures/install.sql;
```

| Migración | Qué hace |
|---|---|
| `2026_11_chat_read.sql` | Agrega `chat.student_last_read_at` y `chat.instructor_last_read_at` para los mensajes no leídos. |
| `2026_11_content_report.sql` | Crea la tabla `content_report` (reportes de comentarios y opiniones para la moderación). |

Cada migración revisa primero si el cambio ya está y, si es así, no hace nada: se puede ejecutar más de una vez. Una base nueva creada con el archivo del esquema ya trae todos los cambios y no necesita migraciones. Si copias los datos de otra PC con el dump de la sección siguiente, ejecuta las migraciones después de importarlo.

## Copiar los datos de otra PC (opcional)

GitHub solo lleva el código. Los usuarios, cursos, compras y avance viven en el MySQL de cada PC. Para llevarte los mismos datos, usa los siguientes comandos en lugar de los pasos 2 a 4.

En la PC de origen:

```powershell
mysqldump -u root -p --routines --result-file=cursology.sql cursology
```

En la PC nueva, desde la carpeta donde está `cursology.sql`:

```
mysql -u root -p
CREATE DATABASE cursology CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cursology;
SOURCE cursology.sql;
```

Usa `--result-file` en lugar de `>`: en PowerShell, `>` guarda el archivo en UTF-16 y MySQL no lo puede leer. **No subas `cursology.sql` al repositorio:** trae correos y hashes de contraseñas.

Los archivos de Cloudinary se ven en la otra PC si usa las mismas credenciales de Cloudinary, porque la base guarda sus referencias.

## Pruebas

Las pruebas que usan base de datos corren contra una **base de pruebas aparte**, `cursology_test`, y nunca contra la local. Así sus cuentas `prueba+…@cursology.test` no llegan a los reportes de la app. Créala una vez y vuelve a crearla cuando cambien el esquema o un SP (las pruebas avisan si está desactualizada):

```powershell
php scripts/setup_test_db.php
```

El script borra `cursology_test` si ya existe, corre el esquema, instala los 104 SP y crea `admin@cursology.local` con una contraseña aleatoria que no se muestra (las pruebas no la usan). Usa el mismo servidor y usuario que `DB_LOCAL_*`; para otro nombre u otro servidor, llena las claves `DB_TEST_*` del `.env`. Se niega a correr si la base de pruebas se llama igual que la local o la de producción.

Para probar a mano por HTTP sin llenar la base local, levanta el servidor sobre la base de pruebas:

```powershell
$env:CURSOLOGY_DB = 'test'; php -S localhost:8099 -t public
```

La lista de pruebas está en [docs/api.md](docs/api.md), sección "Probar".

## Limpiar los datos de prueba

Antes de que existiera la base de pruebas, las pruebas dejaban sus cuentas, cursos y categorías en la base local. `scripts/purge_test_data.php` los borra de verdad con el SP `sp_maintenance_purge`, siempre en un ensayo primero:

```powershell
php scripts/purge_test_data.php            # ensayo: cuenta lo que se borraría y no borra nada
php scripts/purge_test_data.php --apply    # borra
```

Se borran:

- las cuentas `…@cursology.test` (dominio reservado para pruebas) y todo lo que depende de ellas: cursos con niveles, capítulos y exámenes, inscripciones, compras, avance, intentos, certificados, opiniones, comentarios, reacciones, conversaciones y reportes;
- lo que otras cuentas tengan ligado a esos datos, por ejemplo su inscripción a un curso de prueba o su respuesta a un comentario de prueba;
- las categorías y reacciones con la marca de las pruebas (`Prueba 20261108191603-fcbcb9 …`), si ningún curso o comentario las usa.

Nunca toca administradores ni otras cuentas, y todo corre en una transacción: si algo falla, no se borra nada. Los archivos que las pruebas de punta a punta subieron a Cloudinary no se borran. Con `--prod` usa la base de producción del `.env`.

**En una base que ya tenía datos de prueba** (por ejemplo, la otra PC), desde la raíz del repositorio:

```
mysql -u root -p cursology
SOURCE app/database/procedures/maintenance/sp_maintenance_purge.sql;
exit
php scripts/purge_test_data.php
php scripts/purge_test_data.php --apply
```

## Datos de demostración

Para presentar el proyecto, `scripts/seed_demo.php` carga instructores, alumnos y cursos de ejemplo. Todo se crea con los mismos SP que usa la app. Las portadas y los videos son archivos públicos de la cuenta *demo* de Cloudinary: no se sube nada a tu cuenta.

```powershell
php scripts/seed_demo.php                       # genera una contraseña y la muestra una sola vez
php scripts/seed_demo.php --password="<contraseña>"
```

Las 9 cuentas usan la misma contraseña. El admin (`admin@cursology.local`, o el que indiques con `--admin=...`) conserva la suya. Si la tarjeta simulada estaba apagada, el script la activa y lo avisa; también agrega las reacciones sugeridas y las categorías "Programación web", "Bases de datos" y "Diseño" si faltan.

| Cuenta | Rol | Qué enseñar |
|---|---|---|
| `laura.mendez@demo.cursology.local` | Instructora | PHP (curso completo y por niveles) y JavaScript (por niveles): ventas, alumnos, comentarios y un mensaje sin leer. |
| `diego.herrera@demo.cursology.local` | Instructor | MySQL (gratis) y Procedimientos almacenados (curso completo): opiniones y un mensaje sin leer. |
| `sofia.ramirez@demo.cursology.local` | Instructora | Diseño de interfaces, y un curso en borrador (Figma) sin publicar. |
| `ana.lopez@demo.cursology.local` | Alumna | Compró PHP completo y terminó PHP y MySQL: puede generar 2 certificados. Opina, comenta, reporta y le escribe a Laura. |
| `bruno.garcia@demo.cursology.local` | Alumno | Avance parcial en PHP (nivel gratis) y en JavaScript (compró el nivel 2). Laura le respondió una pregunta. |
| `carla.nunez@demo.cursology.local` | Alumna | Compró y terminó Procedimientos almacenados (certificado disponible) y le escribió a Diego. |
| `daniel.ortiz@demo.cursology.local` | Alumno | Terminó MySQL (opinión de 3 estrellas, certificado disponible) y empezó JavaScript. |
| `elena.castro@demo.cursology.local` | Alumna | Compró solo el nivel 1 de Diseño de interfaces; tiene una respuesta de Sofía sin leer. |
| `fernando.rios@demo.cursology.local` | Alumno | Reprobó el examen de PHP y publicó un comentario de spam que está en la cola de moderación del admin. |

Las fechas de compras, avance y comentarios son las del momento de la carga. Los certificados no se generan por adelantado: se obtienen en vivo desde el panel del alumno.

Si ya hay datos de demostración, el script no hace nada. Para volver a cargarlos (por ejemplo, para dejar la demostración como nueva antes de presentar):

```powershell
php scripts/purge_test_data.php --demo --apply
php scripts/seed_demo.php
```

Con `--db=test` usa la base de pruebas y con `--prod` la de producción.
