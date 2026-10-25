<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Models\Entities\AppUser;
use App\Models\Entities\Certificate;
use App\Services\CertificateService;

/** Certificado del alumno y verificación pública por código. */
final class CertificateController
{
    public function __construct(private readonly CertificateService $certificates)
    {
    }

    /** POST /api/student/courses/{id}/certificate: emite (o devuelve) el certificado del curso terminado. */
    public function issue(Request $request): JsonResponse
    {
        /** @var AppUser $student */
        $student = $request->attribute('user');
        $certificate = $this->certificates->issue($student, (string) $request->attribute('id'));

        return JsonResponse::data(['certificate' => self::present($certificate) + [
            'id' => $certificate->id(),
            'courseId' => $certificate->courseId(),
            'downloadUrl' => $this->certificates->downloadUrl($certificate),
            'verifyUrl' => $this->certificates->verifyUrl($certificate->verificationCode()),
        ]]);
    }

    /** GET /api/student/certificates/{id}/download: el PDF del alumno, como "Certificado - <curso>.pdf". */
    public function download(Request $request): JsonResponse
    {
        /** @var AppUser $student */
        $student = $request->attribute('user');
        ['fileName' => $fileName, 'pdf' => $pdf] = $this->certificates->download($student, (string) $request->attribute('id'));

        return JsonResponse::file($pdf, 'application/pdf', $fileName);
    }

    /** GET /api/certificates/verify?code=: público; solo los datos congelados. */
    public function verify(Request $request): JsonResponse
    {
        $code = $request->queryParams()['code'] ?? '';

        return JsonResponse::data(['certificate' => self::present($this->certificates->verify(is_string($code) ? $code : ''))]);
    }

    /** @return array<string, mixed> */
    public static function present(Certificate $certificate): array
    {
        return [
            'code' => $certificate->verificationCode(),
            'studentName' => $certificate->studentNameSnapshot(),
            'courseTitle' => $certificate->courseTitleSnapshot(),
            'certifierName' => $certificate->certifierNameSnapshot(),
            'completedAt' => $certificate->completedAtSnapshot()->format(DATE_ATOM),
            'issuedAt' => $certificate->issuedAt()?->format(DATE_ATOM),
        ];
    }
}
