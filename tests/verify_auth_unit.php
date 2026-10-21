<?php

declare(strict_types=1);

/**
 * Verificación de registro/login sin base de datos ni red.
 *
 *   php tests/verify_auth_unit.php
 *
 * Termina con código 0 si todas las comprobaciones pasan y 1 si alguna falla.
 */

use App\Database\Connection;
use App\Database\ProcedureException;
use App\Database\StoredProcedures;
use App\Http\ApiKernel;
use App\Http\HttpException;
use App\Http\JsonResponse;
use App\Http\Middleware\Cors;
use App\Http\Middleware\RequireCsrf;
use App\Http\Request;
use App\Http\Router;
use App\Http\Session;
use App\Integrations\CloudinaryClient;
use App\Integrations\MediaUploadException;
use App\Models\Dto\LoginResult;
use App\Models\Dto\UserResponse;
use App\Models\Entities\AppUser;
use App\Models\Enums\AccountStatus;
use App\Models\Enums\LoginOutcome;
use App\Models\Enums\UserRole;
use App\Models\Mapping\HydrationException;
use App\Services\Exceptions\ValidationException;
use App\Services\RegistrationValidator;

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$tempFiles = [];

try {
    $today = new DateTimeImmutable('2026-10-19', new DateTimeZone('America/Monterrey'));
    $validator = new RegistrationValidator();
    $valid = [
        'rol' => 'student',
        'nombre' => '  Ana  ',
        'apellido_paterno' => 'López',
        'apellido_materno' => '',
        'genero' => 'femenino',
        'fecha_nacimiento' => '2000-05-17',
        'email' => '  Ana.Lopez@Example.COM ',
        'password' => 'Segura#2026',
    ];

    // -----------------------------------------------------------
    Report::section('Validación de registro');
    $data = $validator->validate($valid, null, $today);
    check($data->name === 'Ana', 'el nombre se recorta');
    check($data->fatherLastName === 'López', 'acentos se conservan');
    check($data->email === 'ana.lopez@example.com', 'el email se normaliza a minúsculas');
    check($data->motherLastName === null, 'apellido materno vacío -> NULL');
    check($data->role === UserRole::Student, 'rol student');
    check($data->photoPath === null, 'sin foto -> photoPath NULL');
    check(!str_contains(print_r($data, true), 'Segura#2026'), 'la contraseña no aparece en print_r()');
    check(
        $validator->validate(array_merge($valid, ['rol' => 'instructor']), null, $today)->role === UserRole::Instructor,
        'rol instructor se acepta',
    );
    check(
        $validator->validate(array_merge($valid, ['fecha_nacimiento' => '2026-10-19']), null, $today)->birthday === '2026-10-19',
        'la fecha de hoy se acepta',
    );
    check(
        $validator->validate(array_merge($valid, ['apellido_materno' => ' Pérez ']), null, $today)->motherLastName === 'Pérez',
        'apellido materno con valor se conserva recortado',
    );

    $invalid = [
        'nombre vacío' => [['nombre' => '   '], 'nombre'],
        'nombre de 101 caracteres' => [['nombre' => str_repeat('á', 101)], 'nombre'],
        'nombre que no es texto' => [['nombre' => ['Ana']], 'nombre'],
        'apellido paterno vacío' => [['apellido_paterno' => ''], 'apellido_paterno'],
        'apellido materno de 101 caracteres' => [['apellido_materno' => str_repeat('b', 101)], 'apellido_materno'],
        'género fuera de la lista' => [['genero' => 'X'], 'genero'],
        'fecha futura' => [['fecha_nacimiento' => '2026-10-20'], 'fecha_nacimiento'],
        'fecha inexistente' => [['fecha_nacimiento' => '2026-02-30'], 'fecha_nacimiento'],
        'fecha con otro formato' => [['fecha_nacimiento' => '17/05/2000'], 'fecha_nacimiento'],
        'fecha anterior a 1900' => [['fecha_nacimiento' => '1899-12-31'], 'fecha_nacimiento'],
        'email inválido' => [['email' => 'ana@'], 'email'],
        'email vacío' => [['email' => ' '], 'email'],
        'contraseña vacía' => [['password' => ''], 'password'],
        'contraseña corta' => [['password' => 'Ab#1'], 'password'],
        'contraseña sin mayúscula' => [['password' => 'segura#2026'], 'password'],
        'contraseña sin número' => [['password' => 'Segura#abcd'], 'password'],
        'contraseña sin carácter especial' => [['password' => 'Segura2026'], 'password'],
        'contraseña de más de 72 bytes' => [['password' => 'Aa1#' . str_repeat('x', 70)], 'password'],
        'rol admin' => [['rol' => 'admin'], 'rol'],
        'rol vacío' => [['rol' => ''], 'rol'],
        'rol con mayúsculas' => [['rol' => 'Student'], 'rol'],
    ];
    foreach ($invalid as $label => [$override, $field]) {
        $exception = thrown(ValidationException::class, static fn () => $validator->validate(array_merge($valid, $override), null, $today));
        check($exception !== null && array_key_exists($field, $exception->fields), "$label se rechaza en '$field'");
    }
    $empty = thrown(ValidationException::class, static fn () => $validator->validate([], null, $today));
    $expectedFields = ['nombre', 'apellido_paterno', 'genero', 'fecha_nacimiento', 'email', 'password', 'rol'];
    check($empty !== null && array_keys($empty->fields) === $expectedFields, 'formulario vacío reporta los 7 campos obligatorios');

    foreach (['Segura#2026', 'Contraseña1!', 'ÁRBOL#123x'] as $password) {
        check(RegistrationValidator::passwordError($password) === null, "contraseña válida: $password");
    }

    // -----------------------------------------------------------
    Report::section('Foto de perfil');
    $photo = static fn (string $path, int $error = UPLOAD_ERR_OK): array => [
        'tmp_name' => $path,
        'error' => $error,
        'size' => is_file($path) ? (int) filesize($path) : 0,
        'name' => 'foto.png',
    ];
    $png = $tempFiles[] = (string) tempnam(sys_get_temp_dir(), 'cur');
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $withPhoto = $validator->validate($valid, $photo($png), $today);
    check($withPhoto->photoPath === $png && $withPhoto->photoMimeType === 'image/png', 'PNG real se acepta y se detecta su tipo');

    $fake = $tempFiles[] = (string) tempnam(sys_get_temp_dir(), 'cur');
    file_put_contents($fake, '<?php echo "no soy una imagen";');
    $exception = thrown(ValidationException::class, static fn () => $validator->validate($valid, $photo($fake), $today));
    check($exception !== null && isset($exception->fields['foto']), 'un archivo que no es imagen se rechaza aunque se llame .png');

    $big = $tempFiles[] = (string) tempnam(sys_get_temp_dir(), 'cur');
    file_put_contents($big, str_repeat('0', RegistrationValidator::MAX_PHOTO_BYTES + 1));
    $exception = thrown(ValidationException::class, static fn () => $validator->validate($valid, $photo($big), $today));
    check($exception !== null && str_contains($exception->fields['foto'] ?? '', '5 MB'), 'foto de más de 5 MB se rechaza');

    $exception = thrown(ValidationException::class, static fn () => $validator->validate($valid, $photo('', UPLOAD_ERR_INI_SIZE), $today));
    check($exception !== null && str_contains($exception->fields['foto'] ?? '', '5 MB'), 'UPLOAD_ERR_INI_SIZE se reporta como tamaño excedido');
    $exception = thrown(ValidationException::class, static fn () => $validator->validate($valid, $photo('', UPLOAD_ERR_PARTIAL), $today));
    check($exception !== null && isset($exception->fields['foto']), 'una subida incompleta se rechaza');

    // -----------------------------------------------------------
    Report::section('Cloudinary');
    $cloudinary = new CloudinaryClient('demo', '1234', 'abcd');
    check(
        $cloudinary->sign(['timestamp' => '1315060510']) === 'a21ad0f63beb4de2e5575204b79ab90bffb02c10',
        'firma del ejemplo mínimo de la documentación',
    );
    check(
        $cloudinary->sign([
            'timestamp' => '1315060510',
            'public_id' => 'sample_image',
            'eager' => 'w_400,h_300,c_pad|w_260,h_200,c_crop',
        ]) === 'bfd09f95f331f558cbd1320e67aa8d488770583e',
        'firma del ejemplo con parámetros (orden alfabético)',
    );
    check(!str_contains(print_r($cloudinary, true), 'abcd'), 'el API secret no aparece en print_r()');
    $unconfigured = new CloudinaryClient('', '', '');
    check(!$unconfigured->isConfigured(), 'sin credenciales no está configurado');
    $exception = thrown(MediaUploadException::class, static fn () => $unconfigured->uploadImage($png, 'image/png', 'cursology/test'));
    check($exception !== null && $exception->notConfigured, 'subir sin credenciales lanza MediaUploadException (notConfigured)');

    // -----------------------------------------------------------
    Report::section('Router y respuestas JSON');
    $order = [];
    $first = static function (Request $request, callable $next) use (&$order): JsonResponse {
        $order[] = 'primero';
        return $next($request);
    };
    $second = static function (Request $request, callable $next) use (&$order): JsonResponse {
        $order[] = 'segundo';
        return $next($request);
    };
    $router = new Router();
    $router->add('GET', '/api/ping', static fn (Request $request): JsonResponse => JsonResponse::data(['pong' => true]));
    $router->add('POST', '/api/ping', static function (Request $request) use (&$order): JsonResponse {
        $order[] = 'controlador';
        return JsonResponse::noContent();
    }, [$first, $second]);
    $router->add('GET', '/api/teapot', static fn (Request $request): JsonResponse => throw new HttpException(418, 'TEAPOT', 'Soy una tetera.'));

    $response = $router->dispatch(Request::create('GET', '/api/ping'));
    check($response->status === 200 && $response->body() === '{"data":{"pong":true}}', 'GET /api/ping -> 200 {"data":...}');
    $response = $router->dispatch(Request::create('GET', '/api/nada'));
    check($response->status === 404 && str_contains($response->body(), '"NOT_FOUND"'), 'ruta inexistente -> 404 NOT_FOUND');
    $response = $router->dispatch(Request::create('DELETE', '/api/ping'));
    check($response->status === 405 && $response->header('Allow') === 'GET, POST', 'método no permitido -> 405 con Allow');
    $response = $router->dispatch(Request::create('POST', '/api/ping'));
    check($response->status === 204 && $response->body() === '', '204 sin cuerpo');
    check($order === ['primero', 'segundo', 'controlador'], 'los middleware corren en orden antes del controlador');
    $response = $router->dispatch(Request::create('GET', '/api/teapot'));
    check($response->status === 418 && str_contains($response->body(), '"TEAPOT"'), 'HttpException se convierte en su respuesta');

    $error = json_decode(JsonResponse::error(422, 'VALIDATION_FAILED', 'Revisa.', ['email' => 'Mal.'])->body(), true);
    check($error === ['error' => ['code' => 'VALIDATION_FAILED', 'message' => 'Revisa.', 'fields' => ['email' => 'Mal.']]], 'error con fields');
    $error = json_decode(JsonResponse::error(401, 'INVALID_CREDENTIALS', 'No.')->body(), true);
    check(!array_key_exists('fields', $error['error']), 'error sin fields no incluye la clave');
    check(str_contains(JsonResponse::data(['m' => 'contraseña'])->body(), 'contraseña'), 'el JSON conserva UTF-8 sin escapar');

    $logged = [];
    $failing = new Router();
    $failing->add('GET', '/api/falla', static fn (Request $request): JsonResponse => throw new RuntimeException('detalle interno secreto'));
    $kernel = new ApiKernel($failing, new Cors([]), static function (string $message) use (&$logged): void {
        $logged[] = $message;
    });
    $response = $kernel->handle(Request::create('GET', '/api/falla'));
    check($response->status === 500 && str_contains($response->body(), '"INTERNAL_ERROR"'), 'error inesperado -> 500 INTERNAL_ERROR');
    check(!str_contains($response->body(), 'secreto'), 'el 500 no expone el detalle interno');
    check(count($logged) === 1 && str_contains($logged[0], 'detalle interno secreto'), 'el detalle queda en el log');

    // -----------------------------------------------------------
    Report::section('Request');
    $json = Request::create('POST', '/api/auth/login', ['Content-Type' => 'application/json; charset=utf-8'], '{"email":"a@b.com"}');
    check($json->json() === ['email' => 'a@b.com'], 'JSON válido con charset');
    foreach (['{malo' => 'JSON inválido', '[1,2]' => 'arreglo JSON', '"texto"' => 'cadena JSON'] as $body => $label) {
        $exception = thrown(HttpException::class, static fn () => Request::create('POST', '/api/x', ['Content-Type' => 'application/json'], $body)->json());
        check($exception !== null && $exception->status === 400, "$label -> 400");
    }
    $exception = thrown(HttpException::class, static fn () => Request::create('POST', '/api/x', ['Content-Type' => 'text/plain'], '{}')->json());
    check($exception !== null && $exception->status === 415, 'Content-Type distinto de JSON -> 415');
    check(Request::create('GET', '/api/auth/me')->isApi() && !Request::create('GET', '/apis')->isApi() && !Request::create('GET', '/')->isApi(), 'isApi() solo para /api/...');
    check(Request::create('POST', '/x', ['Content-Type' => 'multipart/form-data; boundary=abc'])->isMultipart(), 'detecta multipart/form-data');
    $original = Request::create('GET', '/api/x');
    $withUser = $original->withAttribute('user', 'ana');
    check($original->attribute('user') === null && $withUser->attribute('user') === 'ana', 'withAttribute() no modifica la petición original');

    // -----------------------------------------------------------
    Report::section('CSRF y CORS');
    $_SESSION = [];
    $session = new Session();
    $token = $session->csrfToken();
    check(preg_match('/^[0-9a-f]{64}$/D', $token) === 1, 'token CSRF de 64 caracteres hexadecimales');
    check($session->csrfToken() === $token, 'el token se conserva durante la sesión');
    check($session->isValidCsrf($token) && !$session->isValidCsrf('otro') && !$session->isValidCsrf(null), 'solo el token exacto es válido');
    $next = static fn (Request $request): JsonResponse => JsonResponse::data(['ok' => true]);
    $csrf = new RequireCsrf($session);
    check($csrf(Request::create('POST', '/api/x'), $next)->status === 403, 'sin X-CSRF-Token -> 403');
    check($csrf(Request::create('POST', '/api/x', ['X-CSRF-Token' => 'falso']), $next)->status === 403, 'token incorrecto -> 403');
    check($csrf(Request::create('POST', '/api/x', ['X-CSRF-Token' => $token]), $next)->status === 200, 'token correcto -> pasa al controlador');

    $cors = new Cors(['http://localhost:5173']);
    $preflight = $cors(Request::create('OPTIONS', '/api/auth/login', ['Origin' => 'http://localhost:5173']), $next);
    check(
        $preflight->status === 204
            && $preflight->header('Access-Control-Allow-Origin') === 'http://localhost:5173'
            && str_contains((string) $preflight->header('Access-Control-Allow-Headers'), 'X-CSRF-Token'),
        'preflight de un origen permitido -> 204 con cabeceras CORS',
    );
    check($cors(Request::create('GET', '/api/x', ['Origin' => 'https://otro-sitio.test']), $next)->header('Access-Control-Allow-Origin') === null, 'origen no permitido no recibe cabeceras CORS');
    check($cors(Request::create('GET', '/api/x'), $next)->status === 200, 'petición del mismo sitio pasa sin CORS');
    unset($_SESSION);

    // -----------------------------------------------------------
    Report::section('SP: errores y parámetros');
    $signal = new PDOException('SQLSTATE[45000]: <<Unknown error>>: 1644 EMAIL_TAKEN');
    $signal->errorInfo = ['45000', 1644, 'EMAIL_TAKEN'];
    $exception = ProcedureException::fromPdo('sp_auth_register', $signal);
    check($exception->businessCode === 'EMAIL_TAKEN' && $exception->is('EMAIL_TAKEN'), 'SIGNAL 45000 -> código de negocio');
    $technical = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1452 detalle de la fila');
    $technical->errorInfo = ['23000', 1452, 'Cannot add or update a child row: detalle de la fila'];
    $exception = ProcedureException::fromPdo('sp_auth_register', $technical);
    check($exception->businessCode === null && !str_contains($exception->getMessage(), 'detalle'), 'error técnico sin código de negocio ni detalle en el mensaje');

    check(StoredProcedures::parameter(null) === [null, PDO::PARAM_NULL], 'null -> PARAM_NULL');
    check(StoredProcedures::parameter(true) === [1, PDO::PARAM_INT] && StoredProcedures::parameter(false) === [0, PDO::PARAM_INT], 'bool -> 0/1');
    check(StoredProcedures::parameter('18446744073709551615') === ['18446744073709551615', PDO::PARAM_STR], 'ID BIGINT como cadena sin pérdida');
    check(StoredProcedures::parameter(UserRole::Instructor) === ['instructor', PDO::PARAM_STR], 'enum -> su valor');
    check(
        StoredProcedures::parameter(new DateTimeImmutable('2026-10-19 10:00:00.5', new DateTimeZone('-06:00'))) === ['2026-10-19 16:00:00.500000', PDO::PARAM_STR],
        'fecha -> UTC con microsegundos',
    );
    check(thrown(InvalidArgumentException::class, static fn () => StoredProcedures::parameter(1.5)) !== null, 'float se rechaza');

    $offline = new StoredProcedures(new Connection([
        'host' => '127.0.0.1', 'port' => 1, 'name' => 'x', 'user' => 'x', 'password' => 'clave-que-no-debe-verse',
        'charset' => 'utf8mb4', 'ssl_ca' => '',
    ]));
    foreach (['DROP TABLE app_user', 'sp_Auth_Register', 'sp_x; DROP', ''] as $name) {
        check(thrown(InvalidArgumentException::class, static fn () => $offline->call($name)) !== null, "nombre de SP inválido se rechaza: '$name'");
    }
    check(!str_contains(print_r($offline, true), 'clave-que-no-debe-verse'), 'la contraseña de la BD no aparece en print_r()');

    // -----------------------------------------------------------
    Report::section('DTO');
    $result = LoginResult::fromRow(['outcome' => 'wrong_password', 'consecutive_failures' => 3, 'account_status' => 'locked']);
    check($result->outcome === LoginOutcome::WrongPassword && $result->consecutiveFailures === 3 && $result->accountStatus === AccountStatus::Locked, 'LoginResult de un bloqueo');
    $result = LoginResult::fromRow(['outcome' => 'unknown_user', 'consecutive_failures' => 0, 'account_status' => null]);
    check($result->outcome === LoginOutcome::UnknownUser && $result->accountStatus === null, 'LoginResult de email inexistente');
    check(
        thrown(HydrationException::class, static fn () => LoginResult::fromRow(['outcome' => 'SUCCESS', 'consecutive_failures' => 0, 'account_status' => null])) !== null,
        'LoginResult rechaza un outcome fuera del CHECK',
    );

    $timestamp = '2026-10-19 10:00:00.000000';
    $user = AppUser::fromRow([
        'id' => 7, 'name' => 'Ana', 'father_last_name' => 'López', 'mother_last_name' => null, 'gender' => 'femenino',
        'birthday' => '2000-05-17', 'avatar' => 'img/avatar-default.svg', 'email' => 'ana@example.com',
        'password_hash' => '$2y$10$hashDePruebaQueNoDebeSalir', 'role' => 'student', 'description' => null,
        'account_status' => 'active', 'active' => 1, 'consecutive_failed_logins' => 0, 'status_changed_at' => $timestamp,
        'ban' => 0, 'banned_by' => null, 'banned_at' => null, 'ban_reason' => null, 'created_at' => $timestamp,
        'updated_at' => $timestamp, 'last_login_at' => null,
    ]);
    $payload = json_encode(['user' => UserResponse::fromUser($user)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $decoded = json_decode($payload, true);
    check(
        array_keys($decoded['user']) === ['id', 'name', 'fatherLastName', 'motherLastName', 'fullName', 'email', 'role', 'avatar'],
        'UserResponse expone solo los campos públicos',
    );
    check($decoded['user']['id'] === '7' && $decoded['user']['fullName'] === 'Ana López', 'id como cadena y nombre completo sin NULL');
    check(!str_contains($payload, '$2y$') && !str_contains($payload, 'ban') && !str_contains($payload, 'account_status'), 'sin hash, ban ni estado de cuenta');

    // -----------------------------------------------------------
    Report::section('Sin SQL fuera de los SP');
    $allowed = [
        str_replace('\\', '/', $root . '/app/database/StoredProcedures.php'),
        str_replace('\\', '/', $root . '/app/database/Connection.php'),
    ];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if ($file->getExtension() !== 'php' || in_array($path, $allowed, true)) {
            continue;
        }
        $code = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $relative = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
        check(preg_match('/\bnew\s+PDO\b|->(prepare|query|exec)\s*\(|\bmysqli\b/i', $code) !== 1, "$relative: no abre consultas propias");
        check(
            preg_match('/\bSELECT\b[\s\S]*\bFROM\b|\bINSERT\s+INTO\b|\bUPDATE\s+`?\w+`?\s+SET\b|\bDELETE\s+FROM\b|\bREPLACE\s+INTO\b|\bCALL\s+\w+/i', $code) !== 1,
            "$relative: sin SQL CRUD",
        );
    }
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), PHP_EOL));
    exit(1);
} finally {
    foreach ($tempFiles as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

Report::finish();
