<?php

namespace App\Models;

use CodeIgniter\Model;

class OrderModel extends Model
{
    protected $table         = 'orders';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'store_id', 'order_id', 'order_name', 'order_number',
        'email', 'customer_name',
        'financial_status', 'fulfillment_status', 'cancelled_at',
        'currency', 'total_price', 'subtotal_price', 'total_weight',
        'shipping_title', 'shipping_code', 'shipping_price',
        'ship_name', 'ship_phone', 'ship_address1', 'ship_address2',
        'ship_city', 'ship_province', 'ship_zip',
        'tags', 'ordered_at', 'shopify_updated_at',
    ];

    /**
     * Shopify's REST webhooks report an unfulfilled order as null and a part-
     * shipped one as "partial"; the GraphQL snapshot taken at Generate AWB
     * says "unfulfilled" and "partially_fulfilled". Stored in the GraphQL
     * words, so one column never holds two spellings of the same state.
     */
    private const WEBHOOK_FULFILLMENT = [
        ''        => 'unfulfilled',
        'partial' => 'partially_fulfilled',
    ];

    public function findByOrderId(int|string $orderId): ?array
    {
        return $this->where('order_id', $orderId)->first();
    }

    /**
     * Insert or update by Shopify order id. Returns the stored row.
     *
     * An update older than what is stored is dropped: Shopify can deliver
     * webhooks out of order, and a retried orders/updated arriving after a
     * newer one would otherwise put the order back to an earlier state. Equal
     * timestamps apply — that is a redelivery of the same change.
     */
    public function upsertByOrderId(array $data): array
    {
        $existing = $this->findByOrderId($data['order_id']);

        if ($existing === null) {
            $this->insert($data);

            return $this->findByOrderId($data['order_id']);
        }

        $incoming = $data['shopify_updated_at'] ?? null;
        $stored   = $existing['shopify_updated_at'] ?? null;

        if ($incoming !== null && $stored !== null && $incoming < $stored) {
            return $existing;
        }

        $this->update($existing['id'], $data);

        return $this->find($existing['id']);
    }

    /**
     * Map a Shopify REST webhook order payload onto this table's columns.
     */
    public static function fromWebhookPayload(array $payload, ?int $storeId = null): array
    {
        $ship     = $payload['shipping_address'] ?? [];
        $line     = $payload['shipping_lines'][0] ?? [];
        $customer = $payload['customer'] ?? [];

        return [
            'store_id'           => $storeId,
            'order_id'           => (int) $payload['id'],
            'order_name'         => $payload['name'] ?? null,
            'order_number'       => isset($payload['order_number']) ? (int) $payload['order_number'] : null,
            'email'              => $payload['email'] ?? null,
            'customer_name'      => trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) ?: null,
            'financial_status'   => $payload['financial_status'] ?? null,
            'fulfillment_status' => self::WEBHOOK_FULFILLMENT[(string) ($payload['fulfillment_status'] ?? '')]
                ?? (string) $payload['fulfillment_status'],
            'cancelled_at'       => self::toDatetime($payload['cancelled_at'] ?? null),
            'currency'           => $payload['currency'] ?? null,
            'total_price'        => (float) ($payload['current_total_price'] ?? $payload['total_price'] ?? 0),
            'subtotal_price'     => (float) ($payload['current_subtotal_price'] ?? $payload['subtotal_price'] ?? 0),
            'total_weight'       => (int) ($payload['total_weight'] ?? 0),
            'shipping_title'     => $line['title'] ?? null,
            'shipping_code'      => $line['code'] ?? null,
            'shipping_price'     => (float) ($line['price'] ?? 0),
            'ship_name'          => $ship['name'] ?? null,
            'ship_phone'         => $ship['phone'] ?? null,
            'ship_address1'      => $ship['address1'] ?? null,
            'ship_address2'      => $ship['address2'] ?? null,
            'ship_city'          => $ship['city'] ?? null,
            'ship_province'      => $ship['province'] ?? null,
            'ship_zip'           => $ship['zip'] ?? null,
            'tags'               => $payload['tags'] ?? null,
            'ordered_at'         => self::toDatetime($payload['created_at'] ?? null),
            'shopify_updated_at' => self::toDatetime($payload['updated_at'] ?? null),
        ];
    }

    /**
     * Map a GraphQL order node (as fetched by AdminClient::getOrderForAwb)
     * onto this table's columns. Generate AWB snapshots the live order with
     * this, which also fills in an order the webhooks never delivered.
     */
    public static function fromGraphqlNode(array $node, ?int $storeId = null): array
    {
        $ship = $node['shippingAddress'] ?? [];
        $line = $node['shippingLine'] ?? [];
        $tags = $node['tags'] ?? [];

        return [
            'store_id'           => $storeId,
            'order_id'           => (int) $node['legacyResourceId'],
            'order_name'         => $node['name'] ?? null,
            'order_number'       => isset($node['name']) ? (int) ltrim((string) $node['name'], '#') : null,
            'email'              => $node['customer']['defaultEmailAddress']['emailAddress'] ?? ($node['email'] ?? null),
            'customer_name'      => $node['customer']['displayName'] ?? null,
            'financial_status'   => isset($node['displayFinancialStatus']) ? strtolower($node['displayFinancialStatus']) : null,
            'fulfillment_status' => isset($node['displayFulfillmentStatus']) ? strtolower($node['displayFulfillmentStatus']) : null,
            'cancelled_at'       => self::toDatetime($node['cancelledAt'] ?? null),
            'currency'           => $node['totalPriceSet']['shopMoney']['currencyCode'] ?? null,
            'total_price'        => (float) ($node['totalPriceSet']['shopMoney']['amount']
                ?? $node['currentTotalPriceSet']['shopMoney']['amount'] ?? 0),
            'subtotal_price'     => (float) ($node['currentSubtotalPriceSet']['shopMoney']['amount'] ?? 0),
            'total_weight'       => (int) ($node['totalWeight'] ?? 0),
            'shipping_title'     => $line['title'] ?? null,
            'shipping_code'      => $line['code'] ?? null,
            'ship_name'          => $ship['name'] ?? null,
            'ship_phone'         => $ship['phone'] ?? null,
            'ship_address1'      => $ship['address1'] ?? null,
            'ship_address2'      => $ship['address2'] ?? null,
            'ship_city'          => $ship['city'] ?? null,
            'ship_province'      => $ship['province'] ?? null,
            'ship_zip'           => $ship['zip'] ?? null,
            'tags'               => is_array($tags) ? implode(', ', $tags) : (string) $tags,
            'ordered_at'         => self::toDatetime($node['createdAt'] ?? null),
            'shopify_updated_at' => self::toDatetime($node['updatedAt'] ?? null),
        ];
    }

    private static function toDatetime(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        $ts = strtotime($iso);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
