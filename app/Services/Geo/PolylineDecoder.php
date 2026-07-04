<?php

declare(strict_types=1);

namespace App\Services\Geo;

// https://developers.google.com/maps/documentation/utilities/polylinealgorithm
class PolylineDecoder
{
    /**
     * Decode a full Google-encoded polyline into its ordered [lat, lng] points.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function decode(string $polyline): array
    {
        if ($polyline === '') {
            return [];
        }

        $index = 0;
        $length = strlen($polyline);
        $lat = 0;
        $lng = 0;
        $points = [];

        while ($index < $length) {
            $latDelta = $this->decodeNext($polyline, $index, $length);
            if ($latDelta === null) {
                break;
            }
            $lat += $latDelta;

            $lngDelta = $this->decodeNext($polyline, $index, $length);
            if ($lngDelta === null) {
                break;
            }
            $lng += $lngDelta;

            $points[] = [$lat / 1e5, $lng / 1e5];
        }

        return $points;
    }

    /** @return array{0: float, 1: float}|null */
    public function firstPoint(string $polyline): ?array
    {
        if ($polyline === '') {
            return null;
        }

        $index = 0;
        $length = strlen($polyline);
        $lat = 0;
        $lng = 0;

        $latDelta = $this->decodeNext($polyline, $index, $length);
        if ($latDelta === null) {
            return null;
        }
        $lat += $latDelta;

        $lngDelta = $this->decodeNext($polyline, $index, $length);
        if ($lngDelta === null) {
            return null;
        }
        $lng += $lngDelta;

        return [$lat / 1e5, $lng / 1e5];
    }

    private function decodeNext(string $polyline, int &$index, int $length): ?int
    {
        $result = 0;
        $shift = 0;
        while (true) {
            if ($index >= $length) {
                return null;
            }
            $byte = ord($polyline[$index]) - 63;
            $index++;
            $result |= ($byte & 0x1F) << $shift;
            $shift += 5;
            if ($byte < 0x20) {
                break;
            }
        }

        // ZigZag undo: low bit is sign, rest is magnitude.
        return ($result & 1) ? ~($result >> 1) : ($result >> 1);
    }
}
