<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 fix order 1, K5 — a recipe save reads the recipe it replaces
 * inside its transaction, under a row lock on the product.
 *
 * Read outside the lock, a second save (A) landing between save B's read and
 * B's write made B snapshot the state BEFORE A: the version row B wrote no
 * longer held the recipe B replaced, and A's recipe vanished from the
 * history. The test interleaves A right after B reads the current lines; a
 * save started while B holds the product lock waits for B's commit (the lock
 * itself is a no-op on SQLite, so the wait is modelled by the transaction B
 * is in). Every version row must hold exactly the lines it replaced.
 */

use App\Actions\Pos\Catalogue\UpdateProductRecipeAction;
use App\Models\Product;
use App\Models\ProductRecipeVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('writes a version holding the recipe the save really replaced when a second save interleaves', function (): void {
    $ctx = makeMerchantActor();
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0005');
    $product = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte', 'stock_mode' => 'ingredient']);
    $action = app(UpdateProductRecipeAction::class);
    $save = fn (string $ml, string $note) => $action->handle($product->fresh(), [['ingredient_uuid' => $milk->uuid, 'quantity' => $ml]], $ctx['user'], $note);

    $save('100', 'R0');

    $baseLevel = DB::transactionLevel();
    $interleaved = false;
    $waiting = null;
    $checking = false;
    /** @var list<array{version: list<string>, on_disk: list<string>}> $writes */
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$interleaved, &$waiting, &$checking, &$writes, $save, $baseLevel, $product): void {
        if ($checking) {
            return;
        }
        // Save B has just read the lines it is about to replace: save A comes in.
        if (! $interleaved && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "pos_product_recipes"')) {
            $interleaved = true;
            $saveA = fn () => $save('120', 'A');
            if (DB::transactionLevel() > $baseLevel) {
                $waiting = $saveA; // B holds the product lock: A waits for B's commit.
            } else {
                $saveA(); // nothing held: A commits in between.
            }

            return;
        }
        // Every version row must hold the lines on disk when it is written (the ones it replaces).
        if (str_starts_with($query->sql, 'insert into "pos_product_recipe_versions"')) {
            $checking = true;
            $version = ProductRecipeVersion::query()->latest('id')->firstOrFail();
            $writes[] = [
                'version' => array_map(static fn (array $l): string => (string) (float) $l['quantity'], $version->recipe_json),
                'on_disk' => DB::table('pos_product_recipes')->where('product_id', $product->id)->pluck('quantity')->map(static fn ($q): string => (string) (float) $q)->all(),
            ];
            $checking = false;
        }
    });

    $save('150', 'B');
    if ($waiting !== null) {
        $waiting();
    }

    expect($interleaved)->toBeTrue()
        ->and($writes)->toHaveCount(2);
    foreach ($writes as $write) {
        expect($write['version'])->toBe($write['on_disk']);
    }
    // Three edits, three versions, and no state is lost from the history.
    expect(ProductRecipeVersion::query()->where('product_id', $product->id)->count())->toBe(3);
});
