import { HeartPulse, Route, Timer, TrendingUp } from 'lucide-react';

import StatTile, { Stat, StatDelta } from '@/components/ui/StatTile';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'StatTile',
    description:
        "MASTER's stat tile: a secondary-filled block with the icon and label on top and the number big below, only once two or more sit side by side. The number is up to 24px on a phone, fitted to its tile, and 30px from tablet up; a unit sits beside it and drops to its own line when the tile is too narrow. Stat is the bare labelled number, StatDelta its signed change.",
    usage: `<StatTile icon={Route} label="distance" value="31.2" delta={<StatDelta value={4.1} unit=" km" />} />`,
    states: [
        {
            name: 'tile row',
            render: () => (
                <div className="grid grid-cols-2 gap-2">
                    <StatTile icon={Route} label="distance" value="31.2 km" />
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
            name: 'three-up with units',
            render: () => (
                <div className="grid grid-cols-2 gap-2 min-[360px]:grid-cols-3">
                    <StatTile
                        icon={HeartPulse}
                        label="HR"
                        value="148"
                        delta={
                            <span className="text-label-micro text-text-2">
                                bpm
                            </span>
                        }
                    />
                    <StatTile icon={Route} label="total km" value="1239.8" />
                    <StatTile
                        icon={TrendingUp}
                        label="threshold"
                        value="5:52"
                        delta={
                            <span className="text-label-micro text-text-2">
                                /km
                            </span>
                        }
                    />
                </div>
            ),
        },
        {
            name: 'with deltas',
            render: () => (
                <div className="grid grid-cols-2 gap-2 min-[360px]:grid-cols-3">
                    <StatTile
                        label="up"
                        value="31.2"
                        delta={<StatDelta value={4.1} />}
                    />
                    <StatTile
                        label="down"
                        value="27.0"
                        delta={<StatDelta value={-1.2} />}
                    />
                    <StatTile
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
