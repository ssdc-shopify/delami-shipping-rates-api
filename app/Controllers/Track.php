<?php

namespace App\Controllers;

use App\Libraries\Tracking\ShipmentLookup;
use App\Libraries\Tracking\TrackingService;

/**
 * The customer-facing "where is my parcel" page.
 *
 * A shopper enters the order number (or the tracking number from their
 * shipping confirmation) together with the email address on the order, and
 * sees the courier's own scans on one timeline.
 *
 * Why the email is required. The waybill and the order number are both
 * sequential and short, so a lookup on either alone is a scraper's shopping
 * list: names, destinations and delivery times for every order this warehouse
 * ships. Pairing the reference with the email is the same bargain Shopify's
 * own order-status page strikes — and a wrong pair returns exactly the same
 * "we could not find it" as a reference that does not exist, so the page
 * cannot be used to confirm that an order number is real either.
 *
 * The rules themselves live in {@see ShipmentLookup}, shared with the
 * storefront JSON endpoint so the two cannot drift apart.
 *
 * Read-only from end to end: it reads local rows, asks couriers what they
 * know, and renders. It never fulfills, and never writes.
 */
class Track extends BaseController
{
    /**
     * Lookups per minute per IP.
     *
     * Each miss is cheap (one indexed local read), but each hit can reach a
     * courier, so this is set for the courier's benefit rather than ours.
     */
    private const RATE_LIMIT = 15;

    /** GET /track — the empty form. */
    public function index()
    {
        return $this->page();
    }

    /** POST /track — look up one shipment. */
    public function lookup()
    {
        $reference = trim((string) $this->request->getPost('reference'));
        $email     = trim((string) $this->request->getPost('email'));

        if ($reference === '' || $email === '') {
            return $this->page($reference, $email, [
                'error' => 'Enter both your order or tracking number and the email address you ordered with.',
            ]);
        }

        $throttler = service('throttler');
        $bucket    = 'track-' . md5($this->request->getIPAddress());

        if ($throttler->check($bucket, self::RATE_LIMIT, MINUTE) === false) {
            return $this->response
                ->setStatusCode(429)
                ->setHeader('Retry-After', (string) $throttler->getTokenTime())
                ->setBody($this->page($reference, $email, [
                    'error' => 'Too many lookups from this connection. Please wait a minute and try again.',
                ]));
        }

        $match = (new ShipmentLookup())->find($reference, $email);

        // One message for "no such shipment" and for "wrong email" alike.
        // Splitting them would turn this form into an order-number oracle.
        if ($match === null) {
            return $this->page($reference, $email, [
                'error' => 'We could not find a shipment for that number and email address. '
                    . 'Check both against your order confirmation — and note that a parcel only '
                    . 'appears here once it has been handed to the courier.',
            ]);
        }

        return $this->page($reference, $email, [
            'summary'  => $match['summary'],
            'tracking' => (new TrackingService())->track(
                $match['awb'],
                ['destination' => $match['summary']['destination']],
            ),
        ]);
    }

    /**
     * Render the page with a complete payload — every key, every time.
     *
     * Config\View sets saveData, so a renderer reused within one process
     * carries the previous render's variables forward. A view that leaned on
     * "this variable is simply not set" would then show the last shopper's
     * shipment to the next one. Passing all of it explicitly is what makes
     * that impossible rather than merely unlikely.
     */
    private function page(string $reference = '', string $email = '', array $extra = []): string
    {
        return view('track/index', array_merge([
            'reference' => $reference,
            'email'     => $email,
            'error'     => null,
            'summary'   => [],
            'tracking'  => null,
        ], $extra));
    }
}
