<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Shopify\AdminClient;
use App\Models\StoreModel;

class Stores extends BaseController
{
    public function index()
    {
        return view('admin/stores/index', [
            'stores' => model(StoreModel::class)->orderBy('id')->findAll(),
        ]);
    }

    /**
     * POST /admin/stores/connect
     * Save (shop domain, Client ID, Client Secret) then start OAuth.
     */
    public function connect()
    {
        if (! $this->validate([
            'shop'          => 'required|string',
            'client_id'     => 'required|alpha_numeric_punct',
            'client_secret' => 'required|string',
        ])) {
            return redirect()->back()->withInput()->with('error', implode(' ', $this->validator->getErrors()));
        }

        $shop = strtolower(trim((string) $this->request->getPost('shop')));
        if ($shop !== '' && ! str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop) !== 1) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid shop domain, e.g. my-store.myshopify.com');
        }

        $stores = model(StoreModel::class);
        $store  = $stores->findByDomain($shop);

        if ($store === null) {
            $slug = substr(explode('.', $shop)[0], 0, 20);
            $stores->insert([
                'slug'        => $stores->findBySlug($slug) ? $slug . '-' . random_string('numeric', 3) : $slug,
                'name'        => explode('.', $shop)[0],
                'merchant_id' => '',
                'shop_domain' => $shop,
                'active'      => 1,
            ]);
            $store = $stores->find($stores->getInsertID());
        }

        $stores->saveAppCredentials(
            (int) $store['id'],
            (string) $this->request->getPost('client_id'),
            (string) $this->request->getPost('client_secret'),
        );

        return redirect()->to(site_url('shopify/install') . '?store=' . $store['id']);
    }

    /**
     * POST /admin/stores/webhooks/{id}
     * (Re-)subscribe the store to this app's webhook topics. Needed for
     * stores installed before a topic was added — install-time registration
     * only covers whatever topics existed back then.
     */
    public function webhooks(int $id)
    {
        $store = model(StoreModel::class)->find($id);
        if ($store === null) {
            return redirect()->to(site_url('admin/stores'))->with('error', 'Unknown store.');
        }
        if (empty($store['access_token'])) {
            return redirect()->to(site_url('admin/stores'))->with('error', 'Store is not installed — authorize it first.');
        }

        try {
            $results = (new AdminClient($store))->registerAppWebhooks(StoreModel::receivesOrderWebhooks($store));
        } catch (\Throwable $e) {
            return redirect()->to(site_url('admin/stores'))->with('error', 'Webhook registration failed: ' . $e->getMessage());
        }

        $failed = array_filter($results, static fn ($r) => $r !== 'ok');
        if ($failed !== []) {
            $detail = implode('; ', array_map(static fn ($t, $m) => "{$t}: {$m}", array_keys($failed), $failed));

            return redirect()->to(site_url('admin/stores'))->with('error', "Some webhooks failed — {$detail}");
        }

        return redirect()->to(site_url('admin/stores'))
            ->with('message', 'Registered ' . count($results) . ' webhook topics for ' . $store['shop_domain']
                . (StoreModel::receivesOrderWebhooks($store) ? '.' : ' — order webhooks are off for this store, so those were left out.'));
    }

    /**
     * POST /admin/stores/order-webhooks/{id}   enabled=1|0
     *
     * Decide whether this site receives the store's order webhooks. A store
     * connected to several copies of this app (a development tunnel beside
     * production) sends each of them every order; this is how one of them is
     * taken off that list, or put back on it.
     *
     * The switch is saved first and holds by itself — the webhook receiver
     * ignores order topics for a switched-off store — so a Shopify call that
     * fails below leaves Shopify sending, never this site storing.
     */
    public function orderWebhooks(int $id)
    {
        $stores = model(StoreModel::class);
        $store  = $stores->find($id);

        if ($store === null) {
            return redirect()->to(site_url('admin/stores'))->with('error', 'Unknown store.');
        }

        $enabled = $this->request->getPost('enabled') === '1';
        $stores->update($id, ['order_webhooks' => $enabled ? 1 : 0]);

        $state = $enabled ? 'on' : 'off';

        // Not installed: nothing is registered on Shopify to change. The
        // choice is applied when the store is authorized.
        if (empty($store['access_token'])) {
            return redirect()->to(site_url('admin/stores'))
                ->with('message', "Order webhooks turned {$state} for {$store['slug']}. It takes effect when the store is authorized.");
        }

        try {
            $client  = new AdminClient($store);
            $results = $enabled ? $client->registerAppWebhooks() : $client->unregisterOrderWebhooks();
        } catch (\Throwable $e) {
            $results = ['Shopify' => $e->getMessage()];
        }

        $failed = array_filter($results, static fn ($r) => ! in_array($r, ['ok', 'removed', 'not registered'], true));

        if ($failed !== []) {
            $detail = implode('; ', array_map(static fn ($t, $m) => "{$t}: {$m}", array_keys($failed), $failed));

            return redirect()->to(site_url('admin/stores'))->with('error', $enabled
                ? "Order webhooks turned on for {$store['slug']}, but Shopify registration failed — {$detail}. Press Webhooks to retry."
                : "Order webhooks turned off for {$store['slug']} — this site now ignores them — but Shopify still sends them: {$detail}");
        }

        return redirect()->to(site_url('admin/stores'))->with('message', $enabled
            ? "This site now receives {$store['slug']}'s order webhooks."
            : "This site no longer receives {$store['slug']}'s order webhooks. Other sites connected to the store are unaffected.");
    }

    /**
     * POST /admin/stores/carrier/{id}
     *
     * Register this app's rate callback as a CarrierService on the store, or
     * re-point it if it already exists. Idempotent, which is the point: the
     * callback URL is frozen at creation, so a tunnel restart in development
     * leaves it aimed at a dead host — and that fails as no rates offered at
     * checkout, with nothing logged anywhere to say why.
     */
    public function carrier(int $id)
    {
        $store  = model(StoreModel::class)->find($id);
        $config = config('Shopify');

        if ($store === null) {
            return redirect()->to(site_url('admin/stores'))->with('error', 'Unknown store.');
        }
        if (empty($store['access_token'])) {
            return redirect()->to(site_url('admin/stores'))->with('error', 'Store is not installed — authorize it first.');
        }
        if ($config->carrierCallbackToken === '') {
            return redirect()->to(site_url('admin/stores'))
                ->with('error', 'shopify.carrierCallbackToken is empty in .env — set it before registering.');
        }

        $callbackUrl = rtrim(config('App')->baseURL, '/')
            . '/carrier/rates/' . $store['slug'] . '?token=' . $config->carrierCallbackToken;

        try {
            [$service, $action] = (new AdminClient($store))
                ->ensureCarrierService($config->carrierServiceName, $callbackUrl);
        } catch (\Throwable $e) {
            return redirect()->to(site_url('admin/stores'))
                ->with('error', 'Carrier service registration failed: ' . $e->getMessage());
        }

        return redirect()->to(site_url('admin/stores'))->with('message', match ($action) {
            'created'   => 'Carrier service registered for ' . $store['slug'] . '. Rates now appear at checkout.',
            'updated'   => 'Carrier service re-pointed for ' . $store['slug'] . ' → ' . $service['callbackUrl'],
            default     => 'Carrier service for ' . $store['slug'] . ' was already pointing at the current URL.',
        });
    }

    /**
     * POST /admin/stores/storefront-key/{id}
     *
     * Issue or rotate the publishable key the headless cart pages send to
     * /api/storefront/rates. Per store, because the rate thresholds it quotes
     * against are per store.
     *
     * Rotation takes effect at once and cannot be undone: any Hydrogen build
     * or Expo release carrying the old key stops quoting until it ships the
     * new one. That is the point — it is how a leaked key is revoked — but it
     * is why the button asks for confirmation when a key already exists.
     */
    public function storefrontKey(int $id)
    {
        $stores = model(StoreModel::class);
        $store  = $stores->find($id);

        if ($store === null) {
            return redirect()->to(site_url('admin/stores'))->with('error', 'Unknown store.');
        }

        $rotated = ! empty($store['storefront_key']);
        $stores->rotateStorefrontKey($id);

        return redirect()->to(site_url('admin/stores'))->with(
            'message',
            $rotated
                ? 'Storefront key rotated for ' . $store['slug'] . ' — the previous key stopped working immediately.'
                : 'Storefront key issued for ' . $store['slug'] . '.',
        );
    }

    /**
     * POST /admin/stores/settings/{id}
     * Update the rate settings used by the RateEngine: shipping subsidy
     * (subsidi ongkir) and the minimum cart total that activates it.
     */
    public function settings(int $id)
    {
        $stores = model(StoreModel::class);
        if ($stores->find($id) === null) {
            return redirect()->to(site_url('admin/stores'))->with('error', 'Unknown store.');
        }

        if (! $this->validate([
            'subsidi_ongkir'     => 'required|is_natural',
            'minimum_order'      => 'required|is_natural',
            'jne_max_cart'       => 'permit_empty|is_natural',
            'spx_min_cart'       => 'permit_empty|is_natural',
            'insurance_min_cart' => 'permit_empty|is_natural',
        ])) {
            return redirect()->back()->with('error', 'All amounts must be whole IDR values (0 or more).');
        }

        // Blank means "inherit the Config\Couriers default".
        $optional = static function (mixed $value): ?int {
            $value = trim((string) $value);

            return $value === '' ? null : (int) $value;
        };

        $stores->update($id, [
            'subsidi_ongkir'     => (int) $this->request->getPost('subsidi_ongkir'),
            'minimum_order'      => (int) $this->request->getPost('minimum_order'),
            'jne_max_cart'       => $optional($this->request->getPost('jne_max_cart')),
            'spx_min_cart'       => $optional($this->request->getPost('spx_min_cart')),
            'insurance_min_cart' => $optional($this->request->getPost('insurance_min_cart')),
        ]);

        return redirect()->to(site_url('admin/stores'))->with('message', 'Rate settings saved.');
    }
}
