import DurationFields from '@/components/ui/DurationFields';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'DurationFields',
    description:
        'Hour, minute and optional second inputs for a goal or finish time. An emptied field stays empty while typing and counts as 0 on blur.',
    usage: `<DurationFields value={{ hours: 0, minutes: 50, seconds: 0 }} onChange={setTime} withSeconds />`,
    states: [
        {
            name: 'hours and minutes',
            render: () => (
                <DurationFields
                    value={{ hours: 0, minutes: 50, seconds: 0 }}
                    onChange={() => {}}
                />
            ),
        },
        {
            name: 'with seconds',
            render: () => (
                <DurationFields
                    value={{ hours: 1, minutes: 45, seconds: 30 }}
                    onChange={() => {}}
                    withSeconds
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
