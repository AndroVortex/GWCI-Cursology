<?php

declare(strict_types=1);

namespace App\Models\Mapping;

use App\Models\Entities\AppUser;
use App\Models\Entities\Category;
use App\Models\Entities\Certificate;
use App\Models\Entities\ChapterComment;
use App\Models\Entities\ChapterCommentReaction;
use App\Models\Entities\Chat;
use App\Models\Entities\Course;
use App\Models\Entities\CourseCategory;
use App\Models\Entities\CourseChapter;
use App\Models\Entities\CourseChapterContent;
use App\Models\Entities\CourseChapterTestQuestion;
use App\Models\Entities\CourseLevel;
use App\Models\Entities\CourseOpinion;
use App\Models\Entities\CourseRate;
use App\Models\Entities\LoginAttempt;
use App\Models\Entities\Message;
use App\Models\Entities\MessageReaction;
use App\Models\Entities\OpinionReaction;
use App\Models\Entities\PaymentMethod;
use App\Models\Entities\Purchase;
use App\Models\Entities\Reaction;
use App\Models\Entities\TestAttempt;
use App\Models\Entities\TestAttemptAnswer;
use App\Models\Entities\TestAttemptQuestion;
use App\Models\Entities\TestQuestionAnswer;
use App\Models\Entities\UserAccountEvent;
use App\Models\Entities\UserChapterProgress;
use App\Models\Entities\UserCourse;
use App\Models\Entities\UserCourseLevel;
use InvalidArgumentException;

/**
 * Correspondencia tabla MySQL => clase de entidad (29 tablas, en el orden del DDL).
 */
final class EntityMap
{
    /** @var array<string, class-string<Entity>> */
    private const ENTITIES = [
        'app_user' => AppUser::class,
        'login_attempt' => LoginAttempt::class,
        'user_account_event' => UserAccountEvent::class,
        'category' => Category::class,
        'reaction' => Reaction::class,
        'payment_method' => PaymentMethod::class,
        'course' => Course::class,
        'course_category' => CourseCategory::class,
        'course_level' => CourseLevel::class,
        'course_chapter' => CourseChapter::class,
        'course_chapter_content' => CourseChapterContent::class,
        'course_chapter_test_question' => CourseChapterTestQuestion::class,
        'test_question_answer' => TestQuestionAnswer::class,
        'user_courses' => UserCourse::class,
        'purchase' => Purchase::class,
        'user_course_level' => UserCourseLevel::class,
        'user_chapter_progress' => UserChapterProgress::class,
        'test_attempt' => TestAttempt::class,
        'test_attempt_question' => TestAttemptQuestion::class,
        'test_attempt_answer' => TestAttemptAnswer::class,
        'certificate' => Certificate::class,
        'course_rate' => CourseRate::class,
        'course_opinion' => CourseOpinion::class,
        'chapter_comment' => ChapterComment::class,
        'chapter_comment_reaction' => ChapterCommentReaction::class,
        'opinion_reaction' => OpinionReaction::class,
        'chat' => Chat::class,
        'message' => Message::class,
        'message_reaction' => MessageReaction::class,
    ];

    /** @return array<string, class-string<Entity>> */
    public static function all(): array
    {
        return self::ENTITIES;
    }

    /** @return class-string<Entity> */
    public static function classFor(string $table): string
    {
        return self::ENTITIES[$table]
            ?? throw new InvalidArgumentException(sprintf('No hay una entidad para la tabla %s.', $table));
    }
}
