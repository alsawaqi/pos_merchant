<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part B item 3 — A3 the count container (tester call 6).
 *
 * The "Count container" form field is gone: a container row is marked "Tills
 * count in this" (at most one). The four pos_ingredients columns devices read —
 * piece_unit_label, piece_unit_label_ar, units_per_piece, allow_fractional_pieces
 * — stay as its MIRROR, written with count_container_id; '@piece' keeps
 * converting with the same number. Removing it clears the mirror (tills count
 * in l / kg after their next settings refresh; the portal warns first).
 */

use App\Actions\Pos\Inventory\IngredientUnitConverter;
use App\Models\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('mirrors the container marked "Tills count in this" into the device columns', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1500', nameAr: 'زجاجة');

    $this->patchJson("/api/ingredients/{$milk->uuid}", ['count_container_uuid' => rvContainerUuid($bottle)])
        ->assertOk()
        ->assertJsonPath('data.count_container_uuid', rvContainerUuid($bottle))
        ->assertJsonPath('data.piece_unit_label', 'bottle')
        ->assertJsonPath('data.piece_unit_label_ar', 'زجاجة')
        ->assertJsonPath('data.units_per_piece', '1500.0000');

    $fresh = $milk->fresh();
    expect((int) $fresh->count_container_id)->toBe($bottle)
        ->and(app(IngredientUnitConverter::class)->toBase($fresh, '2', '@piece'))->toBe(3000.0);

    $listed = collect($this->getJson('/api/ingredients')->json('data'))->firstWhere('uuid', $milk->uuid);
    expect(collect($listed['alt_units'])->firstWhere('uuid', rvContainerUuid($bottle))['is_count_container'])->toBeTrue();
});

it('marks the count container on create, and turns an old piece pair into a container row', function (): void {
    makeMerchantActor();

    $this->postJson('/api/ingredients', [
        'name' => 'Milk', 'unit' => 'ml',
        'pack_sizes' => [
            ['name' => 'bottle', 'amount' => '1.5', 'unit' => 'l', 'count_container' => true],
            ['name' => 'crate', 'contains_index' => 0, 'contains_quantity' => 6],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.piece_unit_label', 'bottle')
        ->assertJsonPath('data.units_per_piece', '1500.0000');

    // At most one.
    $this->postJson('/api/ingredients', [
        'name' => 'Oil', 'unit' => 'ml',
        'pack_sizes' => [
            ['name' => 'bottle', 'amount' => '1', 'unit' => 'l', 'count_container' => true],
            ['name' => 'can', 'amount' => '5', 'unit' => 'l', 'count_container' => true],
        ],
    ])->assertStatus(422);

    // An older caller still sends the piece pair: it becomes the count container row.
    $res = $this->postJson('/api/ingredients', [
        'name' => 'Bread', 'unit' => 'g', 'piece_unit_label' => 'loaf', 'units_per_piece' => '500',
    ])->assertCreated();
    $bread = Ingredient::query()->where('uuid', $res->json('data.uuid'))->firstOrFail();
    expect($bread->count_container_id)->not->toBeNull()
        ->and($res->json('data.alt_units.0.name'))->toBe('loaf')
        ->and($res->json('data.alt_units.0.is_count_container'))->toBeTrue()
        ->and($bread->piece_unit_label)->toBe('loaf')
        ->and((string) $bread->units_per_piece)->toBe('500.0000');
});

it('clears the mirror when the count container is removed or unmarked', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1500');
    $jug = rvContainer($milk, 'jug', '2000');
    $this->patchJson("/api/ingredients/{$milk->uuid}", ['count_container_uuid' => rvContainerUuid($bottle)])->assertOk();

    // Moved to another container: the mirror follows.
    $this->patchJson("/api/ingredients/{$milk->uuid}", ['count_container_uuid' => rvContainerUuid($jug)])
        ->assertOk()
        ->assertJsonPath('data.piece_unit_label', 'jug')
        ->assertJsonPath('data.units_per_piece', '2000.0000');

    // Deleted: count_container_id and the mirror are cleared.
    $this->deleteJson("/api/ingredients/{$milk->uuid}/units/".rvContainerUuid($jug))->assertNoContent();
    $fresh = $milk->fresh();
    expect($fresh->count_container_id)->toBeNull()
        ->and($fresh->piece_unit_label)->toBeNull()
        ->and($fresh->units_per_piece)->toBeNull();

    // Unmarked (null): cleared too.
    $this->patchJson("/api/ingredients/{$milk->uuid}", ['count_container_uuid' => rvContainerUuid($bottle)])->assertOk();
    $this->patchJson("/api/ingredients/{$milk->uuid}", ['count_container_uuid' => null])
        ->assertOk()
        ->assertJsonPath('data.piece_unit_label', null)
        ->assertJsonPath('data.count_container_uuid', null);
});

it('follows a rename of the count container and refuses a mirror change made around it', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1500');
    $this->patchJson("/api/ingredients/{$milk->uuid}", ['count_container_uuid' => rvContainerUuid($bottle)])->assertOk();

    $this->patchJson("/api/ingredients/{$milk->uuid}/units/".rvContainerUuid($bottle), ['name' => 'flask', 'name_ar' => 'قارورة'])->assertOk();
    expect($milk->fresh()->piece_unit_label)->toBe('flask')
        ->and($milk->fresh()->piece_unit_label_ar)->toBe('قارورة');

    // The old count-container fields: a change is refused, the same values pass.
    $this->patchJson("/api/ingredients/{$milk->uuid}", ['piece_unit_label' => 'jar', 'units_per_piece' => '700'])
        ->assertStatus(422);
    $this->patchJson("/api/ingredients/{$milk->uuid}", ['piece_unit_label' => 'flask', 'units_per_piece' => '1500'])
        ->assertOk();
});

it('refuses a count container of another item', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $oil = rvIngredient($ctx['company'], 'Oil', 'ml');
    $drum = rvContainer($oil, 'drum', '20000');

    $this->patchJson("/api/ingredients/{$milk->uuid}", ['count_container_uuid' => rvContainerUuid($drum)])
        ->assertStatus(422);
});
