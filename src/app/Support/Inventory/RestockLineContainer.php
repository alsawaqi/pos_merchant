<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Actions\Pos\Inventory\IngredientUnitConverter;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use RuntimeException;

/**
 * LAUNCH review add-on (D4, tester call 10) — a restock request line may name
 * a container ("1 × bottle 1.5 l", what a scan adds). The requested amount
 * then fills in as pieces × size and may only be lowered; without a container
 * the line is a free amount in any unit, as before.
 */
final class RestockLineContainer
{
    /**
     * @param  array<string, mixed>  $line  {quantity_requested?, unit?, container_uuid?, pieces?}
     * @return array{quantity: float, container: ?IngredientAltUnit, pieces: ?string, label: ?string}
     *
     * @throws RuntimeException
     */
    public static function resolve(Ingredient $ingredient, array $line, IngredientUnitConverter $units): array
    {
        $unit = isset($line['unit']) && is_string($line['unit']) && $line['unit'] !== '' ? $line['unit'] : null;
        if (! empty($line['container_uuid'])) {
            $rows = ContainerAmount::rows($ingredient, [['container_uuid' => (string) $line['container_uuid'], 'pieces' => $line['pieces'] ?? null]]);
            $base = ContainerAmount::amount($ingredient, $rows, $line['quantity_requested'] ?? null, $unit, $units);

            return [
                'quantity' => (float) (string) $base,
                'container' => $rows[0]['container'],
                'pieces' => (string) $rows[0]['pieces'],
                'label' => ContainerAmount::label($ingredient, $rows[0]['container']),
            ];
        }

        if (! isset($line['quantity_requested']) || $line['quantity_requested'] === null || $line['quantity_requested'] === '') {
            throw new RuntimeException(sprintf('Enter how much "%s" is needed, or its container.', $ingredient->name));
        }

        return [
            'quantity' => $units->toBase($ingredient, $line['quantity_requested'], $unit),
            'container' => null,
            'pieces' => null,
            'label' => null,
        ];
    }
}
