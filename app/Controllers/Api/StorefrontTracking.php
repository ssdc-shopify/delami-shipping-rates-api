<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\Tracking\ShipmentLookup;
use App\Libraries\Tracking\TrackingPayload;

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
            return $this->response->setStatusCode(404)->setJSON(TrackingPayload::NOT_FOUND);
        }

        // Built by TrackingPayload, which the admin Track Simulator also uses,
        // so the simulator shows exactly this response.
        return $this->response->setJSON(TrackingPayload::build($match));
    }

    private function fail(int $status, string $message)
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => $message]);
    }
}
