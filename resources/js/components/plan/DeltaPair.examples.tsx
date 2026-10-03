import {
    AskedRanResult,
    ChangeRow,
    DeltaPair,
    DeltaTag,
} from '@/components/plan/DeltaPair';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'DeltaPair',
    description:
        'One changed value read as old → new, the arrow pointing and coloured the way it moved. ChangeRow labels it with a DeltaTag; AskedRanResult states a credited day by side.',
    usage: `<ChangeRow label="km" from="8" to="6" direction="down" tag="eased" />`,
    states: [
        {
            name: 'directions',
            render: () => (
                <div className="flex flex-col gap-1.5 text-sm">
                    <DeltaPair from="6 km" to="8 km" direction="up" />
                    <DeltaPair from="8 km" to="6 km" direction="down" />
                    <DeltaPair from="tempo" to="easy" direction="neutral" />
                    <DeltaPair from="6:10" to="6:25" direction="down" quiet />
                </div>
            ),
        },
        {
            name: 'change rows',
            render: () => (
                <div className="flex flex-col gap-1">
                    <ChangeRow
                        label="type"
                        from="interval"
                        to="easy"
                        direction="neutral"
                        tag="eased"
                    />
                    <ChangeRow
                        label="km"
                        from="8"
                        to="6"
                        direction="down"
                        tag="trimmed"
                    />
                </div>
            ),
        },
        {
            name: 'tag alone',
            render: () => <DeltaTag>topped up</DeltaTag>,
        },
        {
            name: 'asked and ran',
            render: () => (
                <AskedRanResult
                    askedKm={6}
                    askedPace="7:00/km"
                    ranKm={6.4}
                    ranPace="6:52/km"
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
