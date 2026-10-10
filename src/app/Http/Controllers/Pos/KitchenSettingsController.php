<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Kitchen\Configuration;
use App\Kitchen\KitchenFault;
use App\Kitchen\Routing;
use App\Kitchen\Wire;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Session-only merchant boundary. The shared module never receives browser-supplied authority. */
final class KitchenSettingsController extends Controller
{
    public function __construct(private readonly MerchantTenantContext $tenant, private readonly Configuration $configuration) {}

    private function actor(Request $request, bool $write = false): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isMerchantUser() && $user->can(MerchantPermission::BranchesView->value) && $user->can(($write ? MerchantPermission::BranchesUpdate : MerchantPermission::BranchesView)->value), 403);
        KitchenFault::require((bool) config('kitchen.settings_enabled'), 'kitchen_settings_disabled', 503);

        return $user;
    }

    private function branches(User $user)
    {
        $allowed = $user->allowedBranchIds();

        return DB::table('pos_branches')->where('company_id', $this->tenant->requiredId())->whereNull('deleted_at')
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('id')->get(['id', 'uuid', 'name', 'name_ar', 'status']);
    }

    private function branch(User $user, string $uuid): object
    {
        $branch = DB::table('pos_branches')->where('company_id', $this->tenant->requiredId())->where('uuid', $uuid)->whereNull('deleted_at')->first();
        abort_unless($branch, 404);
        abort_unless($user->canAccessBranchId((int) $branch->id), 403);

        return $branch;
    }

    private function state(object $branch): object
    {
        return DB::table('pos_kv2_branches')->where('company_id', $branch->company_id ?? $this->tenant->requiredId())->where('branch_id', $branch->id)->first()
            ?? (object) ['desired_version' => 0, 'applied_version' => 0, 'mode' => 'legacy', 'activation_state' => 'idle', 'coordinator_id' => null, 'epoch' => 0];
    }

    private function bundle(object $branch, int $version): array
    {
        $row = DB::table('pos_kv2_configurations')->where('company_id', $this->tenant->requiredId())->where('branch_id', $branch->id)->where('version', $version)->first();

        return $row ? Wire::read($row->bundle) : ['areas' => [], 'destinations' => [], 'rules' => [], 'all_items' => [], 'fallback' => ['areas' => [], 'destinations' => []], 'policies' => $this->policies((int) $branch->id)];
    }

    private function policies(int $branch): array
    {
        $out = [];
        foreach (['staff', 'qr_web', 'customer_tablet'] as $source) {
            $out[$source] = DB::table('pos_kv2_policies')->where('company_id', $this->tenant->requiredId())->where('source', $source)->whereIn('branch_scope', [0, $branch])->orderByDesc('branch_scope')->value('release_mode') ?? 'manual';
        }

        return $out;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        return response()->json(['data' => ['can_manage' => $user->can(MerchantPermission::BranchesUpdate->value), 'can_apply_all' => $user->allowedBranchIds() === null && $user->can(MerchantPermission::BranchesUpdate->value), 'defaults' => $this->policies(0), 'branches' => $this->branches($user)->map(fn ($b) => $this->summary($b))->all()]]);
    }

    private function summary(object $branch): array
    {
        $state = $this->state($branch);
        $overrides = DB::table('pos_kv2_policies')->where('company_id', $this->tenant->requiredId())->where('branch_scope', $branch->id)->pluck('release_mode', 'source')->all();

        return ['uuid' => $branch->uuid, 'name' => $branch->name, 'name_ar' => $branch->name_ar, 'status' => $branch->status, 'mode' => $state->mode,
            'desired_version' => (int) $state->desired_version, 'applied_version' => (int) $state->applied_version, 'activation_state' => $state->activation_state,
            'policies' => $this->policies((int) $branch->id), 'overrides' => $overrides,
            'applied_policies' => $state->applied_version > 0 ? $this->bundle($branch, (int) $state->applied_version)['policies'] : null,
            'pending' => (int) $state->desired_version !== (int) $state->applied_version || $state->activation_state !== 'idle' || $state->mode === 'pending'];
    }

    public function show(Request $request, string $branch): JsonResponse
    {
        $user = $this->actor($request);
        $b = $this->branch($user, $branch);
        $state = $this->state($b);
        $devices = DB::table('pos_devices')->where('company_id', $b->company_id)->where('branch_id', $b->id)->whereNull('deleted_at')->whereIn('device_type', ['fixed_pos', 'handheld'])->get(['id', 'uuid', 'name', 'device_type', 'status', 'assignment_activated_at']);
        $eligible = [];
        foreach ($devices as $device) {
            $enrolled = DB::table('pos_kv2_devices')->where('company_id', $b->company_id)->where('branch_id', $b->id)->where('device_id', $device->id)->where('enabled', true)->first();
            $ready = $device->status === 'active' && $enrolled && $device->assignment_activated_at && $enrolled->assignment === Carbon::parse($device->assignment_activated_at)->toIso8601String();
            $eligible[] = ['uuid' => $device->uuid, 'name' => $device->name, 'type' => $device->device_type, 'enrolled' => (bool) $ready, 'coordinator' => (int) $state->coordinator_id === (int) $device->id];
        }
        $counts = DB::table('pos_kv2_deliveries')->whereIn('submission_id', DB::table('pos_kv2_submissions')->select('id')->where('company_id', $b->company_id)->where('branch_id', $b->id))->selectRaw('state, COUNT(*) as total')->groupBy('state')->pluck('total', 'state')->map(fn ($n) => (int) $n)->all();
        $catalogue = $this->catalogue($b);
        $audit = DB::table('pos_kv2_audit')->where('company_id', $b->company_id)->where('branch_id', $b->id)->orderByDesc('id')->limit(20)->get(['action', 'actor', 'created_at']);

        return response()->json(['data' => [...$this->summary($b), 'bundle' => $this->bundle($b, (int) $state->desired_version), 'devices' => $eligible,
            'activation_available' => (bool) config('kitchen.activation_enabled'), 'delivery_counts' => $counts, 'catalogue' => $catalogue, 'audit' => $audit]]);
    }

    private function catalogue(object $branch): array
    {
        // Names/route keys only; no customer, recipe, cost or device credentials.
        $categories = DB::table('pos_product_categories')->where('company_id', $branch->company_id)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'name_ar', 'branch_availability_json'])->filter(function ($c) use ($branch) {
            $scope = $c->branch_availability_json === null ? null : json_decode($c->branch_availability_json, true);

            return $scope === null || $scope === [] || (is_array($scope) && in_array((int) $branch->id, array_map('intval', $scope), true));
        });
        $categoryIds = $categories->pluck('id')->all();
        $products = DB::table('pos_products')->where('company_id', $branch->company_id)->whereNull('deleted_at')->where('is_internal', false)
            ->where(fn ($q) => $q->whereNull('category_id')->orWhereIn('category_id', $categoryIds))
            ->where(function ($scope) use ($branch): void {
                $row = static fn ($query, bool $available) => $query->selectRaw('1')->from('pos_branch_product')
                    ->whereColumn('pos_branch_product.product_id', 'pos_products.id')
                    ->where('pos_branch_product.branch_id', $branch->id)->where('pos_branch_product.is_available', $available);
                $scope->where(fn ($all) => $all->where(fn ($kind) => $kind->whereNull('branch_scope')->orWhere('branch_scope', 'all'))
                    ->whereNotExists(fn ($query) => $row($query, false)))
                    ->orWhere(fn ($selected) => $selected->where('branch_scope', 'selected')->whereExists(fn ($query) => $row($query, true)));
            })
            ->orderBy('name')->get(['id', 'category_id', 'name', 'name_ar']);

        return ['products' => $products->all(), 'categories' => $categories->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'name_ar' => $c->name_ar])->values()->all()];
    }

    private function policyInput(Request $request, User $user): array
    {
        $v = $request->validate(['source' => 'required|in:staff,qr_web,customer_tablet', 'mode' => 'required|in:manual,immediate', 'branch_uuid' => 'present|nullable|uuid']);
        if ($v['branch_uuid'] === null) {
            abort_unless($user->allowedBranchIds() === null, 403);
        }
        $branch = $v['branch_uuid'] === null ? null : $this->branch($user, $v['branch_uuid']);

        return [$v, $branch];
    }

    private function preview(User $user, array $input, ?object $branch): array
    {
        $targets = $branch ? collect([$branch]) : $this->branches($user);
        $rows = $targets->map(function ($b) use ($input): array {
            $summary = $this->summary($b);

            return ['uuid' => $b->uuid, 'name' => $b->name, 'before' => $summary['policies'][$input['source']], 'override_removed' => $input['branch_uuid'] === null && isset($summary['overrides'][$input['source']]), 'desired_version' => $summary['desired_version']];
        })->all();
        $preview = ['source' => $input['source'], 'mode' => $input['mode'], 'all_branches' => $branch === null, 'future_branches' => $branch === null, 'branches' => $rows, 'default_before' => $this->policies(0)[$input['source']]];

        return [...$preview, 'preview_hash' => Wire::hash(['company' => $this->tenant->requiredId(), 'actor' => $user->id, ...$preview])];
    }

    public function previewPolicy(Request $request): JsonResponse
    {
        $user = $this->actor($request, true);
        [$v, $branch] = $this->policyInput($request, $user);

        return response()->json(['data' => $this->preview($user, $v, $branch)]);
    }

    public function savePolicy(Request $request): JsonResponse
    {
        $user = $this->actor($request, true);
        [$v, $branch] = $this->policyInput($request, $user);
        $request->validate(['preview_hash' => 'required|string|size:64']);
        DB::transaction(function () use ($user, $v, $branch, $request): void {
            DB::table('pos_companies')->where('id', $this->tenant->requiredId())->lockForUpdate()->first();
            $preview = $this->preview($user, $v, $branch);
            KitchenFault::require(hash_equals($preview['preview_hash'], $request->string('preview_hash')->toString()), 'preview_changed');
            $this->configuration->setPolicy($this->tenant->requiredId(), $this->branches($user)->pluck('id')->map(fn ($id) => (int) $id)->all(), $branch ? (int) $branch->id : null, $v['source'], $v['mode'], 'merchant:'.$user->id);
        }, 5);

        return $this->index($request);
    }

    private function routingInput(Request $request, object $branch): array
    {
        $request->validate(['bundle' => 'required|array:areas,destinations,rules,all_items,fallback,display', 'expected_version' => 'required|integer|min:0']);
        $bundle = Routing::validate($request->input('bundle'));
        $catalogue = $this->catalogue($branch);
        foreach ($bundle['rules'] as $rule) {
            $ids = array_map(fn ($row) => (int) (is_object($row) ? $row->id : $row['id']), $catalogue[$rule['kind'] === 'item' ? 'products' : 'categories']);
            KitchenFault::require(in_array((int) $rule['reference_id'], $ids, true), 'route_reference_not_available', 422);
        }

        return $bundle;
    }

    public function previewRouting(Request $request, string $branch): JsonResponse
    {
        $user = $this->actor($request, true);
        $b = $this->branch($user, $branch);
        $bundle = $this->routingInput($request, $b);
        $request->validate(['product_ids' => 'required|array|min:1|max:100', 'product_ids.*' => 'required|integer|distinct']);
        $products = collect($this->catalogue($b)['products'])->keyBy('id');
        $lines = [];
        foreach ($request->input('product_ids') as $id) {
            $p = $products->get($id);
            KitchenFault::require($p !== null, 'route_reference_not_available', 422);
            $lines[] = ['line_uuid' => (string) Str::uuid(), 'product_id' => (int) $p->id, 'category_id' => $p->category_id === null ? null : (int) $p->category_id, 'name' => $p->name, 'name_ar' => $p->name_ar, 'quantity' => '1.000000'];
        }
        $resolved = Routing::resolve($bundle, $lines);
        $resolved['copies'] = (object) $resolved['copies'];

        return response()->json(['data' => $resolved]);
    }

    private function mutate(Request $request, string $branch, callable $command): JsonResponse
    {
        $user = $this->actor($request, true);
        $b = $this->branch($user, $branch);
        $request->validate(['expected_version' => 'required|integer|min:0']);
        DB::transaction(function () use ($request, $b, $user, $command): void {
            DB::table('pos_companies')->where('id', $b->company_id)->lockForUpdate()->first();
            $state = $this->configuration->branch((int) $b->company_id, (int) $b->id);
            KitchenFault::require((int) $state->desired_version === (int) $request->input('expected_version'), 'configuration_changed');
            $command($b, $user, $state);
        }, 5);

        return $this->show($request, $branch);
    }

    public function saveRouting(Request $request, string $branch): JsonResponse
    {
        return $this->mutate($request, $branch, function ($b, $user) use ($request): void {
            $bundle = $this->routingInput($request, $b);
            $this->configuration->setRouting((int) $b->company_id, [(int) $b->id], (int) $b->id, $bundle, 'merchant:'.$user->id);
        });
    }

    public function activate(Request $request, string $branch): JsonResponse
    {
        return $this->mutate($request, $branch, function ($b, $user, $state) use ($request): void {
            KitchenFault::require((bool) config('kitchen.activation_enabled'), 'kitchen_activation_unavailable', 422);
            KitchenFault::require($b->status === 'active' && (int) $state->desired_version > 0, 'configuration_required', 422);
            $v = $request->validate(['device_uuid' => 'required|uuid', 'isolation_evidence' => 'required|string|min:12|max:1000', 'confirm_opt_in' => 'accepted']);
            $id = DB::table('pos_devices')->where('company_id', $b->company_id)->where('branch_id', $b->id)->where('uuid', $v['device_uuid'])->whereNull('deleted_at')->value('id');
            KitchenFault::require($id !== null, 'device_forbidden', 403);
            $this->configuration->assign((int) $b->company_id, [(int) $b->id], (int) $id, 'merchant:'.$user->id, $v['isolation_evidence']);
        });
    }
}
