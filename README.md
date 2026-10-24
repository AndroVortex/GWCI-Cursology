# GWCI-Cursology

Andrés Emmanuel Calderón Quiroz   1877274
Aldo Javier Zamora Santiago              1917600

Propuesta de API: Stripe (https://stripe.com/docs/api)

## Instalación en una PC nueva

**Requisitos:** [Laragon](https://laragon.org) con PHP 8.3 y MySQL 8.4 (MySQL 8.0.19 o más reciente). Los comandos de abajo se corren en la **terminal de Laragon** (botón *Terminal*), que ya tiene `php` y `mysql` en el PATH, desde la raíz del repositorio.

### 1. Clonar y crear el `.env`

```powershell
git clone <url-del-repositorio>
cd GWCI-Cursology
copy .env.example .env
```

Llena el `.env` con las credenciales de Cloudinary y de PayPal Sandbox. El `.env` está en `.gitignore`: **no lo subas**, y comparte las credenciales por un medio privado.

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

Deben aparecer 57 procedimientos. Detalle de cada uno: [docs/stored-procedures.md](docs/stored-procedures.md).

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
- activa las formas de pago en **Formas de pago**.

Guías:

- [docs/payments.md](docs/payments.md): PayPal Sandbox y la tarjeta simulada.
- [docs/media.md](docs/media.md): Cloudinary.

## Actualizar los procedimientos almacenados

Después de un `git pull` que cambie archivos en `app/database/procedures/`, vuelve a ejecutar desde la raíz del repositorio:

```
mysql -u root -p cursology
SOURCE app/database/procedures/install.sql;
```

Cada archivo hace `DROP PROCEDURE IF EXISTS` + `CREATE`: reescribe los que cambiaron y crea los nuevos. Hay tres cosas que no hace:

- **Un SP nuevo debe agregarse a `install.sql`** con su línea `SOURCE`; si no, no se instala.
- **Un SP renombrado o eliminado se queda en la base.** Bórralo a mano con `DROP PROCEDURE nombre;`.
- **No cambia las tablas.** Un cambio al esquema (columna o tabla nueva) se aplica aparte, con su `ALTER TABLE`, en cada PC. El archivo del esquema no se vuelve a ejecutar.

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

Comandos en [docs/api.md](docs/api.md), sección "Probar". Las que corren contra la base crean cuentas y cursos de prueba en cada corrida.
