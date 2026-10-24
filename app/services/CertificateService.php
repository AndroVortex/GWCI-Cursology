<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\MediaUploadException;
use App\Models\Entities\AppUser;
use App\Models\Entities\Certificate;
use App\Models\Enums\CertificateStatus;
use App\Repositories\CertificateRepository;
use App\Services\Exceptions\BusinessRuleException;

/**
 * Certificados de los cursos terminados. Se emiten la primera vez que el alumno los pide:
 * la base congela los datos (sp_certificate_issue), PHP genera el PDF, lo guarda en
 * Cloudinary y lo marca emitido. Si la subida falla, queda pendiente y el siguiente intento
 * lo vuelve a generar con los mismos datos.
 */
final class CertificateService
{
    private const CODE_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /** @param string $appUrl dirección pública de la app sin "/" final ('' = enlaces relativos) */
    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly CertificateRenderer $renderer,
        private readonly CertificateStore $store,
        private readonly string $appUrl,
    ) {
    }

    /**
     * @throws BusinessRuleException NOT_ENROLLED, COURSE_NOT_COMPLETED
     * @throws MediaUploadException
     */
    public function issue(AppUser $student, string $courseId): Certificate
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $courseId) !== 1) {
            throw BusinessRuleException::of('NOT_ENROLLED');
        }

        $certificate = $this->certificates->issue($student->id(), $courseId);
        if ($certificate->status() === CertificateStatus::Issued) {
            return $certificate;
        }

        $pdf = $this->renderer->render($certificate, $this->verifyUrl($certificate->verificationCode()));
        $reference = $this->store->save($student->id(), $certificate->verificationCode(), $pdf);

        return $this->certificates->markIssued($student->id(), $certificate->id(), $reference);
    }

    /** @return list<Certificate> */
    public function forStudent(AppUser $student): array
    {
        return $this->certificates->listByStudent($student->id());
    }

    /** @throws BusinessRuleException CERTIFICATE_NOT_FOUND */
    public function verify(string $code): Certificate
    {
        $code = strtolower(trim($code));
        if (!self::isCode($code)) {
            throw BusinessRuleException::of('CERTIFICATE_NOT_FOUND');
        }

        return $this->certificates->verify($code);
    }

    /** URL temporal del PDF; null si todavía no se emite. Solo para el dueño del certificado. */
    public function downloadUrl(Certificate $certificate): ?string
    {
        $reference = $certificate->documentUri();

        return $certificate->status() === CertificateStatus::Issued && $reference !== null ? $this->store->downloadUrl($reference) : null;
    }

    public function verifyUrl(string $code): string
    {
        return ($this->appUrl === '' ? '' : $this->appUrl . '/') . '?view=verify&code=' . rawurlencode($code);
    }

    public static function isCode(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1;
    }
}
