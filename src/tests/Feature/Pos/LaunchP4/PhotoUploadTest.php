<?php

declare(strict_types=1);

/**
 * LAUNCH-P4 B5 — photo upload. The browser resizes the photo; the server
 * checks the real type (JPEG / PNG / WebP from the content), the size (500 KB)
 * and the dimensions (1600 px), stores it on the public disk under
 * products/{company uuid}/ and returns its APP_URL/storage URL, which the
 * product and category forms save as image_url. Before: there was no upload
 * at all (link only). Fixtures are tiny hand-made files in
 * tests/Fixtures/launch-p4 (the image has no GD).
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function p4Fixture(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/launch-p4/'.$name));
}

it('stores an uploaded JPEG on the public disk and returns its URL on our own host', function (): void {
    Storage::fake('public');
    $ctx = makeMerchantActor();
    $companyUuid = (string) Company::query()->whereKey($ctx['company']->id)->value('uuid');

    $res = $this->post('/api/catalogue/images', [
        'image' => UploadedFile::fake()->createWithContent('photo.jpg', p4Fixture('photo.jpg')),
        'kind' => 'product',
    ], ['Accept' => 'application/json'])->assertCreated();

    $path = (string) $res->json('data.path');
    expect($path)->toStartWith("products/{$companyUuid}/")->toEndWith('.jpg')
        ->and($res->json('data.url'))->toBe(rtrim((string) config('app.url'), '/').'/storage/'.$path);
    Storage::disk('public')->assertExists($path);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'catalogue.image.uploaded', 'company_id' => $ctx['company']->id]);

    // The product form saves that URL as the photo.
    $product = p4Product($ctx['company'], 'Shawarma', '1.200');
    $this->patchJson("/api/products/{$product->uuid}", ['image_url' => $res->json('data.url')])
        ->assertOk()
        ->assertJsonPath('data.image_url', $res->json('data.url'));
});

it('keeps category photos apart and keeps the real type of a PNG', function (): void {
    Storage::fake('public');
    $ctx = makeMerchantActor();

    $path = (string) $this->post('/api/catalogue/images', [
        'image' => UploadedFile::fake()->createWithContent('menu.png', p4Fixture('photo.png')),
        'kind' => 'category',
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.path');

    expect($path)->toContain('/categories/')->toEndWith('.png');
    Storage::disk('public')->assertExists($path);
    expect(DB::table('pos_audit_logs')->where('event', 'catalogue.image.uploaded')->count())->toBe(1);
});

it('refuses a file that is not a photo, a photo over 500 KB and one over 1600 px', function (): void {
    Storage::fake('public');
    makeMerchantActor();

    $this->post('/api/catalogue/images', [
        'image' => UploadedFile::fake()->createWithContent('menu.jpg', "name,price\nTea,0.300\n"),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['image']);

    $big = p4Fixture('photo.jpg').str_repeat("\0", 520 * 1024);
    $this->post('/api/catalogue/images', [
        'image' => UploadedFile::fake()->createWithContent('big.jpg', $big),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['image']);

    $this->post('/api/catalogue/images', [
        'image' => UploadedFile::fake()->createWithContent('wide.png', p4Fixture('wide.png')),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['image']);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('lets only catalogue managers upload', function (): void {
    Storage::fake('public');
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->post('/api/catalogue/images', [
        'image' => UploadedFile::fake()->createWithContent('photo.jpg', p4Fixture('photo.jpg')),
    ], ['Accept' => 'application/json'])->assertForbidden();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});
