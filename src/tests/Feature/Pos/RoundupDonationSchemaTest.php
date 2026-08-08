<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('mirrors durable round-up attribution columns', function (): void {
    expect(Schema::hasColumns('pos_roundup_donations', [
        'organization_id',
        'branch_name',
    ]))->toBeTrue();
});
