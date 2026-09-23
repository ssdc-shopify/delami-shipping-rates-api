<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', static fn () => redirect()->to('/admin'));

// Container and public readiness probe. Keep the response generic: database
// failures are logged server-side and never expose connection details.
$routes->get('health', static function () {
    $response = service('response');

    try {
        if (db_connect()->query('SELECT 1') === false) {
            throw new RuntimeException('Database readiness query returned no result.');
        }
    } catch (Throwable $exception) {
        log_message('error', 'Health check database query failed: {message}', [
            'message' => $exception->getMessage(),
        ]);

        return $response->setStatusCode(503)->setJSON(['status' => 'unavailable']);
    }

    return $response->setJSON(['status' => 'ok']);
});

// ---------------------------------------------------------------
// Customer order tracking (public)
// ---------------------------------------------------------------
// The only page in this app a shopper ever sees. Unauthenticated by
// necessity, so the controller pairs the reference with the order's email
// and throttles by IP rather than relying on the URL being secret.
$routes->get('track', 'Track::index');
$routes->post('track', 'Track::lookup');

// ---------------------------------------------------------------
// Shopify OAuth (app install) + webhooks
// ---------------------------------------------------------------
// Admin-only: the redirect it answers with names the store's Shopify domain
// and the app's Client ID, so an open route let anyone list every store by id.
// The callback stays public — Shopify sends the browser there — and is
// guarded by HMAC and the state held in the admin's own session.
$routes->get('shopify/install', 'ShopifyOauth::install', ['filter' => ['session', 'group:admin,superadmin']]);
$routes->get('shopify/oauth/callback', 'ShopifyOauth::callback', ['as' => 'shopify-oauth-callback']);
$routes->post('shopify/webhooks/(:segment)', 'ShopifyWebhooks::handle/$1', ['as' => 'shopify-webhook']);

// ---------------------------------------------------------------
// Shopify CarrierService rate callback (token-authenticated)
// ---------------------------------------------------------------
$routes->post('carrier/rates/(:segment)', 'Api\CarrierRates::quote/$1');

// ---------------------------------------------------------------
// Headless storefront cart rates (publishable key in a header)
// ---------------------------------------------------------------
// The store is identified by the key, not by a URL segment: a slug in the
// path would let a caller quote against any store by guessing a name.
$routes->post('api/storefront/rates', 'Api\StorefrontRates::quote');

// Address → coordinates, so a cart can drop the GrabExpress pin on the
// address the shopper typed. Same key + throttle as the rate endpoint.
$routes->post('api/storefront/geocode', 'Api\StorefrontRates::geocode');

// Order tracking for a storefront that renders the timeline itself rather
// than linking to the hosted /track page. Same key, same throttle.
$routes->post('api/storefront/track', 'Api\StorefrontTracking::lookup');

// Routing runs before URI-pattern filters, so without a matching OPTIONS
// route the browser's preflight 404s and the cors filter never gets to
// answer it. A valid preflight is handled by the filter and stops there;
// this closure only catches a bare OPTIONS request.
$routes->options('api/storefront/rates', static fn () => service('response')->setStatusCode(204));
$routes->options('api/storefront/geocode', static fn () => service('response')->setStatusCode(204));
$routes->options('api/storefront/track', static fn () => service('response')->setStatusCode(204));

// ---------------------------------------------------------------
// Admin backend (Shield session auth + admin group)
// ---------------------------------------------------------------
$routes->group('admin', ['filter' => ['session', 'group:admin,superadmin'], 'namespace' => 'App\Controllers\Admin'], static function ($routes) {
    $routes->get('/', 'Dashboard::index');
    $routes->get('orders', 'Orders::index');
    $routes->post('awb/generate/(:num)', 'Awb::generate/$1');
    $routes->get('awb/print/(:num)', 'Awb::print/$1');
    $routes->get('stores', 'Stores::index');
    $routes->post('stores/connect', 'Stores::connect');
    $routes->post('stores/webhooks/(:num)', 'Stores::webhooks/$1');
    $routes->post('stores/order-webhooks/(:num)', 'Stores::orderWebhooks/$1');
    $routes->post('stores/settings/(:num)', 'Stores::settings/$1');
    $routes->post('stores/storefront-key/(:num)', 'Stores::storefrontKey/$1');
    $routes->post('stores/server-key/(:num)', 'Stores::serverKey/$1');
    $routes->post('stores/carrier/(:num)', 'Stores::carrier/$1');
    $routes->get('rate-simulator', 'RateSimulator::index');
    $routes->get('settings', 'Settings::index');
    $routes->post('settings/courier-mode', 'Settings::courierMode');
    $routes->get('profile', 'Profile::index');
    $routes->post('profile', 'Profile::update');
});

service('auth')->routes($routes);
