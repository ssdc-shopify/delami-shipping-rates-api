<?php

namespace Tests\Unit;

use App\Libraries\Awb\MockMode;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * The switch between inventing waybills and booking real shipments.
 *
 * It lives in the settings table and is flipped from Admin → Settings. What
 * has to hold: a database nobody has touched is mock, a saved choice survives
 * into the next request, and going live cannot happen by accident.
 */
final class MockModeTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    // Every namespace: the settings table comes from CodeIgniter Settings and
    // the admin login from Shield, not from App.
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The Settings service caches what it has read for the life of the
        // process, while the database under it is rebuilt for every test.
        Services::resetSingle('settings');

        // Shield's authenticator remembers who logged in, and it is shared
        // across the run — so a login in an earlier test class would make the
        // guest checks here pass or fail by test order.
        Services::resetSingle('auth');
    }

    /** What the next request would see: a fresh service that re-reads the table. */
    private function nextRequest(): void
    {
        Services::resetSingle('settings');
    }

    private function admin(): User
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'ops', 'active' => 1]);
        $users->save($user);

        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => 'ops@delamibrands.com', 'password' => 'correct-horse-battery']);
        $user->addGroup('admin');

        return $user;
    }

    private function switchTo(string $mode, bool $confirmed = false)
    {
        return $this->actingAs($this->admin())->post('admin/settings/courier-mode', [
            csrf_token() => csrf_hash(),
            'mode'       => $mode,
        ] + ($confirmed ? ['confirm_live' => '1'] : []));
    }

    // ------------------------------------------------------------------
    // The stored switch
    // ------------------------------------------------------------------

    public function testMockIsOnWhenNothingHasBeenSaved(): void
    {
        $this->assertTrue(MockMode::enabled(), 'a fresh install must never book a real shipment');
        $this->assertNull(MockMode::lastChange());
    }

    public function testLiveIsReadBackFromTheDatabase(): void
    {
        MockMode::set(false, 'ops@delamibrands.com');
        $this->nextRequest();

        $this->assertFalse(MockMode::enabled());
        $this->assertSame('ops@delamibrands.com', MockMode::lastChange()['by']);
    }

    public function testSwitchingBackToMockIsReadBack(): void
    {
        MockMode::set(false, 'ops@delamibrands.com');
        MockMode::set(true, 'ops@delamibrands.com');
        $this->nextRequest();

        $this->assertTrue(MockMode::enabled());
    }

    /**
     * A server whose .env still says couriers.mockAwb = false keeps that until
     * an operator saves a choice — and from then on the admin decides.
     */
    public function testASavedChoiceWinsOverALeftoverEnvValue(): void
    {
        config('Couriers')->mockAwb = false;
        $this->assertFalse(MockMode::enabled(), 'with nothing saved, the old .env value still applies');

        MockMode::set(true, 'ops@delamibrands.com');
        $this->nextRequest();

        $this->assertTrue(MockMode::enabled());
    }

    // ------------------------------------------------------------------
    // Admin → Settings
    // ------------------------------------------------------------------

    public function testThePageIsAdminOnly(): void
    {
        $this->get('admin/settings')->assertRedirect();
    }

    public function testThePageShowsTheCurrentMode(): void
    {
        $result = $this->actingAs($this->admin())->get('admin/settings');

        $result->assertOK();
        $result->assertSee('Mock mode is on.');
    }

    public function testGoingLiveWithoutTheConfirmationChangesNothing(): void
    {
        $this->switchTo('live')->assertRedirectTo(site_url('admin/settings'));
        $this->nextRequest();

        $this->assertTrue(MockMode::enabled(), 'an unconfirmed POST must not turn on real bookings');
        $this->assertNull(MockMode::lastChange());
    }

    public function testGoingLiveWithTheConfirmationIsSavedAndAttributed(): void
    {
        $this->switchTo('live', true)->assertRedirectTo(site_url('admin/settings'));
        $this->nextRequest();

        $this->assertFalse(MockMode::enabled());
        $this->assertSame('ops@delamibrands.com', MockMode::lastChange()['by']);
    }

    public function testGoingBackToMockNeedsNoConfirmation(): void
    {
        MockMode::set(false, 'someone@delamibrands.com');

        $this->switchTo('mock');
        $this->nextRequest();

        $this->assertTrue(MockMode::enabled());
    }

    public function testAnUnknownModeChangesNothing(): void
    {
        $this->switchTo('staging', true);
        $this->nextRequest();

        $this->assertTrue(MockMode::enabled());
        $this->assertNull(MockMode::lastChange());
    }
}
