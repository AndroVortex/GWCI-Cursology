<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Dto\ChapterComment;
use App\Models\Dto\CommentPage;
use App\Models\Dto\InstructorCommentPage;

/**
 * JSON de los comentarios. Cada uno trae lo que quien consulta puede hacer con él ("can"), con
 * las mismas reglas que la vista PHP, para que el navegador no tenga que saber de permisos.
 */
final class CommentPresenter
{
    /**
     * - reply: cualquier comentario activo (responder a una respuesta la cuelga del principal);
     * - edit y delete: el autor, mientras siga activo;
     * - hide: el instructor del curso, en los comentarios activos de otros;
     * - report: los demás, en los comentarios activos de otros (el instructor los oculta directo).
     *
     * @return array{reply: bool, edit: bool, delete: bool, hide: bool, report: bool}
     */
    public static function can(ChapterComment $comment, string $viewerId, bool $viewerIsOwner): array
    {
        $mine = $comment->isFrom($viewerId);

        return [
            'reply' => $comment->active,
            'edit' => $comment->active && $mine,
            'delete' => $comment->active && $mine,
            'hide' => $comment->active && $viewerIsOwner && !$mine,
            'report' => $comment->active && !$viewerIsOwner && !$mine,
        ];
    }

    /** @return array<string, mixed> */
    public static function comment(ChapterComment $comment, string $viewerId, bool $viewerIsOwner): array
    {
        $json = [
            'id' => $comment->id,
            'chapterId' => $comment->chapterId,
            'parentId' => $comment->parentId,
            'author' => $comment->authorName,
            'avatar' => $comment->authorAvatar,
            'isInstructor' => $comment->authorIsInstructor,
            'text' => $comment->content,
            'removed' => $comment->isRemoved(),
            'edited' => $comment->wasEdited(),
            'commentedAt' => $comment->commentedAt->format(DATE_ATOM),
            'commentedAtLabel' => format_date_time($comment->commentedAt),
            'replyCount' => $comment->replyCount,
            'replies' => array_map(static fn (ChapterComment $reply): array => self::comment($reply, $viewerId, $viewerIsOwner), $comment->replies),
            'reactions' => ReactionPresenter::summary($comment->reactions),
            'reported' => $comment->reportedByMe,
            'can' => self::can($comment, $viewerId, $viewerIsOwner),
        ];
        if ($comment->answered !== null) {
            $json['answered'] = $comment->answered;
            $json['chapterLabel'] = $comment->chapterLabel();
        }

        return $json;
    }

    /** @return array<string, mixed> */
    public static function page(CommentPage $page, string $viewerId): array
    {
        return [
            'comments' => array_map(static fn (ChapterComment $comment): array => self::comment($comment, $viewerId, $page->viewerIsOwner), $page->comments),
            'total' => $page->total,
            'page' => $page->page,
            'pages' => $page->pages,
        ];
    }

    /** @return array<string, mixed> */
    public static function instructorPage(InstructorCommentPage $page, string $viewerId): array
    {
        return [
            'course' => ['id' => $page->courseId, 'title' => $page->courseTitle, 'active' => $page->courseActive],
            'chapters' => $page->chapters,
            'comments' => array_map(static fn (ChapterComment $comment): array => self::comment($comment, $viewerId, true), $page->threads),
            'filters' => $page->filters,
            'total' => $page->total,
            'page' => $page->page,
            'pages' => $page->pages,
        ];
    }
}
