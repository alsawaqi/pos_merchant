<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function omanRulePayload(string $kind): array
{
    return match ($kind) {
        'discounts' => ['name' => 'OMAN P1 discount', 'scope' => 'order', 'amount_type' => 'percent', 'amount' => '10'],
        'offers' => ['name' => 'OMAN P1 offer', 'type' => 'spend_get', 'config' => [
            'min_subtotal_baisas' => 1000, 'reward_type' => 'percent_off', 'reward_value' => 10,
        ]],
        'loyalty/rules' => ['name' => 'OMAN P1 loyalty', 'type' => 'spend_based', 'config_json' => [
            'points_per_omr' => 1, 'redemption_points' => 100, 'redemption_value' => '5.000', 'min_redemption_points' => 100,
        ]],
    };
}

it('P1 stores an Oman rule create and edit as UTC instants and activates at that instant', function (string $kind, string $table): void {
    makeMerchantActor();
    Carbon::setTestNow('2026-09-27T12:59:59+00:00');
    try {
        $created = $this->postJson('/api/'.$kind, omanRulePayload($kind) + [
            'validity_start' => '2026-09-27T17:00:00+04:00',
            'validity_end' => '2026-09-27T18:00:00+04:00',
        ])->assertCreated();
        $uuid = $created->json('data.uuid');
        expect(DB::table($table)->where('uuid', $uuid)->value('validity_start'))->toBe('2026-09-27 13:00:00');
        $created->assertJsonPath('data.validity_start', '2026-09-27T13:00:00+00:00')
            ->assertJsonPath('data.validity_end', '2026-09-27T14:00:00+00:00')
            ->assertJsonPath('data.currently_active', false);
        foreach (['2026-09-27T13:00:00+00:00' => true, '2026-09-27T14:00:01+00:00' => false] as $instant => $active) {
            Carbon::setTestNow($instant);
            $this->withSession(['pos_merchant.last_activity_at' => now()->timestamp]);
            $listed = $this->getJson('/api/'.$kind)->assertOk()->json('data');
            expect(collect($listed)->firstWhere('uuid', $uuid)['currently_active'])->toBe($active);
        }
        $this->patchJson('/api/'.$kind.'/'.$uuid, [
            'validity_start' => '2026-09-28T17:00:00+04:00',
            'validity_end' => '2026-09-28T18:00:00+04:00',
        ])->assertOk()->assertJsonPath('data.validity_start', '2026-09-28T13:00:00+00:00');
        expect(DB::table($table)->where('uuid', $uuid)->value('validity_start'))->toBe('2026-09-28 13:00:00');
        $before = DB::table($table)->where('uuid', $uuid)->first();
        $this->getJson('/api/'.$kind)->assertOk();
        expect(DB::table($table)->where('uuid', $uuid)->first())->toEqual($before);
    } finally {
        Carbon::setTestNow();
    }
})->with([
    ['discounts', 'pos_discounts'],
    ['offers', 'pos_offers'],
    ['loyalty/rules', 'pos_loyalty_rules'],
]);

it('P1 honors explicit offsets in partial updates without changing the other bound', function (string $kind, string $table): void {
    makeMerchantActor();
    $created = $this->postJson('/api/'.$kind, omanRulePayload($kind) + [
        'validity_start' => '2026-09-27T17:00:00+04:00',
        'validity_end' => '2026-09-27T18:00:00+04:00',
    ])->assertCreated()->assertJsonPath('data.validity_start', '2026-09-27T13:00:00+00:00');
    $uuid = $created->json('data.uuid');
    $this->patchJson('/api/'.$kind.'/'.$uuid, [
        'validity_start' => '2026-09-27T12:00:00Z',
    ])->assertOk()->assertJsonPath('data.validity_start', '2026-09-27T12:00:00+00:00');
    expect(DB::table($table)->where('uuid', $uuid)->value('validity_end'))->toBe('2026-09-27 14:00:00');
    $before = DB::table($table)->where('uuid', $uuid)->first();
    $this->patchJson('/api/'.$kind.'/'.$uuid, ['validity_start' => 'not a date'])->assertUnprocessable();
    expect(DB::table($table)->where('uuid', $uuid)->first())->toEqual($before);
})->with([
    ['discounts', 'pos_discounts'],
    ['offers', 'pos_offers'],
    ['loyalty/rules', 'pos_loyalty_rules'],
]);

it('P1 keeps validation and persistence on the same mixed-offset instant and preserves legacy UTC input', function (string $kind): void {
    makeMerchantActor();
    $created = $this->postJson('/api/'.$kind, omanRulePayload($kind) + [
        'validity_start' => '2026-09-27T17:00:00+04:00',
        'validity_end' => '2026-09-27T14:00:00Z',
    ])->assertCreated()->assertJsonPath('data.validity_start', '2026-09-27T13:00:00+00:00');
    $uuid = $created->json('data.uuid');
    $this->patchJson('/api/'.$kind.'/'.$uuid, [
        'validity_start' => '2026-09-27T13:30:00Z',
        'validity_end' => '2026-09-27T18:00:00+04:00',
    ])->assertOk()->assertJsonPath('data.validity_start', '2026-09-27T13:30:00+00:00')
        ->assertJsonPath('data.validity_end', '2026-09-27T14:00:00+00:00');
    $this->patchJson('/api/'.$kind.'/'.$uuid, [
        'validity_start' => '2026-09-27T13:00',
        'validity_end' => '2026-09-27T14:00',
    ])->assertOk()->assertJsonPath('data.validity_start', '2026-09-27T13:00:00+00:00')
        ->assertJsonPath('data.validity_end', '2026-09-27T14:00:00+00:00');
})->with(['discounts', 'offers', 'loyalty/rules']);
