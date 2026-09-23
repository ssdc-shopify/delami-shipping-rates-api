<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', static fn () => redirect()->to('/admin'));

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
$routes->get('shopify/install', 'ShopifyOauth::install');
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
    $routes->post('stores/carrier/(:num)', 'Stores::carrier/$1');
    $routes->get('rate-simulator', 'RateSimulator::index');
    $routes->get('settings', 'Settings::index');
    $routes->post('settings/courier-mode', 'Settings::courierMode');
    $routes->get('profile', 'Profile::index');
    $routes->post('profile', 'Profile::update');
});

service('auth')->routes($routes);
