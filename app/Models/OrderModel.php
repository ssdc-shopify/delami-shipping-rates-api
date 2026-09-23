<?php

namespace App\Models;

use CodeIgniter\Database\Exceptions\DatabaseException;
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
            try {
                if ($this->insert($data) !== false) {
                    return $this->findByOrderId($data['order_id']);
                }
            } catch (DatabaseException $e) {
                // Handled below.
            }

            // Shopify sends orders/create and orders/updated for a new order
            // within milliseconds of each other, and both can find no row and
            // both insert. The loser hits the unique index on order_id — that
            // is the index doing its job, not a failure — and its data is then
            // applied as an update like any other delivery.
            $existing = $this->findByOrderId($data['order_id']);
            if ($existing === null) {
                throw $e ?? new DatabaseException('Could not store order ' . $data['order_id']);
            }
        }

        $incoming = $data['shopify_updated_at'] ?? null;
        $stored   = $existing['shopify_updated_at'] ?? null;

        if ($incoming !== null && $stored !== null && $incoming < $stored) {
            return $existing;
        }

        $this->update($existing['id'], $data);

        return $this->find($existing['id']);
    }

    /** The columns that identify a customer, cleared by a redact request. */
    public const PERSONAL_COLUMNS = [
        'email', 'customer_name', 'ship_name', 'ship_phone',
        'ship_address1', 'ship_address2', 'ship_zip',
    ];

    /**
     * Erase a customer's personal data from their orders in one store, for
     * Shopify's customers/redact. Returns how many orders were touched.
     *
     * Shopify names the orders to redact; any other order in the store under
     * the same email is included too, since it is the same person's data. The
     * order rows themselves stay — totals, statuses and the airway bill are
     * shipping records, not personal data — and so do city and province,
     * which identify nobody.
     *
     * @param list<int> $orderIds
     */
    public function redactCustomer(int $storeId, array $orderIds, string $email): int
    {
        $email = trim($email);
        if ($orderIds === [] && $email === '') {
            return 0;
        }

        $builder = $this->db->table($this->table)->where('store_id', $storeId)->groupStart();

        if ($orderIds !== []) {
            $builder->whereIn('order_id', $orderIds);
        }
        if ($email !== '') {
            $builder->orWhere('LOWER(email)', strtolower($email));
        }

        $builder->groupEnd()->update(array_fill_keys(self::PERSONAL_COLUMNS, null) + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->db->affectedRows();
    }

    /** Delete every stored order of one store, for Shopify's shop/redact. */
    public function forgetStore(int $storeId): int
    {
        $this->db->table($this->table)->where('store_id', $storeId)->delete();

        return $this->db->affectedRows();
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
