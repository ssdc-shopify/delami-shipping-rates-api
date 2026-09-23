<?php

namespace App\Libraries\Couriers;

use Config\Couriers as CouriersConfig;

/**
 * JNE API client: AWB creation (generatecnote) and tracking.
 * Credentials come from .env via Config\Couriers.
 */
class JneClient
{
    private CouriersConfig $config;

    /** Per-request ceiling in seconds; null keeps the 30s booking default. */
    private ?float $timeout = null;

    public function __construct(?CouriersConfig $config = null)
    {
        $this->config = $config ?? config(CouriersConfig::class);
    }

    /**
     * Make the next requests give up after $seconds (null: this client's
     * default). Tracking sets it: a shopper is waiting on the page.
     */
    public function setTimeout(?float $seconds): void
    {
        $this->timeout = $seconds === null ? null : max(0.1, $seconds);
    }

    /**
     * Create a consignment note (AWB). Returns decoded response;
     * the waybill is at detail.0.cnote_no on success.
     *
     * @param array $shipment keys: order_id, receiver_name, receiver_addr1..3,
     *                        receiver_city, receiver_province, receiver_zip,
     *                        receiver_phone, qty, weight, goods_desc, goods_value,
     *                        insurance_flag (Y|N), destination, service,
     *                        cod_flag (YES|N), account, cod_amount
     */
    public function generateCnote(array $shipment): ?array
    {
        $fields = [
            'username'              => $this->config->jneUsername,
            'api_key'               => $this->config->jneApiKey,
            'OLSHOP_BRANCH'         => $this->config->jneBranch,
            'OLSHOP_CUST'           => $shipment['account'],
            'OLSHOP_ORDERID'        => $shipment['order_id'],
            'OLSHOP_SHIPPER_NAME'   => $this->config->shipperBrand,
            'OLSHOP_SHIPPER_ADDR1'  => $this->config->shipperAddress1,
            'OLSHOP_SHIPPER_ADDR2'  => $this->config->shipperAddress2,
            'OLSHOP_SHIPPER_CITY'   => $this->config->shipperCity,
            'OLSHOP_SHIPPER_ZIP'    => $this->config->shipperZip,
            'OLSHOP_SHIPPER_PHONE'  => $this->config->shipperPhone,
            'OLSHOP_RECEIVER_NAME'  => $shipment['receiver_name'],
            'OLSHOP_RECEIVER_ADDR1' => $shipment['receiver_addr1'],
            'OLSHOP_RECEIVER_ADDR2' => $shipment['receiver_addr2'],
            'OLSHOP_RECEIVER_ADDR3' => $shipment['receiver_addr3'],
            'OLSHOP_RECEIVER_CITY'  => $shipment['receiver_city'],
            'OLSHOP_RECEIVER_REGION' => $shipment['receiver_province'],
            'OLSHOP_RECEIVER_ZIP'   => $shipment['receiver_zip'],
            'OLSHOP_RECEIVER_PHONE' => $shipment['receiver_phone'],
            'OLSHOP_QTY'            => $shipment['qty'],
            'OLSHOP_WEIGHT'         => $shipment['weight'],
            'OLSHOP_GOODSDESC'      => $shipment['goods_desc'],
            'OLSHOP_GOODSVALUE'     => $shipment['goods_value'],
            'OLSHOP_GOODSTYPE'      => 2,
            'OLSHOP_INST'           => 'Handed',
            'OLSHOP_INS_FLAG'       => $shipment['insurance_flag'],
            'OLSHOP_ORIG'           => $this->config->jneBranch,
            'OLSHOP_DEST'           => $shipment['destination'],
            'OLSHOP_SERVICE'        => $shipment['service'],
            'OLSHOP_COD_FLAG'       => $shipment['cod_flag'],
            'OLSHOP_COD_AMOUNT'     => $shipment['cod_amount'],
        ];

        return $this->post(
            rtrim($this->config->jneBaseUrl, '/') . '/tracing/api/generatecnote',
            $fields
        );
    }

    /**
     * Trace a waybill. Returns decoded tracing payload or null.
     */
    public function trace(string $awb): ?array
    {
        return $this->post(
            rtrim($this->config->jneTraceUrl, '/') . '/tracing/api/list/v1/cnote/' . rawurlencode($awb),
            [
                'username' => $this->config->jneUsername,
                'api_key'  => $this->config->jneApiKey,
            ]
        );
    }

    private function post(string $url, array $fields): ?array
    {
        $client = single_service('curlrequest', ['timeout' => $this->timeout ?? 30]);

        try {
            $response = $client->post($url, [
                'form_params' => $fields,
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => false,
                // Certificates are verified. This used to be switched off for
                // a "non-standard chain", which sent the API key to anyone
                // able to intercept the connection; JNE's HTTPS host now
                // verifies cleanly (checked 2026-09-23), including the HTTPS
                // trace host on :10205 that couriers.jneTraceUrl points at.
            ]);

            return json_decode($response->getBody(), true);
        } catch (\Throwable $e) {
            log_message('error', 'JNE request failed ({url}): {msg}', ['url' => $url, 'msg' => $e->getMessage()]);

            return null;
        }
    }
}
