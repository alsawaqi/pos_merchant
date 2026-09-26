<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Pos\Customers\MergeCustomersAction;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class MergeDuplicateCustomers extends Command
{
    protected $signature = 'customers:merge-duplicates {--company=} {--actor=} {--apply} {--only=}';

    protected $description = 'Inspect canonical-phone duplicates; merge only explicitly selected pairs.';

    public function handle(MerchantTenantContext $tenant, MergeCustomersAction $merge): int
    {
        $connection = DB::connection();
        $this->line('Database host: '.(string) $connection->getConfig('host'));
        $this->line('Database name: '.$connection->getDatabaseName());
        $company = filter_var($this->option('company'), FILTER_VALIDATE_INT);
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
        if ($company === false || $company <= 0 || $actorId === false || $actorId <= 0) {
            $this->error('--company and --actor are required positive ids.');

            return self::FAILURE;
        }
        try {
            $tenant->set($company);
            setPermissionsTeamId($company);
            $actor = User::query()->where('company_id', $company)->where('user_type', 'merchant')->find($actorId);
            if ($actor === null || ! $actor->can('customers.manage')) {
                $this->error('Actor must be a merchant user of this company with customers.manage.');

                return self::FAILURE;
            }
            if (! $this->option('apply')) {
                $phones = Customer::query()->where('company_id', $company)->whereNotNull('phone_canonical')
                    ->groupBy('phone_canonical')->havingRaw('COUNT(*) > 1')->orderBy('phone_canonical')->pluck('phone_canonical');
                foreach ($phones as $phone) {
                    $members = Customer::query()->where('company_id', $company)->where('phone_canonical', $phone)->orderBy('id')->get();
                    $this->line('Group '.$phone);
                    foreach ($members as $customer) {
                        $this->line(json_encode([
                            'id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone,
                            'orders' => Order::query()->where('company_id', $company)->where('customer_id', $customer->id)->count(),
                            'balances' => MergeCustomersAction::balances($customer),
                            'plates' => $customer->vehiclePlates()->pluck('plate_number')->all(),
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    }
                    $this->line(MergeCustomersAction::blockingReason($company, $members->modelKeys()) ?? 'Unblocked');
                }
                $this->line('Dry run complete: '.$phones->count().' duplicate groups; no changes.');

                return self::SUCCESS;
            }
            $only = (string) $this->option('only');
            if (! preg_match('/^[1-9][0-9]*:[1-9][0-9]*(,[1-9][0-9]*:[1-9][0-9]*)*$/D', $only)) {
                $this->error('--apply requires --only=<survivor>:<source>[,...].');

                return self::FAILURE;
            }
            $failed = false;
            foreach (explode(',', $only) as $pair) {
                [$survivorId, $sourceId] = array_map(intval(...), explode(':', $pair));
                try {
                    $summary = DB::transaction(function () use ($company, $survivorId, $sourceId, $merge, $actor): array {
                        $rows = Customer::withTrashed()->where('company_id', $company)
                            ->whereIn('id', [$survivorId, $sourceId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                        $survivor = $rows->get($survivorId);
                        $source = $rows->get($sourceId);
                        if ($survivor === null || $source === null || $survivorId === $sourceId) {
                            throw new \RuntimeException('Two distinct customers of this company are required.');
                        }
                        if ($source->merged_into_customer_id !== null) {
                            return ['already_merged' => 1];
                        }
                        if ($survivor->phone_canonical === null || $survivor->phone_canonical !== $source->phone_canonical) {
                            throw new \RuntimeException('Pair must share a non-null canonical phone.');
                        }

                        return $merge->handle($survivor, $source, $actor, refuseUnfinishedOrders: true)['summary'];
                    });
                    $this->line($pair.' '.json_encode($summary, JSON_THROW_ON_ERROR));
                } catch (Throwable $e) {
                    $failed = true;
                    $this->error($pair.' refused: '.$e->getMessage());
                }
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } finally {
            $tenant->set(null);
            setPermissionsTeamId(null);
        }
    }
}
