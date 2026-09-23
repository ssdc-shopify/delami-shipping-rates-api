<?php

namespace App\Libraries\Tracking;

/**
 * The /api/storefront/track response body — built in one place.
 *
 * The storefront endpoint and the admin Track Simulator both render through
 * here, so what the simulator shows is, by construction, what a storefront
 * receives: the same fields, the same order, the same null-until-shipped.
 */
final class TrackingPayload
{
    /** The single answer for "no such order" and "wrong email" alike. */
    public const NOT_FOUND = ['error' => 'no shipment found for that reference and email'];

    /**
     * The 200 body for a ShipmentLookup match. Only an order with a tracking
     * number is traced with its courier; without one, shipment is null,
     * order.status says where the order is, and order.note says there is no
     * tracking number yet.
     *
     * @param array{awb: array<string, mixed>|null, summary: array<string, string>} $match
     */
    public static function build(array $match): array
    {
        $summary  = $match['summary'];
        $tracking = ! empty($match['awb']['waybill'])
            ? (new TrackingService())->track($match['awb'], ['destination' => $summary['destination']])
            : null;

        return [
            'order' => [
                'name'        => $summary['orderName'],
                // awaiting_payment | processing | shipped | cancelled
                'status'      => $summary['status'],
                'statusLabel' => $summary['statusLabel'],
                'recipient'   => $summary['recipient'],
                'destination' => $summary['destination'],
                'service'     => $summary['service'],
                'serviceCode' => $summary['serviceCode'],
                'orderedAt'   => $summary['orderedAt'],
                'bookedAt'    => $summary['bookedAt'] === '' ? null : $summary['bookedAt'],
                // "This order does not have a tracking number yet." — or null.
                'note'        => $summary['note'] === '' ? null : $summary['note'],
            ],
            'shipment' => $tracking === null ? null : self::shipment($tracking),
            'stages'   => self::stages(),
        ];
    }

    /**
     * One traced shipment, as the API projects it — deliberately explicit, so
     * a storefront cannot come to depend on internals of TrackingService.
     */
    public static function shipment(array $tracking): array
    {
        return [
            'courier'     => $tracking['courier'],
            'courierName' => $tracking['courierName'],
            'waybill'     => $tracking['waybill'],
            'trackingUrl' => $tracking['trackingUrl'],
            'stage'       => $tracking['stage'],
            'stageLabel'  => $tracking['stageLabel'],
            // 'mock' tells a storefront the scans are simulated, so it can
            // say so rather than showing a demo parcel as a real one.
            'source'      => $tracking['source'],
            'note'        => $tracking['note'],
            // When the courier was last asked; stale = it did not answer this
            // time and these are the last scans it gave.
            'checkedAt'   => $tracking['checkedAt'],
            'stale'       => $tracking['stale'],
            'events'      => $tracking['events'],
        ];
    }

    /**
     * The stage vocabulary, so a storefront can render its own progress bar
     * without hardcoding a list this app might extend.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function stages(): array
    {
        return array_map(
            static fn (string $stage) => ['key' => $stage, 'label' => TrackingService::STAGE_LABELS[$stage]],
            TrackingService::STAGE_FLOW,
        );
    }
}
