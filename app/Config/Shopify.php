<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Shopify Admin API settings.
 *
 * Values are overridden by .env keys with the `shopify.` prefix
 * (e.g. shopify.adminToken). Never commit real credentials here.
 */
class Shopify extends BaseConfig
{
    public string $storeDomain = '';

    public string $adminToken = '';

    public string $apiVersion = '2026-07';

    public string $apiKey = '';

    public string $apiSecret = '';

    /**
     * Shared secret required as ?token= on the CarrierService
     * rate callback URL.
     */
    public string $carrierCallbackToken = '';

    /** Human-readable name registered as the carrier service. */
    public string $carrierServiceName = 'Delami Shipping (CI4)';

    /**
     * Scopes requested during OAuth install.
     * write_* implies the matching read_* scope.
     */
    public string $scopes = 'read_orders,read_customers,read_products,read_inventory,write_merchant_managed_fulfillment_orders,write_assigned_fulfillment_orders,write_third_party_fulfillment_orders,write_shipping';

    /** HTTP timeout (seconds) for Admin API calls. */
    public int $timeout = 15;

    /**
     * Webhook topics this app subscribes to, mapped to the `{topic}` segment
     * of POST /shopify/webhooks/{topic}. Registered on OAuth install and
     * re-registerable from Admin → Stores. The GDPR topics are configured in
     * the Shopify app settings, not through the API, so they are not listed.
     */
    public array $webhookTopics = [
        'APP_UNINSTALLED' => 'app-uninstalled',
        'ORDERS_CREATE'   => 'orders-create',
        'ORDERS_UPDATED'  => 'orders-updated',
    ];

    /**
     * The topics above that carry order data. A store can take this site off
     * them (Admin → Stores → Edit settings) while APP_UNINSTALLED stays on:
     * that one is what revokes the token of a store that removed the app.
     */
    public array $orderWebhookTopics = ['ORDERS_CREATE', 'ORDERS_UPDATED'];
}
