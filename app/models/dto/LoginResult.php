<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Enums\AccountStatus;
use App\Models\Enums\LoginOutcome;
use App\Models\Mapping\RowReader;

/** Resultado de sp_auth_record_login: qué pasó con el intento y cómo quedó la cuenta. */
final class LoginResult
{
    private function __construct(
        public readonly LoginOutcome $outcome,
        public readonly int $consecutiveFailures,
        public readonly ?AccountStatus $accountStatus,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        return new self(
            $r->enum('outcome', LoginOutcome::class),
            $r->tinyIntUnsigned('consecutive_failures'),
            $r->nullableEnum('account_status', AccountStatus::class),
        );
    }
}
