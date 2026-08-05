<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Models\Tenant;
use App\Services\Scheduling\WaitlistMatchEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Matches waiting waitlist entries to free appointment slots across all active clinics.
 *
 * The command configures one tenant's database context at a time, exactly like
 * background jobs, so PostgreSQL row-level security and Eloquent tenant scopes
 * remain fail-closed while the engine runs.
 */
final class MatchWaitlistSlots extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'waitlist:match-slots
        {--tenant= : Restrict matching to one tenant id}
        {--clinic= : Restrict matching to one clinic id}
        {--limit= : Override the maximum offers for this run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Match waiting waitlist entries to free appointment slots and create offers';

    /**
     * Execute the automated slot matching across active tenants and clinics.
     */
    public function handle(WaitlistMatchEngine $engine): int
    {
        if (! (bool) config('waitlist.matching.enabled')) {
            $this->info('Waitlist matching is disabled by configuration.');

            return self::SUCCESS;
        }

        $budget = (int) ($this->option('limit') ?? config('waitlist.matching.max_offers_per_run'));
        $totalScanned = 0;
        $totalOffered = 0;
        $totalFailed = 0;

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($query, string $id) => $query->whereKey($id))
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            $this->setTenantContext($tenant);

            try {
                $clinics = Clinic::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->getKey())
                    ->when($this->option('clinic'), fn ($query, string $id) => $query->whereKey($id))
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->orderBy('id')
                    ->get();

                foreach ($clinics as $clinic) {
                    app()->instance('currentClinic', $clinic);
                    $result = $engine->match($tenant, $clinic, $budget - $totalOffered);
                    $totalScanned += $result['scanned'];
                    $totalOffered += $result['offered'];
                    $totalFailed += $result['failed'];

                    if ($totalOffered >= $budget) {
                        break 2;
                    }
                }
            } finally {
                $this->clearTenantContext();
            }
        }

        Log::info('waitlist.matching.run_completed', [
            'event' => 'waitlist.matching.run_completed',
            'scanned' => $totalScanned,
            'offered' => $totalOffered,
            'failed' => $totalFailed,
            'budget' => $budget,
        ]);

        $this->info(sprintf(
            'Matched %d offer(s) from %d waiting entr%s (%d failed).',
            $totalOffered,
            $totalScanned,
            $totalScanned === 1 ? 'y' : 'ies',
            $totalFailed,
        ));

        return self::SUCCESS;
    }

    /**
     * Activate the tenant database session setting and container context.
     */
    private function setTenantContext(Tenant $tenant): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenant->getKey()]);
        }

        app()->instance('currentTenant', $tenant);
    }

    /**
     * Clear the tenant session setting and container context before the next tenant.
     */
    private function clearTenantContext(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.tenant_id', '', false)");
        }

        app()->forgetInstance('currentClinic');
        app()->forgetInstance('currentTenant');
    }
}
