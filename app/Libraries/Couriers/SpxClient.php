<?php

namespace App\Libraries\Couriers;

use Config\Couriers as CouriersConfig;

/**
 * SPX (Shopee Express) Open API client with HMAC-SHA256 request signing.
 */
class SpxClient
{
    private CouriersConfig $config;

    public function __construct(?CouriersConfig $config = null)
    {
        $this->config = $config ?? config(CouriersConfig::class);
    }

    /**
     * Available pickup slots for a service type. Returns the first slot as
     * ['pickup_time' => ..., 'range_id' => ..., 'range' => ...] or null.
     */
    public function firstPickupSlot(int $serviceType = 1): ?array
    {
        $body = json_encode([
            'user_id'      => (int) $this->config->spxUserId,
            'user_secret'  => $this->config->spxUserSecret,
            'service_type' => $serviceType,
        ]);

        $response = $this->signedPost('/open/api/v1/order/get_pickup_time', $body);
        $slot     = $response['data'][0] ?? null;
        if ($slot === null) {
            return null;
        }

        return [
            'pickup_time' => $slot['pickup_time'],
            'range_id'    => $slot['slots'][0]['pickup_time_range_id'] ?? null,
            'range'       => $slot['slots'][0]['pickup_time_range'] ?? null,
        ];
    }

    /**
     * Create an SPX order. Returns decoded response; on success the tracking
     * number is at data.orders.0.tracking_no with r_first/r_third sort codes.
     *
     * @param array $shipment keys: order_ref, name, phone, email, address,
     *                        district, city, province, zip, qty, weight_kg,
     *                        subtotal, insurance_fee, insurance_flag (0|1),
     *                        pickup (from firstPickupSlot), service_type
     */
    public function createOrder(array $shipment): ?array
    {
        $order = [
            'order_id'  => $shipment['order_ref'],
            'base_info' => ['service_type' => (int) ($shipment['service_type'] ?? 1)],
            'sender_info' => [
                'sender_state'          => 'JAWA BARAT',
                'sender_city'           => 'KOTA BEKASI',
                'sender_district'       => 'RAWALUMBU',
                'sender_longitude'      => '106.9851154',
                'sender_latitude'       => '-6.2888924',
                'sender_name'           => 'DELAMIBRANDS KHARISMA BUSANA, PT',
                'sender_detail_address' => 'Jl. RAYA NAROGONG KM 6 NO. 20 RT 07 RW 03 NO 15 BOJONG RAWA LUMBU',
                'sender_phone'          => '08111717250',
            ],
            'fulfillment_info' => [
                'payment_role'         => 1,
                'cod_collection'       => 0,
                'insurance_collection' => (int) $shipment['insurance_flag'],
                'collect_type'         => 1,
                'pickup_time'          => $shipment['pickup']['pickup_time'],
                'pickup_time_range_id' => $shipment['pickup']['range_id'],
                'pickup_time_range'    => $shipment['pickup']['range'],
            ],
            'deliver_info' => [
                'deliver_longitude'      => '',
                'deliver_latitude'       => '',
                'deliver_detail_address' => $shipment['address'],
                'deliver_name'           => $shipment['name'],
                'deliver_phone'          => $shipment['phone'],
                'deliver_district'       => $shipment['district'],
                'deliver_city'           => $shipment['city'],
                'deliver_state'          => $shipment['province'],
                'deliver_instruction'    => 'Leave at doorstep',
            ],
            'parcel_info' => [
                'parcel_weight'         => $shipment['weight_kg'],
                'parcel_item_name'      => 'Clothing',
                'parcel_item_quantity'  => (int) $shipment['qty'],
                'parcel_length'         => 1,
                'parcel_width'          => 1,
                'parcel_height'         => 1,
                'express_insured_value' => (int) $shipment['subtotal'],
            ],
        ];

        $body = json_encode([
            'user_id'     => (int) $this->config->spxUserId,
            'user_secret' => $this->config->spxUserSecret,
            'orders'      => [$order],
        ]);

        return $this->signedPost('/open/api/v1/order/batch_create_order', $body);
    }

    /**
     * Signed POST per SPX spec: check-sign = HMAC-SHA256 of
     * "{appId}_{timestamp}_{randomNum}_{rawBody}" with the app secret.
     */
    private function signedPost(string $path, string $rawBody): ?array
    {
        $timestamp = time();
        $randomNum = random_int(100000, 999999);
        $signStr   = "{$this->config->spxAppId}_{$timestamp}_{$randomNum}_{$rawBody}";
        $checkSign = hash_hmac('sha256', $signStr, $this->config->spxAppSecret);

        $client = single_service('curlrequest', ['timeout' => 30]);

        try {
            $response = $client->post(rtrim($this->config->spxBaseUrl, '/') . $path, [
                'body'    => $rawBody,
                'headers' => [
                    'app-id'       => $this->config->spxAppId,
                    'timestamp'    => (string) $timestamp,
                    'random-num'   => (string) $randomNum,
                    'check-sign'   => $checkSign,
                    'Content-Type' => 'application/json',
                ],
                'http_errors' => false,
            ]);

            return json_decode($response->getBody(), true);
        } catch (\Throwable $e) {
            log_message('error', 'SPX request failed ({path}): {msg}', ['path' => $path, 'msg' => $e->getMessage()]);

            return null;
        }
    }
}
