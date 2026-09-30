<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fix3OrdersUrl(): string
{
    $today = now()->toDateString();

    return '/api/orders?date_from='.$today.'&date_to='.$today;
}

it('E5 an open Orders tab refreshing in the background still idle-logs-out', function () {
    makeMerchantActor();
    $start = now()->timestamp;
    $this->withSession(['pos_merchant.last_activity_at' => $start, 'pos_merchant.remembered' => false]);

    // The Orders page refreshes every 30 s while visible; 29 minutes of it.
    for ($minute = 1; $minute <= 29; $minute++) {
        $this->travel(1)->minutes();
        $this->getJson(fix3OrdersUrl(), ['X-Background-Refresh' => '1'])->assertOk();
        expect(session('pos_merchant.last_activity_at'))->toBe($start);
    }

    $this->travel(2)->minutes();
    $this->getJson(fix3OrdersUrl(), ['X-Background-Refresh' => '1'])->assertUnauthorized();
    $this->assertGuest();
});

it('E5 a user-initiated Orders request still counts as activity', function () {
    makeMerchantActor();
    $start = now()->timestamp;
    $this->withSession(['pos_merchant.last_activity_at' => $start, 'pos_merchant.remembered' => false]);

    $this->travel(20)->minutes();
    $this->getJson(fix3OrdersUrl())->assertOk();
    expect(session('pos_merchant.last_activity_at'))->toBeGreaterThan($start);

    $this->travel(20)->minutes();
    $this->getJson(fix3OrdersUrl())->assertOk();
});
