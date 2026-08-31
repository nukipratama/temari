import type { PlanSessionSegment } from '@/types/inertia';

import { HR_ZONE_COLORS, type HrZoneKey } from '@/lib/chartTokens';

export function zoneColor(zone: string): string {
    return HR_ZONE_COLORS[zone as HrZoneKey] ?? 'var(--color-text-3)';
}

/**
 * The session's shape as a 4px-tall zone-coloured strip: enough to tell an
 * even easy run from an interval day without expanding the row.
 */
export default function MiniSessionBar({
    segments,
}: Readonly<{ segments: PlanSessionSegment[] }>) {
    const total = segments.reduce((sum, s) => sum + (s.minutes ?? 0), 0);

    if (segments.length === 0 || total === 0) {
        return null;
    }

    return (
        <div className="mt-1.5 flex h-1 gap-px" aria-hidden>
            {segments.map((segment, index) => (
                <div
                    key={index}
                    className="rounded-full"
                    style={{
                        width: `${((segment.minutes ?? 0) / total) * 100}%`,
                        backgroundColor: zoneColor(segment.zone),
                    }}
                />
            ))}
        </div>
    );
}
