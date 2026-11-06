<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Models\Dto\CourseSalesRow;
use App\Models\Dto\CourseStudentRow;
use App\Models\Dto\InstructorReportRow;
use App\Models\Dto\PaymentMethodTotal;
use App\Models\Dto\RecentReview;
use App\Models\Dto\StudentReportRow;
use App\Models\Entities\AppUser;
use App\Services\ReportService;

/** Reportes de ventas del instructor y reportes del admin (solo lectura). */
final class ReportController
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    /** GET /api/instructor/sales?desde=&hasta=&categoria=&estado= */
    public function sales(Request $request): JsonResponse
    {
        $dashboard = $this->reports->instructorDashboard($this->user($request), $request->queryParams());
        $overview = $dashboard['overview'];

        return JsonResponse::data([
            'overview' => [
                'revenueTotal' => $overview->revenueTotal,
                'studentTotal' => $overview->studentTotal,
                'ratingAvg' => $overview->ratingAvg,
                'ratingCount' => $overview->ratingCount,
            ],
            'courses' => array_map(static fn (CourseSalesRow $row): array => [
                'id' => $row->id,
                'title' => $row->title,
                'active' => $row->active,
                'createdAt' => $row->createdAt->format(DATE_ATOM),
                'students' => $row->studentCount,
                'avgProgress' => $row->avgProgress,
                'revenue' => $row->revenue,
                'currency' => $row->currency,
                'ratingAvg' => $row->ratingAvg,
                'ratingCount' => $row->ratingCount,
            ], $dashboard['sales']),
            'byMethod' => array_map(static fn (PaymentMethodTotal $total): array => [
                'method' => $total->methodName,
                'purchases' => $total->purchases,
                'total' => $total->total,
            ], $dashboard['byMethod']),
            'revenueTotal' => $dashboard['revenueTotal'],
            'filters' => $dashboard['filters'],
            'recentReviews' => array_map(static fn (RecentReview $review): array => [
                'id' => $review->opinionId,
                'courseId' => $review->courseId,
                'courseTitle' => $review->courseTitle,
                'author' => $review->authorName,
                'rate' => $review->rate,
                'opinion' => $review->opinion,
                'commentedAt' => $review->commentedAt->format(DATE_ATOM),
                'hidden' => !$review->active,
                'reason' => $review->reason,
            ], $dashboard['recentReviews']),
        ]);
    }

    /** GET /api/instructor/courses/{id}/students */
    public function courseStudents(Request $request): JsonResponse
    {
        $detail = $this->reports->courseStudents($this->user($request), (string) $request->attribute('id'));

        return JsonResponse::data(['course' => [
            'id' => $detail->id,
            'title' => $detail->title,
            'active' => $detail->active,
            'chapters' => $detail->chapterCount,
            'students' => $detail->studentCount,
            'avgProgress' => $detail->avgProgress,
            'revenue' => $detail->revenue,
            'currency' => $detail->currency,
            'enrollments' => array_map(static fn (CourseStudentRow $student): array => [
                'userId' => $student->userId,
                'name' => $student->fullName,
                'email' => $student->email,
                'enrolledAt' => $student->enrolledAt->format(DATE_ATOM),
                'completedAt' => $student->completedAt?->format(DATE_ATOM),
                'progress' => $student->progress,
                'paid' => $student->paid,
                'paymentMethods' => $student->paymentMethods,
            ], $detail->students),
        ]]);
    }

    /** GET /api/admin/reports */
    public function admin(Request $request): JsonResponse
    {
        $dashboard = $this->reports->adminDashboard($this->user($request));
        $overview = $dashboard['overview'];

        return JsonResponse::data([
            'overview' => [
                'activeStudents' => $overview->activeStudents,
                'activeInstructors' => $overview->activeInstructors,
                'publishedCourses' => $overview->publishedCourses,
            ],
            'instructors' => array_map(static fn (InstructorReportRow $row): array => [
                'id' => $row->id,
                'email' => $row->email,
                'name' => $row->fullName,
                'joinedAt' => $row->createdAt->format(DATE_ATOM),
                'publishedCourses' => $row->publishedCourses,
                'earnings' => $row->earnings,
            ], $dashboard['instructors']),
            'students' => array_map(static fn (StudentReportRow $row): array => [
                'id' => $row->id,
                'email' => $row->email,
                'name' => $row->fullName,
                'joinedAt' => $row->createdAt->format(DATE_ATOM),
                'enrolledCourses' => $row->enrolledCourses,
                'completedCourses' => $row->completedCourses,
                'completedPercent' => $row->completedPercent,
            ], $dashboard['students']),
        ]);
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }
}
