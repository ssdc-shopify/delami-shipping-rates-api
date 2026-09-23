<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\StoreModel;

/**
 * Dashboard: the stores this app is connected to and how each is configured.
 *
 * Deliberately carries no order counts. Every order figure belongs to one
 * store, and a dashboard that spans all of them can only show a total that
 * matches no single list on the Orders page.
 */
class Dashboard extends BaseController
{
    public function index()
    {
        return view('admin/dashboard', [
            'stores' => model(StoreModel::class)->findAll(),
        ]);
    }
}
