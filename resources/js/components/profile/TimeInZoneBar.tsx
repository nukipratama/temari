import Eyebrow from '@/components/ui/Eyebrow';
import {
    HR_ZONES,
    HR_ZONE_COLORS,
    HR_ZONE_LABELS,
    type HrZoneKey,
} from '@/lib/chartTokens';

export type TimeInZone = Partial<Record<HrZoneKey, number>>;

/**
 * Where training time actually went: one segmented bar over Z1-Z5 and a dot
 * legend. On Profile it answers the last twelve weeks, in the slot the
 * behavioural persona mix used to occupy (decision P13); on a single run it
 * answers that run, which is why the caller names the span.
 *
 * `anchored` puts a `zone:*` citation target on each legend entry rather than
 * on the bar segments, which are a few pixels wide and carry no text.
 */
export default function TimeInZoneBar({
    zones,
    label = 'Time in zone · last 12 weeks',
    anchored = false,
}: Readonly<{ zones: TimeInZone; label?: string; anchored?: boolean }>) {
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
                {label}
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
                    <span
                        key={zone}
                        id={
                            anchored
                                ? `anchor-zone-${zone.toLowerCase()}`
                                : undefined
                        }
                        className="inline-flex items-center gap-1"
                    >
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
