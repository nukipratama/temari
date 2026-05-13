<?php

declare(strict_types=1);

namespace App\Services\Geo;

/**
 * Plain DTO for a successfully reverse-geocoded location. `name` is the
 * UI-ready display string (kecamatan → kabupaten/kota → provinsi →
 * negara, comma-separated); `country` is the ISO-3166 alpha-2 code
 * (uppercased) for cheap downstream filtering.
 */
final readonly class ResolvedLocation
{
    public function __construct(
        public string $name,
        public ?string $country,
    ) {
    }
}
