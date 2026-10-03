import { Timer } from 'lucide-react';

import StatTile, { Stat, StatDelta } from '@/components/ui/StatTile';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'StatTile',
    description:
        "MASTER's stat tile: a secondary-filled block with one label and one number, only once two or more sit side by side. Stat is the bare labelled number, StatDelta its signed change.",
    usage: `<StatTile label="distance" value="31.2 km" delta={<StatDelta value={4.1} unit=" km" />} />`,
    states: [
        {
            name: 'tile row',
            render: () => (
                <div className="grid grid-cols-2 gap-2">
                    <StatTile label="distance" value="31.2 km" />
                    <StatTile
                        label="time"
                        value="3:12:40"
                        icon={Timer}
                        sub="across 4 runs"
                    />
                </div>
            ),
        },
        {
            name: 'with deltas',
            render: () => (
                <div className="grid grid-cols-3 gap-2">
                    <StatTile
                        size="xs"
                        label="up"
                        value="31.2"
                        delta={<StatDelta value={4.1} />}
                    />
                    <StatTile
                        size="xs"
                        label="down"
                        value="27.0"
                        delta={<StatDelta value={-1.2} />}
                    />
                    <StatTile
                        size="xs"
                        label="flat"
                        value="27.0"
                        delta={<StatDelta value={0} />}
                    />
                </div>
            ),
        },
        {
            name: 'bare stat',
            render: () => (
                <Stat
                    label="load balance"
                    value="-8.4"
                    sub="heavy for a few days."
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
