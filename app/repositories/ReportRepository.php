<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\AdminOverview;
use App\Models\Dto\CourseSalesDetail;
use App\Models\Dto\CourseSalesRow;
use App\Models\Dto\InstructorOverview;
use App\Models\Dto\InstructorReportRow;
use App\Models\Dto\PaymentMethodTotal;
use App\Models\Dto\RecentReview;
use App\Models\Dto\StudentReportRow;

/** Reportes de los paneles del instructor y del admin, exclusivamente mediante SP. */
final class ReportRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    public function instructorOverview(string $instructorId): InstructorOverview
    {
        return InstructorOverview::fromRow($this->requiredRow('sp_instructor_overview', [$instructorId]));
    }

    /**
     * @param ?string $from Y-m-d
     * @param ?string $to Y-m-d
     * @return array{0: list<CourseSalesRow>, 1: list<PaymentMethodTotal>, 2: string} [cursos, por forma de pago, total]
     */
    public function instructorSales(string $instructorId, ?string $from, ?string $to, ?string $categoryId, bool $onlyActive): array
    {
        [$courseRows, $methodRows, $totalRows] = $this->resultSets('sp_instructor_sales', [$instructorId, $from, $to, $categoryId, $onlyActive]) + [[], [], []];

        return [
            array_map(CourseSalesRow::fromRow(...), $courseRows),
            array_map(PaymentMethodTotal::fromRow(...), $methodRows),
            (string) ($totalRows[0]['revenue_total'] ?? '0.00'),
        ];
    }

    public function courseStudents(string $instructorId, string $courseId): CourseSalesDetail
    {
        return CourseSalesDetail::fromResultSets($this->resultSets('sp_instructor_course_students', [$instructorId, $courseId]));
    }

    /** @return list<RecentReview> */
    public function recentReviews(string $instructorId, int $limit): array
    {
        return array_map(RecentReview::fromRow(...), $this->resultSets('sp_instructor_recent_reviews', [$instructorId, $limit])[0] ?? []);
    }

    public function adminOverview(string $adminId): AdminOverview
    {
        return AdminOverview::fromRow($this->requiredRow('sp_admin_overview', [$adminId]));
    }

    /** @return list<InstructorReportRow> */
    public function instructorReport(string $adminId): array
    {
        return array_map(InstructorReportRow::fromRow(...), $this->resultSets('sp_admin_report_instructors', [$adminId])[0] ?? []);
    }

    /** @return list<StudentReportRow> */
    public function studentReport(string $adminId): array
    {
        return array_map(StudentReportRow::fromRow(...), $this->resultSets('sp_admin_report_students', [$adminId])[0] ?? []);
    }
}
