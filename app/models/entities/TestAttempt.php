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
 * Fila de `test_attempt`: intento de un examen.
 *
 * - minimum_grade_snapshot congela el mínimo aprobatorio al iniciar.
 * - earned_points y grade son NULL hasta que se entrega (submitted_at).
 * - passed es generada por MySQL y vale NULL antes de entregar: no se calcula en PHP.
 */
final class TestAttempt extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $userId,
        protected readonly string $courseChapterId,
        protected readonly string $courseLevelId,
        protected readonly int $attemptNumber,
        protected readonly DateTimeImmutable $startedAt,
        protected readonly ?DateTimeImmutable $submittedAt,
        protected readonly string $minimumGradeSnapshot,
        protected readonly string $maxPoints,
        protected readonly ?string $earnedPoints,
        protected readonly ?string $grade,
        protected readonly ?bool $passed,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            userId: $r->id('user_id'),
            courseChapterId: $r->id('course_chapter_id'),
            courseLevelId: $r->id('course_level_id'),
            attemptNumber: $r->intUnsigned('attempt_number'),
            startedAt: $r->dateTime('started_at'),
            submittedAt: $r->nullableDateTime('submitted_at'),
            minimumGradeSnapshot: $r->decimal('minimum_grade_snapshot', 5, 2),
            maxPoints: $r->decimal('max_points', 14, 2),
            earnedPoints: $r->nullableDecimal('earned_points', 14, 2),
            grade: $r->nullableDecimal('grade', 5, 2),
            passed: $r->nullableBool('passed'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'test_attempt',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('user_id', 'userId', 'BIGINT UNSIGNED'),
                new Column('course_chapter_id', 'courseChapterId', 'BIGINT UNSIGNED'),
                new Column('course_level_id', 'courseLevelId', 'BIGINT UNSIGNED'),
                new Column('attempt_number', 'attemptNumber', 'INT UNSIGNED'),
                new Column('started_at', 'startedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('submitted_at', 'submittedAt', 'DATETIME(6)', nullable: true),
                new Column('minimum_grade_snapshot', 'minimumGradeSnapshot', 'DECIMAL(5,2)'),
                new Column('max_points', 'maxPoints', 'DECIMAL(14,2)'),
                new Column('earned_points', 'earnedPoints', 'DECIMAL(14,2)', nullable: true),
                new Column('grade', 'grade', 'DECIMAL(5,2)', nullable: true),
                new Column('passed', 'passed', 'BOOLEAN', nullable: true, generated: true),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_attempt_number', ['user_id', 'course_chapter_id', 'attempt_number']),
                new UniqueKey('uq_attempt_chapter', ['id', 'course_chapter_id']),
            ],
            foreignKeys: [
                new ForeignKey('fk_attempt_access', ['user_id', 'course_level_id'], 'user_course_level', ['user_id', 'course_level_id']),
                new ForeignKey('fk_attempt_chapter', ['course_chapter_id', 'course_level_id'], 'course_chapter', ['id', 'course_level_id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function userId(): string { return $this->userId; }
    public function courseChapterId(): string { return $this->courseChapterId; }
    public function courseLevelId(): string { return $this->courseLevelId; }
    public function attemptNumber(): int { return $this->attemptNumber; }
    public function startedAt(): DateTimeImmutable { return $this->startedAt; }
    public function submittedAt(): ?DateTimeImmutable { return $this->submittedAt; }
    public function minimumGradeSnapshot(): string { return $this->minimumGradeSnapshot; }
    public function maxPoints(): string { return $this->maxPoints; }
    public function earnedPoints(): ?string { return $this->earnedPoints; }
    public function grade(): ?string { return $this->grade; }
    /** Columna generada: NULL mientras el intento no se entrega. */
    public function passed(): ?bool { return $this->passed; }
}
