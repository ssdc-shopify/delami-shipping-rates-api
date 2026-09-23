<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Awb\MockMode;

/**
 * App-wide settings an operator changes from the admin rather than from the
 * server's .env. Per-store settings stay on the Stores page.
 */
class Settings extends BaseController
{
    public function index()
    {
        return view('admin/settings/index', [
            'mock'       => MockMode::enabled(),
            'lastChange' => MockMode::lastChange(),
        ]);
    }

    /**
     * POST /admin/settings/courier-mode
     *
     * Going live books real courier pickups, dispatches Grab riders and emails
     * customers their shipping confirmation, so it needs a confirmation that a
     * stray POST or a double click cannot supply. Going back to mock is the
     * safe direction and needs none.
     */
    public function courierMode()
    {
        $mode = (string) $this->request->getPost('mode');

        if (! in_array($mode, ['mock', 'live'], true)) {
            return redirect()->to(site_url('admin/settings'))->with('error', 'Unknown courier mode.');
        }

        if ($mode === 'live' && $this->request->getPost('confirm_live') !== '1') {
            return redirect()->to(site_url('admin/settings'))
                ->with('error', 'Live mode was not turned on — tick the confirmation first. It books real shipments and emails customers.');
        }

        MockMode::set($mode === 'mock', (string) (auth()->user()->email ?? 'unknown'));

        return redirect()->to(site_url('admin/settings'))->with('message', $mode === 'live'
            ? 'Live mode is on. Generate AWB now books real shipments and emails the customer.'
            : 'Mock mode is on. Generate AWB invents waybills locally and calls no courier.');
    }
}
