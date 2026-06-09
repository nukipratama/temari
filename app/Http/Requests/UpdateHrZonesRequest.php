<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Override;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a custom Z1-Z5 heart-rate zone submission for the runner profile.
 *
 * Beyond per-field bounds, {@see withValidator()} enforces the structural
 * invariants the rest of the app relies on: the five zones are ascending and
 * gapless (each zone's `hi` equals the next zone's `lo`), Z1 starts at or above
 * the resting HR, and Z5 extends past the max HR.
 */
class UpdateHrZonesRequest extends FormRequest
{
    /**
     * Breakpoints (as a fraction of heart-rate reserve, Karvonen %HRR) at which
     * each zone begins. These reproduce the {@see config('runner.hr_zones')}
     * defaults at max 180 / rest 55 (Z1 lo 116, Z2 138, Z3 154, Z4 168, Z5 176).
     *
     * @var array<int, float>
     */
    private const array ZONE_BREAKPOINTS = [0.488, 0.664, 0.792, 0.904, 0.968];

    /**
     * High sentinel for Z5's open-ended upper bound, matching the Z5 `hi` in
     * {@see config('runner.hr_zones')}.
     */
    private const int Z5_SENTINEL_HI = 999;

    /**
     * @var array<int, string>
     */
    private const array ZONE_KEYS = ['Z1', 'Z2', 'Z3', 'Z4', 'Z5'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'max_hr' => ['required', 'integer', 'between:120,220'],
            'resting_hr' => ['required', 'integer', 'between:30,90', 'lt:max_hr'],
            'zones' => ['required', 'array', 'size:5'],
            'zones.*.lo' => ['required', 'integer'],
            'zones.*.hi' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function messages(): array
    {
        return [
            'max_hr.required' => 'Max HR wajib diisi.',
            'max_hr.integer' => 'Max HR harus berupa angka.',
            'max_hr.between' => 'Max HR harus di antara 120 dan 220 bpm.',
            'resting_hr.required' => 'Resting HR wajib diisi.',
            'resting_hr.integer' => 'Resting HR harus berupa angka.',
            'resting_hr.between' => 'Resting HR harus di antara 30 dan 90 bpm.',
            'resting_hr.lt' => 'Resting HR harus lebih kecil dari Max HR.',
            'zones.required' => 'Zona HR wajib diisi.',
            'zones.size' => 'Zona HR harus tepat 5 (Z1 sampai Z5).',
            'zones.*.lo.required' => 'Batas bawah zona wajib diisi.',
            'zones.*.lo.integer' => 'Batas bawah zona harus berupa angka.',
            'zones.*.hi.required' => 'Batas atas zona wajib diisi.',
            'zones.*.hi.integer' => 'Batas atas zona harus berupa angka.',
        ];
    }

    /**
     * Enforce the cross-field zone invariants once the per-field rules pass:
     * ascending and gapless bands, Z1 not starting below resting HR, and Z5
     * reaching past max HR.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->getData();
            /** @var array<int, array{lo:int, hi:int}> $zones */
            $zones = array_values($data['zones'] ?? []);

            if (count($zones) !== 5) {
                return;
            }

            $this->enforceZoneInvariants(
                $validator,
                $zones,
                (int) ($data['max_hr'] ?? 0),
                (int) ($data['resting_hr'] ?? 0),
            );
        });
    }

    /**
     * @param  array<int, array{lo:int, hi:int}>  $zones
     */
    private function enforceZoneInvariants(Validator $validator, array $zones, int $maxHr, int $restingHr): void
    {
        foreach ($zones as $index => $zone) {
            $lo = (int) $zone['lo'];
            $hi = (int) $zone['hi'];

            if ($hi <= $lo) {
                $validator->errors()->add(
                    "zones.{$index}.hi",
                    'Batas atas zona harus lebih besar dari batas bawah.',
                );
            }

            $next = $zones[$index + 1] ?? null;
            if ($next !== null && $hi !== (int) $next['lo']) {
                $validator->errors()->add(
                    "zones.{$index}.hi",
                    'Zona harus nyambung tanpa celah: batas atas zona ini harus sama dengan batas bawah zona berikutnya.',
                );
            }
        }

        if ((int) $zones[0]['lo'] < $restingHr) {
            $validator->errors()->add(
                'zones.0.lo',
                'Batas bawah Z1 tidak boleh di bawah Resting HR.',
            );
        }

        if ((int) $zones[4]['hi'] <= $maxHr) {
            $validator->errors()->add(
                'zones.4.hi',
                'Batas atas Z5 harus lebih besar dari Max HR.',
            );
        }
    }

    /**
     * Derive Z1-Z5 bands from max/resting HR using the Karvonen %HRR
     * breakpoints. Each zone's `lo` is `round(resting + pct * (max - resting))`;
     * its `hi` is the next zone's `lo`, with Z5's `hi` fixed at the open-ended
     * sentinel. Reusable for the live preview and for seeding defaults.
     *
     * @return array<string, array{lo:int, hi:int}>
     */
    public static function deriveZones(int $maxHr, int $restingHr): array
    {
        $reserve = $maxHr - $restingHr;

        $los = array_map(
            static fn (float $pct): int => (int) round($restingHr + $pct * $reserve),
            self::ZONE_BREAKPOINTS,
        );

        $zones = [];
        foreach (self::ZONE_KEYS as $index => $key) {
            $isLast = $index === \count(self::ZONE_KEYS) - 1;
            $zones[$key] = [
                'lo' => $los[$index],
                'hi' => $isLast ? self::Z5_SENTINEL_HI : $los[$index + 1],
            ];
        }

        return $zones;
    }
}
