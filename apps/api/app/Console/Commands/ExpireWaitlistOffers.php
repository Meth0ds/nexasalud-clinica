<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WaitlistStatus;
use App\Models\WaitlistEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Expires waitlist offers whose acceptance window has passed and releases their reserved slots.
 */
final class ExpireWaitlistOffers extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'waitlist:expire-offers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire stale waitlist offers and release their provisional appointments';

    /**
     * Execute the scheduled expiry of stale waitlist offers.
     */
    public function handle(): int
    {
        $expired = 0;
        $failed = 0;

        WaitlistEntry::query()
            ->where('status', WaitlistStatus::Offered)
            ->where('offer_expires_at', '<=', now())
            ->orderBy('offer_expires_at')
            ->each(function (WaitlistEntry $entry) use (&$expired, &$failed): void {
                try {
                    DB::transaction(function () use ($entry, &$expired): void {
                        $locked = WaitlistEntry::query()->lockForUpdate()->find($entry->getKey());

                        if (! $locked instanceof WaitlistEntry || $locked->status !== WaitlistStatus::Offered) {
                            return;
                        }

                        $locked->status = WaitlistStatus::Expired;
                        $locked->lock_version++;
                        $locked->save();
                        $locked->releaseOwnedReservation('Oferta de lista de espera caducada');
                        $locked->fulfilled_appointment_id = null;
                        $locked->offer_created_appointment = false;
                        $locked->lock_version++;
                        $locked->save();
                        $expired++;
                    });
                } catch (\Throwable) {
                    $failed++;
                }
            });

        $this->info("Expired {$expired} stale waitlist offer(s); {$failed} could not be processed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
