<?php

namespace App\Controllers\Api;

use App\Models\StoreModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Who may call the storefront endpoints, and how often.
 *
 * Every request names its store with the publishable key (X-Storefront-Key).
 * That key sits in browser bundles and app builds, so it identifies a store
 * rather than proving anything, and those callers are limited per client IP.
 *
 * A storefront calling from its OWN SERVER also sends the store's secret
 * server key (X-Storefront-Secret). All of that site's shoppers then arrive
 * from one IP, so a per-IP limit would ration the whole site to one person's
 * allowance; a verified server gets a per-store limit sized for a site
 * instead. A wrong secret is refused outright rather than downgraded, so a
 * misconfigured server finds out at once instead of being quietly throttled.
 *
 * One gate for all three endpoints, so their rules cannot drift apart.
 */
trait StorefrontAccess
{
    /**
     * Requests per minute: [per client IP with the publishable key alone,
     * per store for a verified server].
     *
     * Geocoding is the tightest for browsers: every miss spends Delami's
     * Google quota, and a publishable key is anyone's to use.
     */
    public const STOREFRONT_LIMITS = [
        'rates'   => [60, 1200],
        'geocode' => [20, 600],
        'track'   => [15, 300],
    ];

    /**
     * The store this request is for, or the refusal to send back.
     *
     * @return array<string, mixed>|ResponseInterface
     */
    protected function admitStorefront(string $scope): array|ResponseInterface
    {
        $store = model(StoreModel::class)->findByStorefrontKey(
            (string) $this->request->getHeaderLine('X-Storefront-Key'),
        );

        if ($store === null) {
            return $this->refuseStorefront(401, 'invalid storefront key');
        }

        [$perIp, $perServer] = self::STOREFRONT_LIMITS[$scope];

        $secret = trim($this->request->getHeaderLine('X-Storefront-Secret'));

        if ($secret !== '') {
            if (! StoreModel::isServerKey($store, $secret)) {
                return $this->refuseStorefront(401, 'invalid storefront secret');
            }

            $bucket = "sf-{$scope}-{$store['id']}-server";
            $limit  = $perServer;
        } else {
            // Throttled per store as well as per IP: one storefront being
            // hammered must not stop another store's shoppers. The IP is
            // hashed because an IPv6 address contains colons, which the cache
            // handler rejects in a key.
            $bucket = "sf-{$scope}-{$store['id']}-" . md5($this->request->getIPAddress());
            $limit  = $perIp;
        }

        $throttler = service('throttler');

        if ($throttler->check($bucket, $limit, MINUTE) === false) {
            return $this->refuseStorefront(429, 'too many requests')
                ->setHeader('Retry-After', (string) $throttler->getTokenTime());
        }

        return $store;
    }

    private function refuseStorefront(int $status, string $message): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => $message]);
    }
}
