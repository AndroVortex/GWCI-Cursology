<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\ChapterComment;
use App\Models\Dto\CommentPage;
use App\Models\Dto\InstructorCommentPage;
use App\Models\Dto\ReactionSummary;
use App\Models\Dto\RecentComment;

/** Comentarios de los capítulos y sus reacciones, exclusivamente mediante SP. */
final class CommentRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    public function list(string $userId, string $chapterId, int $page, int $pageSize): CommentPage
    {
        return CommentPage::fromResultSets($chapterId, $this->resultSets('sp_comment_list', [$userId, $chapterId, $pageSize, ($page - 1) * $pageSize]), $page, $pageSize);
    }

    public function create(string $userId, string $chapterId, ?string $parentId, string $content): ChapterComment
    {
        return ChapterComment::fromRow($this->requiredRow('sp_comment_create', [$userId, $chapterId, $parentId, $content]));
    }

    public function update(string $userId, string $commentId, string $content): ChapterComment
    {
        return ChapterComment::fromRow($this->requiredRow('sp_comment_update', [$userId, $commentId, $content]));
    }

    public function delete(string $userId, string $commentId): ChapterComment
    {
        return ChapterComment::fromRow($this->requiredRow('sp_comment_delete', [$userId, $commentId]));
    }

    public function hide(string $userId, string $commentId, string $reason): ChapterComment
    {
        return ChapterComment::fromRow($this->requiredRow('sp_comment_hide', [$userId, $commentId, $reason]));
    }

    /** @param ?string $reactionId null quita la reacción */
    public function react(string $userId, string $commentId, ?string $reactionId): ReactionSummary
    {
        return ReactionSummary::fromRows($this->resultSets('sp_comment_react', [$userId, $commentId, $reactionId])[0] ?? []);
    }

    /** @param array{capitulo: string, estado: string} $filters */
    public function forInstructor(string $instructorId, string $courseId, array $filters, int $page, int $pageSize): InstructorCommentPage
    {
        $resultSets = $this->resultSets('sp_instructor_course_comments', [
            $instructorId, $courseId, $filters['capitulo'] === '' ? null : $filters['capitulo'], $filters['estado'] === 'sin_responder',
            $pageSize, ($page - 1) * $pageSize,
        ]);

        return InstructorCommentPage::fromResultSets($resultSets, $filters, $page, $pageSize);
    }

    /** @return list<RecentComment> */
    public function recentForInstructor(string $instructorId, int $limit): array
    {
        return array_map(RecentComment::fromRow(...), $this->resultSets('sp_instructor_recent_comments', [$instructorId, $limit])[0] ?? []);
    }
}
