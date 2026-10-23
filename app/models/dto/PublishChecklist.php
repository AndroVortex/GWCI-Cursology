<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Services\PricingMode;

/**
 * Requisitos para publicar un curso, armados a partir de su CourseOutline para que el
 * editor muestre qué falta y dónde. sp_course_publish los vuelve a comprobar: esta lista
 * no decide, solo guía.
 */
final class PublishChecklist
{
    /** @param list<array{key: string, label: string, ok: bool, detail: ?string}> $items */
    private function __construct(public readonly array $items)
    {
    }

    public static function for(CourseOutline $outline): self
    {
        $levelsWithoutChapters = [];
        $chaptersWithoutVideo = [];
        foreach ($outline->levels as $level) {
            $chapters = $outline->chapters($level->id());
            if ($chapters === []) {
                $levelsWithoutChapters[] = sprintf('Nivel %d (%s)', $level->sortOrder(), $level->title());
            }
            foreach ($chapters as $chapter) {
                if (!$chapter->isTest() && $outline->mainVideo($chapter->id()) === null) {
                    $chaptersWithoutVideo[] = $level->sortOrder() . '.' . $chapter->sortOrder();
                }
            }
        }

        $hasLevels = $outline->levels !== [];
        $mode = $outline->pricingMode();
        $pricesOk = match ($mode) {
            PricingMode::Free => array_filter($outline->levels, static fn ($level): bool => $level->cost() !== '0.00') === [],
            PricingMode::Levels => true,
            PricingMode::Bundle => $outline->course->fullCoursePrice() !== '0.00',
        };

        return new self([
            [
                'key' => 'category',
                'label' => 'Al menos una categoría activa',
                'ok' => $outline->activeCategoryCount > 0,
                'detail' => $outline->activeCategoryCount > 0 ? null : 'Elige una categoría en "Información del curso".',
            ],
            [
                'key' => 'levels',
                'label' => 'Al menos un nivel',
                'ok' => $hasLevels,
                'detail' => $hasLevels ? null : 'Agrégalo en "Niveles y capítulos".',
            ],
            [
                'key' => 'chapters',
                'label' => 'Cada nivel con al menos un capítulo',
                'ok' => $hasLevels && $levelsWithoutChapters === [],
                'detail' => $levelsWithoutChapters === [] ? null : 'Sin capítulos: ' . implode(', ', $levelsWithoutChapters) . '.',
            ],
            [
                'key' => 'videos',
                'label' => 'Cada capítulo con su video principal',
                'ok' => $outline->chapterCount() > 0 && $chaptersWithoutVideo === [],
                'detail' => $chaptersWithoutVideo === [] ? null : 'Falta el video en: ' . implode(', ', $chaptersWithoutVideo) . '.',
            ],
            [
                'key' => 'pricing',
                'label' => 'Precios coherentes con la forma de cobro (' . $mode->label() . ')',
                'ok' => $pricesOk,
                'detail' => $pricesOk ? null : 'Guarda de nuevo la información del curso para corregirlos.',
            ],
        ]);
    }

    public function ready(): bool
    {
        return $this->pendingCount() === 0;
    }

    public function pendingCount(): int
    {
        return count(array_filter($this->items, static fn (array $item): bool => !$item['ok']));
    }
}
