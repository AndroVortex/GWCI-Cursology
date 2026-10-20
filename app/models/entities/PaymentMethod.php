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
 * Fila de `payment_method`: catálogo global. El método elegido se guarda en
 * purchase, no en course_level.
 */
final class PaymentMethod extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $code,
        protected readonly string $methodName,
        protected readonly string $createdBy,
        protected readonly DateTimeImmutable $createdAt,
        protected readonly ?string $updatedBy,
        protected readonly DateTimeImmutable $updatedAt,
        protected readonly bool $active,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            code: $r->string('code', 40),
            methodName: $r->string('method_name', 100),
            createdBy: $r->id('created_by'),
            createdAt: $r->dateTime('created_at'),
            updatedBy: $r->nullableId('updated_by'),
            updatedAt: $r->dateTime('updated_at'),
            active: $r->bool('active'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'payment_method',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('code', 'code', 'VARCHAR(40)'),
                new Column('method_name', 'methodName', 'VARCHAR(100)'),
                new Column('created_by', 'createdBy', 'BIGINT UNSIGNED'),
                new Column('created_at', 'createdAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('updated_by', 'updatedBy', 'BIGINT UNSIGNED', nullable: true),
                new Column('updated_at', 'updatedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)', onUpdate: 'CURRENT_TIMESTAMP(6)'),
                new Column('active', 'active', 'BOOLEAN', default: 'TRUE'),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_payment_method_name', ['code']),
            ],
            foreignKeys: [
                new ForeignKey('fk_payment_method_creator', ['created_by'], 'app_user', ['id']),
                new ForeignKey('fk_payment_method_updater', ['updated_by'], 'app_user', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function code(): string { return $this->code; }
    public function methodName(): string { return $this->methodName; }
    public function createdBy(): string { return $this->createdBy; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedBy(): ?string { return $this->updatedBy; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function active(): bool { return $this->active; }
}
