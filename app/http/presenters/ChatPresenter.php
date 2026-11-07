<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Dto\ChatMessage;
use App\Models\Dto\ChatSummary;
use App\Models\Dto\ChatThread;

/** JSON de los mensajes. Las fechas van en ISO y ya formateadas, igual que en la vista PHP. */
final class ChatPresenter
{
    public static function url(string $chatId): string
    {
        return '?view=messages&chat=' . rawurlencode($chatId);
    }

    /** @return array<string, mixed> */
    public static function summary(ChatSummary $chat): array
    {
        return [
            'id' => $chat->id,
            'url' => self::url($chat->id),
            'courseId' => $chat->courseId,
            'courseTitle' => $chat->courseTitle,
            'courseActive' => $chat->courseActive,
            'other' => ['name' => $chat->otherName, 'avatar' => $chat->otherAvatar],
            'lastMessage' => $chat->lastMessageId === null ? null : $chat->excerpt(),
            'lastActivityAt' => $chat->lastActivityAt->format(DATE_ATOM),
            'lastActivityLabel' => format_date_time($chat->lastActivityAt),
            'unread' => $chat->unreadCount,
        ];
    }

    /** @return array<string, mixed> */
    public static function message(ChatMessage $message, string $viewerId): array
    {
        return [
            'id' => $message->id,
            'mine' => $message->isFrom($viewerId),
            'author' => $message->senderName,
            'avatar' => $message->senderAvatar,
            'text' => $message->content,
            'sentAt' => $message->sentAt->format(DATE_ATOM),
            'sentAtLabel' => format_date_time($message->sentAt),
            'replyTo' => $message->replyTo,
            'replyAuthor' => $message->replySenderName,
            'replyExcerpt' => $message->replyTo === null ? null : $message->replyExcerpt(),
        ];
    }

    /** @return array<string, mixed> */
    public static function thread(ChatThread $thread, string $viewerId): array
    {
        return [
            'chat' => [
                'id' => $thread->id,
                'courseId' => $thread->courseId,
                'courseTitle' => $thread->courseTitle,
                'courseActive' => $thread->courseActive,
                'other' => ['name' => $thread->otherName(), 'avatar' => $thread->otherAvatar()],
            ],
            'messages' => array_map(static fn (ChatMessage $message): array => self::message($message, $viewerId), $thread->messages),
            'lastMessageId' => $thread->lastMessageId(),
            'unreadTotal' => $thread->unreadTotal,
        ];
    }
}
