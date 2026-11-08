<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Models\Dto\ModerationItem;
use App\Models\Dto\ModerationReport;
use App\Models\Entities\AppUser;
use App\Models\Enums\ReportTarget;
use App\Services\ModerationService;

/** Reportes de comentarios y opiniones (alumnos e instructores) y moderación (admin). */
final class ModerationController
{
    public function __construct(private readonly ModerationService $moderation)
    {
    }

    /** POST /api/comments/{id}/report ({motivo, detalle?}) */
    public function reportComment(Request $request): JsonResponse
    {
        return $this->report($request, ReportTarget::Comment);
    }

    /** POST /api/opinions/{id}/report ({motivo, detalle?}) */
    public function reportOpinion(Request $request): JsonResponse
    {
        return $this->report($request, ReportTarget::Opinion);
    }

    /** GET /api/admin/moderation/reports?pagina= */
    public function reports(Request $request): JsonResponse
    {
        $page = $this->moderation->reports($this->user($request), $request->queryParams());

        return JsonResponse::data([
            'reports' => array_map(self::presentReport(...), $page->items),
            'total' => $page->total,
            'reportTotal' => $page->reportTotal,
            'page' => $page->page,
            'pages' => $page->pages,
        ]);
    }

    /** GET /api/admin/moderation/content?tipo=&estado=&q=&pagina= */
    public function content(Request $request): JsonResponse
    {
        $page = $this->moderation->content($this->user($request), $request->queryParams());

        return JsonResponse::data([
            'items' => array_map(self::presentItem(...), $page->items),
            'filters' => $page->filters,
            'total' => $page->total,
            'page' => $page->page,
            'pages' => $page->pages,
        ]);
    }

    /** POST /api/admin/comments/{id}/dismiss-reports */
    public function dismissComment(Request $request): JsonResponse
    {
        return JsonResponse::data(['dismissed' => $this->moderation->dismiss($this->user($request), ReportTarget::Comment, (string) $request->attribute('id'))]);
    }

    /** POST /api/admin/opinions/{id}/dismiss-reports */
    public function dismissOpinion(Request $request): JsonResponse
    {
        return JsonResponse::data(['dismissed' => $this->moderation->dismiss($this->user($request), ReportTarget::Opinion, (string) $request->attribute('id'))]);
    }

    /** POST /api/admin/comments/{id}/restore */
    public function restoreComment(Request $request): JsonResponse
    {
        $this->moderation->restore($this->user($request), ReportTarget::Comment, (string) $request->attribute('id'));

        return JsonResponse::data(['restored' => true]);
    }

    /** POST /api/admin/opinions/{id}/restore */
    public function restoreOpinion(Request $request): JsonResponse
    {
        $this->moderation->restore($this->user($request), ReportTarget::Opinion, (string) $request->attribute('id'));

        return JsonResponse::data(['restored' => true]);
    }

    /** @return array<string, mixed> */
    public static function presentReport(ModerationReport $report): array
    {
        return [
            'type' => $report->targetType->value,
            'id' => $report->targetId,
            'text' => $report->content,
            'author' => ['name' => $report->authorName, 'email' => $report->authorEmail, 'avatar' => $report->authorAvatar],
            'course' => ['id' => $report->courseId, 'title' => $report->courseTitle],
            'chapter' => $report->chapterId === null ? null : ['id' => $report->chapterId, 'title' => $report->chapterTitle],
            'reportCount' => $report->reportCount,
            'reasons' => $report->reasonCounts,
            'reasonsSummary' => $report->reasonsSummary(),
            'topReason' => $report->topReason()->value,
            'lastDetails' => $report->lastDetails,
            'lastReportedAt' => $report->lastReportedAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function presentItem(ModerationItem $item): array
    {
        return [
            'type' => $item->targetType->value,
            'id' => $item->targetId,
            'text' => $item->content,
            'active' => $item->active,
            'status' => $item->statusLabel(),
            'removedByAuthor' => $item->removedByAuthor,
            'restorable' => $item->restorable,
            'author' => ['name' => $item->authorName, 'email' => $item->authorEmail, 'avatar' => $item->authorAvatar],
            'course' => ['id' => $item->courseId, 'title' => $item->courseTitle],
            'chapter' => $item->chapterId === null ? null : ['id' => $item->chapterId, 'title' => $item->chapterTitle],
            'reportCount' => $item->reportCount,
            'createdAt' => $item->createdAt->format(DATE_ATOM),
        ];
    }

    private function report(Request $request, ReportTarget $target): JsonResponse
    {
        $created = $this->moderation->report($this->user($request), $target, (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['reported' => true, 'created' => $created], $created ? 201 : 200);
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }
}
