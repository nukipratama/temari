import Eyebrow from '@/components/ui/Eyebrow';
import {
    HR_ZONES,
    HR_ZONE_COLORS,
    HR_ZONE_LABELS,
    type HrZoneKey,
} from '@/lib/chartTokens';

export type TimeInZone = Partial<Record<HrZoneKey, number>>;

/**
 * Where the last twelve weeks of training time actually went: one segmented
 * bar over Z1-Z5 and a dot legend. Occupies the slot the behavioural persona
 * mix used to (decision P13) — the same question answered with heart rate
 * instead of narration.
 */
export default function TimeInZoneBar({
    zones,
}: Readonly<{ zones: TimeInZone }>) {
    const present = HR_ZONES.filter((z) => (zones[z] ?? 0) > 0);
    if (present.length === 0) {
        return null;
    }

    const summary = present
        .map((z) => `${HR_ZONE_LABELS[z]} ${zones[z]}%`)
        .join(', ');

    return (
        <div>
            <Eyebrow token="micro" tone="ink-3">
                Time in zone · last 12 weeks
            </Eyebrow>
            <div
                role="img"
                aria-label={`Time in heart-rate zone: ${summary}`}
                className="mt-1.5 flex h-2 gap-[3px]"
            >
                {present.map((zone) => (
                    <span
                        key={zone}
                        className="block h-full rounded-full"
                        style={{
                            width: `${zones[zone]}%`,
                            background: HR_ZONE_COLORS[zone],
                        }}
                    />
                ))}
            </div>
            <div
                aria-hidden
                className="mt-2 flex flex-wrap gap-x-2.5 gap-y-1 text-label-micro text-text-2"
            >
                {present.map((zone) => (
                    <span key={zone} className="inline-flex items-center gap-1">
                        <span
                            className="inline-block size-1.5 rounded-full"
                            style={{ background: HR_ZONE_COLORS[zone] }}
                        />
                        {`${HR_ZONE_LABELS[zone]} ${zones[zone]}%`}
                    </span>
                ))}
            </div>
        </div>
    );
}
