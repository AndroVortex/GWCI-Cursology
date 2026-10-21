<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use App\Models\Mapping\UniqueKey;
use DateTimeImmutable;

/**
 * Fila de `chat`: conversación por curso entre el instructor propietario y un alumno.
 * Los participantes son datos internos; que coincidan con course.created_by y con
 * los roles se valida en PHP, no en esta entidad.
 */
final class Chat extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $courseId,
        protected readonly string $instructorId,
        protected readonly string $studentId,
        protected readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            courseId: $r->id('course_id'),
            instructorId: $r->id('instructor_id'),
            studentId: $r->id('student_id'),
            createdAt: $r->dateTime('created_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'chat',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('course_id', 'courseId', 'BIGINT UNSIGNED'),
                new Column('instructor_id', 'instructorId', 'BIGINT UNSIGNED'),
                new Column('student_id', 'studentId', 'BIGINT UNSIGNED'),
                new Column('created_at', 'createdAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_chat_participants', ['course_id', 'instructor_id', 'student_id']),
            ],
            foreignKeys: [
                new ForeignKey('fk_chat_course', ['course_id'], 'course', ['id']),
                new ForeignKey('fk_chat_instructor', ['instructor_id'], 'app_user', ['id']),
                new ForeignKey('fk_chat_student', ['student_id'], 'app_user', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function courseId(): string { return $this->courseId; }
    public function instructorId(): string { return $this->instructorId; }
    public function studentId(): string { return $this->studentId; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
}
