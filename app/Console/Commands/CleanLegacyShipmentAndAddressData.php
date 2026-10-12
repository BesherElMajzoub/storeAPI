<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Order;
use App\Services\AddressUnitDeduplicator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanLegacyShipmentAndAddressData extends Command
{
    protected $signature = 'data:clean-legacy-shipments-addresses
        {--apply : Actually change the data. Without this flag, only a preview is shown.}';

    protected $description = 'Reset hand-shipped orders to the manual-shipment contract and clear address units already contained in the street.';

    public function handle(AddressUnitDeduplicator $deduplicator): int
    {
        $orders = Order::query()
            ->where('shipment_source', 'manual')
            ->where(function ($query): void {
                $query->whereNull('shipment_status')->orWhere('shipment_status', '!=', 'unknown')
                    ->orWhereNotNull('shipping_service')
                    ->orWhereNotNull('tracking_url')
                    ->orWhereNotNull('estimated_delivery')
                    ->orWhereNotNull('tracking_events');
            })
            ->orderBy('id')
            ->get();

        $addressFixes = Address::query()->orderBy('id')->get()
            ->map(function (Address $address) use ($deduplicator): ?array {
                $lines = trim(implode(' ', array_filter([$address->street, $address->line2])));
                $components = $deduplicator->duplicatedComponents($address->only(['building', 'floor', 'apartment']), $lines);

                return $components === [] ? null : ['address' => $address, 'components' => $components];
            })
            ->filter()
            ->values();

        if ($orders->isEmpty() && $addressFixes->isEmpty()) {
            $this->info('No legacy shipment or address data needs cleaning.');

            return self::SUCCESS;
        }

        if ($orders->isNotEmpty()) {
            $this->info('Hand-shipped orders to reset to status "unknown" / service null:');
            $this->table(
                ['Order', 'Number', 'Status', 'Service'],
                $orders->map(fn (Order $o) => [$o->id, $o->order_number, $o->shipment_status ?? 'null', $o->shipping_service ?? 'null'])->all()
            );
        }

        if ($addressFixes->isNotEmpty()) {
            $this->info('Addresses whose unit is already part of the street:');
            $this->table(
                ['Address', 'User', 'Street', 'Line 2', 'Clear'],
                $addressFixes->map(fn (array $fix) => [
                    $fix['address']->id, $fix['address']->user_id, $fix['address']->street,
                    $fix['address']->line2 ?? '', implode(', ', $fix['components']),
                ])->all()
            );
        }

        if (! $this->option('apply')) {
            $this->warn("{$orders->count()} order(s) and {$addressFixes->count()} address(es) would change. Re-run with --apply to make the change.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($orders, $addressFixes): void {
            $before = [
                'orders' => $orders->mapWithKeys(fn (Order $o) => [$o->id => [
                    'shipment_status' => $o->shipment_status,
                    'shipping_service' => $o->shipping_service,
                    'tracking_url' => $o->tracking_url,
                    'estimated_delivery' => $o->estimated_delivery?->toDateString(),
                    'tracking_events' => $o->tracking_events,
                ]])->all(),
                'addresses' => $addressFixes->mapWithKeys(fn (array $fix) => [
                    $fix['address']->id => $fix['address']->only($fix['components']),
                ])->all(),
            ];

            foreach ($orders as $order) {
                $order->forceFill([
                    'shipment_status' => 'unknown',
                    'shipping_service' => null,
                    'tracking_url' => null,
                    'estimated_delivery' => null,
                    'tracking_events' => null,
                ])->save();
            }

            foreach ($addressFixes as $fix) {
                $fix['address']->forceFill(array_fill_keys($fix['components'], null))->save();
            }

            AuditLog::create([
                'action' => 'cleaned_legacy_shipments_and_addresses',
                'description' => "Reset {$orders->count()} hand-shipped order(s) and cleaned {$addressFixes->count()} address(es) via console",
                'changes' => ['before' => $before],
            ]);
        });

        $this->info("Reset {$orders->count()} order(s) and cleaned {$addressFixes->count()} address(es).");

        return self::SUCCESS;
    }
}
