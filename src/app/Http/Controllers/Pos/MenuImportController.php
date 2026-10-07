<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Catalogue\MenuImport\CommitMenuImportAction;
use App\Actions\Pos\Catalogue\MenuImport\MenuImportRowRefusedException;
use App\Actions\Pos\Catalogue\MenuImport\PlanMenuImportAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Catalogue\MenuImportRequest;
use App\Support\Catalogue\MenuSheet;
use App\Support\MerchantTenantContext;
use App\Support\Spreadsheet\SpreadsheetRows;
use App\Support\Spreadsheet\XlsxReaderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * LAUNCH-P4 B6 — menu import and export (owner decision 5: an Excel template,
 * then a preview, then save).
 *
 *   GET  /api/products/import/template  → menu-template.xlsx (the columns, two
 *        example rows and an Instructions sheet in English and Arabic)
 *   POST /api/products/import/preview   → what each row would do (no writes)
 *   POST /api/products/import/commit    → save it all in one transaction, or
 *        422 with the preview while a row has an error
 *   GET  /api/products/export?format=xlsx|csv → the menu in the same columns
 *
 * Files: .xlsx (read by the built-in reader — no ext-zip needed) or CSV
 * UTF-8 (the byte-order mark is stripped). Template/export: catalogue.view;
 * preview/commit: catalogue.manage.
 */
class MenuImportController extends Controller
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SpreadsheetRows $reader,
        private readonly PlanMenuImportAction $plan,
        private readonly CommitMenuImportAction $commit,
    ) {}

    public function template(Request $request): Response
    {
        $this->ensure($request, MerchantPermission::CatalogueView);

        return $this->download(
            MenuSheet::toXlsx(MenuSheet::templateRows(), 'Menu', MenuSheet::instructions()),
            'menu-template.xlsx',
            self::XLSX,
        );
    }

    public function export(Request $request): Response
    {
        $this->ensure($request, MerchantPermission::CatalogueView);
        $rows = MenuSheet::exportRows($this->tenant->requiredId());
        $stamp = now()->format('Y-m-d');

        if ($request->query('format') === 'csv') {
            return $this->download(MenuSheet::toCsv($rows), "menu-{$stamp}.csv", 'text/csv; charset=UTF-8');
        }

        return $this->download(MenuSheet::toXlsx($rows, 'Menu'), "menu-{$stamp}.xlsx", self::XLSX);
    }

    public function preview(MenuImportRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        try {
            $plan = $this->plan->handle($this->sheet($request), $request->boolean('create_categories'));
        } catch (XlsxReaderException $e) {
            return $this->unreadable($e);
        }

        return response()->json(['data' => self::publicPlan($plan)]);
    }

    public function commit(MenuImportRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        try {
            $result = $this->commit->handle(
                $this->sheet($request),
                $request->boolean('create_categories'),
                $request->user(),
                (string) $request->file('file')->getClientOriginalName(),
            );
        } catch (XlsxReaderException $e) {
            return $this->unreadable($e);
        } catch (MenuImportRowRefusedException $e) {
            // Combo fix order 2 (C-15) — never a 500: the row and why.
            return response()->json([
                'message' => $e->getMessage().' Nothing was saved.',
                'reason' => 'row_refused',
                'row' => $e->row,
                'errors' => ['file' => [$e->getMessage()]],
            ], 422);
        }

        $body = [
            'saved' => $result['saved'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'unchanged' => $result['unchanged'],
            'categories_created' => $result['categories_created'],
            'preview' => self::publicPlan($result['plan']),
        ];
        if (! $result['saved']) {
            return response()->json([
                'message' => 'Fix the rows marked as errors, then try again. Nothing was saved.',
                'reason' => 'rows_have_errors',
                'data' => $body,
            ], 422);
        }

        return response()->json(['data' => $body]);
    }

    /**
     * @return array<int, list<string>>
     */
    private function sheet(MenuImportRequest $request): array
    {
        return $this->reader->read((string) $request->file('file')->get());
    }

    /**
     * The plan without the fields only the commit needs.
     *
     * @param  array{rows: list<array<string, mixed>>, summary: array<string, int>, new_categories: list<array{name: string, name_ar: ?string}>}  $plan
     * @return array<string, mixed>
     */
    private static function publicPlan(array $plan): array
    {
        return [
            'summary' => $plan['summary'],
            'new_categories' => $plan['new_categories'],
            'rows' => array_map(static fn (array $row): array => array_diff_key($row, array_flip(['attributes', 'category_ref', 'product_id'])), $plan['rows']),
        ];
    }

    private function unreadable(XlsxReaderException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'reason' => $e->reason,
            'errors' => ['file' => [$e->getMessage()]],
        ], 422);
    }

    private function download(string $bytes, string $name, string $type): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }
}
