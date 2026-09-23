<?php

namespace App\Models;

use CodeIgniter\Model;

class AirwaybillModel extends Model
{
    public const COURIER_JNE   = 'jne';
    public const COURIER_LJR   = 'ljr';
    public const COURIER_NINJA = 'ninja';
    public const COURIER_SPX   = 'spx';
    public const COURIER_GRAB  = 'grab';

    public const STATUS_PENDING   = 0;
    public const STATUS_FULFILLED = 1;

    protected $table         = 'airwaybills';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'order_id', 'courier', 'waybill', 'tracking_url',
        'status', 'spx_order_ref', 'sort_code', 'third_sort_code',
        'booking_started_at',
    ];

    /**
     * How long a booking claim is honoured before another attempt may take
     * it. Comfortably longer than the couriers' 30s HTTP timeout, so it can
     * only ever expire on a request that died outright (PHP fatal, worker
     * killed, deploy mid-booking) rather than on one still waiting.
     */
    public const BOOKING_CLAIM_SECONDS = 300;

    public function findByOrderId(int|string $orderId): ?array
    {
        return $this->where('order_id', $orderId)->first();
    }

    /**
     * Take the exclusive right to book this shipment. True means the caller
     * owns it; false means another request already does, and this one must
     * not call the courier.
     *
     * The guard is the UPDATE's own WHERE clause, evaluated by the database
     * while the row is locked, so two callers cannot both satisfy it. Doing
     * the same check in PHP first and then updating would reopen exactly the
     * gap this exists to close.
     */
    public function claimForBooking(int $id): bool
    {
        $expiry = date('Y-m-d H:i:s', time() - self::BOOKING_CLAIM_SECONDS);

        $this->db->table($this->table)
            ->where('id', $id)
            // Never re-book something already booked, whatever the claim says.
            ->where('waybill IS NULL', null, false)
            ->groupStart()
                ->where('booking_started_at IS NULL', null, false)
                ->orWhere('booking_started_at <', $expiry)
            ->groupEnd()
            ->update(['booking_started_at' => date('Y-m-d H:i:s')]);

        return $this->db->affectedRows() === 1;
    }

    /**
     * Give the claim back, so a retry does not have to wait it out.
     *
     * Only safe when the courier cannot have created a shipment. If a booking
     * request was actually sent and the outcome is unknown, the claim must be
     * left to expire instead — releasing it there is how one lost response
     * turns into two parcels.
     */
    public function releaseBooking(int $id): void
    {
        $this->db->table($this->table)
            ->where('id', $id)
            ->where('waybill IS NULL', null, false)
            ->update(['booking_started_at' => null]);
    }

    /**
     * Pending shipments created within the last $days days (tracking window).
     */
    public function pendingSince(int $days = 3): array
    {
        return $this->where('status', self::STATUS_PENDING)
            ->where('waybill IS NOT NULL')
            ->where('created_at >=', date('Y-m-d H:i:s', strtotime("-{$days} days")))
            ->findAll();
    }
}
