<?php

namespace App\Services;

use App\Exceptions\ShippingProviderException;
use App\Exceptions\ShippingValidationException;
use App\Models\Product;
use App\Models\ShippingRateQuote;
use Illuminate\Support\Collection;

class ShippingQuoteService
{
    public function __construct(private readonly EasyPostService $easyPost) {}

    public function quote(array $address, array $items): array
    {
        $normalizedAddress = $this->normalizeAddress($address);
        $normalizedItems = $this->normalizeItems($items);
        $parcel = $this->buildParcel($normalizedItems);

        try {
            $shipment = $this->easyPost->getShippingRates($normalizedAddress, $parcel);
        } catch (\Throwable $e) {
            throw new ShippingProviderException('Shipping rates are temporarily unavailable.', previous: $e);
        }

        if (collect($shipment->rates ?? [])->isEmpty()) {
            throw new ShippingValidationException('No shipping options are available for this address.', 'unserviceable_address');
        }

        $addressHash = $this->hash($this->addressFingerprint($normalizedAddress));
        $itemsHash = $this->hash($this->itemFingerprint($normalizedItems));
        $parcelHash = $this->hash($parcel);
        $expiresAt = now()->addMinutes((int) config('services.easypost.quote_ttl_minutes', 15));
        $result = [];

        foreach ($shipment->rates as $rate) {
            $quote = ShippingRateQuote::updateOrCreate(
                ['rate_id' => $rate->id],
                [
                    'shipment_id' => $shipment->id,
                    'carrier' => $rate->carrier,
                    'service' => $rate->service,
                    'amount' => $rate->rate,
                    'currency' => strtoupper($rate->currency ?? 'USD'),
                    'eta_days' => isset($rate->delivery_days) ? (int) $rate->delivery_days : null,
                    'address_hash' => $addressHash,
                    'items_hash' => $itemsHash,
                    'parcel_hash' => $parcelHash,
                    'expires_at' => $expiresAt,
                    'consumed_at' => null,
                    'order_id' => null,
                ]
            );

            $result[] = [
                'rate_id' => $quote->rate_id,
                'carrier' => $quote->carrier,
                'service' => $quote->service,
                'amount' => (float) $quote->amount,
                'eta_days' => $quote->eta_days,
            ];
        }

        return $result;
    }

    public function storeLegacyQuotes(object $shipment, array $address, array $parcel): void
    {
        $normalizedAddress = $this->normalizeAddress($address);
        $normalizedParcel = $this->normalizeParcel($parcel);
        $expiresAt = now()->addMinutes((int) config('services.easypost.quote_ttl_minutes', 15));

        foreach ($shipment->rates ?? [] as $rate) {
            ShippingRateQuote::updateOrCreate(['rate_id' => $rate->id], [
                'shipment_id' => $shipment->id,
                'carrier' => $rate->carrier,
                'service' => $rate->service,
                'amount' => $rate->rate,
                'currency' => strtoupper($rate->currency ?? 'USD'),
                'eta_days' => isset($rate->delivery_days) ? (int) $rate->delivery_days : null,
                'address_hash' => $this->hash($this->addressFingerprint($normalizedAddress)),
                'items_hash' => null,
                'parcel_hash' => $this->hash($normalizedParcel),
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'order_id' => null,
            ]);
        }
    }

    public function validateForCheckout(string $rateId, array $address, array $items): ShippingRateQuote
    {
        $quote = ShippingRateQuote::where('rate_id', $rateId)->first();
        if (! $quote || $quote->consumed_at || $quote->expires_at->isPast()) {
            throw new ShippingValidationException('The selected shipping rate is invalid or expired.', 'invalid_shipping_rate');
        }

        $normalizedItems = $this->normalizeItems($items);
        $parcel = $this->buildParcel($normalizedItems);
        if (! hash_equals($quote->address_hash, $this->hash($this->addressFingerprint($this->normalizeAddress($address))))) {
            throw new ShippingValidationException('The shipping address changed. Request new shipping rates.', 'shipping_address_changed');
        }

        if ($quote->items_hash !== null && ! hash_equals($quote->items_hash, $this->hash($this->itemFingerprint($normalizedItems)))) {
            throw new ShippingValidationException('The cart changed. Request new shipping rates.', 'shipping_items_changed');
        }

        if (! hash_equals($quote->parcel_hash, $this->hash($parcel))) {
            throw new ShippingValidationException('The shipment package changed. Request new shipping rates.', 'shipping_parcel_changed');
        }

        try {
            $rate = $this->easyPost->retrieveRate($rateId);
        } catch (\Throwable $e) {
            throw new ShippingProviderException('The shipping provider could not verify the selected rate.', previous: $e);
        }

        if (($rate->shipment_id ?? null) !== $quote->shipment_id || strtoupper($rate->currency ?? '') !== $quote->currency) {
            throw new ShippingValidationException('The selected shipping rate does not belong to this shipment.', 'invalid_shipping_rate');
        }

        $quote->forceFill([
            'amount' => (float) $rate->rate,
            'carrier' => $rate->carrier,
            'service' => $rate->service,
            'eta_days' => isset($rate->delivery_days) ? (int) $rate->delivery_days : null,
        ])->save();

        return $quote->refresh();
    }

    public function lockAvailableQuote(int $quoteId): ShippingRateQuote
    {
        $quote = ShippingRateQuote::lockForUpdate()->findOrFail($quoteId);
        if ($quote->consumed_at || $quote->expires_at->isPast()) {
            throw new ShippingValidationException('The selected shipping rate is no longer available.', 'invalid_shipping_rate');
        }

        return $quote;
    }

    public function normalizeAddress(array $address): array
    {
        $country = strtoupper(trim((string) ($address['country'] ?? '')));
        if (! in_array($country, config('services.easypost.supported_countries', ['US']), true)) {
            throw new ShippingValidationException('Shipping is currently available only within the United States.', 'unsupported_destination');
        }

        return [
            'name' => trim((string) ($address['name'] ?? $address['full_name'] ?? 'Customer')),
            'street1' => trim((string) ($address['line1'] ?? $address['street1'] ?? $address['street'] ?? '')),
            'street2' => trim((string) ($address['line2'] ?? $address['street2'] ?? $address['apartment'] ?? '')),
            'city' => trim((string) ($address['city'] ?? '')),
            'state' => strtoupper(trim((string) ($address['state'] ?? ''))),
            'zip' => strtoupper(trim((string) ($address['postal_code'] ?? $address['zip'] ?? ''))),
            'country' => $country,
            'phone' => trim((string) ($address['phone'] ?? '')),
        ];
    }

    public function buildParcel(Collection $items): array
    {
        $weight = 0.0;
        $volume = 0.0;
        $largestDimensions = [0.0, 0.0, 0.0];

        foreach ($items as $line) {
            $source = $line['variant'] ?? $line['product'];
            $product = $line['product'];
            $dimensions = [];
            foreach (['length_in', 'width_in', 'height_in'] as $field) {
                $value = $source?->{$field} ?? $product->{$field};
                if ($value === null || (float) $value <= 0) {
                    throw new ShippingValidationException("Product SKU {$product->sku} is missing shipping dimensions.", 'shipping_configuration');
                }
                $dimensions[] = (float) $value;
            }

            $itemWeight = $source?->weight_oz ?? $product->weight_oz;
            if ($itemWeight === null || (float) $itemWeight <= 0) {
                throw new ShippingValidationException("Product SKU {$product->sku} is missing shipping weight.", 'shipping_configuration');
            }

            rsort($dimensions, SORT_NUMERIC);
            $quantity = $line['quantity'];
            $weight += (float) $itemWeight * $quantity;
            $volume += array_product($dimensions) * $quantity;
            foreach ($dimensions as $index => $dimension) {
                $largestDimensions[$index] = max($largestDimensions[$index], $dimension);
            }
        }

        $packages = collect(config('services.easypost.packages', []))
            ->sortBy(fn (array $package) => $package['length'] * $package['width'] * $package['height']);

        foreach ($packages as $package) {
            $boxDimensions = [(float) $package['length'], (float) $package['width'], (float) $package['height']];
            rsort($boxDimensions, SORT_NUMERIC);
            $fitsDimensions = collect($largestDimensions)->every(fn (float $value, int $index) => $value <= $boxDimensions[$index]);
            $fitsVolume = $volume <= array_product($boxDimensions);
            $fitsWeight = $weight <= (float) $package['max_weight'];

            if ($fitsDimensions && $fitsVolume && $fitsWeight) {
                return [
                    'length' => (float) $package['length'],
                    'width' => (float) $package['width'],
                    'height' => (float) $package['height'],
                    'weight' => round($weight, 2),
                ];
            }
        }

        throw new ShippingValidationException('The cart does not fit an available shipping package.', 'shipping_configuration');
    }

    private function normalizeItems(array $items): Collection
    {
        $products = Product::with('variants')->whereIn('id', collect($items)->pluck('product_id')->unique())->get()->keyBy('id');

        return collect($items)->map(function (array $line) use ($products) {
            $product = $products->get((int) $line['product_id']);
            if (! $product || $product->status !== 'published') {
                throw new ShippingValidationException('A cart product is unavailable.', 'unavailable_product');
            }

            $variant = null;
            if (! empty($line['variant_id'])) {
                $variant = $product->variants->firstWhere('id', (int) $line['variant_id']);
                if (! $variant) {
                    throw new ShippingValidationException('A selected variant does not belong to its product.', 'invalid_variant');
                }
            }

            $quantity = (int) $line['quantity'];
            $available = $variant ? $variant->stock_qty : $product->stock_qty;
            if ($quantity < 1 || $quantity > $available) {
                throw new ShippingValidationException('A cart item does not have enough stock.', 'insufficient_stock');
            }

            return compact('product', 'variant', 'quantity');
        });
    }

    private function itemFingerprint(Collection $items): array
    {
        return $items->map(fn (array $line) => [
            'product_id' => $line['product']->id,
            'variant_id' => $line['variant']?->id,
            'quantity' => $line['quantity'],
        ])->sortBy(fn (array $line) => sprintf('%010d-%010d', $line['product_id'], $line['variant_id'] ?? 0))->values()->all();
    }

    private function normalizeParcel(array $parcel): array
    {
        return collect(['length', 'width', 'height', 'weight'])
            ->mapWithKeys(fn (string $field) => [$field => round((float) ($parcel[$field] ?? 0), 2)])
            ->all();
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function addressFingerprint(array $address): array
    {
        return collect($address)->only(['street1', 'street2', 'city', 'state', 'zip', 'country'])->all();
    }
}
