<?php

declare(strict_types=1);

/**
 * Verificación de perfil, primer admin, cuentas bloqueadas, categorías y cursos contra la base LOCAL,
 * con los SP instalados (app/database/procedures/install.sql).
 *
 *   php tests/verify_profile_courses_db.php
 *
 * Usa referencias de medios inventadas: no llama a Cloudinary. Cada corrida deja en la
 * base tres cuentas de prueba (prueba+<marca>...@cursology.test), dos categorías
 * "Prueba <marca>" (desactivadas al terminar) y un curso en borrador: no hay SP de
 * borrado para ellos y no se ejecuta SQL directo desde PHP.
 */

use App\Database\Connection;
use App\Database\ProcedureException;
use App\Database\StoredProcedures;
use App\Integrations\CloudinaryClient;
use App\Models\Dto\PublishChecklist;
use App\Models\Entities\AppUser;
use App\Models\Enums\AccountStatus;
use App\Models\Enums\ContentType;
use App\Models\Enums\UserRole;
use App\Models\Enums\SelectionMode;
use App\Repositories\CatalogRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CourseRepository;
use App\Repositories\UserRepository;
use App\Services\AccountAdminService;
use App\Services\AuthService;
use App\Services\CatalogService;
use App\Services\CategoryService;
use App\Services\CourseService;
use App\Services\CourseValidator;
use App\Services\Exceptions\AccountLockedException;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\InvalidCredentialsException;
use App\Services\Exceptions\ValidationException;
use App\Services\MediaService;
use App\Services\ProfileService;
use App\Services\ProfileValidator;
use App\Services\QuestionInput;
use App\Services\RegistrationValidator;
use App\Services\VerifiedMedia;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/database/conn.php';

$procedures = new StoredProcedures(new Connection($DATABASES['local']));
$users = new UserRepository($procedures);
$noCloudinary = new CloudinaryClient('', '', '');
$auth = new AuthService($users, new RegistrationValidator(), $noCloudinary);
$profiles = new ProfileService($users, new ProfileValidator(), $noCloudinary);
$accounts = new AccountAdminService($users);
$categoryRepository = new CategoryRepository($procedures);
$categories = new CategoryService($categoryRepository);
$courseRepository = new CourseRepository($procedures);
$validator = new CourseValidator();
$courseService = new CourseService($courseRepository, $validator, new MediaService($noCloudinary));
$catalog = new CatalogService(new CatalogRepository($procedures));

try {
    $procedures->call('sp_category_list', [1]);
} catch (ProcedureException $exception) {
    fwrite(STDERR, 'No se pudo ejecutar sp_category_list en la base local. Revisa el .env y que MySQL esté encendido,' . PHP_EOL
        . 'e instala los SP con: SOURCE app/database/procedures/install.sql;' . PHP_EOL
        . 'Detalle: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()) . PHP_EOL);
    exit(1);
}

/** Código de la regla de negocio que lanzó $callback, o null si no lanzó ninguna. */
function ruleCode(callable $callback): ?string
{
    return thrown(BusinessRuleException::class, $callback)?->errorCode;
}

/** @return list<string>|null */
function failedFields(callable $callback): ?array
{
    $exception = thrown(ValidationException::class, $callback);

    return $exception === null ? null : array_keys($exception->fields);
}

function fresh(UserRepository $users, AppUser $user): AppUser
{
    return $users->findById($user->id()) ?? throw new RuntimeException('El usuario de prueba desapareció.');
}

try {
    $mark = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $password = 'Segura#2026';
    $account = static fn (string $role, string $suffix): array => [
        'rol' => $role, 'nombre' => 'Prueba', 'apellido_paterno' => 'Cursos', 'apellido_materno' => '',
        'genero' => 'otro', 'fecha_nacimiento' => '1995-03-10', 'email' => "prueba+$mark-$suffix@cursology.test", 'password' => $password,
    ];
    $instructor = $auth->register($account('instructor', 'instructor'), null);
    $otherInstructor = $auth->register($account('instructor', 'otro'), null);
    $student = $auth->register($account('student', 'alumno'), null);
    printf('Cuentas de prueba de esta corrida: prueba+%s-{instructor,otro,alumno}@cursology.test%s%s', $mark, PHP_EOL, PHP_EOL);

    // -----------------------------------------------------------
    Report::section('Perfil');
    $profileInput = [
        'nombre' => 'Instructora', 'apellido_paterno' => 'De Prueba', 'apellido_materno' => 'Núñez', 'genero' => 'femenino',
        'fecha_nacimiento' => '1990-01-15', 'descripcion' => 'Enseño PHP.', 'email' => $instructor->email(),
    ];
    $updated = $profiles->updateProfile($instructor, $profileInput);
    check(
        $updated->name() === 'Instructora' && $updated->motherLastName() === 'Núñez' && $updated->description() === 'Enseño PHP.'
            && $updated->birthday() === '1990-01-15' && $updated->gender() === 'femenino',
        'actualiza nombre, apellidos, género, nacimiento y descripción',
    );
    check($updated->email() === $instructor->email() && $updated->role() === UserRole::Instructor, 'sin cambiar email ni rol');
    $cleared = $profiles->updateProfile($updated, ['apellido_materno' => '', 'descripcion' => ''] + $profileInput);
    check($cleared->motherLastName() === null && $cleared->description() === null, 'vaciar campos opcionales -> NULL');

    $newEmail = "prueba+$mark-nuevo@cursology.test";
    check(
        failedFields(static fn () => $profiles->updateProfile($cleared, ['email' => $newEmail, 'current_password' => 'Otra#2026'] + $profileInput)) === ['current_password'],
        'cambiar email con contraseña incorrecta -> error en current_password',
    );
    $exception = thrown(BusinessRuleException::class, static fn () => $profiles->updateProfile($cleared, ['email' => $student->email(), 'current_password' => $password] + $profileInput));
    check($exception !== null && $exception->errorCode === 'EMAIL_TAKEN' && $exception->status === 409 && isset($exception->fields()['email']), 'email de otra cuenta -> EMAIL_TAKEN (409, campo email)');
    $moved = $profiles->updateProfile($cleared, ['email' => strtoupper($newEmail), 'current_password' => $password] + $profileInput);
    check($moved->email() === $newEmail, 'email nuevo guardado en minúsculas');
    check($auth->login($newEmail, $password)->id() === $instructor->id(), 'se inicia sesión con el email nuevo');
    check(thrown(InvalidCredentialsException::class, static fn () => $auth->login($instructor->email(), $password)) !== null, 'el email anterior ya no inicia sesión');

    $moved = fresh($users, $moved);
    $passwordChange = static fn (string $current, string $new): array => ['current_password' => $current, 'new_password' => $new, 'confirm_password' => $new];
    check(failedFields(static fn () => $profiles->changePassword($moved, $passwordChange('Otra#2026', 'Nueva#2026'))) === ['current_password'], 'contraseña actual incorrecta se rechaza');
    check(failedFields(static fn () => $profiles->changePassword($moved, $passwordChange($password, $password))) === ['new_password'], 'la nueva debe ser distinta de la actual');
    $profiles->changePassword($moved, $passwordChange($password, 'Nueva#2026'));
    check(thrown(InvalidCredentialsException::class, static fn () => $auth->login($newEmail, $password)) !== null, 'la contraseña anterior deja de funcionar');
    check($auth->login($newEmail, 'Nueva#2026')->id() === $instructor->id(), 'la contraseña nueva funciona');
    $instructor = fresh($users, $instructor);

    // -----------------------------------------------------------
    Report::section('Primer administrador');
    $admin = $users->findForLogin('admin@cursology.local');
    check($admin !== null && $admin->role() === UserRole::Admin, 'existe la cuenta admin@cursology.local');
    check(
        ruleCode(static fn () => $users->createFirstAdmin('Otro', 'Admin', null, 'otro', '1990-01-01', AuthService::DEFAULT_AVATAR, "prueba+$mark-admin@cursology.test", password_hash($password, PASSWORD_DEFAULT))) === 'ADMIN_EXISTS',
        'un segundo admin por el script -> ADMIN_EXISTS',
    );
    check($users->findForLogin("prueba+$mark-admin@cursology.test") === null, 'y no se crea la cuenta');

    // -----------------------------------------------------------
    Report::section('Cuentas bloqueadas');
    $lockOut = static function (AppUser $user) use ($auth): ?AccountLockedException {
        thrown(InvalidCredentialsException::class, static fn () => $auth->login($user->email(), 'Mala#2026'));
        thrown(InvalidCredentialsException::class, static fn () => $auth->login($user->email(), 'Mala#2026'));

        return thrown(AccountLockedException::class, static fn () => $auth->login($user->email(), 'Mala#2026'));
    };
    check($lockOut($student)?->justLocked === true, '3 contraseñas incorrectas bloquean la cuenta');
    $lockedIds = static fn (): array => array_map(static fn ($a): string => $a->id, $accounts->lockedAccounts($admin));
    $entry = array_values(array_filter($accounts->lockedAccounts($admin), static fn ($a): bool => $a->id === $student->id()))[0] ?? null;
    check($entry !== null && $entry->email === $student->email() && $entry->role === UserRole::Student, 'aparece en la lista del admin con su email y rol');
    check($entry !== null && $entry->lastAttemptAt !== null && $entry->lockedAt <= $entry->lastAttemptAt, 'con la fecha del bloqueo y la del último intento');
    check(ruleCode(static fn () => $accounts->lockedAccounts($instructor)) === 'NOT_ADMIN', 'un instructor no ve la lista -> NOT_ADMIN');
    check(ruleCode(static fn () => $users->unlock($instructor->id(), $student->id(), 'x')) === 'NOT_ADMIN', 'un instructor no reactiva cuentas -> NOT_ADMIN');

    $unlocked = $accounts->unlock($admin, $student->id());
    check($unlocked->accountStatus() === AccountStatus::Active && $unlocked->consecutiveFailedLogins() === 0, 'el admin la reactiva: activa y contador en 0');
    check(!in_array($student->id(), $lockedIds(), true), 'ya no aparece en la lista');
    check($auth->login($student->email(), $password)->id() === $student->id(), 'vuelve a iniciar sesión con su misma contraseña');
    check(ruleCode(static fn () => $accounts->unlock($admin, $student->id())) === 'USER_NOT_LOCKED', 'reactivar una cuenta activa -> USER_NOT_LOCKED');
    check(ruleCode(static fn () => $accounts->unlock($admin, '999999999999')) === 'USER_NOT_FOUND', 'cuenta inexistente -> USER_NOT_FOUND');

    $lockOut($student);
    $users->unlock(null, $student->id(), 'Reactivación desde la consola');
    check($users->findById($student->id())?->accountStatus() === AccountStatus::Active, 'desde la consola (sin actor) también se reactiva');

    // -----------------------------------------------------------
    Report::section('Categorías');
    $category = $categories->create($admin, ['nombre' => "Prueba $mark", 'descripcion' => 'Categoría de prueba']);
    check($category->name() === "Prueba $mark" && $category->active() && $category->createdBy() === $admin->id(), 'el admin crea una categoría activa');
    check(ruleCode(static fn () => $categories->create($admin, ['nombre' => strtoupper("prueba $mark")])) === 'CATEGORY_NAME_TAKEN', 'nombre repetido (sin importar mayúsculas) -> CATEGORY_NAME_TAKEN');
    check(ruleCode(static fn () => $categories->create($instructor, ['nombre' => "Prueba $mark otra"])) === 'NOT_ADMIN', 'un instructor no crea categorías -> NOT_ADMIN');
    $inactive = $categories->create($admin, ['nombre' => "Prueba $mark inactiva"]);
    $inactive = $categories->update($admin, $inactive->id(), ['activa' => false]);
    check(!$inactive->active(), 'el admin desactiva una categoría');
    $activeIds = array_map(static fn ($c): string => $c->id(), $categories->active());
    check(in_array($category->id(), $activeIds, true) && !in_array($inactive->id(), $activeIds, true), 'la lista pública solo trae las activas');
    $renamed = $categories->update($admin, $category->id(), ['nombre' => "Prueba $mark", 'descripcion' => '']);
    check($renamed->description() === null && $renamed->updatedBy() === $admin->id(), 'editar guarda quién la modificó');
    check(ruleCode(static fn () => $categories->update($admin, '999999999999', ['activa' => true])) === 'CATEGORY_NOT_FOUND', 'categoría inexistente -> CATEGORY_NOT_FOUND');

    // -----------------------------------------------------------
    Report::section('Cursos');
    $folder = 'cursology/courses/u' . $instructor->id();
    $cover = "https://res.cloudinary.com/prueba/image/upload/v1/$folder/covers/portada-$mark.jpg";
    $promo = "https://res.cloudinary.com/prueba/video/upload/v1/$folder/promos/promo-$mark.mp4";
    $courseForm = [
        'titulo' => "Curso de prueba $mark", 'descripcion' => 'Descripción del curso.', 'certificador' => '',
        'precio_modo' => 'paquete', 'precio' => '1249.9', 'categorias' => [$category->id()], 'portada' => [], 'video' => [],
    ];
    $courseInput = $validator->validateCourse($courseForm, creating: true);

    $course = $courseRepository->create($instructor->id(), $courseInput, $cover, $promo);
    check($course->createdBy() === $instructor->id() && $course->publishedAt() === null && $course->active(), 'curso creado como borrador del instructor');
    check($course->fullCoursePrice() === '1249.90' && $course->currency() === 'MXN' && $course->coverImage() === $cover, 'precio exacto, MXN y portada guardada');

    $inactiveInput = $validator->validateCourse(['categorias' => [$category->id(), $inactive->id()]] + $courseForm, true);
    check(ruleCode(static fn () => $courseRepository->create($instructor->id(), $inactiveInput, $cover, $promo)) === 'CATEGORY_NOT_AVAILABLE', 'con una categoría inactiva -> CATEGORY_NOT_AVAILABLE');
    $missingInput = $validator->validateCourse(['categorias' => ['999999999999']] + $courseForm, true);
    check(ruleCode(static fn () => $courseRepository->create($instructor->id(), $missingInput, $cover, $promo)) === 'CATEGORY_NOT_AVAILABLE', 'con una categoría inexistente -> CATEGORY_NOT_AVAILABLE');
    check(ruleCode(static fn () => $courseRepository->create($student->id(), $courseInput, $cover, $promo)) === 'NOT_INSTRUCTOR', 'un alumno no crea cursos -> NOT_INSTRUCTOR');

    $outline = $courseService->outline($instructor, $course->id());
    check($outline->course->id() === $course->id() && $outline->categoryIds === [$category->id()] && $outline->levels === [], 'el dueño ve el curso con sus categorías y sin niveles');
    check(ruleCode(static fn () => $courseService->outline($otherInstructor, $course->id())) === 'COURSE_NOT_FOUND', 'otro instructor no ve el curso -> COURSE_NOT_FOUND');
    check(ruleCode(static fn () => $courseService->outline($instructor, 'abc')) === 'COURSE_NOT_FOUND', 'id no numérico -> COURSE_NOT_FOUND sin llamar al SP');
    check(ruleCode(static fn () => $courseRepository->update($otherInstructor->id(), $course->id(), $courseInput, null, null)) === 'COURSE_NOT_FOUND', 'otro instructor no lo edita');

    $keepInput = $validator->validateCourse(['titulo' => "Curso editado $mark", 'precio_modo' => 'niveles'] + $courseForm, false);
    [$edited, $replaced] = $courseRepository->update($instructor->id(), $course->id(), $keepInput, null, null);
    check($edited->title() === "Curso editado $mark" && $edited->fullCoursePrice() === null && $edited->coverImage() === $cover, 'editar sin archivos conserva la portada; precio -> suma de niveles');
    check($replaced === [], 'sin archivos nuevos no hay nada que borrar');
    $newCover = "https://res.cloudinary.com/prueba/image/upload/v2/$folder/covers/portada-2-$mark.jpg";
    [$edited, $replaced] = $courseRepository->update($instructor->id(), $course->id(), $keepInput, $newCover, null);
    check($edited->coverImage() === $newCover && $replaced === [$cover], 'portada nueva -> devuelve la anterior para borrarla');

    $summaries = array_values(array_filter($courseService->listMine($instructor), static fn ($s): bool => $s->id === $course->id()));
    check(count($summaries) === 1 && !$summaries[0]->isPublished() && $summaries[0]->levelCount === 0, 'aparece en "Mis cursos" como borrador');
    check(array_filter($courseService->listMine($otherInstructor), static fn ($s): bool => $s->id === $course->id()) === [], 'no aparece en los cursos de otro instructor');

    // -----------------------------------------------------------
    Report::section('Niveles');
    $levelVideo = static fn (int $n): string => "https://res.cloudinary.com/prueba/video/upload/v1/$folder/levels/nivel-$n-$mark.mp4";
    $levelInput = static fn (string $title, string $cost) => $validator->validateLevel(['titulo' => $title, 'descripcion' => "Descripción de $title", 'costo' => $cost, 'video' => []], true);
    $levels = [];
    foreach ([1 => '0', 2 => '199.5', 3 => '300'] as $n => $cost) {
        $levels[$n] = $courseRepository->createLevel($instructor->id(), $course->id(), $levelInput("Nivel $n", $cost), $levelVideo($n));
    }
    check(array_map(static fn ($l): int => $l->sortOrder(), $levels) === [1 => 1, 2 => 2, 3 => 3], 'niveles numerados 1, 2, 3');
    check($levels[1]->cost() === '0.00' && $levels[2]->cost() === '199.50', 'costos exactos (0 = gratis)');
    check(
        ruleCode(static fn () => $courseRepository->createLevel($otherInstructor->id(), $course->id(), $levelInput('Ajeno', '0'), $levelVideo(9))) === 'COURSE_NOT_FOUND',
        'otro instructor no agrega niveles',
    );

    [$level2, $replaced] = $courseRepository->updateLevel($instructor->id(), $levels[2]->id(), $levelInput('Nivel 2 editado', '250'), $levelVideo(22));
    check($level2->title() === 'Nivel 2 editado' && $level2->cost() === '250.00' && $replaced === [$levelVideo(2)], 'editar con video nuevo devuelve el anterior');
    [, $replaced] = $courseRepository->updateLevel($instructor->id(), $levels[3]->id(), $levelInput('Nivel 3', '300'), null);
    check($replaced === [], 'editar sin video nuevo lo conserva');

    check($courseRepository->deleteLevel($instructor->id(), $levels[2]->id()) === [$levelVideo(22)], 'borrar un nivel vacío devuelve su video');
    $orders = array_map(static fn ($l): array => [$l->id(), $l->sortOrder()], $courseService->outline($instructor, $course->id())->levels);
    check($orders === [[$levels[1]->id(), 1], [$levels[3]->id(), 2]], 'los siguientes se renumeran sin huecos (1, 2)');
    check(ruleCode(static fn () => $courseRepository->deleteLevel($instructor->id(), $levels[2]->id())) === 'LEVEL_NOT_FOUND', 'borrar de nuevo -> LEVEL_NOT_FOUND');

    // -----------------------------------------------------------
    Report::section('Capítulos y contenido');
    $levelId = $levels[1]->id();
    $videoChapter = static fn (string $title) => $validator->validateChapter(['titulo' => $title, 'tipo' => 'video'], true);
    $renamed = static fn (string $title) => $validator->validateChapter(['titulo' => $title], false);
    $chapter1 = $courseRepository->createChapter($instructor->id(), $levelId, $videoChapter('Introducción'));
    $chapter2 = $courseRepository->createChapter($instructor->id(), $levelId, $videoChapter('Variables'));
    check($chapter1->sortOrder() === 1 && $chapter2->sortOrder() === 2 && !$chapter1->isTest(), 'capítulos de video numerados 1, 2');
    check(ruleCode(static fn () => $courseRepository->createChapter($otherInstructor->id(), $levelId, $videoChapter('Ajeno'))) === 'LEVEL_NOT_FOUND', 'otro instructor no agrega capítulos');
    check($courseRepository->updateChapter($instructor->id(), $chapter2->id(), $renamed('Variables y tipos'))->title() === 'Variables y tipos', 'renombrar capítulo');
    check(ruleCode(static fn () => $courseRepository->updateChapter($otherInstructor->id(), $chapter2->id(), $renamed('X'))) === 'CHAPTER_NOT_FOUND', 'otro instructor no lo renombra');
    check(ruleCode(static fn () => $courseRepository->deleteLevel($instructor->id(), $levelId)) === 'LEVEL_NOT_EMPTY', 'un nivel con capítulos no se borra -> LEVEL_NOT_EMPTY');

    $video = new VerifiedMedia("$folder/content/clase-$mark", 'video', 'authenticated', 'mp4', 2_500_000, 95, 'clase.mp4', 'video/mp4', '');
    $document = new VerifiedMedia("$folder/content/guia-$mark.pdf", 'raw', 'authenticated', 'pdf', 120_000, null, 'guia.pdf', 'application/pdf', '');
    $mainInput = $validator->validateContent(['tipo' => 'video_principal', 'archivo' => []]);
    $main = $courseRepository->addContent($instructor->id(), $chapter1->id(), $mainInput, 'Clase 1', $video);
    check(
        $main->isMain() && $main->contentType() === ContentType::Video && $main->contentUri() === $video->reference()
            && $main->durationSeconds() === 95 && $main->sizeBytes() === '2500000' && $main->mimeType() === 'video/mp4',
        'video principal guardado con referencia protegida, duración, tamaño y MIME',
    );
    check(ruleCode(static fn () => $courseRepository->addContent($instructor->id(), $chapter1->id(), $mainInput, 'Otra', $video)) === 'MAIN_VIDEO_EXISTS', 'segundo video principal -> MAIN_VIDEO_EXISTS');
    $docInput = $validator->validateContent(['tipo' => 'documento', 'archivo' => []]);
    $courseRepository->addContent($instructor->id(), $chapter1->id(), $docInput, 'Guía', $document);
    $linkInput = $validator->validateContent(['tipo' => 'enlace', 'titulo' => 'Manual de PHP', 'url' => 'https://www.php.net/manual/es/']);
    $link = $courseRepository->addContent($instructor->id(), $chapter1->id(), $linkInput, 'Manual de PHP', null);
    check($link->contentType() === ContentType::Link && $link->contentUri() === 'https://www.php.net/manual/es/' && !$link->isMain(), 'enlace guardado como recurso');
    $textInput = $validator->validateContent(['tipo' => 'texto', 'titulo' => 'Notas', 'texto' => 'Repasa el capítulo.']);
    $text = $courseRepository->addContent($instructor->id(), $chapter2->id(), $textInput, 'Notas', null);
    check($text->contentType() === ContentType::Text && $text->contentUri() === null && $text->textContent() === 'Repasa el capítulo.', 'texto guardado sin URI');
    check(ruleCode(static fn () => $courseRepository->addContent($otherInstructor->id(), $chapter1->id(), $linkInput, 'X', null)) === 'CHAPTER_NOT_FOUND', 'otro instructor no agrega contenido');

    $outline = $courseService->outline($instructor, $course->id());
    check(count($outline->chapters($levelId)) === 2 && $outline->chapterCount() === 2, 'el editor agrupa los capítulos por nivel');
    check($outline->mainVideo($chapter1->id())?->id() === $main->id() && count($outline->resources($chapter1->id())) === 2, 'video principal aparte de los recursos');
    check($outline->mainVideo($chapter2->id()) === null, 'capítulo sin video -> null');

    check(ruleCode(static fn () => $courseRepository->deleteContent($otherInstructor->id(), $link->id())) === 'CONTENT_NOT_FOUND', 'otro instructor no borra contenido');
    check($courseRepository->deleteContent($instructor->id(), $link->id()) === ['https://www.php.net/manual/es/'], 'borrar un recurso devuelve su referencia');
    $refs = $courseRepository->deleteChapter($instructor->id(), $chapter1->id());
    sort($refs);
    $expected = [$document->reference(), $video->reference()];
    sort($expected);
    check($refs === $expected, 'borrar un capítulo devuelve las referencias de todo su contenido');
    $remaining = $courseService->outline($instructor, $course->id())->chapters($levelId);
    check(count($remaining) === 1 && $remaining[0]->id() === $chapter2->id() && $remaining[0]->sortOrder() === 1, 'el capítulo siguiente pasa a ser el 1');
    check($courseRepository->deleteChapter($instructor->id(), $chapter2->id()) === [], 'un capítulo solo con texto no tiene archivos que borrar');

    $summaryOf = static fn (string $courseId) => array_values(array_filter(
        $courseService->listMine($instructor),
        static fn ($s): bool => $s->id === $courseId,
    ))[0] ?? null;
    $summary = $summaryOf($course->id());
    check($summary->levelCount === 2 && $summary->chapterCount === 0 && $summary->studentCount === 0, '"Mis cursos" cuenta niveles, capítulos y alumnos');
    check($summary->levelPriceTotal === '300.00', '"Mis cursos" suma los precios de los niveles (0 + 300)');

    // -----------------------------------------------------------
    Report::section('Forma de cobro');
    $costs = static fn (): array => array_map(static fn ($l): string => $l->cost(), $courseService->outline($instructor, $course->id())->levels);
    $withMode = static fn (string $mode, string $price = '') => $validator->validateCourse(['precio_modo' => $mode, 'precio' => $price] + $courseForm, false);
    [$free] = $courseRepository->update($instructor->id(), $course->id(), $withMode('gratis'), null, null);
    check($free->fullCoursePrice() === '0.00' && $costs() === ['0.00', '0.00'], 'pasar a gratis pone en 0 todos los niveles');
    $freeLevel = $courseRepository->createLevel($instructor->id(), $course->id(), $levelInput('Nivel extra', '450'), $levelVideo(4));
    check($freeLevel->cost() === '0.00' && $freeLevel->sortOrder() === 3, 'en un curso gratis, un nivel nuevo queda en 0 aunque llegue un precio');
    [$stillFree] = $courseRepository->updateLevel($instructor->id(), $levels[3]->id(), $levelInput('Nivel 3', '99'), null);
    check($stillFree->cost() === '0.00', 'y editar un nivel tampoco le pone precio');
    [$bundle] = $courseRepository->update($instructor->id(), $course->id(), $withMode('paquete', '799'), null, null);
    [$priced] = $courseRepository->updateLevel($instructor->id(), $levels[3]->id(), $levelInput('Nivel 3', '120.5'), null);
    check($bundle->fullCoursePrice() === '799.00' && $priced->cost() === '120.50', 'en curso completo + niveles, cada nivel vuelve a tener precio');
    check($summaryOf($course->id())?->levelPriceTotal === '120.50', 'la suma de niveles se actualiza');
    // PRICING_LOCKED necesita alumnos inscritos: se prueba con la entrega de inscripciones y pagos.

    // -----------------------------------------------------------
    Report::section('Orden de niveles y capítulos');
    $levelOrder = static fn (): array => array_map(static fn ($l): string => $l->id(), $courseService->outline($instructor, $course->id())->levels);
    check($levelOrder() === [$levels[1]->id(), $levels[3]->id(), $freeLevel->id()], 'orden inicial: 1, 3 y el extra');
    $moved = $courseRepository->moveLevel($instructor->id(), $freeLevel->id(), 'up');
    check($moved->sortOrder() === 2 && $levelOrder() === [$levels[1]->id(), $freeLevel->id(), $levels[3]->id()], 'subir el último lo intercambia con su vecino');
    check($courseRepository->moveLevel($instructor->id(), $levels[1]->id(), 'up')->sortOrder() === 1, 'subir el primero no cambia nada');
    check($courseRepository->moveLevel($instructor->id(), $levels[3]->id(), 'down')->sortOrder() === 3, 'bajar el último no cambia nada');
    check(
        array_map(static fn ($l): int => $l->sortOrder(), $courseService->outline($instructor, $course->id())->levels) === [1, 2, 3],
        'los órdenes quedan 1, 2, 3 sin huecos ni repetidos',
    );
    check(ruleCode(static fn () => $courseRepository->moveLevel($otherInstructor->id(), $levels[1]->id(), 'down')) === 'LEVEL_NOT_FOUND', 'otro instructor no mueve niveles');
    $badDirection = thrown(ProcedureException::class, static fn () => $courseRepository->moveLevel($instructor->id(), $levels[1]->id(), 'left'));
    check($badDirection !== null && $badDirection->is('INVALID_DIRECTION'), 'el SP rechaza una dirección desconocida');

    $chapterIds = array_map(
        static fn (string $title): string => $courseRepository->createChapter($instructor->id(), $levelId, $videoChapter($title))->id(),
        ['A', 'B', 'C'],
    );
    $chapterOrder = static fn (): array => array_map(static fn ($c): string => $c->title(), $courseService->outline($instructor, $course->id())->chapters($levelId));
    $courseService->moveChapter($instructor, $chapterIds[2], ['direccion' => 'arriba']);
    check($chapterOrder() === ['A', 'C', 'B'], 'subir un capítulo: A, C, B');
    $courseService->moveChapter($instructor, $chapterIds[0], ['direccion' => 'abajo']);
    check($chapterOrder() === ['C', 'A', 'B'], 'bajar un capítulo: C, A, B');
    check($courseService->moveChapter($instructor, $chapterIds[1], ['direccion' => 'abajo'])->sortOrder() === 3, 'bajar el último capítulo no cambia nada');
    check(ruleCode(static fn () => $courseRepository->moveChapter($otherInstructor->id(), $chapterIds[0], 'up')) === 'CHAPTER_NOT_FOUND', 'otro instructor no mueve capítulos');
    check(failedFields(static fn () => $courseService->moveChapter($instructor, $chapterIds[0], ['direccion' => 'up'])) === ['direccion'], 'la API pide arriba o abajo');

    // -----------------------------------------------------------
    Report::section('Exámenes');
    $exam = $courseRepository->createChapter($instructor->id(), $levelId, $validator->validateChapter(['titulo' => 'Examen del nivel', 'tipo' => 'examen', 'minimo' => '70'], true));
    check($exam->isTest() && $exam->minimumPassingGrade() === '70.00' && $exam->sortOrder() === 4, 'examen creado al final del nivel con mínimo 70.00');
    check(ruleCode(static fn () => $courseRepository->addContent($instructor->id(), $exam->id(), $linkInput, 'X', null)) === 'CHAPTER_IS_EXAM', 'un examen no lleva contenido -> CHAPTER_IS_EXAM');
    $questionInput = static fn (array $input) => $validator->validateQuestion($input);
    $yesNo = $questionInput(['pregunta' => '¿PHP se ejecuta en el servidor?', 'modo' => 'unica', 'respuestas' => [
        ['texto' => 'Sí', 'correcta' => true], ['texto' => 'No', 'correcta' => false],
    ]]);
    check(ruleCode(static fn () => $courseRepository->createQuestion($instructor->id(), $chapterIds[0], $yesNo)) === 'CHAPTER_NOT_EXAM', 'las preguntas solo van en exámenes -> CHAPTER_NOT_EXAM');
    check(ruleCode(static fn () => $courseRepository->createQuestion($otherInstructor->id(), $exam->id(), $yesNo)) === 'CHAPTER_NOT_FOUND', 'otro instructor no agrega preguntas');

    [$q1, $q1Answers] = $courseRepository->createQuestion($instructor->id(), $exam->id(), $yesNo);
    check($q1->sortOrder() === 1 && count($q1Answers) === 2 && $q1Answers[0]->isCorrect() && $q1Answers[0]->weight() === '1.00' && $q1Answers[1]->weight() === '0.00', 'pregunta de opción única con sus opciones y pesos');
    [$q2, $q2Answers] = $courseRepository->createQuestion($instructor->id(), $exam->id(), $questionInput(['pregunta' => 'Lenguajes del servidor', 'modo' => 'multiple', 'respuestas' => [
        ['texto' => 'PHP', 'correcta' => true, 'peso' => '2'], ['texto' => 'Python', 'correcta' => true, 'peso' => '3'], ['texto' => 'CSS', 'correcta' => false],
    ]]));
    check($q2->sortOrder() === 2 && array_map(static fn ($a): int => $a->sortOrder(), $q2Answers) === [1, 2, 3], 'opción múltiple: opciones numeradas en su orden');
    $forged = new QuestionInput('Forzada', SelectionMode::Single, [
        ['answer' => 'A', 'correct' => true, 'weight' => '1.00'], ['answer' => 'B', 'correct' => true, 'weight' => '1.00'],
    ]);
    check(ruleCode(static fn () => $courseRepository->createQuestion($instructor->id(), $exam->id(), $forged)) === 'INVALID_ANSWERS', 'el SP también rechaza dos correctas en opción única');
    $alone = new QuestionInput('Sola', SelectionMode::Multiple, [['answer' => 'A', 'correct' => true, 'weight' => '1.00']]);
    check(ruleCode(static fn () => $courseRepository->createQuestion($instructor->id(), $exam->id(), $alone)) === 'INVALID_ANSWERS', 'y una sola opción');

    [$edited, $editedAnswers] = $courseRepository->updateQuestion($instructor->id(), $q1->id(), $questionInput(['pregunta' => '¿Dónde se ejecuta PHP?', 'modo' => 'unica', 'respuestas' => [
        ['texto' => 'En el servidor', 'correcta' => true], ['texto' => 'En el navegador', 'correcta' => false], ['texto' => 'En la base de datos', 'correcta' => false],
    ]]));
    check($edited->question() === '¿Dónde se ejecuta PHP?' && count($editedAnswers) === 3 && $editedAnswers[0]->id() !== $q1Answers[0]->id(), 'editar reemplaza el texto y las opciones');
    check(ruleCode(static fn () => $courseRepository->updateQuestion($otherInstructor->id(), $q1->id(), $yesNo)) === 'QUESTION_NOT_FOUND', 'otro instructor no edita preguntas');
    check($courseRepository->moveQuestion($instructor->id(), $q2->id(), 'up')->sortOrder() === 1, 'subir la pregunta 2');
    $examOutline = $courseService->outline($instructor, $course->id());
    check(array_map(static fn ($q): string => $q->id(), $examOutline->questions($exam->id())) === [$q2->id(), $q1->id()], 'el editor ve las preguntas en su nuevo orden');
    check(count($examOutline->answers($q2->id())) === 3, 'con sus opciones');

    $correctOf = static fn (array $answers): array => array_values(array_map(static fn ($a): string => $a->id(), array_filter($answers, static fn ($a): bool => $a->isCorrect())));
    $wrongOf = static fn (array $answers): array => array_values(array_map(static fn ($a): string => $a->id(), array_filter($answers, static fn ($a): bool => !$a->isCorrect())));
    $preview = static fn (array $answers) => $courseService->previewGrade($instructor, $exam->id(), ['respuestas' => $answers]);
    $half = $preview([$q2->id() => [$correctOf($q2Answers)[0]], $q1->id() => $correctOf($editedAnswers)]);
    check($half->earnedPoints === '3.00' && $half->maxPoints === '6.00' && $half->grade === '50.00' && !$half->passed, 'probar examen: PHP (2) + servidor (1) = 3 de 6, no aprueba con 70');
    $full = $preview([$q2->id() => $correctOf($q2Answers), $q1->id() => $correctOf($editedAnswers)]);
    check($full->grade === '100.00' && $full->passed, 'todas las correctas: 100.00, aprueba');
    $wrong = $preview([$q2->id() => [...$correctOf($q2Answers), ...$wrongOf($q2Answers)], $q1->id() => $correctOf($editedAnswers)]);
    check($wrong->earnedPoints === '1.00' && $wrong->questions[0]['markedWrong'], 'marcar una incorrecta deja la pregunta en 0');
    check(failedFields(static fn () => $preview([$q1->id() => [$q2Answers[0]->id()]])) === ['respuestas'], 'una opción de otra pregunta se rechaza');
    check(failedFields(static fn () => $preview([$q1->id() => $wrongOf($editedAnswers)])) === ['respuestas'], 'dos opciones en una pregunta de opción única se rechazan');
    check(ruleCode(static fn () => $courseService->previewGrade($instructor, $chapterIds[0], ['respuestas' => []])) === 'CHAPTER_NOT_EXAM', 'un capítulo de video no se califica');

    check($courseRepository->updateChapter($instructor->id(), $exam->id(), $validator->validateChapter(['titulo' => 'Examen', 'minimo' => '80'], false))->minimumPassingGrade() === '80.00', 'cambiar el mínimo a 80');
    check($courseRepository->updateChapter($instructor->id(), $exam->id(), $renamed('Examen final'))->minimumPassingGrade() === '80.00', 'renombrar sin mínimo lo conserva');
    check($courseRepository->deleteQuestion($instructor->id(), $q2->id()) === $exam->id(), 'borrar una pregunta');
    check(array_map(static fn ($q): int => $q->sortOrder(), $courseService->outline($instructor, $course->id())->questions($exam->id())) === [1], 'la siguiente pasa a ser la 1');
    check(ruleCode(static fn () => $courseRepository->deleteQuestion($instructor->id(), $q2->id())) === 'QUESTION_NOT_FOUND', 'borrarla otra vez -> QUESTION_NOT_FOUND');
    check($courseRepository->deleteChapter($instructor->id(), $exam->id()) === [], 'borrar el examen borra sus preguntas (sin archivos)');

    // -----------------------------------------------------------
    Report::section('Publicación y baja lógica');
    $publishCategory = $categories->create($admin, ['nombre' => "Prueba $mark publicar"]);
    $draftInput = $validator->validateCourse(['titulo' => "Publicable $mark", 'categorias' => [$publishCategory->id()], 'precio_modo' => 'niveles'] + $courseForm, true);
    $draft = $courseRepository->create($instructor->id(), $draftInput, $cover, $promo);
    $publish = static fn () => $courseService->publish($instructor, $draft->id());
    $categories->update($admin, $publishCategory->id(), ['activa' => false]);
    check(ruleCode($publish) === 'NO_ACTIVE_CATEGORY', 'sin categoría activa -> NO_ACTIVE_CATEGORY');
    $categories->update($admin, $publishCategory->id(), ['activa' => true]);
    check(ruleCode($publish) === 'NO_LEVELS', 'sin niveles -> NO_LEVELS');
    $draftLevel = $courseRepository->createLevel($instructor->id(), $draft->id(), $levelInput('Único', '0'), $levelVideo(7));
    check(ruleCode($publish) === 'LEVEL_WITHOUT_CHAPTERS', 'un nivel sin capítulos -> LEVEL_WITHOUT_CHAPTERS');
    $draftChapter = $courseRepository->createChapter($instructor->id(), $draftLevel->id(), $videoChapter('Bienvenida'));
    check(ruleCode($publish) === 'CHAPTER_WITHOUT_VIDEO', 'un capítulo sin video -> CHAPTER_WITHOUT_VIDEO');
    check(!PublishChecklist::for($courseService->outline($instructor, $draft->id()))->ready(), 'la lista del editor también marca el pendiente');
    $courseRepository->addContent($instructor->id(), $draftChapter->id(), $mainInput, 'Bienvenida', $video);
    $draftExam = $courseRepository->createChapter($instructor->id(), $draftLevel->id(), $validator->validateChapter(['titulo' => 'Examen de bienvenida', 'tipo' => 'examen', 'minimo' => '60'], true));
    check(ruleCode($publish) === 'EXAM_WITHOUT_QUESTIONS', 'un examen sin preguntas -> EXAM_WITHOUT_QUESTIONS');
    $courseRepository->createQuestion($instructor->id(), $draftExam->id(), $yesNo);
    check(PublishChecklist::for($courseService->outline($instructor, $draft->id()))->ready(), 'con el video y la pregunta, la lista queda completa');
    check(ruleCode(static fn () => $courseService->publish($otherInstructor, $draft->id())) === 'COURSE_NOT_FOUND', 'otro instructor no lo publica');

    $published = $publish();
    check($published->publishedAt() !== null && $published->active(), 'publicado: published_at con fecha');
    check(ruleCode($publish) === 'COURSE_ALREADY_PUBLISHED', 'publicarlo otra vez -> COURSE_ALREADY_PUBLISHED');
    check($summaryOf($draft->id())?->isPublished() === true, '"Mis cursos" lo muestra publicado');
    check(ruleCode(static fn () => $categories->update($admin, $publishCategory->id(), ['activa' => false])) === 'CATEGORY_IN_USE', 'su única categoría ya no se puede desactivar (CATEGORY_IN_USE)');

    check($courseService->unpublish($instructor, $draft->id())->publishedAt() === null, 'sin alumnos puede volver a borrador');
    check(ruleCode(static fn () => $courseService->unpublish($instructor, $draft->id())) === 'COURSE_NOT_PUBLISHED', 'un borrador no se "despublica" -> COURSE_NOT_PUBLISHED');
    $published = $publish();

    $inactive = $courseService->setActive($instructor, $draft->id(), ['activo' => false]);
    check(!$inactive->active() && $inactive->deactivatedAt() !== null && $inactive->publishedAt() !== null, 'dado de baja: inactivo, con fecha de baja y conserva published_at');
    check($courseService->setActive($instructor, $draft->id(), ['activo' => false])->deactivatedAt() == $inactive->deactivatedAt(), 'darlo de baja otra vez no cambia la fecha');
    check(ruleCode($publish) === 'COURSE_INACTIVE', 'dado de baja no se publica -> COURSE_INACTIVE');
    check(failedFields(static fn () => $courseService->setActive($instructor, $draft->id(), ['activo' => 'no'])) === ['activo'], 'activo debe ser true o false');
    check(ruleCode(static fn () => $courseService->setActive($otherInstructor, $draft->id(), ['activo' => true])) === 'COURSE_NOT_FOUND', 'otro instructor no lo reactiva');
    $reactivated = $courseService->setActive($instructor, $draft->id(), ['activo' => true]);
    check($reactivated->active() && $reactivated->deactivatedAt() === null && $reactivated->publishedAt() == $published->publishedAt(), 'reactivado vuelve a estar publicado como antes');

    // -----------------------------------------------------------
    Report::section('Catálogo público');
    $find = static fn (array $filters): array => array_map(static fn ($c): string => $c->id, $catalog->search($filters)->courses);
    $page = $catalog->search(['q' => "publicable $mark"]);
    check($page->total === 1 && $page->courses[0]->id === $draft->id(), 'el curso publicado aparece al buscar su título (sin importar mayúsculas)');
    $card = $page->courses[0];
    check($card->instructorName === 'Instructora De Prueba Núñez' && $card->categoryNames === ["Prueba $mark publicar"] && $card->levelCount === 1 && $card->chapterCount === 2, 'tarjeta con instructor, categoría y conteos');
    check(in_array($draft->id(), $find(['q' => 'Instructora De Prueba']), true), 'también se encuentra por el nombre del instructor');
    check(in_array($draft->id(), $find(['q' => $mark, 'categoria' => $publishCategory->id()]), true) && !in_array($draft->id(), $find(['q' => $mark, 'categoria' => $category->id()]), true), 'el filtro de categoría funciona');
    check($catalog->search(['q' => '%'])->total === 0 && $catalog->search(['q' => '_'])->total === 0, '% y _ se buscan como texto, no como comodines');
    check(!in_array($course->id(), $find(['q' => $mark]), true), 'un borrador no aparece en el catálogo');
    check(ruleCode(static fn () => $catalog->course($course->id())) === 'COURSE_NOT_FOUND', 'ni su página pública');
    $second = $catalog->search(['q' => "publicable $mark", 'pagina' => '2']);
    check($second->courses === [] && $second->total === 1 && $second->page === 2, 'paginación: la página 2 viene vacía con el total');
    foreach (['titulo', 'precio'] as $sort) {
        check(in_array($draft->id(), $find(['q' => $mark, 'orden' => $sort]), true), "ordenar por $sort");
    }
    $public = $catalog->course($draft->id());
    $publicChapters = $public->levels[0]['chapters'];
    check($public->title === "Publicable $mark" && $public->instructorName === 'Instructora De Prueba Núñez' && count($public->levels) === 1, 'página pública con su temario');
    check($publicChapters[0]['durationSeconds'] === 95 && !$publicChapters[0]['isExam'] && $publicChapters[1]['isExam'] && $publicChapters[1]['questionCount'] === 1, 'capítulos con la duración del video y los exámenes con sus preguntas');
    check($public->durationSeconds() === 95 && $public->examCount() === 1 && $public->levelPriceTotal() === '0.00', 'duración total, exámenes y precio');
    check(!str_contains(json_encode(App\Http\Presenters\CatalogPresenter::course($public), JSON_THROW_ON_ERROR), 'cloudinary:'), 'el JSON público no trae referencias protegidas');

    // Limpieza: dado de baja, su categoría ya se puede desactivar.
    $courseService->setActive($instructor, $draft->id(), ['activo' => false]);
    check(ruleCode(static fn () => $catalog->course($draft->id())) === 'COURSE_NOT_FOUND' && $catalog->search(['q' => "publicable $mark"])->total === 0, 'dado de baja desaparece del catálogo');
    $categories->update($admin, $publishCategory->id(), ['activa' => false]);

    // Deja la lista de categorías del instructor sin las de prueba (el curso es un borrador).
    $categories->update($admin, $category->id(), ['activa' => false]);
    check(!in_array($category->id(), array_map(static fn ($c): string => $c->id(), $categories->active()), true), 'las categorías de prueba se desactivan al terminar');
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), PHP_EOL));
    if ($exception->getPrevious() !== null) {
        fwrite(STDERR, 'Causa: ' . $exception->getPrevious()->getMessage() . PHP_EOL);
    }
    exit(1);
}

Report::finish();
