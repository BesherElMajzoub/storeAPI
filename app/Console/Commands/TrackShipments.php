<?php

namespace App\Console\Commands;

use App\Jobs\SendAdminAlert;
use App\Models\Order;
use App\Services\EasyPostService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TrackShipments extends Command
{
    protected $signature = 'shipping:track';

    protected $description = 'Poll EasyPost tracking API for shipped orders and update status.';

    protected EasyPostService $easyPostService;

    public function __construct(EasyPostService $easyPostService)
    {
        parent::__construct();
        $this->easyPostService = $easyPostService;
    }

    public function handle(): int
    {
        $this->info('Starting tracking updates for shipped orders...');

        // Query orders that are 'shipped' and have an EasyPost shipment ID
        $orders = Order::where('status', 'shipped')
            ->whereNotNull('easypost_shipment_id')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No shipped orders to track.');
            return self::SUCCESS;
        }

        $this->info("Found {$orders->count()} order(s) to track.");

        $client = new \EasyPost\EasyPostClient(config('services.easypost.api_key'));

        foreach ($orders as $order) {
            $this->line("Checking Order #{$order->order_number} (Shipment ID: {$order->easypost_shipment_id})...");

            try {
                // Retrieve the shipment and its tracker
                $shipment = $client->shipment->retrieve($order->easypost_shipment_id);
                $tracker = $shipment->tracker;

                if (!$tracker) {
                    $this->warn("No tracker found for Order #{$order->order_number} yet.");
                    continue;
                }

                $trackerStatus = $tracker->status;
                $this->line("  EasyPost Status: {$trackerStatus}");

                if ($trackerStatus === 'delivered' && $order->status !== 'delivered') {
                    $order->status = 'delivered';
                    $order->save();

                    $this->info("  Order #{$order->order_number} updated to DELIVERED!");
                    SendAdminAlert::dispatch("🎉 Order #{$order->order_number} has been DELIVERED! Polled via scheduler. Tracking: {$order->tracking_number}");
                } elseif (in_array($trackerStatus, ['failure', 'return_to_sender'])) {
                    $this->error("  Shipping failure/returned for Order #{$order->order_number}!");
                    SendAdminAlert::dispatch("⚠️ EasyPost Polling Alert: Order #{$order->order_number} shipping status marked as: {$trackerStatus}. Tracking: {$order->tracking_number}");
                }
            } catch (Exception $e) {
                $this->error("  Error checking Order #{$order->order_number}: " . $e->getMessage());
                Log::error("TrackShipments failed for Order #{$order->id}: " . $e->getMessage());
            }
        }

        $this->info('Tracking updates complete!');
        return self::SUCCESS;
    }
}
