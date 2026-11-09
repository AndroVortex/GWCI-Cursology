<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\PurgeResult;

/** Limpieza de las cuentas de prueba o de demostración, exclusivamente mediante SP. */
final class MaintenanceRepository
{
    use CallsProcedures;

    public const SCOPE_TEST = 'test';
    public const SCOPE_DEMO = 'demo';

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    /** Con $apply = false es un ensayo: cuenta lo que se borraría y no borra nada. */
    public function purge(string $scope, bool $apply): PurgeResult
    {
        return PurgeResult::fromRow($this->requiredRow('sp_maintenance_purge', [$scope, $apply ? 1 : 0]));
    }
}
