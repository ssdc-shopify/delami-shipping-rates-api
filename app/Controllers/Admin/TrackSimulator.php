<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Tracking\ShipmentLookup;
use App\Libraries\Tracking\TrackingPayload;
use App\Libraries\Tracking\TrackingService;
use App\Models\StoreModel;

/**
 * Admin Track Simulator — the tracking twin of the Rate Simulator.
 *
 * Two ways in, both through the code the shopper-facing endpoints run:
 *
 *  - By waybill: ask a courier about any waybill and see its answer exactly
 *    as the tracking timeline normalizes it — the way to prove a courier's
 *    real tracing works before going live.
 *  - As a shopper: look an order up by reference + email within a store and
 *    see the exact /api/storefront/track response, status code included.
 *
 * Read-only. Posted rather than put in the URL, so a shopper's email never
 * lands in browser history or the server's access log.
 */
class TrackSimulator extends BaseController
{
    public function index()
    {
        return view('admin/track_simulator/index', $this->blank());
    }

    public function run()
    {
        $data = $this->blank();

        return view('admin/track_simulator/index', $data['mode'] === 'shopper'
            ? $this->asShopper($data)
            : $this->byWaybill($data));
    }

    /** Every key the view reads, with the posted form values (if any). */
    private function blank(): array
    {
        $post = fn (string $key, string $default = '') => trim((string) ($this->request->getPost($key) ?? $default));

        return [
            'stores'   => model(StoreModel::class)->where('active', 1)->orderBy('id')->findAll(),
            'mode'     => $post('mode') === 'shopper' ? 'shopper' : 'waybill',
            'input'    => [
                'courier'   => $post('courier', 'jne'),
                'waybill'   => $post('waybill'),
                'fresh'     => $this->request->getPost('fresh') === '1',
                'store'     => $post('store'),
                'reference' => $post('reference'),
                'email'     => $post('email'),
            ],
            'couriers' => TrackingService::COURIER_NAMES,
            'error'    => null,
            'tracking' => null, // TrackingService::track() result
            'status'   => null, // HTTP status the API would answer (shopper mode)
            'payload'  => null, // the JSON body — for the waybill tab, the shipment object
            'elapsed'  => null,
        ];
    }

    private function byWaybill(array $data): array
    {
        $courier = strtolower($data['input']['courier']);
        $waybill = $data['input']['waybill'];

        if (! array_key_exists($courier, TrackingService::COURIER_NAMES) || $waybill === '') {
            $data['error'] = 'Choose a courier and enter a waybill.';

            return $data;
        }

        if ($data['input']['fresh']) {
            service('cache')->delete('track_' . md5($courier . '|' . $waybill));
        }

        $started          = microtime(true);
        $data['tracking'] = (new TrackingService())->track([
            'courier'    => $courier,
            'waybill'    => $waybill,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $data['elapsed'] = (int) round((microtime(true) - $started) * 1000);
        $data['payload'] = TrackingPayload::shipment($data['tracking']);

        return $data;
    }

    private function asShopper(array $data): array
    {
        $store = null;
        foreach ($data['stores'] as $candidate) {
            if ($candidate['slug'] === $data['input']['store']) {
                $store = $candidate;
            }
        }

        if ($store === null || $data['input']['reference'] === '' || $data['input']['email'] === '') {
            $data['error'] = 'Choose a store and enter both the order or tracking number and the email.';

            return $data;
        }

        $started = microtime(true);
        $match   = (new ShipmentLookup())->find($data['input']['reference'], $data['input']['email'], $store);

        if ($match === null) {
            $data['status']  = 404;
            $data['payload'] = TrackingPayload::NOT_FOUND;
        } else {
            $data['status']  = 200;
            $data['payload'] = TrackingPayload::build($match);
        }
        $data['elapsed'] = (int) round((microtime(true) - $started) * 1000);

        return $data;
    }
}
