<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\AdminOverview;
use App\Models\Dto\CourseSalesDetail;
use App\Models\Dto\CourseSalesRow;
use App\Models\Dto\InstructorOverview;
use App\Models\Dto\InstructorReportRow;
use App\Models\Dto\PaymentMethodTotal;
use App\Models\Dto\RecentReview;
use App\Models\Dto\StudentReportRow;
use App\Models\Entities\AppUser;
use App\Repositories\ReportRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Validation\UserRules;
use DateTimeImmutable;

/**
 * Reportes de los paneles: ventas del instructor (por curso, por forma de pago y el detalle de
 * alumnos de cada curso) y las cifras y reportes del admin. Los importes son la suma de las
 * compras completadas, sin comisión; las inscripciones de $0 no suman. Los filtros inválidos
 * se ignoran (como en el kardex).
 */
final class ReportService
{
    public const RECENT_REVIEWS = 5;

    public function __construct(private readonly ReportRepository $reports)
    {
    }

    /**
     * @param array<string, mixed> $query parámetros GET: desde, hasta, categoria, estado (activos)
     * @return array{
     *     overview: InstructorOverview,
     *     sales: list<CourseSalesRow>,
     *     byMethod: list<PaymentMethodTotal>,
     *     revenueTotal: string,
     *     filters: array{desde: string, hasta: string, categoria: string, estado: string},
     *     recentReviews: list<RecentReview>
     * }
     * @throws BusinessRuleException NOT_INSTRUCTOR
     */
    public function instructorDashboard(AppUser $instructor, array $query): array
    {
        $filters = self::salesFilters($query);
        [$sales, $byMethod, $revenueTotal] = $this->reports->instructorSales(
            $instructor->id(),
            $filters['desde'] ?: null,
            $filters['hasta'] ?: null,
            $filters['categoria'] ?: null,
            $filters['estado'] === 'activos',
        );

        return [
            'overview' => $this->reports->instructorOverview($instructor->id()),
            'sales' => $sales,
            'byMethod' => $byMethod,
            'revenueTotal' => $revenueTotal,
            'filters' => $filters,
            'recentReviews' => $this->reports->recentReviews($instructor->id(), self::RECENT_REVIEWS),
        ];
    }

    /** @throws BusinessRuleException COURSE_NOT_FOUND (no existe o no es del instructor) */
    public function courseStudents(AppUser $instructor, string $courseId): CourseSalesDetail
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $courseId) !== 1) {
            throw BusinessRuleException::of('COURSE_NOT_FOUND');
        }

        return $this->reports->courseStudents($instructor->id(), $courseId);
    }

    /**
     * @return array{overview: AdminOverview, instructors: list<InstructorReportRow>, students: list<StudentReportRow>}
     * @throws BusinessRuleException NOT_ADMIN
     */
    public function adminDashboard(AppUser $admin): array
    {
        return [
            'overview' => $this->reports->adminOverview($admin->id()),
            'instructors' => $this->reports->instructorReport($admin->id()),
            'students' => $this->reports->studentReport($admin->id()),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{desde: string, hasta: string, categoria: string, estado: string} '' = sin filtro
     */
    public static function salesFilters(array $query): array
    {
        $from = self::date(UserRules::text($query, 'desde'));
        $to = self::date(UserRules::text($query, 'hasta'));
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $category = UserRules::text($query, 'categoria');

        return [
            'desde' => $from,
            'hasta' => $to,
            'categoria' => preg_match('/^[1-9][0-9]{0,19}$/D', $category) === 1 ? $category : '',
            'estado' => UserRules::text($query, 'estado') === 'activos' ? 'activos' : '',
        ];
    }

    /** "Y-m-d" válida o ''. */
    private static function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
