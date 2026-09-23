<?php

namespace App\Libraries\Couriers;

use Config\Couriers as CouriersConfig;

/**
 * Ninja Xpress (Ninja Van ID) client: order creation.
 * The OAuth bearer token is issued by the widget proxy.
 */
class NinjaClient
{
    private CouriersConfig $config;
    private WidgetProxyClient $proxy;

    public function __construct(?CouriersConfig $config = null, ?WidgetProxyClient $proxy = null)
    {
        $this->config = $config ?? config(CouriersConfig::class);
        $this->proxy  = $proxy ?? new WidgetProxyClient($this->config);
    }

    /**
     * Create a Ninja Van order with a requested tracking number.
     *
     * @param array $shipment keys: waybill, name, phone, email, address,
     *                        subdistrict, city, province, zip, qty, weight_kg,
     *                        pickup_date, pickup_start, pickup_end,
     *                        delivery_date, delivery_start, delivery_end,
     *                        insured_value
     */
    public function createOrder(array $shipment): ?array
    {
        $token = $this->proxy->ninjaToken()['token'] ?? null;
        if ($token === null) {
            log_message('error', 'Ninja token unavailable from widget proxy');

            return null;
        }

        $payload = [
            'corporate'                 => ['branch_id' => '4'],
            'service_type'              => 'Corporate',
            'service_level'             => 'Standard',
            'requested_tracking_number' => $shipment['waybill'],
            'reference'                 => ['merchant_order_number' => $shipment['waybill']],
            'from'                      => [
                'name'         => 'DELAMIBRANDS KHARISMA BUSANA, PT',
                'phone_number' => '02129779599',
                'email'        => 'csonline@theexecutive.co.id',
                'address'      => [
                    'address1'     => 'Jl. RAYA NAROGONG KM 6 No. 20 RT 07 RW 03 NO 15 Bojong Rawa Lumbu',
                    'address2'     => '',
                    'kecamatan'    => 'Rawa Lumbu',
                    'city'         => 'Bekasi',
                    'province'     => 'Jawa Barat',
                    'address_type' => 'office',
                    'country'      => 'ID',
                    'postcode'     => '17116',
                ],
            ],
            'to' => [
                'name'         => $shipment['name'],
                'phone_number' => $shipment['phone'],
                'email'        => $shipment['email'],
                'address'      => [
                    'address1'     => $shipment['address'],
                    'address2'     => '',
                    'kecamatan'    => $shipment['subdistrict'],
                    'city'         => $shipment['city'],
                    'province'     => $shipment['province'],
                    'address_type' => 'home',
                    'country'      => 'ID',
                    'postcode'     => $shipment['zip'],
                ],
            ],
            'parcel_job' => [
                'is_pickup_required'    => true,
                'pickup_service_type'   => 'Scheduled',
                'pickup_service_level'  => 'Standard',
                'pickup_date'           => $shipment['pickup_date'],
                'pickup_timeslot'       => [
                    'start_time' => $shipment['pickup_start'],
                    'end_time'   => $shipment['pickup_end'],
                    'timezone'   => 'Asia/Jakarta',
                ],
                'pickup_instructions'   => 'Pickup with care!',
                'delivery_instructions' => 'If recipient is not around, leave parcel in power riserr.',
                'delivery_start_date'   => $shipment['delivery_date'],
                'delivery_timeslot'     => [
                    'start_time' => $shipment['delivery_start'],
                    'end_time'   => $shipment['delivery_end'],
                    'timezone'   => 'Asia/Jakarta',
                ],
                'dimensions'    => ['weight' => (string) $shipment['weight_kg']],
                'insured_value' => $shipment['insured_value'],
                'items'         => [[
                    'item_description' => 'Handle with care',
                    'quantity'         => (string) $shipment['qty'],
                    'is_dangerous_good' => false,
                ]],
            ],
        ];

        $client = single_service('curlrequest', ['timeout' => 30]);

        try {
            $response = $client->post(rtrim($this->config->ninjaBaseUrl, '/') . '/4.2/orders', [
                'json'        => $payload,
                'headers'     => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => $token,
                ],
                'http_errors' => false,
            ]);

            return json_decode($response->getBody(), true);
        } catch (\Throwable $e) {
            log_message('error', 'Ninja order creation failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Track a waybill through the widget proxy.
     */
    public function track(string $awb): ?array
    {
        return $this->proxy->trackStatus($awb);
    }

    /**
     * Every scan Ninja has recorded for a waybill, not just the latest state.
     */
    public function history(string $awb): ?array
    {
        return $this->proxy->trackHistory($awb);
    }
}
