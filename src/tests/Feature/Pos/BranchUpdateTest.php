<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets a merchant edit a branch name but never its admin-owned location', function (): void {
    $ctx = makeMerchantActor(); // SuperAdmin — holds branches.update
    $branch = $ctx['branch'];
    $branch->forceFill([
        'latitude' => '23.5880000',
        'longitude' => '58.3829000',
    ])->save();

    // A merchant renames the branch AND tries to move it / widen the fence.
    // latitude/longitude/geofence_radius_m are admin-owned and must be ignored.
    $this->patchJson("/api/pos/branches/{$branch->uuid}", [
        'name' => 'Renamed Branch',
        'latitude' => '0.0000000',
        'longitude' => '0.0000000',
        'geofence_radius_m' => 2000,
    ])->assertOk();

    $fresh = $branch->fresh();
    expect($fresh->name)->toBe('Renamed Branch');            // editable field applied
    expect((string) $fresh->latitude)->toBe('23.5880000');   // location untouched
    expect((string) $fresh->longitude)->toBe('58.3829000');
});
