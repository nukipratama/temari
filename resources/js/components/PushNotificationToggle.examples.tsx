import PushNotificationToggle from '@/components/PushNotificationToggle';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'PushNotificationToggle',
    description:
        'Web push for this device, as a SettingsRow. Its state comes from the browser (support, install, permission, subscription) and it renders nothing without a VAPID key, so the catalogue shows whatever this browser and environment give it.',
    usage: `<PushNotificationToggle muted={muted} onMuteChange={setMuted} />`,
    states: [
        { name: 'this browser', render: () => <PushNotificationToggle /> },
    ],
} satisfies CatalogueEntry;
