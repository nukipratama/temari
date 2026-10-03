import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

const DISTANCE_PRESETS = [
    { label: '5K', km: 5 },
    { label: '10K', km: 10 },
    { label: 'half', km: 21.1 },
    { label: 'marathon', km: 42.2 },
] as const;

/** The race-distance presets on onboarding's goal step. */
export default function DistancePresets({
    km,
    onChange,
    labelledBy,
    className,
}: Readonly<{
    km: number;
    onChange: (km: number) => void;
    labelledBy: string;
    className?: string;
}>) {
    return (
        <ToggleGroup
            value={String(km)}
            onValueChange={(next) => onChange(Number(next))}
            aria-labelledby={labelledBy}
            className={className}
        >
            {DISTANCE_PRESETS.map((preset) => (
                <ToggleGroupItem key={preset.label} value={String(preset.km)}>
                    {preset.label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}
