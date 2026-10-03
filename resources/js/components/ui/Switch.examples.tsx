import { useState } from 'react';

import Switch from '@/components/ui/Switch';
import { type CatalogueEntry } from '@/lib/catalogue';

function Demo({
    initial,
    disabled = false,
}: Readonly<{ initial: boolean; disabled?: boolean }>) {
    const [checked, setChecked] = useState(initial);

    return (
        <Switch
            label="weekly recap"
            checked={checked}
            onChange={setChecked}
            disabled={disabled}
        />
    );
}

export default {
    name: 'Switch',
    description:
        'The app switch: the control only, labelled by the SettingsRow it sits in. It takes effect immediately.',
    usage: `<Switch label="weekly recap" checked={on} onChange={setOn} />`,
    states: [
        { name: 'off', render: () => <Demo initial={false} /> },
        { name: 'on', render: () => <Demo initial /> },
        { name: 'disabled', render: () => <Demo initial disabled /> },
    ],
} satisfies CatalogueEntry;
