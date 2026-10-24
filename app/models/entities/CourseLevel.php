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
 * Fila de `course_level`. El precio (cost) aplica a ventas futuras; los importes
 * históricos están en purchase. La moneda se hereda de course.currency.
 */
final class CourseLevel extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $courseId,
        protected readonly string $title,
        protected readonly string $description,
        protected readonly string $onboardingVideo,
        protected readonly int $sortOrder,
        protected readonly string $cost,
        protected readonly DateTimeImmutable $createdAt,
        protected readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            courseId: $r->id('course_id'),
            title: $r->string('title', 200),
            description: $r->text('description'),
            onboardingVideo: $r->string('onboarding_video', 2048),
            sortOrder: $r->intUnsigned('sort_order'),
            cost: $r->decimal('cost', 12, 2),
            createdAt: $r->dateTime('created_at'),
            updatedAt: $r->dateTime('updated_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'course_level',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('course_id', 'courseId', 'BIGINT UNSIGNED'),
                new Column('title', 'title', 'VARCHAR(200)'),
                new Column('description', 'description', 'TEXT'),
                new Column('onboarding_video', 'onboardingVideo', 'VARCHAR(2048)'),
                new Column('sort_order', 'sortOrder', 'INT UNSIGNED'),
                new Column('cost', 'cost', 'DECIMAL(12,2)', default: '0.00'),
                new Column('created_at', 'createdAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('updated_at', 'updatedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)', onUpdate: 'CURRENT_TIMESTAMP(6)'),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_level_order', ['course_id', 'sort_order']),
                new UniqueKey('uq_level_course', ['id', 'course_id']),
            ],
            foreignKeys: [
                new ForeignKey('fk_level_course', ['course_id'], 'course', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function courseId(): string { return $this->courseId; }
    public function title(): string { return $this->title; }
    public function description(): string { return $this->description; }
    public function onboardingVideo(): string { return $this->onboardingVideo; }
    public function sortOrder(): int { return $this->sortOrder; }
    /** DECIMAL(12,2) exacto como cadena. */
    public function cost(): string { return $this->cost; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
}
