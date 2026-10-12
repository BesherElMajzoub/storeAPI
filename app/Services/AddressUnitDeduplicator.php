<?php

namespace App\Services;

/**
 * Detects building / floor / apartment values that the street lines already
 * contain, so a unit is stored once instead of showing up as "APT 2, Apt 2".
 */
class AddressUnitDeduplicator
{
    private const COMPONENTS = ['building', 'floor', 'apartment'];

    /**
     * Components of $address that are already spelled out in $canonicalLines.
     *
     * @return list<string>
     */
    public function duplicatedComponents(array $address, string $canonicalLines): array
    {
        return array_values(array_filter(
            self::COMPONENTS,
            fn (string $component) => $this->appearsIn($component, $address[$component] ?? null, $canonicalLines)
        ));
    }

    public function appearsIn(string $component, mixed $value, string $canonicalLines): bool
    {
        $value = $this->normalizedText($value);
        $lines = $this->normalizedText($canonicalLines);
        if ($value === '' || $lines === '') {
            return false;
        }

        $labels = match ($component) {
            'apartment' => ['apartment', 'apt', 'unit'],
            'floor' => ['floor', 'fl'],
            'building' => ['building', 'bldg'],
            default => [],
        };

        foreach ($labels as $label) {
            if (preg_match('/(?:^| )'.preg_quote($label, '/').' '.preg_quote($value, '/').'(?: |$)/', $lines)) {
                return true;
            }
        }

        if ($component === 'apartment' && preg_match('/(?:^| )'.preg_quote($value, '/').'(?: |$)/', $lines)
            && preg_match('/#\s*'.preg_quote($value, '/').'(?:\b|$)/i', $canonicalLines)) {
            return true;
        }

        return $component === 'building' && mb_strlen($value) >= 3 && str_contains($lines, $value);
    }

    public function normalizedText(mixed $value): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower((string) $value))) ?? '');
    }
}
