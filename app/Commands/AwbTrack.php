<?php

namespace App\Commands;

use App\Libraries\Awb\AwbService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Cron: php spark awb:track
 *
 * Reports courier progress for shipments that already have a waybill.
 * Read-only — fulfillment is always manual, via the Generate AWB button.
 *
 * INACTIVE. The command is kept whole, and AwbService::track() with it, but
 * it refuses to run: with mock mode still on (Admin → Settings) there are no
 * real waybills to poll, so every run would query couriers about invented MOCK-
 * numbers. Re-enable by flipping ACTIVE below once real shipments are being
 * booked — nothing else needs changing.
 */
class AwbTrack extends BaseCommand
{
    /** Flip to true when real waybills exist and the cron should run. */
    private const ACTIVE = false;

    protected $group       = 'Shipping';
    protected $name        = 'awb:track';
    protected $description = '[INACTIVE] Report courier tracking progress for pending AWBs (never fulfills)';
    protected $options     = ['--days' => 'Tracking window in days (default 3)'];

    public function run(array $params)
    {
        if (! self::ACTIVE) {
            CLI::write('awb:track is inactive.', 'yellow');
            CLI::write('  Nothing is polled while mock mode is on — the only waybills');
            CLI::write('  are locally invented MOCK- numbers that no courier can report on.');
            CLI::write('  Re-enable by setting ACTIVE = true in ' . static::class . '.');

            // Success, not failure: a cron entry that still points here should
            // not page anyone simply because the command is switched off.
            return EXIT_SUCCESS;
        }

        $days = (int) (CLI::getOption('days') ?? 3);

        try {
            $moving = (new AwbService())->track(max(1, $days));
            CLI::write("Tracking complete: {$moving} shipment(s) moving at the courier.", 'green');
        } catch (\Throwable $e) {
            CLI::error('Tracking failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
