<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Shipping\RateEngine;
use App\Models\StoreModel;

/**
 * Admin rate simulator. Runs the SAME RateEngine the Shopify CarrierService
 * callback uses, so the output is exactly what checkout would receive.
 */
class RateSimulator extends BaseController
{
    public function index()
    {
        $stores = model(StoreModel::class)->where('active', 1)->orderBy('id')->findAll();

        // Which tab ran: 'standard' (zip → JNE/Ninja/SPX) or 'grab'
        // (coordinates → GRABEXPRESS - INSTANT). They are separate forms so
        // each can require its own fields without fighting the other.
        $mode = $this->request->getGet('mode') === 'grab' ? 'grab' : 'standard';

        $data = [
            'stores'  => $stores,
            'mode'    => $mode,
            'result'  => null,
            'error'   => null,
            'elapsed' => null,
            // Filled once a store is chosen — thresholds are per store, so
            // there is no single set of numbers to state before then.
            'limits'  => null,
            'input'   => [
                'store'     => $this->request->getGet('store') ?? '',
                'zip'       => $this->request->getGet('zip') ?? '',
                'city'      => $this->request->getGet('city') ?? '',
                'weight'    => $this->request->getGet('weight') ?? '1000',
                'total'     => $this->request->getGet('total') ?? '150000',
                // Grab tab only — coordinate-based. Prefilled with a real
                // Kuningan (Jakarta) drop-off so the tab is ready to run.
                'lat'       => $this->request->getGet('lat') ?? '-6.2285501',
                'lng'       => $this->request->getGet('lng') ?? '106.8337856',
                'city_code' => $this->request->getGet('city_code') ?? 'CGK',
                'address'   => $this->request->getGet('address') ?? 'Plaza ORI,Jl. H. R. Rasuna Said 7,RT.7/RW.4,Kuningan Timur [[Lt8 DAPN PTPN IV PALMCO ]]',
            ],
        ];

        if ($this->request->getGet('run') !== null && $stores !== []) {
            $data = $this->runSimulation($data, $stores);
        }

        return view('admin/rate_simulator/index', $data);
    }

    private function runSimulation(array $data, array $stores): array
    {
        $slug  = (string) $data['input']['store'];
        $store = null;
        foreach ($stores as $s) {
            if ($s['slug'] === $slug) {
                $store = $s;
                break;
            }
        }

        if ($store === null) {
            $data['error'] = 'Select a valid store.';

            return $data;
        }

        // The effective values for THIS store: its own column when set,
        // otherwise the Config\Couriers default.
        $data['limits'] = [
            'jne_max_cart'       => StoreModel::threshold($store, 'jne_max_cart', 'jneMaxCart'),
            'spx_min_cart'       => StoreModel::threshold($store, 'spx_min_cart', 'spxMinCart'),
            'insurance_min_cart' => StoreModel::threshold($store, 'insurance_min_cart', 'insuranceMinCart'),
            'subsidi_ongkir'     => (int) $store['subsidi_ongkir'],
            'minimum_order'      => (int) $store['minimum_order'],
        ];

        // Each tab validates and builds only its own destination, so the two
        // never interfere: standard needs a zip, Grab needs coordinates.
        if ($data['mode'] === 'grab') {
            $lat = trim((string) $data['input']['lat']);
            $lng = trim((string) $data['input']['lng']);

            if ($lat === '' || $lng === '') {
                $data['error'] = 'Latitude and longitude are required for a GRABEXPRESS - INSTANT quote.';

                return $data;
            }

            // No zip in Grab mode — a coordinates-only quote returns just the
            // GRABEXPRESS line.
            $destination = [
                'postal_code' => '',
                'city'        => (string) $data['input']['city'],
                'latitude'    => $lat,
                'longitude'   => $lng,
                'cityCode'    => trim((string) $data['input']['city_code']),
                'address'     => trim((string) $data['input']['address']),
            ];
        } else {
            $zip = trim((string) $data['input']['zip']);

            if ($zip === '') {
                $data['error'] = 'Postal code is required — the rate engine resolves JNE/Ninja/SPX by zip.';

                return $data;
            }

            $destination = [
                'postal_code' => $zip,
                'city'        => (string) $data['input']['city'],
            ];
        }

        $start = microtime(true);
        try {
            // Each tab quotes the method it is named after, so the simulator
            // shows the same list a shopper on that tab would see — not the
            // union of both.
            $data['result'] = (new RateEngine())->quote(
                $store,
                $destination,
                (int) $data['input']['weight'],
                (float) $data['input']['total'],
                $data['mode'] === 'grab' ? RateEngine::METHOD_INSTANT : RateEngine::METHOD_STANDARD,
            );
        } catch (\Throwable $e) {
            $data['error'] = $e->getMessage();
        }
        $data['elapsed'] = round((microtime(true) - $start) * 1000);

        return $data;
    }
}
