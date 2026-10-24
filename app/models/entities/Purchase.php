<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Enums\PurchaseScope;
use App\Models\Enums\PurchaseStatus;
use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use App\Models\Mapping\UniqueKey;
use DateTimeImmutable;

/**
 * Fila de `purchase`: compra de un curso completo o de UN nivel (también gratis).
 *
 * Los *_snapshot, amount y currency conservan lo que se cobró en su momento;
 * no se recalculan con los precios o títulos actuales. Datos de pago: uso interno.
 */
final class Purchase extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $userId,
        protected readonly string $courseId,
        protected readonly ?string $courseLevelId,
        protected readonly PurchaseScope $purchaseScope,
        protected readonly ?string $paymentMethodId,
        protected readonly ?string $paymentMethodNameSnapshot,
        protected readonly string $courseTitleSnapshot,
        protected readonly ?string $levelTitleSnapshot,
        protected readonly string $amount,
        protected readonly string $currency,
        protected readonly PurchaseStatus $status,
        protected readonly string $idempotencyKey,
        protected readonly ?string $provider,
        protected readonly ?string $providerTransactionId,
        protected readonly DateTimeImmutable $createdAt,
        protected readonly ?DateTimeImmutable $completedAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            userId: $r->id('user_id'),
            courseId: $r->id('course_id'),
            courseLevelId: $r->nullableId('course_level_id'),
            purchaseScope: $r->enum('purchase_scope', PurchaseScope::class),
            paymentMethodId: $r->nullableId('payment_method_id'),
            paymentMethodNameSnapshot: $r->nullableString('payment_method_name_snapshot', 100),
            courseTitleSnapshot: $r->string('course_title_snapshot', 200),
            levelTitleSnapshot: $r->nullableString('level_title_snapshot', 200),
            amount: $r->decimal('amount', 12, 2),
            currency: $r->string('currency', 3),
            status: $r->enum('status', PurchaseStatus::class),
            idempotencyKey: $r->string('idempotency_key', 64),
            provider: $r->nullableString('provider', 50),
            providerTransactionId: $r->nullableString('provider_transaction_id', 191),
            createdAt: $r->dateTime('created_at'),
            completedAt: $r->nullableDateTime('completed_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'purchase',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('user_id', 'userId', 'BIGINT UNSIGNED'),
                new Column('course_id', 'courseId', 'BIGINT UNSIGNED'),
                new Column('course_level_id', 'courseLevelId', 'BIGINT UNSIGNED', nullable: true),
                new Column('purchase_scope', 'purchaseScope', 'VARCHAR(10)', enum: PurchaseScope::class),
                new Column('payment_method_id', 'paymentMethodId', 'BIGINT UNSIGNED', nullable: true),
                new Column('payment_method_name_snapshot', 'paymentMethodNameSnapshot', 'VARCHAR(100)', nullable: true),
                new Column('course_title_snapshot', 'courseTitleSnapshot', 'VARCHAR(200)'),
                new Column('level_title_snapshot', 'levelTitleSnapshot', 'VARCHAR(200)', nullable: true),
                new Column('amount', 'amount', 'DECIMAL(12,2)'),
                new Column('currency', 'currency', 'CHAR(3)', default: "'MXN'"),
                new Column('status', 'status', 'VARCHAR(12)', default: "'pending'", enum: PurchaseStatus::class),
                new Column('idempotency_key', 'idempotencyKey', 'VARCHAR(64)'),
                new Column('provider', 'provider', 'VARCHAR(50)', nullable: true),
                new Column('provider_transaction_id', 'providerTransactionId', 'VARCHAR(191)', nullable: true),
                new Column('created_at', 'createdAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('completed_at', 'completedAt', 'DATETIME(6)', nullable: true),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_purchase_idempotency', ['idempotency_key']),
                new UniqueKey('uq_purchase_provider', ['provider', 'provider_transaction_id']),
                new UniqueKey('uq_purchase_owner', ['id', 'user_id', 'course_id']),
            ],
            foreignKeys: [
                new ForeignKey('fk_purchase_user', ['user_id'], 'app_user', ['id']),
                new ForeignKey('fk_purchase_course', ['course_id'], 'course', ['id']),
                new ForeignKey('fk_purchase_level', ['course_level_id', 'course_id'], 'course_level', ['id', 'course_id']),
                new ForeignKey('fk_purchase_method', ['payment_method_id'], 'payment_method', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function userId(): string { return $this->userId; }
    public function courseId(): string { return $this->courseId; }
    public function courseLevelId(): ?string { return $this->courseLevelId; }
    public function purchaseScope(): PurchaseScope { return $this->purchaseScope; }
    public function paymentMethodId(): ?string { return $this->paymentMethodId; }
    public function paymentMethodNameSnapshot(): ?string { return $this->paymentMethodNameSnapshot; }
    public function courseTitleSnapshot(): string { return $this->courseTitleSnapshot; }
    public function levelTitleSnapshot(): ?string { return $this->levelTitleSnapshot; }
    /** Importe real DECIMAL(12,2) como cadena exacta. */
    public function amount(): string { return $this->amount; }
    public function currency(): string { return $this->currency; }
    public function status(): PurchaseStatus { return $this->status; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function provider(): ?string { return $this->provider; }
    public function providerTransactionId(): ?string { return $this->providerTransactionId; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function completedAt(): ?DateTimeImmutable { return $this->completedAt; }
}
