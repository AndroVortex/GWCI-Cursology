<?php

declare(strict_types=1);

/**
 * Verificación de perfil, primer admin, categorías y cursos contra la base LOCAL,
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
use App\Models\Entities\AppUser;
use App\Models\Enums\ContentType;
use App\Models\Enums\UserRole;
use App\Repositories\CategoryRepository;
use App\Repositories\CourseRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CategoryService;
use App\Services\CourseService;
use App\Services\CourseValidator;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\InvalidCredentialsException;
use App\Services\Exceptions\ValidationException;
use App\Services\MediaService;
use App\Services\ProfileService;
use App\Services\ProfileValidator;
use App\Services\RegistrationValidator;
use App\Services\VerifiedMedia;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/database/conn.php';

$procedures = new StoredProcedures(new Connection($DATABASES['local']));
$users = new UserRepository($procedures);
$noCloudinary = new CloudinaryClient('', '', '');
$auth = new AuthService($users, new RegistrationValidator(), $noCloudinary);
$profiles = new ProfileService($users, new ProfileValidator(), $noCloudinary);
$categoryRepository = new CategoryRepository($procedures);
$categories = new CategoryService($categoryRepository);
$courseRepository = new CourseRepository($procedures);
$validator = new CourseValidator();
$courseService = new CourseService($courseRepository, $validator, new MediaService($noCloudinary));

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
        'precio_modo' => 'fijo', 'precio' => '1249.9', 'categorias' => [$category->id()], 'portada' => [], 'video' => [],
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
    $chapter1 = $courseRepository->createChapter($instructor->id(), $levelId, 'Introducción');
    $chapter2 = $courseRepository->createChapter($instructor->id(), $levelId, 'Variables');
    check($chapter1->sortOrder() === 1 && $chapter2->sortOrder() === 2 && !$chapter1->isTest(), 'capítulos de video numerados 1, 2');
    check(ruleCode(static fn () => $courseRepository->createChapter($otherInstructor->id(), $levelId, 'Ajeno')) === 'LEVEL_NOT_FOUND', 'otro instructor no agrega capítulos');
    check($courseRepository->updateChapter($instructor->id(), $chapter2->id(), 'Variables y tipos')->title() === 'Variables y tipos', 'renombrar capítulo');
    check(ruleCode(static fn () => $courseRepository->updateChapter($otherInstructor->id(), $chapter2->id(), 'X')) === 'CHAPTER_NOT_FOUND', 'otro instructor no lo renombra');
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

    $summary = array_values(array_filter($courseService->listMine($instructor), static fn ($s): bool => $s->id === $course->id()))[0];
    check($summary->levelCount === 2 && $summary->chapterCount === 0 && $summary->studentCount === 0, '"Mis cursos" cuenta niveles, capítulos y alumnos');

    // Deja la lista de categorías del instructor sin las de prueba (el curso es un borrador).
    $categories->update($admin, $category->id(), ['activa' => false]);
    check(!in_array($category->id(), array_map(static fn ($c): string => $c->id(), $categories->active()), true), 'la categoría de prueba se desactiva al terminar');
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), PHP_EOL));
    if ($exception->getPrevious() !== null) {
        fwrite(STDERR, 'Causa: ' . $exception->getPrevious()->getMessage() . PHP_EOL);
    }
    exit(1);
}

Report::finish();
