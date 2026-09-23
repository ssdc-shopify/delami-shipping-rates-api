<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Awb\AwbService;
use App\Libraries\Awb\MockMode;
use App\Libraries\Labels\LabelAssets;
use App\Models\AirwaybillModel;
use App\Models\OrderModel;
use App\Models\StoreModel;

class Awb extends BaseController
{
    /**
     * The store to act against: the one named in the request, else the one
     * the order itself came from.
     *
     * Waybills carry no store of their own, so leaving it implicit meant
     * booking against whichever store AwbService picks first — wrong, and
     * silently so, as soon as a second store is connected. Every order stored
     * from a webhook records its store, so an unnamed request resolves to the
     * right one. Null (an order this site never received) still falls back to
     * AwbService's default, which is right for a single store.
     */
    private function store(?string $slug, string $orderId): ?array
    {
        $slug = trim((string) $slug);
        if ($slug !== '') {
            return model(StoreModel::class)->findBySlug($slug);
        }

        $storeId = model(OrderModel::class)->findByOrderId($orderId)['store_id'] ?? null;

        return empty($storeId) ? null : model(StoreModel::class)->find($storeId);
    }

    /** Back to the order list, for the same store. */
    private function ordersUrl(?array $store): string
    {
        return site_url('admin/orders') . ($store === null ? '' : '?' . http_build_query(['store' => $store['slug']]));
    }

    /**
     * POST /admin/awb/generate/{legacyOrderId}
     *
     * Books the shipment with the courier the shopper's chosen rate maps to,
     * stores the waybill, and marks the Shopify order fulfilled with
     * tracking. Idempotent — an order that already has a waybill is left
     * untouched. The Orders list no longer shows a button for this (nor for
     * print() below); both routes are kept and work when called directly.
     */
    public function generate(string $orderId)
    {
        $store = $this->store($this->request->getPost('store'), $orderId);

        try {
            $service = new AwbService($store);
            $service->ensureForOrder($orderId);
            $label = $service->generate($orderId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'AWB generation failed: ' . $e->getMessage());
        }

        $awb = strtoupper($label['courier']) . ' AWB ' . $label['awb'];

        // Never claim the order was fulfilled unless Shopify actually said so.
        if (($label['fulfillment_issue'] ?? null) !== null) {
            return redirect()->to($this->ordersUrl($store))
                ->with('error', $awb . ' was created, but ' . $label['fulfillment_issue']
                    . ' Press Generate AWB again on that order to retry.');
        }

        $message = MockMode::enabled()
            ? 'MOCK ' . $awb . ' created and order fulfilled on Shopify — no courier was called, customer not notified.'
            : $awb . ' generated, order fulfilled, and the shipping confirmation emailed to the customer.';

        return redirect()->to($this->ordersUrl($store))->with('message', $message);
    }

    /**
     * GET /admin/awb/print/{legacyOrderId}
     *
     * Renders the printable label. Genuinely read-only: it reads the order
     * and formats it, and touches nothing.
     *
     * It used to call generate(). That could not mint a second shipment —
     * the empty-waybill guard below saw to that — but generate() also calls
     * fulfill(), which is a write to Shopify. A GET must never do that: link
     * prefetch, a crawler, or a plain reload would fulfill the order, and
     * once mock mode is off that emails the customer a shipping
     * confirmation. labelData() gives the same array without the write.
     */
    public function print(string $orderId)
    {
        try {
            $service = new AwbService($this->store($this->request->getGet('store'), $orderId));

            $row = model(AirwaybillModel::class)->findByOrderId($orderId);
            if ($row === null || empty($row['waybill'])) {
                return view('admin/awb/error', [
                    'message' => 'No AWB for this order yet — generate it from the Orders list first.',
                ]);
            }

            $order = $service->shopify()->getOrderForAwb((string) $row['order_id']);
            if ($order === null) {
                return view('admin/awb/error', [
                    'message' => "Shopify order {$row['order_id']} could not be loaded.",
                ]);
            }

            $label = $service->labelData($row, $order);
        } catch (\Throwable $e) {
            return view('admin/awb/error', ['message' => $e->getMessage()]);
        }

        $label['barcode'] = LabelAssets::code128((string) $label['awb']);
        if ($label['courier'] === 'spx') {
            $label['qr'] = LabelAssets::qr((string) $label['awb']);
        }
        $label['couriersConfig'] = config('Couriers');

        $view = match ($label['courier']) {
            'ninja' => 'admin/awb/label_ninja',
            'spx'   => 'admin/awb/label_spx',
            'grab'  => 'admin/awb/label_grab',
            default => 'admin/awb/label_jne',
        };

        return view($view, $label);
    }
}
