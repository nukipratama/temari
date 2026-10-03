import PushNotificationToggle from '@/components/PushNotificationToggle';
import { type CatalogueEntry } from '@/lib/catalogue';

const CONFIGURED = { webPushPublicKey: 'catalogue-public-key' };

export default {
    name: 'PushNotificationToggle',
    description:
        'Web push for this device, as a SettingsRow. Its state comes from the browser (support, install, permission, subscription), so the catalogue shows whichever one this browser is in.',
    usage: `<PushNotificationToggle muted={muted} onMuteChange={setMuted} />`,
    states: [
        {
            name: 'this browser',
            sharedProps: CONFIGURED,
            render: () => <PushNotificationToggle />,
        },
        {
            name: 'no VAPID key configured',
            sharedProps: { webPushPublicKey: '' },
            render: () => <PushNotificationToggle />,
        },
    ],
} satisfies CatalogueEntry;
