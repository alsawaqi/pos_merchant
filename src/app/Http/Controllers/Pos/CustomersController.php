<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Customers\AttachVehiclePlateAction;
use App\Actions\Pos\Customers\CreateCustomerAction;
use App\Actions\Pos\Customers\DeleteCustomerAction;
use App\Actions\Pos\Customers\DetachVehiclePlateAction;
use App\Actions\Pos\Customers\MergeCustomersAction;
use App\Actions\Pos\Customers\UpdateCustomerAction;
use App\Actions\Pos\Reports\CustomerAnalyticsAction;
use App\Actions\Pos\Reports\CustomerOrdersAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Customers\AttachVehiclePlateRequest;
use App\Http\Requests\Pos\Customers\CreateCustomerRequest;
use App\Http\Requests\Pos\Customers\MergeCustomersRequest;
use App\Http\Requests\Pos\Customers\UpdateCustomerRequest;
use App\Http\Resources\Pos\Customers\CustomerResource;
use App\Http\Resources\Pos\Customers\CustomerVehiclePlateResource;
use App\Models\Customer;
use App\Models\CustomerVehiclePlate;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Phase 6a — customers CRUD + vehicle plate attach/detach.
 *
 *   GET    /api/customers                          → paginated list, ?search=... ?tag=...
 *   GET    /api/customers/tags                     → distinct tag list (filter dropdown)
 *   GET    /api/customers/{customer:uuid}          → show with plates
 *   POST   /api/customers                          → create (optional initial plates)
 *   PATCH  /api/customers/{customer:uuid}          → update name/phone
 *   DELETE /api/customers/{customer:uuid}          → soft delete
 *   POST   /api/customers/{customer:uuid}/plates   → attach a plate
 *   DELETE /api/customer-plates/{plate:uuid}       → detach a plate
 *
 * Permission gating:
 *   - CustomersView for index + show
 *   - CustomersManage for everything else
 *
 * All endpoints tenant-scoped via MerchantTenantContext.
 *
 * Index search: ?search=X does a case-insensitive LIKE across
 * name + phone + plates. The plate match joins through the
 * 1:N table and uses the canonical (uppercased) stored form
 * for comparison — the controller uppercases the search query
 * before matching plates.
 */
class CustomersController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateCustomerAction $create,
        private readonly UpdateCustomerAction $update,
        private readonly DeleteCustomerAction $delete,
        private readonly AttachVehiclePlateAction $attachPlate,
        private readonly DetachVehiclePlateAction $detachPlate,
        private readonly MergeCustomersAction $merge,
        private readonly CustomerAnalyticsAction $customerAnalytics,
        private readonly CustomerOrdersAction $customerOrders,
    ) {}

    public function index(Request $request): LengthAwarePaginator
    {
        $this->ensure($request, MerchantPermission::CustomersView);

        $companyId = $this->tenant->requiredId();
        $query = Customer::query()
            ->where('company_id', $companyId)
            ->withCount('vehiclePlates')
            ->with('vehiclePlates');

        if ($request->filled('search')) {
            $raw = trim((string) $request->query('search'));
            $lower = '%'.strtolower($raw).'%';
            $upper = strtoupper($raw);
            // Plate match: any plate row LIKE the uppercased
            // search query inside this customer's bag of plates.
            // Wrap the OR cluster so it composes with the
            // outer tenant scope without pulling in other
            // companies' customers via the join.
            $query->where(function ($q) use ($lower, $upper): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$lower])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$lower])
                    ->orWhereHas('vehiclePlates', function ($p) use ($upper): void {
                        $p->where('plate_number', 'LIKE', '%'.$upper.'%');
                    });
            });
        }

        // Phase D3 — ?tag= narrows to customers carrying that exact
        // tag. The filter dropdown is fed by tags() below, so the
        // value arrives in the stored canonical form (exact match,
        // including case). whereJsonContains compares DECODED JSON
        // values — portable across the sqlite test connection and
        // the live Postgres, and immune to PHP's \uXXXX escaping
        // of non-ASCII (Arabic) tags in the stored column.
        if ($request->filled('tag')) {
            $query->whereJsonContains('tags_json', trim((string) $request->query('tag')));
        }

        $perPage = min((int) $request->query('per_page', 50), 200);

        return $query
            ->orderBy('name')
            ->paginate($perPage)
            ->through(fn (Customer $c): array => (new CustomerResource($c))->resolve($request));
    }

    /**
     * Phase D3 — the company's distinct customer tags, for the list
     * page's filter dropdown. Flattened in PHP: per-tenant customer
     * books are small (company_id prefilters) and the column is a
     * flat JSON array, so no DB-specific json_each gymnastics.
     * Case-insensitive dedupe mirrors the write-side normalisation;
     * first-seen casing wins, sorted for a stable dropdown.
     *
     * @return array{data: array<int, string>}
     */
    public function tags(Request $request): array
    {
        $this->ensure($request, MerchantPermission::CustomersView);

        $distinct = [];
        Customer::query()
            ->where('company_id', $this->tenant->requiredId())
            ->whereNotNull('tags_json')
            // Deterministic "first-seen casing wins": without an
            // ORDER BY the DB is free to return rows in index
            // order, which varies run to run.
            ->orderBy('id')
            ->pluck('tags_json')
            ->each(function ($tags) use (&$distinct): void {
                foreach (($tags ?? []) as $tag) {
                    $key = mb_strtolower((string) $tag);
                    if (! array_key_exists($key, $distinct)) {
                        $distinct[$key] = (string) $tag;
                    }
                }
            });
        ksort($distinct);

        return ['data' => array_values($distinct)];
    }

    public function show(Request $request, Customer $customer): CustomerResource
    {
        $this->ensure($request, MerchantPermission::CustomersView);
        $this->refuseIfNotInTenant($customer);

        $customer->load('vehiclePlates')->loadCount('vehiclePlates');

        return CustomerResource::make($customer);
    }

    /**
     * Customer 360 analytics (v2 #8): lifetime rollups + favorite item +
     * monthly spend trend. Sales data → reports.view gated.
     */
    public function analytics(Request $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);
        $this->refuseIfNotInTenant($customer);

        return response()->json(['data' => $this->customerAnalytics->handle((int) $customer->id)]);
    }

    /**
     * Customer 360 order history (v2 #8): paginated, newest-first, all
     * statuses. Sales data → reports.view gated.
     */
    public function orders(Request $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);
        $this->refuseIfNotInTenant($customer);

        return response()->json(['data' => $this->customerOrders->handle((int) $customer->id, [
            'page' => (int) $request->query('page', '1'),
            'per_page' => (int) $request->query('per_page', '20'),
        ])]);
    }

    public function store(CreateCustomerRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CustomersManage);

        $payload = $request->validated();
        $plates = $payload['plates'] ?? [];

        try {
            // Wrap "create + attach initial plates" in a single
            // transaction so a duplicate plate (caught by the
            // attach action) rolls back the customer too.
            $customer = DB::transaction(function () use ($payload, $plates, $request): Customer {
                $c = $this->create->handle(
                    [
                        'name' => $payload['name'],
                        'phone' => $payload['phone'],
                        'date_of_birth' => $payload['date_of_birth'] ?? null,
                        'tags' => $payload['tags'] ?? null,
                    ],
                    $request->user(),
                );
                foreach ($plates as $plate) {
                    $this->attachPlate->handle($c, (string) $plate, $request->user());
                }

                return $c;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $customer->load('vehiclePlates')->loadCount('vehiclePlates');

        return response()->json([
            'data' => (new CustomerResource($customer))->resolve($request),
        ], 201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::CustomersManage);
        $this->refuseIfNotInTenant($customer);

        try {
            $updated = $this->update->handle($customer, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load('vehiclePlates')->loadCount('vehiclePlates');

        return CustomerResource::make($updated);
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CustomersManage);
        $this->refuseIfNotInTenant($customer);

        try {
            $this->delete->handle($customer, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => null], 204);
    }

    public function attachPlate(AttachVehiclePlateRequest $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CustomersManage);
        $this->refuseIfNotInTenant($customer);

        try {
            $plate = $this->attachPlate->handle(
                $customer,
                (string) $request->validated()['plate_number'],
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new CustomerVehiclePlateResource($plate))->resolve($request),
        ], 201);
    }

    public function detachPlate(Request $request, CustomerVehiclePlate $plate): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CustomersManage);
        if ((int) $plate->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }

        $this->detachPlate->handle($plate, $request->user());

        return response()->json(['data' => null], 204);
    }

    /**
     * POST /api/customers/{customer:uuid}/merge
     *
     * Merge the source customer (body: source_uuid) INTO this one (the
     * survivor): re-point orders / plates / loyalty / wallet, fold balances,
     * soft-delete the source. Returns the survivor + a summary of what moved.
     */
    public function merge(MergeCustomersRequest $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CustomersManage);
        $this->refuseIfNotInTenant($customer);

        $source = Customer::query()
            ->where('uuid', $request->validated()['source_uuid'])
            ->where('company_id', $this->tenant->requiredId())
            ->first();

        if ($source === null) {
            throw ValidationException::withMessages([
                'source_uuid' => 'The source customer was not found.',
            ]);
        }

        try {
            $result = $this->merge->handle($customer, $source, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $survivor = $result['customer'];
        $survivor->load(['vehiclePlates' => fn ($q) => $q->orderBy('id')]);
        $survivor->loadCount('vehiclePlates');

        return response()->json([
            'data' => (new CustomerResource($survivor))->resolve($request),
            'summary' => $result['summary'],
        ]);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(Customer $customer): void
    {
        if ((int) $customer->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
