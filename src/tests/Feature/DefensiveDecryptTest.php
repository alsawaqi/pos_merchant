<?php

declare(strict_types=1);

/**
 * The merchant portal shares pos_users/pos_staff (and one APP_KEY) with
 * pos_admin. If the keys ever drift, rows written by the sibling portal hold
 * ciphertext this app cannot decrypt — and every page touching them used to
 * 500 with "The MAC is invalid". DecryptsDefensively degrades such fields to
 * NULL (with a warning log) instead.
 */

use App\Models\PosStaff;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('surfaces null instead of throwing on undecryptable user phone ciphertext', function (): void {
    $user = User::factory()->create(['phone' => '91234567']);

    $foreign = new Encrypter(random_bytes(32), config('app.cipher'));
    DB::table('pos_users')->where('id', $user->id)->update([
        'phone' => $foreign->encrypt('91234567', false),
    ]);

    expect($user->fresh()->phone)->toBeNull();
});

it('surfaces null instead of throwing on undecryptable staff phone ciphertext', function (): void {
    $staff = PosStaff::factory()->create(['phone' => '99887766']);

    $foreign = new Encrypter(random_bytes(32), config('app.cipher'));
    DB::table('pos_staff')->where('id', $staff->id)->update([
        'phone' => $foreign->encrypt('99887766', false),
    ]);

    expect($staff->fresh()->phone)->toBeNull();
});
