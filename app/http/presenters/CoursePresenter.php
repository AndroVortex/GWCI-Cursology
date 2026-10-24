<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Dto\CourseOutline;
use App\Models\Dto\InstructorCourseSummary;
use App\Models\Entities\Course;
use App\Models\Entities\CourseChapter;
use App\Models\Entities\CourseChapterContent;
use App\Models\Dto\ExamResult;
use App\Models\Entities\CourseChapterTestQuestion;
use App\Models\Entities\CourseLevel;
use App\Models\Entities\TestQuestionAnswer;
use App\Models\Enums\SelectionMode;
use App\Services\PricingMode;

/**
 * JSON de los cursos para su instructor. Solo incluye lo que el dueño necesita ver;
 * el contenido protegido se entrega como URL temporal, nunca su referencia interna.
 */
final class CoursePresenter
{
    /**
     * @param list<string> $categoryIds
     * @return array<string, mixed>
     */
    public static function course(Course $course, array $categoryIds = []): array
    {
        return [
            'id' => $course->id(),
            'title' => $course->title(),
            'description' => $course->description(),
            'coverImage' => $course->coverImage(),
            'onboardingVideo' => $course->onboardingVideo(),
            'certifierName' => $course->certifierName(),
            'pricingMode' => PricingMode::fromFullCoursePrice($course->fullCoursePrice())->value,
            'fullCoursePrice' => $course->fullCoursePrice(),
            'currency' => $course->currency(),
            'status' => self::status($course->active(), $course->publishedAt() !== null),
            'active' => $course->active(),
            'categoryIds' => $categoryIds,
            'updatedAt' => $course->updatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function summary(InstructorCourseSummary $summary): array
    {
        return [
            'id' => $summary->id,
            'title' => $summary->title,
            'coverImage' => $summary->coverImage,
            'pricingMode' => $summary->pricingMode()->value,
            'fullCoursePrice' => $summary->fullCoursePrice,
            'levelPriceTotal' => $summary->levelPriceTotal,
            'currency' => $summary->currency,
            'status' => self::status($summary->active, $summary->isPublished()),
            'levels' => $summary->levelCount,
            'chapters' => $summary->chapterCount,
            'students' => $summary->studentCount,
            'updatedAt' => $summary->updatedAt->format(DATE_ATOM),
        ];
    }

    /** draft = borrador, published = publicado, inactive = dado de baja. */
    private static function status(bool $active, bool $published): string
    {
        return match (true) {
            !$active => 'inactive',
            $published => 'published',
            default => 'draft',
        };
    }

    /** @return array<string, mixed> */
    public static function level(CourseLevel $level): array
    {
        return [
            'id' => $level->id(),
            'courseId' => $level->courseId(),
            'title' => $level->title(),
            'description' => $level->description(),
            'onboardingVideo' => $level->onboardingVideo(),
            'sortOrder' => $level->sortOrder(),
            'cost' => $level->cost(),
        ];
    }

    /** @return array<string, mixed> */
    public static function chapter(CourseChapter $chapter): array
    {
        return [
            'id' => $chapter->id(),
            'levelId' => $chapter->courseLevelId(),
            'title' => $chapter->title(),
            'sortOrder' => $chapter->sortOrder(),
            'type' => $chapter->isTest() ? 'exam' : 'video',
            'minimumPassingGrade' => $chapter->minimumPassingGrade(),
        ];
    }

    /**
     * Pregunta con sus opciones, incluidas las claves: solo para el instructor dueño.
     *
     * @param list<TestQuestionAnswer> $answers
     * @return array<string, mixed>
     */
    public static function question(CourseChapterTestQuestion $question, array $answers): array
    {
        $maxCents = 0;
        foreach ($answers as $answer) {
            if ($answer->isCorrect()) {
                [$integer, $fraction] = explode('.', $answer->weight());
                $maxCents += (int) $integer * 100 + (int) $fraction;
            }
        }

        return [
            'id' => $question->id(),
            'chapterId' => $question->courseChapterId(),
            'sortOrder' => $question->sortOrder(),
            'text' => $question->question(),
            'mode' => $question->selectionMode() === SelectionMode::Single ? 'unica' : 'multiple',
            'points' => intdiv($maxCents, 100) . '.' . str_pad((string) ($maxCents % 100), 2, '0', STR_PAD_LEFT),
            'answers' => array_map(static fn (TestQuestionAnswer $answer): array => [
                'id' => $answer->id(),
                'text' => $answer->answer(),
                'correct' => $answer->isCorrect(),
                'weight' => $answer->weight(),
            ], $answers),
        ];
    }

    /** @return array<string, mixed> */
    public static function examResult(ExamResult $result): array
    {
        return [
            'grade' => $result->grade,
            'minimumPassingGrade' => $result->minimumGrade,
            'passed' => $result->passed,
            'earnedPoints' => $result->earnedPoints,
            'maxPoints' => $result->maxPoints,
            'questions' => $result->questions,
        ];
    }

    /** @return array<string, mixed> */
    public static function content(CourseChapterContent $content, ?string $viewUrl): array
    {
        return [
            'id' => $content->id(),
            'chapterId' => $content->courseChapterId(),
            'title' => $content->title(),
            'type' => $content->contentType()->value,
            'isMain' => $content->isMain(),
            'mimeType' => $content->mimeType(),
            'originalFilename' => $content->originalFilename(),
            'sizeBytes' => $content->sizeBytes(),
            'durationSeconds' => $content->durationSeconds(),
            'url' => $viewUrl,
            'text' => $content->textContent(),
        ];
    }

    /**
     * @param callable(CourseChapterContent): ?string $viewUrl
     * @return array<string, mixed>
     */
    public static function outline(CourseOutline $outline, callable $viewUrl): array
    {
        $levels = [];
        foreach ($outline->levels as $level) {
            $chapters = [];
            foreach ($outline->chapters($level->id()) as $chapter) {
                $main = $outline->mainVideo($chapter->id());
                $chapters[] = self::chapter($chapter) + [
                    'mainVideo' => $main === null ? null : self::content($main, $viewUrl($main)),
                    'resources' => array_map(
                        static fn (CourseChapterContent $content): array => self::content($content, $viewUrl($content)),
                        $outline->resources($chapter->id()),
                    ),
                    'questions' => array_map(
                        static fn (CourseChapterTestQuestion $question): array => self::question($question, $outline->answers($question->id())),
                        $outline->questions($chapter->id()),
                    ),
                ];
            }
            $levels[] = self::level($level) + ['chapters' => $chapters];
        }

        return self::course($outline->course, $outline->categoryIds) + ['levels' => $levels];
    }
}
