<?php

declare(strict_types=1);

/**
 * Verificación sin base de datos ni red: perfil, cursos, rutas con {id}, roles,
 * firmas de Cloudinary, referencias de medios y formato de importes.
 *
 *   php tests/verify_profile_courses_unit.php
 *
 * Termina con código 0 si todas las comprobaciones pasan y 1 si alguna falla.
 */

use App\Http\JsonResponse;
use App\Http\Middleware\RequireRole;
use App\Http\Request;
use App\Http\Router;
use App\Integrations\CloudinaryClient;
use App\Models\Entities\AppUser;
use App\Models\Enums\ContentType;
use App\Models\Enums\UserRole;
use App\Services\CourseValidator;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\MediaService;
use App\Services\ProfileValidator;
use App\Services\Validation\UserRules;
use App\Services\VerifiedMedia;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/view_helpers.php';

/** @return list<string>|null campos con error, o null si la validación pasó */
function failedFields(callable $callback): ?array
{
    $exception = thrown(ValidationException::class, $callback);

    return $exception === null ? null : array_keys($exception->fields);
}

function userWithRole(UserRole $role, string $email = 'ana@example.com'): AppUser
{
    $timestamp = '2026-10-19 12:00:00.000000';

    return AppUser::fromRow([
        'id' => 7, 'name' => 'Ana', 'father_last_name' => 'López', 'mother_last_name' => null, 'gender' => 'femenino',
        'birthday' => '2000-05-17', 'avatar' => 'img/avatar-default.svg', 'email' => $email,
        'password_hash' => '$2y$10$hashDePrueba', 'role' => $role->value, 'description' => null,
        'account_status' => 'active', 'active' => 1, 'consecutive_failed_logins' => 0, 'status_changed_at' => $timestamp,
        'ban' => 0, 'banned_by' => null, 'banned_at' => null, 'ban_reason' => null, 'created_at' => $timestamp,
        'updated_at' => $timestamp, 'last_login_at' => null,
    ]);
}

try {
    $today = new DateTimeImmutable('2026-10-19', new DateTimeZone('America/Monterrey'));

    // -----------------------------------------------------------
    Report::section('Reglas compartidas del usuario');
    check(UserRules::text(['x' => '  Ana  '], 'x') === 'Ana', 'text() recorta');
    check(UserRules::text(['x' => ['a']], 'x') === '' && UserRules::text(['x' => "\xFF"], 'x') === '', 'text(): no texto o no UTF-8 -> vacío');
    check(UserRules::nameError(str_repeat('á', 100), 'x') === null && UserRules::nameError(str_repeat('á', 101), 'x') !== null, 'nombre de 100 caracteres sí, 101 no');
    check(UserRules::genderError('otro') === null && UserRules::genderError('Otro') !== null, 'género exacto de la lista');
    check(UserRules::birthdayError('2026-10-19', $today) === null, 'nacimiento hoy se acepta');
    check(UserRules::birthdayError('2026-10-20', $today) !== null, 'nacimiento futuro se rechaza');
    check(UserRules::birthdayError('2026-02-30', $today) !== null && UserRules::birthdayError('1899-12-31', $today) !== null, 'fecha inexistente o anterior a 1900 se rechaza');
    check(UserRules::normalizeEmail('  Ana@Example.COM ') === 'ana@example.com', 'email en minúsculas y recortado');
    check(UserRules::emailError('ana@') !== null && UserRules::emailError('ana@example.com') === null, 'formato de email');
    check(UserRules::descriptionError(str_repeat('a', 1000)) === null && UserRules::descriptionError(str_repeat('a', 1001)) !== null, 'descripción hasta 1000 caracteres');
    check(UserRules::passwordError('Segura#2026') === null, 'contraseña válida');
    foreach (['segura#2026' => 'mayúscula', 'Segura#abc' => 'número', 'Segura2026' => 'carácter especial', 'Se#1' => 'mínimo 8'] as $password => $rule) {
        check(str_contains((string) UserRules::passwordError($password), $rule), "contraseña sin $rule se rechaza");
    }
    $generated = UserRules::generatePassword();
    check(strlen($generated) === 16 && UserRules::passwordError($generated) === null, 'generatePassword() cumple las reglas');

    // -----------------------------------------------------------
    Report::section('Perfil: datos y contraseña');
    $profileValidator = new ProfileValidator();
    $current = userWithRole(UserRole::Student);
    $profile = [
        'nombre' => ' Ana ', 'apellido_paterno' => 'López', 'apellido_materno' => '', 'genero' => 'femenino',
        'fecha_nacimiento' => '2000-05-17', 'descripcion' => '', 'email' => 'ANA@example.com',
    ];
    $data = $profileValidator->validateProfile($profile, $current, $today);
    check($data->name === 'Ana' && $data->motherLastName === null && $data->description === null, 'recorta y convierte vacíos en NULL');
    check($data->email === 'ana@example.com' && !$data->emailChanged, 'mismo email con otras mayúsculas -> no cambió');
    check(
        failedFields(static fn () => $profileValidator->validateProfile(['email' => 'nueva@example.com'] + $profile, $current, $today)) === ['current_password'],
        'cambiar el email sin la contraseña actual -> error en current_password',
    );
    $changed = $profileValidator->validateProfile(['email' => 'nueva@example.com', 'current_password' => 'Segura#2026'] + $profile, $current, $today);
    check($changed->emailChanged && $changed->currentPassword === 'Segura#2026', 'email nuevo con contraseña -> emailChanged');
    check(!str_contains(print_r($changed, true), 'Segura#2026'), 'la contraseña actual no aparece en print_r()');
    check(
        failedFields(static fn () => $profileValidator->validateProfile(['nombre' => '', 'genero' => 'x'] + $profile, $current, $today)) === ['nombre', 'genero'],
        'errores por campo en el orden del formulario',
    );
    check(
        $profileValidator->validatePasswordChange(['current_password' => 'Vieja#2026', 'new_password' => 'Nueva#2026', 'confirm_password' => 'Nueva#2026'])
            === ['Vieja#2026', 'Nueva#2026'],
        'cambio de contraseña válido',
    );
    check(
        failedFields(static fn () => $profileValidator->validatePasswordChange(['new_password' => 'debil', 'confirm_password' => 'otra']))
            === ['current_password', 'new_password', 'confirm_password'],
        'sin actual, nueva débil y confirmación distinta -> tres errores',
    );

    // -----------------------------------------------------------
    Report::section('Validación de cursos');
    $courses = new CourseValidator();
    $media = ['public_id' => 'x', 'version' => '1', 'signature' => 's'];
    $course = [
        'titulo' => '  PHP desde cero ', 'descripcion' => 'Aprende PHP.', 'certificador' => '',
        'precio_modo' => 'fijo', 'precio' => '1249.9', 'categorias' => ['3', 3, '5'], 'portada' => $media, 'video' => $media,
    ];
    $input = $courses->validateCourse($course, creating: true);
    check($input->title === 'PHP desde cero' && $input->certifierName === null, 'título recortado y certificador vacío -> NULL');
    check($input->fullCoursePrice === '1249.90', "precio '1249.9' -> '1249.90'");
    check($input->categoryIds === ['3', '5'], 'categorías sin repetir y como cadenas');
    check($courses->validateCourse(['precio_modo' => 'niveles'] + $course, true)->fullCoursePrice === null, 'suma de niveles -> NULL');
    check($courses->validateCourse(['precio_modo' => 'gratis'] + $course, true)->fullCoursePrice === '0.00', "gratis -> '0.00'");
    check($courses->validateCourse(['precio' => '9999999999.99'] + $course, true)->fullCoursePrice === '9999999999.99', 'precio máximo de DECIMAL(12,2)');
    foreach (['', '12.345', '-1', '01', '1,000', '10000000000', '1e3'] as $price) {
        check(failedFields(static fn () => $courses->validateCourse(['precio' => $price] + $course, true)) === ['precio'], "precio '$price' se rechaza");
    }
    check(failedFields(static fn () => $courses->validateCourse(['precio_modo' => 'otro'] + $course, true)) === ['precio'], 'modo de precio desconocido se rechaza');
    foreach (['vacías' => [], 'seis' => ['1', '2', '3', '4', '5', '6'], 'no numérica' => ['abc'], 'cero' => ['0'], 'texto' => '3'] as $label => $ids) {
        check(failedFields(static fn () => $courses->validateCourse(['categorias' => $ids] + $course, true)) === ['categorias'], "categorías ($label) se rechazan");
    }
    check(
        failedFields(static fn () => $courses->validateCourse(['portada' => null, 'video' => null] + $course, true)) === ['portada', 'video'],
        'al crear, portada y video son obligatorios',
    );
    $kept = $courses->validateCourse(['portada' => null, 'video' => null] + $course, false);
    check($kept->cover === null && $kept->video === null, 'al editar, sin archivos nuevos -> se conservan');
    check(
        failedFields(static fn () => $courses->validateCourse(['titulo' => str_repeat('a', 201), 'descripcion' => ''] + $course, true)) === ['titulo', 'descripcion'],
        'título de 201 caracteres y descripción vacía se rechazan',
    );

    $level = $courses->validateLevel(['titulo' => 'Fundamentos', 'descripcion' => 'Bases.', 'costo' => '0', 'video' => $media], true);
    check($level->cost === '0.00' && $level->video === $media, "nivel: costo '0' -> '0.00'");
    check($courses->validateLevel(['titulo' => 'A', 'descripcion' => 'B', 'costo' => 150], false)->cost === '150.00', 'nivel: costo entero -> 150.00');
    check(
        failedFields(static fn () => $courses->validateLevel(['titulo' => '', 'descripcion' => '', 'costo' => '-5'], true)) === ['titulo', 'descripcion', 'costo', 'video'],
        'nivel vacío con costo negativo y sin video -> cuatro errores',
    );
    check($courses->validateChapterTitle(['titulo' => ' Variables ']) === 'Variables', 'capítulo: título recortado');
    check(failedFields(static fn () => $courses->validateChapterTitle(['titulo' => ' '])) === ['titulo'], 'capítulo sin título se rechaza');

    // -----------------------------------------------------------
    Report::section('Contenido del capítulo y enlaces');
    $main = $courses->validateContent(['tipo' => 'video_principal', 'archivo' => $media]);
    check($main->type === ContentType::Video && $main->isMain && $main->purpose === 'chapter_video', 'video principal -> video, is_main y propósito chapter_video');
    $expectedKinds = [
        'documento' => [ContentType::Document, 'chapter_document'], 'imagen' => [ContentType::Image, 'chapter_image'],
        'audio' => [ContentType::Audio, 'chapter_audio'], 'archivo' => [ContentType::Archive, 'chapter_archive'],
    ];
    foreach ($expectedKinds as $kind => [$type, $purpose]) {
        $content = $courses->validateContent(['tipo' => $kind, 'archivo' => $media]);
        check($content->type === $type && !$content->isMain && $content->purpose === $purpose, "recurso '$kind' -> {$type->value} ($purpose)");
    }
    check(failedFields(static fn () => $courses->validateContent(['tipo' => 'documento'])) === ['archivo'], 'archivo sin subir se rechaza');
    check(failedFields(static fn () => $courses->validateContent(['tipo' => 'ejecutable'])) === ['tipo'], 'tipo desconocido se rechaza');

    $link = $courses->validateContent(['tipo' => 'enlace', 'titulo' => 'Docs', 'url' => 'https://www.php.net/manual/es/']);
    check($link->type === ContentType::Link && $link->url === 'https://www.php.net/manual/es/' && $link->purpose === null, 'enlace https válido, sin subida');
    foreach (['javascript:alert(1)', 'ftp://example.com/a', 'www.example.com', 'https://', 'https://' . str_repeat('a', 2050) . '.com'] as $url) {
        check(failedFields(static fn () => $courses->validateContent(['tipo' => 'enlace', 'titulo' => 'X', 'url' => $url])) === ['url'], "enlace '" . substr($url, 0, 30) . "' se rechaza");
    }
    check(failedFields(static fn () => $courses->validateContent(['tipo' => 'enlace', 'url' => 'https://php.net'])) === ['titulo'], 'enlace sin título se rechaza');
    $text = $courses->validateContent(['tipo' => 'texto', 'titulo' => 'Notas', 'texto' => ' Repasa el capítulo. ']);
    check($text->type === ContentType::Text && $text->text === 'Repasa el capítulo.', 'texto recortado');
    check(
        failedFields(static fn () => $courses->validateContent(['tipo' => 'texto', 'texto' => str_repeat('a', 20001)])) === ['titulo', 'texto'],
        'texto sin título y de 20001 caracteres se rechaza',
    );

    // -----------------------------------------------------------
    Report::section('Rutas con {id}');
    $router = new Router();
    $echo = static fn (Request $request): JsonResponse => JsonResponse::data(['id' => $request->attribute('id')]);
    $router->add('GET', '/api/instructor/courses', static fn (): JsonResponse => JsonResponse::data(['list' => true]));
    $router->add('GET', '/api/instructor/courses/{id}', $echo);
    $router->add('POST', '/api/instructor/courses/{id}/levels', $echo);
    $router->add('DELETE', '/api/instructor/levels/{id}', $echo);
    $get = static fn (string $method, string $path): JsonResponse => $router->dispatch(Request::create($method, $path));

    check($get('GET', '/api/instructor/courses')->body() === '{"data":{"list":true}}', 'la ruta exacta sigue funcionando');
    check($get('GET', '/api/instructor/courses/42')->body() === '{"data":{"id":"42"}}', '{id} llega como atributo de cadena');
    check($get('GET', '/api/instructor/courses/18446744073709551615')->body() === '{"data":{"id":"18446744073709551615"}}', 'id de 20 dígitos sin pérdida');
    check($get('POST', '/api/instructor/courses/7/levels')->body() === '{"data":{"id":"7"}}', 'parámetro en medio de la ruta');
    foreach (['/api/instructor/courses/0', '/api/instructor/courses/abc', '/api/instructor/courses/-1', '/api/instructor/courses/1/', '/api/instructor/courses/{id}', '/api/instructor/courses/123456789012345678901'] as $path) {
        check($get('GET', $path)->status === 404, "'$path' -> 404");
    }
    $wrongMethod = $get('PATCH', '/api/instructor/levels/3');
    check($wrongMethod->status === 405 && $wrongMethod->header('Allow') === 'DELETE', 'método no permitido en ruta con {id} -> 405 con Allow');

    $router->add('POST', '/api/x/{id}', static fn () => throw BusinessRuleException::of('LEVEL_NOT_EMPTY'));
    $router->add('POST', '/api/y/{id}', static fn () => throw BusinessRuleException::of('MAIN_VIDEO_EXISTS'));
    $error = json_decode($get('POST', '/api/x/1')->body(), true);
    check($get('POST', '/api/x/1')->status === 409 && $error['error']['code'] === 'LEVEL_NOT_EMPTY', 'regla de negocio -> su estado y código');
    $error = json_decode($get('POST', '/api/y/1')->body(), true);
    check(isset($error['error']['fields']['archivo']), 'MAIN_VIDEO_EXISTS se atribuye al campo archivo');
    foreach (['COURSE_NOT_FOUND' => 404, 'CATEGORY_NAME_TAKEN' => 409, 'CATEGORY_NOT_AVAILABLE' => 422, 'NOT_INSTRUCTOR' => 403, 'HAS_ENROLLMENTS' => 409] as $code => $status) {
        check(BusinessRuleException::of($code)->status === $status, "$code -> $status");
    }

    // -----------------------------------------------------------
    Report::section('RequireRole');
    $next = static fn (Request $request): JsonResponse => JsonResponse::data(['ok' => true]);
    $instructorOnly = new RequireRole(UserRole::Instructor);
    $base = Request::create('GET', '/api/instructor/courses');
    check($instructorOnly($base->withAttribute('user', userWithRole(UserRole::Instructor)), $next)->status === 200, 'instructor pasa');
    foreach ([UserRole::Student, UserRole::Admin] as $role) {
        $response = $instructorOnly($base->withAttribute('user', userWithRole($role)), $next);
        check($response->status === 403 && str_contains($response->body(), '"FORBIDDEN"'), "{$role->value} -> 403 FORBIDDEN");
    }
    check($instructorOnly($base, $next)->status === 403, 'sin usuario en la petición -> 403');
    check((new RequireRole(UserRole::Admin, UserRole::Instructor))($base->withAttribute('user', userWithRole(UserRole::Admin)), $next)->status === 200, 'varios roles permitidos');

    // -----------------------------------------------------------
    Report::section('Firmas de Cloudinary');
    // Ejemplos de https://cloudinary.com/documentation/authentication_signatures (secret "abcd").
    $docs = new CloudinaryClient('demo', '1234', 'abcd');
    check($docs->sign(['timestamp' => '1315060510']) === 'a21ad0f63beb4de2e5575204b79ab90bffb02c10', 'firma mínima del ejemplo de la documentación');
    check(
        $docs->sign(['timestamp' => '1315060510', 'public_id' => 'sample_image', 'eager' => 'w_400,h_300,c_pad|w_260,h_200,c_crop'])
            === 'bfd09f95f331f558cbd1320e67aa8d488770583e',
        'firma con parámetros desordenados del ejemplo de la documentación',
    );
    check($docs->sign(['timestamp' => '1315060510', 'folder' => '']) === 'a21ad0f63beb4de2e5575204b79ab90bffb02c10', 'los parámetros vacíos no se firman');

    $responseSignature = sha1('public_id=cursology/courses/u7/covers/abc&version=1712345678abcd');
    check($docs->verifyUploadResponse('cursology/courses/u7/covers/abc', '1712345678', $responseSignature), 'respuesta de subida con firma correcta');
    check(!$docs->verifyUploadResponse('cursology/courses/u8/covers/abc', '1712345678', $responseSignature), 'otro public_id con la misma firma se rechaza');
    check(!$docs->verifyUploadResponse('cursology/courses/u7/covers/abc', '1712345679', $responseSignature), 'otra versión se rechaza');
    check(!(new CloudinaryClient('', '', ''))->verifyUploadResponse('a', '1', sha1('public_id=a&version=1')), 'sin credenciales nunca verifica');

    $ticket = $docs->directUpload('video', ['folder' => 'cursology/courses/u7/content', 'type' => 'authenticated']);
    $fields = $ticket['fields'];
    check($ticket['upload_url'] === 'https://api.cloudinary.com/v1_1/demo/video/upload', 'URL de subida por tipo de recurso');
    $signed = $fields;
    unset($signed['api_key'], $signed['signature']);
    check($fields['signature'] === $docs->sign($signed) && $fields['api_key'] === '1234', 'el ticket firma todo menos api_key');
    check(!str_contains(json_encode($ticket, JSON_THROW_ON_ERROR), 'abcd') && !str_contains(print_r($docs, true), 'abcd'), 'el secret no sale en el ticket ni en print_r()');

    $download = $docs->privateDownloadUrl('raw', 'authenticated', 'cursology/courses/u7/content/guia.pdf', null, 1800000000);
    parse_str((string) parse_url($download, PHP_URL_QUERY), $query);
    $signedQuery = $query;
    unset($signedQuery['api_key'], $signedQuery['signature']);
    check(str_starts_with($download, 'https://api.cloudinary.com/v1_1/demo/raw/download?'), 'URL temporal en el endpoint download');
    check($query['expires_at'] === '1800000000' && !isset($query['format']) && $query['signature'] === $docs->sign($signedQuery), 'URL temporal firmada con su expiración');

    check(
        $docs->parseDeliveryUrl('https://res.cloudinary.com/demo/image/upload/v1712/cursology/courses/u7/covers/abc.jpg')
            === ['resource_type' => 'image', 'type' => 'upload', 'public_id' => 'cursology/courses/u7/covers/abc'],
        'URL pública -> public_id sin versión ni extensión',
    );
    check(
        $docs->parseDeliveryUrl('https://res.cloudinary.com/demo/raw/upload/v1/cursology/courses/u7/content/guia.pdf')['public_id'] === 'cursology/courses/u7/content/guia.pdf',
        'en raw la extensión es parte del public_id',
    );
    check($docs->parseDeliveryUrl('https://res.cloudinary.com/otra-cuenta/image/upload/v1/a.jpg') === null, 'URL de otra cuenta -> null');

    // -----------------------------------------------------------
    Report::section('Referencias de medios');
    $protected = new VerifiedMedia('cursology/courses/u7/content/abc', 'video', 'authenticated', 'mp4', 1024, 61, 'clase.mp4', 'video/mp4', 'https://res.cloudinary.com/demo/video/authenticated/s--x--/v1/abc.mp4');
    check($protected->reference() === 'cloudinary:authenticated/video/cursology/courses/u7/content/abc', 'lo protegido se guarda como referencia, no como URL');
    $public = new VerifiedMedia('cursology/courses/u7/covers/abc', 'image', 'upload', 'jpg', 1024, null, 'portada.jpg', 'image/jpeg', 'https://res.cloudinary.com/demo/image/upload/v1/abc.jpg');
    check($public->reference() === 'https://res.cloudinary.com/demo/image/upload/v1/abc.jpg', 'lo público se guarda como secure_url');
    check(
        MediaService::parseReference($protected->reference()) === ['type' => 'authenticated', 'resource_type' => 'video', 'public_id' => 'cursology/courses/u7/content/abc'],
        'parseReference() deshace la referencia',
    );
    foreach (['https://example.com/a.mp4', 'cloudinary:upload/video/a', 'cloudinary:authenticated/audio/a', 'cloudinary:authenticated/video/'] as $reference) {
        check(MediaService::parseReference($reference) === null, "'$reference' no es una referencia protegida");
    }
    $mediaService = new MediaService($docs);
    check($mediaService->viewUrl('https://php.net', null) === 'https://php.net', 'un enlace se muestra tal cual');
    check(str_contains($mediaService->viewUrl($protected->reference(), 'clase.mp4'), 'format=mp4'), 'video protegido -> URL temporal con su formato');
    check(
        thrown(ValidationException::class, static fn () => $mediaService->ticket(userWithRole(UserRole::Instructor), 'ejecutable')) !== null,
        'propósito de subida desconocido -> error de validación',
    );
    $courseTicket = $mediaService->ticket(userWithRole(UserRole::Instructor), 'chapter_document');
    check(
        $courseTicket['fields']['folder'] === 'cursology/courses/u7/content' && $courseTicket['fields']['type'] === 'authenticated'
            && !isset($courseTicket['fields']['allowed_formats']) && $courseTicket['resource_type'] === 'raw',
        'ticket de documento: carpeta del usuario, authenticated y raw sin allowed_formats',
    );
    $coverTicket = $mediaService->ticket(userWithRole(UserRole::Instructor), 'course_cover');
    check(
        $coverTicket['fields']['allowed_formats'] === 'jpg,jpeg,png,webp' && !isset($coverTicket['fields']['type']) && $coverTicket['max_bytes'] === 5 * 1024 * 1024,
        'ticket de portada: formatos permitidos, público y 5 MB',
    );
    $stranger = $mediaService->confirm(...);
    $foreign = ['public_id' => 'cursology/courses/u8/covers/abc', 'version' => '1', 'signature' => sha1('public_id=cursology/courses/u8/covers/abc&version=1abcd')];
    $exception = thrown(ValidationException::class, static fn () => $stranger(userWithRole(UserRole::Instructor), 'course_cover', $foreign, 'portada'));
    check($exception !== null && array_keys($exception->fields) === ['portada'], 'archivo de otra carpeta (otro usuario) se rechaza sin consultar Cloudinary');
    $forged = ['public_id' => 'cursology/courses/u7/covers/abc', 'version' => '1', 'signature' => str_repeat('0', 40)];
    check(thrown(ValidationException::class, static fn () => $stranger(userWithRole(UserRole::Instructor), 'course_cover', $forged, 'portada')) !== null, 'firma falsa se rechaza');

    // -----------------------------------------------------------
    Report::section('Formato para las vistas');
    check(format_money('1249.90') === '$1,249.90 MXN' && format_money('0.00') === '$0.00 MXN', 'importes con separador de miles');
    check(format_money('9999999999.99', 'USD') === '$9,999,999,999.99 USD', 'importe máximo sin pasar por float');
    check(format_money(null) === '—' && format_money('12.5') === '—', 'NULL o sin dos decimales -> guion');
    check(format_bytes('512') === '512 B' && format_bytes('2516582') === '2.4 MB' && format_bytes(null) === '—', 'tamaños legibles');
    check(format_duration(61) === '1:01' && format_duration(3725) === '1:02:05' && format_duration(null) === '—', 'duraciones');
    check(format_date(new DateTimeImmutable('2026-10-19 03:00:00', new DateTimeZone('UTC'))) === '18 oct 2026', 'fecha UTC en hora de Monterrey');
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), PHP_EOL));
    exit(1);
}

Report::finish();
