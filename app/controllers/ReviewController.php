<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Presenters\ReactionPresenter;
use App\Http\Request;
use App\Models\Dto\CourseReview;
use App\Models\Dto\MyReview;
use App\Models\Dto\ReviewPage;
use App\Models\Entities\AppUser;
use App\Services\ReviewService;

/** Calificaciones y opiniones: lista pública, la del alumno y la moderación. */
final class ReviewController
{
    public function __construct(private readonly ReviewService $reviews)
    {
    }

    /** GET /api/catalog/courses/{id}/reviews?pagina= (público; con sesión marca tu reacción) */
    public function list(Request $request): JsonResponse
    {
        $viewer = $request->attribute('user');

        return JsonResponse::data(self::page($this->reviews->list(
            (string) $request->attribute('id'),
            $request->queryParams(),
            $viewer instanceof AppUser ? $viewer : null,
        )));
    }

    /** PUT /api/student/courses/{id}/review ({calificacion, opinion}) */
    public function save(Request $request): JsonResponse
    {
        $review = $this->reviews->save($this->user($request), (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['review' => self::mine($review)]);
    }

    /** POST /api/opinions/{id}/hide ({motivo}); instructor del curso o admin */
    public function hide(Request $request): JsonResponse
    {
        $opinion = $this->reviews->hide($this->user($request), (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['opinion' => ['id' => $opinion->id(), 'active' => $opinion->active(), 'reason' => $opinion->reason()]]);
    }

    /** @return array<string, mixed> */
    public static function page(ReviewPage $page): array
    {
        return [
            'summary' => [
                'ratingAvg' => $page->summary->ratingAvg,
                'ratingCount' => $page->summary->ratingCount,
                'stars' => $page->summary->stars,
            ],
            'reviews' => array_map(static fn (CourseReview $review): array => [
                'id' => $review->opinionId,
                'opinion' => $review->opinion,
                'rate' => $review->rate,
                'author' => $review->authorName,
                'avatar' => $review->authorAvatar,
                'commentedAt' => $review->commentedAt->format(DATE_ATOM),
                'edited' => $review->wasEdited(),
                'mine' => $review->mine,
                'reported' => $review->reportedByMe,
                'reactions' => ReactionPresenter::summary($review->reactions),
            ], $page->reviews),
            'total' => $page->total,
            'page' => $page->page,
            'pages' => $page->pages,
        ];
    }

    /** @return array<string, mixed> */
    public static function mine(MyReview $review): array
    {
        return [
            'canReview' => $review->canReview(),
            'rate' => $review->rate?->rate(),
            'opinion' => $review->opinion?->opinion(),
            'hidden' => $review->isHidden(),
            'hiddenReason' => $review->isHidden() ? $review->opinion?->reason() : null,
        ];
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }
}
