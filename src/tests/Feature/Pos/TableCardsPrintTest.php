<?php

declare(strict_types=1);

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Floor;
use App\Models\Table;
use App\Support\TableCardQr;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders four cards per A4 page with the exact customer URLs and real inline SVGs', function (): void {
    $ctx = makeMerchantActor();
    // Companies have one name column; branch and floor names have Arabic variants.
    $ctx['company']->forceFill(['name' => 'T9 Coffee — قهوة الاختبار'])->save();
    $ctx['branch']->forceFill(['name' => 'Muttrah', 'name_ar' => 'مطرح'])->save();
    config()->set('qr.web_base_url', 'https://qr.example.invalid/');
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')
        ->create(['name' => 'Main Hall', 'name_ar' => 'القاعة الرئيسية']);
    $tables = Table::factory()->count(5)->for($ctx['company'], 'company')->for($floor, 'floor')
        ->sequence(fn ($sequence) => ['label' => 'Table '.($sequence->index + 1), 'display_order' => $sequence->index])->create();
    $response = $this->get("/print/table-cards/{$ctx['branch']->uuid}")->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $html = $response->getContent();
    expect(substr_count($html, 'class="table-card"'))->toBe(5)
        ->and(substr_count($html, '<svg'))->toBe(5)
        ->and(substr_count($html, 'class="sheet"'))->toBe(2)
        ->and($html)->toContain('size: A4', 'width: 50mm', 'height: 50mm', 'page-break-inside: avoid', 'window.print()', 'قهوة الاختبار', 'Main Hall', 'Scan to order');
    foreach ($tables as $table) {
        $url = 'https://qr.example.invalid/t/'.$table->qr_token;
        expect($html)->toContain($url, app(TableCardQr::class)->svg($url));
    }
    expect(strpos($html, '>Table 1<'))->toBeLessThan(strpos($html, '>Table 5<'));
    // Generated handback evidence exists only in the throwaway test export.
    file_put_contents(base_path('../t9-table-cards.html'), $html);
    fwrite(STDOUT, "\nT9_MERCHANT_PRINT_CARDS=5 pages=2 SVG=5 QR=50mm correction=M quiet_zone=2\n");
});

it('excludes inactive floors and tables and supports a scoped floor filter', function (): void {
    $ctx = makeMerchantActor();
    config()->set('qr.web_base_url', 'https://qr.example.invalid');
    $floors = Floor::factory()->count(2)->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $active = Table::factory()->for($ctx['company'], 'company')->for($floors[0], 'floor')->create(['label' => 'Visible']);
    $inactive = Table::factory()->for($ctx['company'], 'company')->for($floors[0], 'floor')->create(['status' => 'inactive']);
    $other = Table::factory()->for($ctx['company'], 'company')->for($floors[1], 'floor')->create();
    $this->get("/print/table-cards/{$ctx['branch']->uuid}?floor={$floors[0]->uuid}")
        ->assertOk()->assertSee($active->qr_token)->assertDontSee($inactive->qr_token)->assertDontSee($other->qr_token);
    $floors[1]->update(['status' => 'inactive']);
    $this->get("/print/table-cards/{$ctx['branch']->uuid}")
        ->assertOk()->assertSee($active->qr_token)->assertDontSee($inactive->qr_token)->assertDontSee($other->qr_token);
});

it('prints no card and no SVG when the customer app base URL is empty', function (): void {
    $ctx = makeMerchantActor();
    config()->set('qr.web_base_url', '');
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $table = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create();
    $this->get("/print/table-cards/{$ctx['branch']->uuid}")->assertOk()
        ->assertSee('QR_WEB_BASE_URL')->assertDontSee('<svg', false)->assertDontSee('class="table-card"', false)->assertDontSee($table->qr_token);
    expect(fn () => app(TableCardQr::class)->url($table->qr_token))->toThrow(LogicException::class);
});

it('refuses foreign tenant branches and floors and out-of-scope branches when printing', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    config()->set('qr.web_base_url', 'https://qr.example.invalid');
    $sibling = Branch::factory()->for($ctx['company'], 'company')->create();
    $foreign = Branch::factory()->for(Company::factory()->create(), 'company')->create();
    $foreignFloor = Floor::factory()->for($foreign->company, 'company')->for($foreign, 'branch')->create();
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();
    $this->get("/print/table-cards/{$foreign->uuid}")->assertNotFound();
    $this->get("/print/table-cards/{$sibling->uuid}")->assertForbidden();
    $this->get("/print/table-cards/{$ctx['branch']->uuid}?floor={$foreignFloor->uuid}")->assertNotFound();
});

it('requires floor-plan permission to print', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $ctx['user']->syncRoles([]);
    $this->get("/print/table-cards/{$ctx['branch']->uuid}")->assertForbidden();
});

it('regeneration changes only the existing token action and the next printed URL', function (): void {
    $ctx = makeMerchantActor();
    config()->set('qr.web_base_url', 'https://qr.example.invalid');
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $table = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create();
    $old = $table->qr_token;
    $this->get("/print/table-cards/{$ctx['branch']->uuid}")->assertOk()->assertSee('/t/'.$old);
    $this->postJson("/api/tables/{$table->uuid}/regenerate-qr")->assertOk();
    $table->refresh();
    expect($table->qr_token)->not->toBe($old);
    $this->get("/print/table-cards/{$ctx['branch']->uuid}")->assertOk()->assertSee('/t/'.$table->qr_token)->assertDontSee($old);
});
