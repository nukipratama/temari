import SettingsDisconnectLink from '@/components/ui/SettingsDisconnectLink';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'SettingsDisconnectLink',
    description:
        "A connected channel's quiet disconnect, sitting at the end of its settings row.",
    usage: `<SettingsDisconnectLink onClick={disconnect} disabled={busy} />`,
    states: [
        {
            name: 'default',
            render: () => <SettingsDisconnectLink onClick={() => undefined} />,
        },
        {
            name: 'disabled',
            render: () => (
                <SettingsDisconnectLink onClick={() => undefined} disabled />
            ),
        },
    ],
} satisfies CatalogueEntry;
