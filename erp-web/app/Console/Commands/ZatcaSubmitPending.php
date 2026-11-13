<?php

namespace App\Console\Commands;

use App\Services\Zatca\ZatcaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sends posted documents still pending or in error to ZATCA, oldest invoice counter first.
 * Respects System Settings (enabled, auto-resubmit) and offline mode. Scheduled every 10 minutes.
 */
#[Signature('zatca:submit-pending {--minutes=5 : Only documents posted at least this many minutes ago} {--limit=100 : Maximum documents per run}')]
#[Description('Submit pending / failed ZATCA e-invoices')]
class ZatcaSubmitPending extends Command
{
    public function handle(ZatcaService $zatca): int
    {
        $result = $zatca->submitPending((int) $this->option('minutes'), (int) $this->option('limit'));

        $this->info(sprintf('Sent %d: %d accepted, %d failed.', $result['sent'], $result['accepted'], $result['failed']));

        return self::SUCCESS;
    }
}
