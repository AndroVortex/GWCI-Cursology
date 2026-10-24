<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Dto\CatalogCourse;
use App\Models\Dto\CatalogPage;
use App\Models\Dto\PublicCourse;

/** JSON del catálogo público. Solo datos públicos: nada de contenido protegido ni claves de examen. */
final class CatalogPresenter
{
    /** @return array<string, mixed> */
    public static function card(CatalogCourse $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'coverImage' => $course->coverImage,
            'instructor' => $course->instructorName,
            'categories' => $course->categoryNames,
            'pricingMode' => $course->pricingMode()->value,
            'fullCoursePrice' => $course->fullCoursePrice,
            'levelPriceTotal' => $course->levelPriceTotal,
            'currency' => $course->currency,
            'levels' => $course->levelCount,
            'chapters' => $course->chapterCount,
            'publishedAt' => $course->publishedAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function page(CatalogPage $page): array
    {
        return [
            'courses' => array_map(self::card(...), $page->courses),
            'total' => $page->total,
            'page' => $page->page,
            'pages' => $page->pages,
            'filters' => $page->filters,
        ];
    }

    /** @return array<string, mixed> */
    public static function course(PublicCourse $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'description' => $course->description,
            'coverImage' => $course->coverImage,
            'promoVideo' => $course->promoVideo,
            'pricingMode' => $course->pricingMode()->value,
            'fullCoursePrice' => $course->fullCoursePrice,
            'levelPriceTotal' => $course->levelPriceTotal(),
            'currency' => $course->currency,
            'publishedAt' => $course->publishedAt->format(DATE_ATOM),
            'instructor' => [
                'id' => $course->instructorId,
                'name' => $course->instructorName,
                'avatar' => $course->instructorAvatar,
                'description' => $course->instructorDescription,
            ],
            'categories' => $course->categories,
            'levels' => $course->levels,
        ];
    }
}
