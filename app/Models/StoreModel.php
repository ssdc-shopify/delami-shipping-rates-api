<?php

namespace App\Models;

use CodeIgniter\Model;

class StoreModel extends Model
{
    protected $table         = 'stores';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'slug', 'name', 'merchant_id', 'shop_domain', 'api_key', 'api_secret', 'storefront_key',
        'access_token', 'scopes', 'installed_at', 'uninstalled_at',
        'subsidi_ongkir', 'minimum_order',
        'jne_max_cart', 'spx_min_cart', 'insurance_min_cart',
        'active', 'order_webhooks',
    ];

    /**
     * Whether this site takes the store's order webhooks. A row from before
     * the column existed has no value, and those stores always did.
     */
    public static function receivesOrderWebhooks(array $store): bool
    {
        return (int) ($store['order_webhooks'] ?? 1) === 1;
    }

    /**
     * A per-store rate threshold, falling back to the Config\Couriers default
     * when the store has not overridden it.
     */
    public static function threshold(array $store, string $column, string $configKey): int
    {
        $value = $store[$column] ?? null;

        return $value === null || $value === ''
            ? (int) config('Couriers')->{$configKey}
            : (int) $value;
    }

    /**
     * This store's Shopify admin URL, e.g. https://admin.shopify.com/store/acme
     *
     * The path segment is the shop handle, which is the myshopify subdomain.
     * Null when the store has no domain yet — it has never been installed.
     */
    public static function adminUrl(array $store): ?string
    {
        $domain = (string) ($store['shop_domain'] ?? '');
        $suffix = '.myshopify.com';

        if (! str_ends_with($domain, $suffix)) {
            return null;
        }

        return 'https://admin.shopify.com/store/' . substr($domain, 0, -strlen($suffix));
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->where('active', 1)->first();
    }

    public function findByDomain(string $shopDomain): ?array
    {
        return $this->where('shop_domain', $shopDomain)->first();
    }

    /** Prefix marking a value as a publishable storefront key. */
    public const STOREFRONT_KEY_PREFIX = 'pk_';

    /**
     * The store a cart-page rate request belongs to, or null.
     *
     * An empty key must never match a store that has none yet, so the blank
     * case is rejected before the query rather than relying on the column
     * being non-null.
     */
    public function findByStorefrontKey(string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        return $this->where('storefront_key', $key)->where('active', 1)->first();
    }

    /**
     * Issue (or rotate) a store's publishable key and return it.
     *
     * Rotation is the revocation mechanism: the old key stops resolving the
     * moment this is called, so a leaked key in a shipped app build is fixed
     * by rotating here and releasing a new build.
     */
    public function rotateStorefrontKey(int $id): string
    {
        $key = self::STOREFRONT_KEY_PREFIX . bin2hex(random_bytes(16));

        $this->update($id, ['storefront_key' => $key]);

        return $key;
    }

    /**
     * Store an access token encrypted at rest.
     */
    public function saveToken(int $id, string $accessToken, string $scopes): bool
    {
        $encrypter = service('encrypter');

        return $this->update($id, [
            'access_token'   => base64_encode($encrypter->encrypt($accessToken)),
            'scopes'         => $scopes,
            'installed_at'   => date('Y-m-d H:i:s'),
            'uninstalled_at' => null,
        ]);
    }

    /**
     * Decrypt and return the store's access token, or null when not installed.
     */
    public function getToken(array $store): ?string
    {
        return $this->decryptField($store, 'access_token');
    }

    /**
     * Save the app's Client ID + Client Secret (secret encrypted at rest).
     */
    public function saveAppCredentials(int $id, string $apiKey, string $apiSecret): bool
    {
        return $this->update($id, [
            'api_key'    => $apiKey,
            'api_secret' => base64_encode(service('encrypter')->encrypt($apiSecret)),
        ]);
    }

    /**
     * Decrypt and return the store's app Client Secret, or null.
     */
    public function getAppSecret(array $store): ?string
    {
        return $this->decryptField($store, 'api_secret');
    }

    private function decryptField(array $store, string $field): ?string
    {
        if (empty($store[$field])) {
            return null;
        }

        try {
            return service('encrypter')->decrypt(base64_decode($store[$field], true));
        } catch (\Throwable $e) {
            log_message('error', 'Failed to decrypt {field} for store {id}: {msg}', [
                'field' => $field,
                'id'    => $store['id'],
                'msg'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
