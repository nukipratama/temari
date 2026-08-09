<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Override;
use App\Services\Run\Metrics\HeartRateZones;
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
            'max_hr' => ['required', 'integer', 'between:' . HeartRateZones::MIN_MAX_HR . ',' . HeartRateZones::MAX_MAX_HR],
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
            'max_hr.required' => 'Max HR is required.',
            'max_hr.integer' => 'Max HR must be a number.',
            'max_hr.between' => 'Max HR must be between 120 and 220 bpm.',
            'resting_hr.required' => 'Resting HR is required.',
            'resting_hr.integer' => 'Resting HR must be a number.',
            'resting_hr.between' => 'Resting HR must be between 30 and 90 bpm.',
            'resting_hr.lt' => 'Resting HR must be lower than Max HR.',
            'zones.required' => 'HR zones are required.',
            'zones.size' => 'HR zones must be exactly 5 (Z1 through Z5).',
            'zones.*.lo.required' => 'Zone lower bound is required.',
            'zones.*.lo.integer' => 'Zone lower bound must be a number.',
            'zones.*.hi.required' => 'Zone upper bound is required.',
            'zones.*.hi.integer' => 'Zone upper bound must be a number.',
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
                    'Zone upper bound must be greater than its lower bound.',
                );
            }

            $next = $zones[$index + 1] ?? null;
            if ($next !== null && $hi !== (int) $next['lo']) {
                $validator->errors()->add(
                    "zones.{$index}.hi",
                    'Zones must connect with no gap: this zone\'s upper bound must equal the next zone\'s lower bound.',
                );
            }
        }

        if ((int) $zones[0]['lo'] < $restingHr) {
            $validator->errors()->add(
                'zones.0.lo',
                'Z1 lower bound cannot be below Resting HR.',
            );
        }

        if ((int) $zones[4]['hi'] <= $maxHr) {
            $validator->errors()->add(
                'zones.4.hi',
                'Z5 upper bound must be greater than Max HR.',
            );
        }
    }

    /**
     * @return array<string, array{lo:int, hi:int}>
     */
    public static function deriveZones(int $maxHr, int $restingHr): array
    {
        return HeartRateZones::derive($maxHr, $restingHr);
    }
}
