import { Bell, HeartPulse, LogOut, Send } from 'lucide-react';
import { useState } from 'react';

import SettingsRow from '@/components/ui/SettingsRow';
import Switch from '@/components/ui/Switch';
import { type CatalogueEntry } from '@/lib/catalogue';

function WithSwitch() {
    const [on, setOn] = useState(true);

    return (
        <SettingsRow
            icon={Bell}
            label="weekly recap"
            description="a note from temari every monday."
            control={
                <Switch label="weekly recap" checked={on} onChange={setOn} />
            }
        />
    );
}

export default {
    name: 'SettingsRow',
    description:
        'A settings list row: icon, label and description, then a chevron or its own control. A row with a control is never itself tappable.',
    usage: `<SettingsRow icon={HeartPulse} label="HR zones" description="…" href="/settings/zones" />`,
    states: [
        {
            name: 'link',
            render: () => (
                <SettingsRow
                    icon={HeartPulse}
                    label="HR zones"
                    description="set your own Z1-Z5 boundaries."
                    href="#"
                />
            ),
        },
        {
            name: 'external, new tab',
            render: () => (
                <SettingsRow
                    icon={Send}
                    label="Telegram"
                    description="get notes on your phone."
                    externalHref="#"
                    openInNewTab
                />
            ),
        },
        { name: 'with a control', render: () => <WithSwitch /> },
        {
            name: 'danger',
            render: () => (
                <SettingsRow
                    icon={LogOut}
                    label="sign out"
                    tone="danger"
                    onClick={() => undefined}
                />
            ),
        },
        {
            name: 'static',
            render: () => <SettingsRow icon={Bell} label="notifications" />,
        },
    ],
} satisfies CatalogueEntry;
