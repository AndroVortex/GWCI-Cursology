<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Enums\UserRole;
use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Una conversación abierta: el curso, sus dos participantes y sus mensajes (sp_chat_open). */
final class ChatThread
{
    /** @param list<ChatMessage> $messages */
    private function __construct(
        public readonly string $id,
        public readonly string $courseId,
        public readonly string $courseTitle,
        public readonly string $courseCover,
        public readonly bool $courseActive,
        public readonly UserRole $viewerRole,
        public readonly string $studentId,
        public readonly string $studentName,
        public readonly string $studentAvatar,
        public readonly string $instructorId,
        public readonly string $instructorName,
        public readonly string $instructorAvatar,
        public readonly DateTimeImmutable $createdAt,
        public readonly array $messages,
        public readonly int $unreadTotal,
    ) {
    }

    /** @param list<list<array<string, mixed>>> $resultSets */
    public static function fromResultSets(array $resultSets): self
    {
        [$chatRows, $messageRows, $unreadRows] = $resultSets + [[], [], []];
        $r = new RowReader(self::class, $chatRows[0] ?? []);

        /** @var UserRole $role */
        $role = $r->enum('viewer_role', UserRole::class);

        return new self(
            $r->id('id'),
            $r->id('course_id'),
            $r->string('course_title', 200),
            $r->string('course_cover', 2048),
            $r->bool('course_active'),
            $role,
            $r->id('student_id'),
            $r->string('student_name', 350),
            $r->string('student_avatar', 2048),
            $r->id('instructor_id'),
            $r->string('instructor_name', 350),
            $r->string('instructor_avatar', 2048),
            $r->dateTime('created_at'),
            array_map(ChatMessage::fromRow(...), $messageRows),
            (new RowReader(self::class, $unreadRows[0] ?? ['unread_total' => 0]))->intUnsigned('unread_total'),
        );
    }

    public function otherName(): string
    {
        return $this->viewerRole === UserRole::Student ? $this->instructorName : $this->studentName;
    }

    public function otherAvatar(): string
    {
        return $this->viewerRole === UserRole::Student ? $this->instructorAvatar : $this->studentAvatar;
    }

    /** El id del mensaje más nuevo, o null si no hay: el punto de partida para pedir los siguientes. */
    public function lastMessageId(): ?string
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)]->id;
    }
}
