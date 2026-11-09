<?php

declare(strict_types=1);

/**
 * Verificación del certificado y las opiniones contra la base de pruebas (cursology_test),
 * creada con php scripts/setup_test_db.php.
 *
 *   php tests/verify_certificate_db.php
 *
 * El PDF se guarda en un almacén falso (no hay red); la subida real a Cloudinary está en
 * tests/verify_cloudinary_live.php. Cada corrida deja cuentas de prueba
 * (prueba+<marca>...@cursology.test), una categoría y dos cursos "Certificado <marca>"
 * (dados de baja y desactivada al terminar).
 */

use App\Database\Connection;
use App\Database\ProcedureException;
use App\Database\StoredProcedures;
use App\Integrations\CloudinaryClient;
use App\Integrations\MediaUploadException;
use App\Integrations\PayPalClient;
use App\Models\Enums\CertificateStatus;
use App\Repositories\CatalogRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CertificateRepository;
use App\Repositories\CourseRepository;
use App\Repositories\LearningRepository;
use App\Repositories\PaymentMethodRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CatalogService;
use App\Services\CategoryService;
use App\Services\CertificateRenderer;
use App\Services\CertificateService;
use App\Services\CertificateStore;
use App\Services\CheckoutService;
use App\Services\CourseService;
use App\Services\CourseValidator;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\LearningService;
use App\Services\MediaService;
use App\Services\PaymentMethodService;
use App\Services\ProfileService;
use App\Services\ProfileValidator;
use App\Services\RegistrationValidator;
use App\Services\ReviewService;
use App\Services\VerifiedMedia;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/database/conn.php';

/** Almacén de PDF en memoria; puede fallar a propósito para probar el reintento. */
final class FakeCertificateStore implements CertificateStore
{
    /** @var list<array{userId: string, code: string, pdf: string}> */
    public array $saved = [];
    public int $failuresLeft = 0;

    public function save(string $userId, string $verificationCode, string $pdf): string
    {
        if ($this->failuresLeft > 0) {
            $this->failuresLeft--;
            throw MediaUploadException::failed('falla simulada de la subida.');
        }
        $this->saved[] = ['userId' => $userId, 'code' => $verificationCode, 'pdf' => $pdf];

        return 'cloudinary:authenticated/raw/cursology/certificates/u' . $userId . '/' . $verificationCode . '.pdf';
    }

    public function read(string $reference): string
    {
        foreach ($this->saved as $file) {
            if (str_ends_with($reference, '/' . $file['code'] . '.pdf')) {
                return $file['pdf'];
            }
        }

        throw MediaUploadException::failed('no existe en el almacén falso.');
    }
}

$procedures = new StoredProcedures(new Connection(testDatabase()));
$users = new UserRepository($procedures);
$noCloudinary = new CloudinaryClient('', '', '');
$auth = new AuthService($users, new RegistrationValidator(), $noCloudinary);
$profiles = new ProfileService($users, new ProfileValidator(), $noCloudinary);
$categories = new CategoryService(new CategoryRepository($procedures));
$courseRepository = new CourseRepository($procedures);
$validator = new CourseValidator();
$media = new MediaService($noCloudinary);
$courseService = new CourseService($courseRepository, $validator, $media);
$catalog = new CatalogService(new CatalogRepository($procedures));
$checkout = new CheckoutService(new PurchaseRepository($procedures), new PaymentMethodService(new PaymentMethodRepository($procedures), new PayPalClient('', '')), new PayPalClient('', ''));
$learning = new LearningService(new LearningRepository($procedures), $media);
$store = new FakeCertificateStore();
$certificates = new CertificateService(new CertificateRepository($procedures), new CertificateRenderer(), $store, 'http://localhost:8000');
$reviewRepository = new ReviewRepository($procedures);
$reviews = new ReviewService($reviewRepository);

try {
    $procedures->call('sp_certificate_list_by_student', [1]);
    $procedures->call('sp_review_mine', [1, 1]);
} catch (ProcedureException $exception) {
    fwrite(STDERR, 'No se pudieron ejecutar los SP de certificados u opiniones en la base de pruebas. Revisa que MySQL esté encendido' . PHP_EOL
        . 'y recrea la base con: php scripts/setup_test_db.php' . PHP_EOL
        . 'Detalle: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()) . PHP_EOL);
    exit(1);
}

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

try {
    $mark = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $account = static fn (string $role, string $suffix, string $name): array => [
        'rol' => $role, 'nombre' => $name, 'apellido_paterno' => 'Certificado', 'apellido_materno' => 'Núñez',
        'genero' => 'otro', 'fecha_nacimiento' => '1998-05-20', 'email' => "prueba+$mark-$suffix@cursology.test", 'password' => 'Segura#2026',
    ];
    $instructor = $auth->register($account('instructor', 'instructor', 'Mariana'), null);
    $otherInstructor = $auth->register($account('instructor', 'otro', 'Otro'), null);
    $s1 = $auth->register($account('student', 'alumno1', 'Sofía'), null);
    $s2 = $auth->register($account('student', 'alumno2', 'Diego'), null);
    $s3 = $auth->register($account('student', 'alumno3', 'Ana'), null);
    $s4 = $auth->register($account('student', 'alumno4', 'Luis'), null);
    $admin = $users->findForLogin('admin@cursology.local') ?? throw new RuntimeException('Falta la cuenta admin@cursology.local.');
    printf('Cuentas de prueba de esta corrida: prueba+%s-{instructor,otro,alumno1..4}@cursology.test%s%s', $mark, PHP_EOL, PHP_EOL);

    // ---------- Dos cursos gratis publicados, de un nivel y un capítulo ----------
    $category = $categories->create($admin, ['nombre' => "Prueba $mark certificados"]);
    $folder = 'cursology/courses/u' . $instructor->id();
    $mainInput = $validator->validateContent(['tipo' => 'video_principal', 'archivo' => []]);
    $courseForm = static fn (string $title, string $certifier): array => [
        'titulo' => $title, 'descripcion' => 'Curso de prueba.', 'certificador' => $certifier, 'precio_modo' => 'gratis',
        'precio' => '', 'categorias' => [$category->id()], 'portada' => [], 'video' => [],
    ];
    $newCourse = static function (string $title, string $certifier) use ($validator, $courseRepository, $courseService, $instructor, $folder, $mark, $mainInput, $courseForm): array {
        $course = $courseRepository->create($instructor->id(), $validator->validateCourse($courseForm($title, $certifier), true), "https://res.cloudinary.com/prueba/image/upload/v1/$folder/covers/c-$mark.jpg", "https://res.cloudinary.com/prueba/video/upload/v1/$folder/promos/p-$mark.mp4");
        $level = $courseRepository->createLevel($instructor->id(), $course->id(), $validator->validateLevel(['titulo' => 'Único', 'descripcion' => 'Nivel único.', 'costo' => '0', 'video' => []], true), "https://res.cloudinary.com/prueba/video/upload/v1/$folder/levels/l-$mark.mp4");
        $chapter = $courseRepository->createChapter($instructor->id(), $level->id(), $validator->validateChapter(['titulo' => 'Bienvenida', 'tipo' => 'video'], true));
        $courseRepository->addContent($instructor->id(), $chapter->id(), $mainInput, 'Bienvenida', new VerifiedMedia("$folder/content/v-$mark-" . bin2hex(random_bytes(2)), 'video', 'authenticated', 'mp4', 1000, 50, 'v.mp4', 'video/mp4', ''));
        $courseService->publish($instructor, $course->id());

        return [$course, $chapter];
    };
    [$course, $chapter] = $newCourse("Certificado $mark", '');
    [$course2, $chapter2] = $newCourse("Certificado $mark dos", 'Academia de Prueba');
    $finish = static fn ($student, $chapterId) => $learning->progress($student, $chapterId, ['terminado' => true]);
    foreach ([$s1, $s2, $s3] as $student) {
        $checkout->enrollFree($student, ['curso' => $course->id()]);
    }

    // -----------------------------------------------------------
    Report::section('Emitir el certificado');
    check(ruleCode(static fn () => $certificates->issue($s4, $course->id())) === 'NOT_ENROLLED', 'sin inscripción -> NOT_ENROLLED');
    check(ruleCode(static fn () => $certificates->issue($s1, $course->id())) === 'COURSE_NOT_COMPLETED', 'sin terminar el curso -> COURSE_NOT_COMPLETED');
    check(ruleCode(static fn () => $certificates->issue($s1, 'abc')) === 'NOT_ENROLLED', 'curso inválido -> NOT_ENROLLED sin llamar al SP');
    $finish($s1, $chapter->id());

    $store->failuresLeft = 1;
    check(thrown(MediaUploadException::class, static fn () => $certificates->issue($s1, $course->id())) !== null, 'si la subida falla, el error llega al alumno');
    $pending = $certificates->forStudent($s1);
    check(count($pending) === 1 && $pending[0]->status() === CertificateStatus::Pending && $pending[0]->documentUri() === null, 'y el certificado queda pendiente, sin archivo');
    check(ruleCode(static fn () => $certificates->verify($pending[0]->verificationCode())) === 'CERTIFICATE_NOT_FOUND', 'un pendiente no se puede verificar');

    $issued = $certificates->issue($s1, $course->id());
    check($issued->status() === CertificateStatus::Issued && $issued->issuedAt() !== null && $issued->id() === $pending[0]->id(), 'el reintento lo emite (el mismo certificado)');
    check($issued->verificationCode() === $pending[0]->verificationCode() && CertificateService::isCode($issued->verificationCode()), 'con el mismo código UUID');
    check($issued->documentUri() === 'cloudinary:authenticated/raw/cursology/certificates/u' . $s1->id() . '/' . $issued->verificationCode() . '.pdf', 'la referencia del PDF protegido queda guardada');
    check($issued->studentNameSnapshot() === 'Sofía Certificado Núñez' && $issued->courseTitleSnapshot() === "Certificado $mark", 'datos congelados: alumno y curso');
    check($issued->certifierNameSnapshot() === 'Mariana Certificado Núñez' && $issued->issuedBy() === $instructor->id(), 'sin certificador en el curso, certifica (y emite) el instructor');
    check($issued->templateSnapshot() === CertificateRenderer::TEMPLATE, 'con la plantilla cursology-v1');
    $savedPdf = $store->saved[0]['pdf'] ?? '';
    check(count($store->saved) === 1 && str_starts_with($savedPdf, '%PDF-1.4') && str_contains($savedPdf, "(Sof\xEDa Certificado N\xFA\xF1ez)") && str_contains($savedPdf, $issued->verificationCode()), 'el PDF guardado trae el nombre y el código');
    check(str_contains($savedPdf, 'http://localhost:8000/?view=verify&code=' . $issued->verificationCode()), 'y la dirección de verificación con APP_URL');
    $again = $certificates->issue($s1, $course->id());
    check($again->id() === $issued->id() && $again->issuedAt() == $issued->issuedAt() && count($store->saved) === 1, 'pedirlo otra vez devuelve el mismo sin generar otro PDF');
    check($certificates->downloadUrl($again) === 'api/student/certificates/' . $issued->id() . '/download' && $certificates->downloadUrl($pending[0]) === null, 'la descarga es una ruta de la app, solo para el emitido');
    $download = $certificates->download($s1, $issued->id());
    check($download['fileName'] === "Certificado - Certificado $mark.pdf" && $download['pdf'] === $savedPdf, 'la descarga entrega el PDF guardado como "Certificado - <curso>.pdf"');
    check(ruleCode(static fn () => $certificates->download($s2, $issued->id())) === 'CERTIFICATE_NOT_FOUND', 'otro alumno no descarga el certificado ajeno -> CERTIFICATE_NOT_FOUND');
    check(ruleCode(static fn () => $certificates->download($s1, '999999999999')) === 'CERTIFICATE_NOT_FOUND', 'un certificado que no existe -> CERTIFICATE_NOT_FOUND');

    $checkout->enrollFree($s1, ['curso' => $course2->id()]);
    $finish($s1, $chapter2->id());
    $second = $certificates->issue($s1, $course2->id());
    check($second->certifierNameSnapshot() === 'Academia de Prueba' && $second->verificationCode() !== $issued->verificationCode(), 'con certificador en el curso, se usa ese; cada certificado tiene su código');
    check(array_map(static fn ($c): string => $c->courseId(), $certificates->forStudent($s1)) === [$course2->id(), $course->id()], 'la lista del alumno, del más reciente al primero');
    check($certificates->forStudent($s2) === [], 'otro alumno no tiene certificados');

    // -----------------------------------------------------------
    Report::section('Datos congelados y verificación');
    $profiles->updateProfile($s1, [
        'nombre' => 'Sofi', 'apellido_paterno' => 'Cambiada', 'apellido_materno' => '', 'genero' => 'otro',
        'fecha_nacimiento' => '1998-05-20', 'descripcion' => '', 'email' => $s1->email(),
    ]);
    $courseRepository->update($instructor->id(), $course->id(), $validator->validateCourse($courseForm("Certificado $mark renombrado", 'Otra Academia'), false), null, null);
    $verified = $certificates->verify($issued->verificationCode());
    check($verified->studentNameSnapshot() === 'Sofía Certificado Núñez' && $verified->courseTitleSnapshot() === "Certificado $mark" && $verified->certifierNameSnapshot() === 'Mariana Certificado Núñez', 'cambiar el nombre del alumno, el título y el certificador no altera el certificado');
    check($certificates->verify(strtoupper($issued->verificationCode()))->id() === $issued->id() && $certificates->verify('  ' . $issued->verificationCode() . ' ')->id() === $issued->id(), 'el código se acepta en mayúsculas o con espacios');
    check(ruleCode(static fn () => $certificates->verify('00000000-0000-0000-0000-000000000000')) === 'CERTIFICATE_NOT_FOUND', 'un código que no existe -> CERTIFICATE_NOT_FOUND');
    check(ruleCode(static fn () => $certificates->verify("x' OR '1'='1")) === 'CERTIFICATE_NOT_FOUND', 'texto que no es un código ni llega al SP');
    check($certificates->verifyUrl('abc') === 'http://localhost:8000/?view=verify&code=abc', 'dirección de verificación');

    // -----------------------------------------------------------
    Report::section('Calificar y opinar');
    check(ruleCode(static fn () => $reviews->save($s4, $course->id(), ['calificacion' => 5])) === 'NOT_ENROLLED', 'sin inscripción -> NOT_ENROLLED');
    check(ruleCode(static fn () => $reviews->save($s2, $course->id(), ['calificacion' => 5])) === 'COURSE_NOT_COMPLETED', 'sin terminar -> COURSE_NOT_COMPLETED');
    $mine = $reviews->mine($s2, $course->id());
    check($mine !== null && $mine->enrolled && !$mine->canReview() && $mine->rate === null, 'el inscrito sin terminar todavía no puede calificar');
    check($reviews->mine($instructor, $course->id()) === null && $reviews->mine(null, $course->id()) === null, 'el instructor y los visitantes no califican');
    check(failedFields(static fn () => $reviews->save($s1, $course->id(), ['calificacion' => 7])) === ['calificacion'], 'estrellas fuera de 1..5 -> calificacion');
    check(ruleCode(static fn () => $reviewRepository->save($s1->id(), $course->id(), 9, null)) === 'INVALID_RATE', 'el SP también rechaza las estrellas inválidas -> INVALID_RATE');

    $s1Review = $reviews->save($s1, $course->id(), ['calificacion' => 5, 'opinion' => 'Excelente curso.']);
    check($s1Review->canReview() && $s1Review->rate?->rate() === 5 && $s1Review->opinion?->opinion() === 'Excelente curso.' && !$s1Review->isHidden(), 'quien terminó: 5 estrellas y su opinión');
    $finish($s2, $chapter->id());
    $reviews->save($s2, $course->id(), ['calificacion' => 2, 'opinion' => 'Muy corto.']);
    $finish($s3, $chapter->id());
    $s3Review = $reviews->save($s3, $course->id(), ['calificacion' => 4]);
    check($s3Review->rate?->rate() === 4 && $s3Review->opinion === null, 'también se puede calificar sin opinión');

    $page = $reviews->list($course->id());
    check($page->summary->ratingAvg === '3.7' && $page->summary->ratingCount === 3, 'promedio (5 + 2 + 4) / 3 = 3.7 de 3 calificaciones');
    check($page->summary->stars === [5 => 1, 4 => 1, 3 => 0, 2 => 1, 1 => 0] && $page->summary->percent(5) === 33, 'reparto por estrellas');
    check($page->total === 2 && array_map(static fn ($r): string => $r->opinion, $page->reviews) === ['Muy corto.', 'Excelente curso.'], 'solo las opiniones con texto, de la más reciente a la primera');
    check($page->reviews[0]->rate === 2 && $page->reviews[0]->authorName === 'Diego Certificado Núñez' && !$page->reviews[0]->wasEdited(), 'cada una con sus estrellas y su autor');
    $card = array_values(array_filter($catalog->search(['q' => $mark])->courses, static fn ($c): bool => $c->id === $course->id()))[0] ?? null;
    check($card !== null && $card->ratingAvg === '3.7' && $card->ratingCount === 3, 'la tarjeta del catálogo trae el promedio');
    check($catalog->course($course->id())->ratingAvg === '3.7' && $catalog->course($course2->id())->ratingAvg === null && $catalog->course($course2->id())->ratingCount === 0, 'y la página del curso (sin calificaciones: NULL y 0)');

    usleep(20000);
    $edited = $reviews->save($s1, $course->id(), ['calificacion' => 4, 'opinion' => 'Muy bueno, aunque corto.']);
    check($edited->rate?->rate() === 4 && $edited->opinion?->opinion() === 'Muy bueno, aunque corto.' && $edited->opinion->id() === $s1Review->opinion?->id(), 'editar cambia las estrellas y el texto (la misma opinión)');
    check($reviews->list($course->id())->summary->ratingAvg === '3.3', 'el promedio se recalcula: (4 + 2 + 4) / 3 = 3.3');
    $reviews->save($s1, $course->id(), ['calificacion' => 4, 'opinion' => '']);
    check($reviews->list($course->id())->total === 1 && $reviews->mine($s1, $course->id())?->opinion === null, 'mandar la opinión vacía la quita (las estrellas quedan)');
    $reviews->save($s1, $course->id(), ['calificacion' => 4, 'opinion' => 'Muy bueno, aunque corto.']);
    check($reviews->list($course->id())->total === 2, 'y se puede volver a escribir');
    check(ruleCode(static fn () => $reviews->list('999999999999')) === 'COURSE_NOT_FOUND', 'opiniones de un curso que no existe -> COURSE_NOT_FOUND');

    // -----------------------------------------------------------
    Report::section('Ocultar opiniones');
    $s2Opinion = $reviews->mine($s2, $course->id())?->opinion;
    check(ruleCode(static fn () => $reviews->hide($otherInstructor, (string) $s2Opinion?->id(), ['motivo' => 'No me gusta'])) === 'OPINION_NOT_FOUND', 'otro instructor -> OPINION_NOT_FOUND');
    check(ruleCode(static fn () => $reviews->hide($s1, (string) $s2Opinion?->id(), ['motivo' => 'No me gusta'])) === 'OPINION_NOT_FOUND', 'un alumno tampoco puede (aunque pasara el rol)');
    check(failedFields(static fn () => $reviews->hide($instructor, (string) $s2Opinion?->id(), ['motivo' => 'x'])) === ['motivo'], 'el motivo es obligatorio (3 a 500)');
    $hidden = $reviews->hide($instructor, (string) $s2Opinion?->id(), ['motivo' => 'Lenguaje inapropiado']);
    check(!$hidden->active() && $hidden->deactivatedBy() === $instructor->id() && $hidden->reason() === 'Lenguaje inapropiado' && $hidden->deactivatedAt() !== null, 'el instructor la oculta con su motivo');
    $afterHide = $reviews->list($course->id());
    check($afterHide->summary->ratingAvg === '4.0' && $afterHide->summary->ratingCount === 2 && $afterHide->total === 1, 'sus estrellas ya no cuentan: (4 + 4) / 2 = 4.0, y no se lista');
    check($catalog->course($course->id())->ratingAvg === '4.0', 'el catálogo también la descuenta');
    $s2Mine = $reviews->mine($s2, $course->id());
    check($s2Mine?->isHidden() === true && $s2Mine->opinion?->reason() === 'Lenguaje inapropiado', 'el autor ve que se ocultó y el motivo');
    check(ruleCode(static fn () => $reviews->save($s2, $course->id(), ['calificacion' => 3, 'opinion' => 'Lo cambio'])) === 'OPINION_HIDDEN', 'ya no puede cambiar el texto -> OPINION_HIDDEN');
    check(ruleCode(static fn () => $reviews->save($s2, $course->id(), ['calificacion' => 3])) === 'OPINION_HIDDEN', 'ni quitarla');
    $starsOnly = $reviews->save($s2, $course->id(), ['calificacion' => 3, 'opinion' => 'Muy corto.']);
    check($starsOnly->rate?->rate() === 3 && $starsOnly->isHidden(), 'con el mismo texto sí cambia sus estrellas, y sigue oculta');
    check($reviews->list($course->id())->summary->ratingAvg === '4.0', 'y siguen sin contar');
    $again = $reviews->hide($instructor, (string) $s2Opinion?->id(), ['motivo' => 'Otro motivo']);
    check($again->reason() === 'Lenguaje inapropiado' && $again->deactivatedAt() == $hidden->deactivatedAt(), 'ocultarla otra vez no cambia el motivo ni la fecha');
    $s1Opinion = $reviews->mine($s1, $course->id())?->opinion;
    check(!$reviews->hide($admin, (string) $s1Opinion?->id(), ['motivo' => 'Revisión del administrador'])->active(), 'el admin también puede ocultar');
    check($reviews->list($course->id())->total === 0 && $reviews->list($course->id())->summary->ratingCount === 1, 'sin opiniones visibles; solo cuentan las estrellas de quien no tiene opinión oculta');

    // Limpieza: cursos de prueba dados de baja y su categoría desactivada.
    $courseService->setActive($instructor, $course->id(), ['activo' => false]);
    $courseService->setActive($instructor, $course2->id(), ['activo' => false]);
    $categories->update($admin, $category->id(), ['activa' => false]);
    check($certificates->verify($issued->verificationCode())->id() === $issued->id(), 'con el curso dado de baja, el certificado se sigue verificando');
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), PHP_EOL));
    if ($exception->getPrevious() !== null) {
        fwrite(STDERR, 'Causa: ' . $exception->getPrevious()->getMessage() . PHP_EOL);
    }
    exit(1);
}

Report::finish();
