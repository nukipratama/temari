import { planDay } from '@/components/catalogue/fixtures';
import { DAY_CELL_CLASS, DayCellBody } from '@/components/plan/DayCell';
import { type CatalogueEntry } from '@/lib/catalogue';

const WEEK = [
    {
        day: planDay({
            date: '2026-09-28',
            status: 'done',
            actual_km: 6.2,
            prescribed_km: 6,
        }),
        hasElapsed: true,
    },
    {
        day: planDay({
            date: '2026-09-29',
            session_type: 'interval',
            distance_km: 8,
            status: 'missed',
            prescribed_km: 8,
        }),
        hasElapsed: true,
    },
    {
        day: planDay({ date: '2026-09-30', session_type: 'rest' }),
        hasElapsed: true,
    },
    {
        day: planDay({
            date: '2026-10-01',
            session_type: 'tempo',
            distance_km: 7,
            status: 'partial',
            actual_km: 4.5,
            prescribed_km: 7,
        }),
        hasElapsed: true,
    },
    {
        day: planDay({
            date: '2026-10-04',
            session_type: 'long',
            distance_km: 14,
        }),
        hasElapsed: false,
    },
];

export default {
    name: 'DayCellBody',
    description:
        'A day in the week strip, shared by Today and Plan: weekday, the session icon in its effort colour, the km line, the status glyph and the effort bar. The caller owns the tappable wrapper; DAY_CELL_CLASS is the column it wraps the body in.',
    usage: `<Link href={…} className={DAY_CELL_CLASS}>
    <DayCellBody day={day} hasElapsed={day.date < today} />
</Link>`,
    states: [
        {
            name: 'a week: done, missed, rest, partial, upcoming',
            render: () => (
                <div className="grid max-w-md grid-cols-5">
                    {WEEK.map(({ day, hasElapsed }) => (
                        <div key={day.date} className={DAY_CELL_CLASS}>
                            <DayCellBody day={day} hasElapsed={hasElapsed} />
                        </div>
                    ))}
                </div>
            ),
        },
    ],
} satisfies CatalogueEntry;
