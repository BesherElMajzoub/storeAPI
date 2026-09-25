<?php

namespace App\Services;

use App\Models\Setting;

class FreeShippingService
{
    private const ENABLED_KEY = 'shipping.free_shipping_enabled';

    private const THRESHOLD_KEY = 'shipping.free_shipping_threshold';

    // Business default until an admin changes it via PUT /admin/settings/shipping:
    // orders of $100 or more ship free.
    private const DEFAULT_ENABLED = true;

    private const DEFAULT_THRESHOLD = 100.0;

    public function isEnabled(): bool
    {
        return (bool) Setting::getValue(self::ENABLED_KEY, self::DEFAULT_ENABLED);
    }

    public function threshold(): ?float
    {
        $value = Setting::getValue(self::THRESHOLD_KEY, self::DEFAULT_THRESHOLD);

        return $value === null ? null : (float) $value;
    }

    public function subtotalQualifies(float $subtotal): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $threshold = $this->threshold();

        return $threshold !== null && $subtotal >= $threshold;
    }

    public function update(bool $enabled, ?float $threshold): void
    {
        Setting::setValue(self::ENABLED_KEY, $enabled, 'boolean', 'shipping');
        Setting::setValue(self::THRESHOLD_KEY, $threshold, 'string', 'shipping');
    }
}
