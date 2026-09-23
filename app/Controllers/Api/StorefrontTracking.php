<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\Tracking\ShipmentLookup;
use App\Libraries\Tracking\TrackingService;

/**
 * Order tracking for headless storefronts (Hydrogen, Expo, the mock cart).
 *
 * POST /api/storefront/track
 *   X-Storefront-Key: pk_…
 *   {"reference": "#1001", "email": "shopper@example.com"}
 *
 * The JSON twin of the hosted page at /track, for a storefront that wants to
 * render the timeline in its own design instead of linking away. Both go
 * through {@see ShipmentLookup}, so the reference-plus-email rule and the
 * refusal to distinguish "wrong email" from "no such order" are identical.
 *
 * Scoped to the store the key belongs to: a publishable key is handed out per
 * storefront, and one storefront must not be able to read another's orders.
 *
 * Strictly read-only — it never fulfills and never writes.
 */
class StorefrontTracking extends BaseController
{
    use StorefrontAccess;

    public function lookup()
    {
        // Lower limits than the rate endpoint: nobody legitimately tracks a
        // parcel ten times a minute, and each hit can reach a courier. See
        // StorefrontAccess::STOREFRONT_LIMITS.
        $store = $this->admitStorefront('track');
        if (! is_array($store)) {
            return $store;
        }

        $payload = $this->jsonBody();
        if ($payload === null) {
            return $this->fail(400, 'request body must be a JSON object');
        }

        $reference = trim((string) ($payload['reference'] ?? ''));
        $email     = trim((string) ($payload['email'] ?? ''));

        if ($reference === '' || $email === '') {
            return $this->fail(400, 'reference and email are both required');
        }

        $match = (new ShipmentLookup())->find($reference, $email, $store);

        // 404 for a wrong email exactly as for an unknown reference: any
        // difference between the two answers is itself the leak.
        if ($match === null) {
            return $this->fail(404, 'no shipment found for that reference and email');
        }

        $tracking = (new TrackingService())->track(
            $match['awb'],
            ['destination' => $match['summary']['destination']],
        );

        return $this->response->setJSON([
            'order' => [
                'name'        => $match['summary']['orderName'],
                'recipient'   => $match['summary']['recipient'],
                'destination' => $match['summary']['destination'],
                'bookedAt'    => $match['summary']['bookedAt'],
            ],
            'shipment' => [
                'courier'     => $tracking['courier'],
                'courierName' => $tracking['courierName'],
                'waybill'     => $tracking['waybill'],
                'trackingUrl' => $tracking['trackingUrl'],
                'stage'       => $tracking['stage'],
                'stageLabel'  => $tracking['stageLabel'],
                // 'mock' tells a storefront the scans are simulated, so it can
                // say so rather than showing a demo parcel as a real one.
                'source'      => $tracking['source'],
                'note'        => $tracking['note'],
                'checkedAt'   => $tracking['checkedAt'],
                'events'      => $tracking['events'],
            ],
            // The stage vocabulary, so a storefront can render its own progress
            // bar without hardcoding a list that this app might extend.
            'stages' => array_map(
                static fn (string $stage) => ['key' => $stage, 'label' => TrackingService::STAGE_LABELS[$stage]],
                TrackingService::STAGE_FLOW,
            ),
        ]);
    }

    private function fail(int $status, string $message)
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => $message]);
    }
}
