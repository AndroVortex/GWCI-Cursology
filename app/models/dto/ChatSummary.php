<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Enums\UserRole;
use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Una conversación en la lista de mensajes: el curso, la otra persona y el último mensaje (sp_chat_list). */
final class ChatSummary
{
    private function __construct(
        public readonly string $id,
        public readonly string $courseId,
        public readonly string $courseTitle,
        public readonly string $courseCover,
        public readonly bool $courseActive,
        public readonly UserRole $viewerRole,
        public readonly string $otherId,
        public readonly string $otherName,
        public readonly string $otherAvatar,
        public readonly ?string $lastMessageId,
        public readonly ?string $lastMessageContent,
        public readonly ?DateTimeImmutable $lastMessageAt,
        public readonly ?bool $lastMessageMine,
        public readonly int $unreadCount,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $lastActivityAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        /** @var UserRole $role */
        $role = $r->enum('viewer_role', UserRole::class);

        return new self(
            $r->id('id'),
            $r->id('course_id'),
            $r->string('course_title', 200),
            $r->string('course_cover', 2048),
            $r->bool('course_active'),
            $role,
            $r->id('other_id'),
            $r->string('other_name', 350),
            $r->string('other_avatar', 2048),
            $r->nullableId('last_message_id'),
            $r->nullableText('last_message_content'),
            $r->nullableDateTime('last_message_at'),
            $r->nullableBool('last_message_mine'),
            $r->intUnsigned('unread_count'),
            $r->dateTime('created_at'),
            $r->dateTime('last_activity_at'),
        );
    }

    /** El último mensaje en una línea, con "Tú: " si lo envió quien consulta; '' si no hay mensajes. */
    public function excerpt(int $maxChars = 90): string
    {
        if ($this->lastMessageId === null) {
            return '';
        }
        $text = ChatMessage::excerpt($this->lastMessageContent, $maxChars);

        return $this->lastMessageMine ? 'Tú: ' . $text : $text;
    }
}
