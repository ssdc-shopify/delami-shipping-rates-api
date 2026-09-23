<?php

namespace Tests\Unit;

use App\Models\StoreModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The publishable key that identifies a headless storefront.
 *
 * The key is the only thing standing between an anonymous caller and a store's
 * rate engine, so the lookup's edge cases are worth pinning: a blank key must
 * never resolve, and rotation must revoke the previous value immediately.
 */
final class StorefrontKeyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private function store(array $overrides = []): int
    {
        $model = model(StoreModel::class);
        $model->insert([
            'slug'        => 'acme',
            'name'        => 'Acme',
            'merchant_id' => '',
            'active'      => 1,
        ] + $overrides);

        return (int) $model->getInsertID();
    }

    public function testIssuedKeyResolvesToItsStore(): void
    {
        $id  = $this->store();
        $key = model(StoreModel::class)->rotateStorefrontKey($id);

        $found = model(StoreModel::class)->findByStorefrontKey($key);

        $this->assertNotNull($found);
        $this->assertSame($id, (int) $found['id']);
        $this->assertStringStartsWith(StoreModel::STOREFRONT_KEY_PREFIX, $key);
    }

    /**
     * A store with no key yet stores NULL. If a blank header were passed
     * straight into the query, any caller sending no key at all would match
     * that store and quote against it.
     */
    public function testBlankKeyNeverResolves(): void
    {
        $this->store();

        $this->assertNull(model(StoreModel::class)->findByStorefrontKey(''));
        $this->assertNull(model(StoreModel::class)->findByStorefrontKey('   '));
    }

    public function testRotationRevokesThePreviousKey(): void
    {
        $id  = $this->store();
        $old = model(StoreModel::class)->rotateStorefrontKey($id);
        $new = model(StoreModel::class)->rotateStorefrontKey($id);

        $this->assertNotSame($old, $new);
        $this->assertNull(model(StoreModel::class)->findByStorefrontKey($old));
        $this->assertNotNull(model(StoreModel::class)->findByStorefrontKey($new));
    }

    /** A deactivated store must stop quoting even though its key still exists. */
    public function testInactiveStoreDoesNotResolve(): void
    {
        $id  = $this->store();
        $key = model(StoreModel::class)->rotateStorefrontKey($id);

        model(StoreModel::class)->update($id, ['active' => 0]);

        $this->assertNull(model(StoreModel::class)->findByStorefrontKey($key));
    }
}
