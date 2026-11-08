<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;

/**
 * Las reacciones de un comentario, una opinión o un mensaje: cuántas hay de cada emoji y cuál
 * es la de quien consulta. Se arma con las filas target_id, reaction_id, emoji, reaction_name,
 * total y mine que devuelven los SP.
 */
final class ReactionSummary
{
    /** @param list<array{reactionId: string, emoji: string, name: string, total: int}> $counts */
    public function __construct(
        public readonly array $counts = [],
        public readonly ?string $mine = null,
    ) {
    }

    /**
     * Agrupa las filas por target_id.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, self>
     */
    public static function byTarget(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $r = new RowReader(self::class, $row);
            $grouped[$r->id('target_id')][] = $row;
        }

        return array_map(self::fromRows(...), $grouped);
    }

    /** @param list<array<string, mixed>> $rows filas de un solo target */
    public static function fromRows(array $rows): self
    {
        $counts = [];
        $mine = null;
        foreach ($rows as $row) {
            $r = new RowReader(self::class, $row);
            $id = $r->id('reaction_id');
            $counts[] = ['reactionId' => $id, 'emoji' => $r->string('emoji', 32), 'name' => $r->string('reaction_name', 60), 'total' => $r->intUnsigned('total')];
            if ($r->bool('mine')) {
                $mine = $id;
            }
        }

        return new self($counts, $mine);
    }

    /** @param array<string, self> $byTarget */
    public static function of(array $byTarget, string $targetId): self
    {
        return $byTarget[$targetId] ?? new self();
    }

    public function total(): int
    {
        return array_sum(array_column($this->counts, 'total'));
    }
}
