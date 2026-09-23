<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\OrderModel;
use App\Models\StoreModel;

/**
 * Order list.
 *
 * Built from the orders Shopify has sent this site through the orders/create
 * and orders/updated webhooks, one store at a time. Nothing here queries
 * Shopify: a store's orders appear only on the sites that receive its order
 * webhooks (Admin → Stores → Edit settings), which is how an operator decides
 * where each store's orders are handled.
 *
 * Generate AWB still reads the live order from Shopify before booking, so a
 * stale row can never ship to an out-of-date address.
 */
class Orders extends BaseController
{
    /** Selectable page sizes; anything else falls back to the first. */
    public const PER_PAGE_OPTIONS = [25, 50, 100, 200];

    public function index()
    {
        $search = trim((string) $this->request->getGet('q'));

        $perPage = (int) $this->request->getGet('per_page');
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::PER_PAGE_OPTIONS[0];
        }

        $stores = model(StoreModel::class)->where('active', 1)->orderBy('slug')->findAll();
        $store  = $this->selectedStore($stores);

        $view = [
            'search'  => $search,
            'perPage' => $perPage,
            'stores'  => $stores,
            'store'   => $store,
            'orders'  => [],
            'pager'   => null,
            'total'   => 0,
            // Base for deep-linking an order into the Shopify admin.
            'shopUrl' => $store === null ? null : StoreModel::adminUrl($store),
        ];

        if ($store === null) {
            return view('admin/orders/index', $view);
        }

        return view('admin/orders/index', array_merge($view, $this->orders($store, $search, $perPage)));
    }

    /**
     * The store being viewed. ?store={slug} picks one; anything unrecognised
     * falls back to the first rather than showing an empty page, so a stale
     * bookmark still lands somewhere useful.
     */
    private function selectedStore(array $stores): ?array
    {
        $slug = (string) $this->request->getGet('store');

        foreach ($stores as $store) {
            if ($store['slug'] === $slug) {
                return $store;
            }
        }

        return $stores[0] ?? null;
    }

    /**
     * One page of the store's orders, newest first, each with its airway bill
     * (if any) from the same query.
     */
    private function orders(array $store, string $search, int $perPage): array
    {
        $model = model(OrderModel::class);

        $model->select('orders.*, airwaybills.id AS awb_id, airwaybills.waybill AS awb_waybill,'
                . ' airwaybills.courier AS awb_courier, airwaybills.status AS awb_status')
            ->join('airwaybills', 'airwaybills.order_id = orders.order_id', 'left')
            ->where('orders.store_id', $store['id']);

        if ($search !== '') {
            // "#1001" and "1001" both find order #1001.
            $term = ltrim($search, '#');

            $model->groupStart()
                ->like('orders.order_name', $term)
                ->orLike('orders.customer_name', $term)
                ->orLike('orders.ship_name', $term)
                ->orLike('orders.email', $term)
                ->orLike('orders.ship_zip', $term)
                ->orLike('airwaybills.waybill', $term)
                ->groupEnd();
        }

        $rows = $model->orderBy('orders.ordered_at', 'DESC')
            ->orderBy('orders.id', 'DESC')
            ->paginate($perPage);

        $model->pager?->only(['q', 'per_page', 'store']);

        foreach ($rows as $i => $row) {
            $rows[$i]['awb'] = $row['awb_id'] === null ? null : [
                'waybill' => $row['awb_waybill'],
                'courier' => $row['awb_courier'],
                'status'  => $row['awb_status'],
            ];
        }

        return [
            'orders' => $rows,
            'pager'  => $model->pager,
            'total'  => $model->pager?->getTotal() ?? count($rows),
        ];
    }
}
