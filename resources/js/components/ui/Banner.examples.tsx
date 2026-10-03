import { CircleAlert, CircleCheck, HeartPulse, Moon } from 'lucide-react';

import Banner from '@/components/ui/Banner';
import PillButton from '@/components/ui/PillButton';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'Banner',
    description:
        'The app-shell banner: one message under the top bar, in the shell column. Every banner in components/ is this with its own copy.',
    usage: `<Banner tone="error" icon={CircleAlert} onDismiss={dismiss}>
    {message}
</Banner>`,
    states: [
        {
            name: 'neutral',
            render: () => (
                <Banner icon={Moon}>
                    temari&apos;s catching her breath. your notes will catch up
                    on their own.
                </Banner>
            ),
        },
        {
            name: 'success, dismissable',
            render: () => (
                <Banner
                    tone="success"
                    icon={CircleCheck}
                    role="status"
                    onDismiss={() => undefined}
                >
                    telegram is connected.
                </Banner>
            ),
        },
        {
            name: 'error',
            render: () => (
                <Banner
                    tone="error"
                    icon={CircleAlert}
                    onDismiss={() => undefined}
                >
                    Strava said no to the connection, so nothing was synced.
                </Banner>
            ),
        },
        {
            name: 'with action',
            render: () => (
                <Banner
                    icon={HeartPulse}
                    action={
                        <PillButton tone="outline" size="sm">
                            reconnect
                        </PillButton>
                    }
                    onDismiss={() => undefined}
                >
                    Strava only shares your HR zones with the profile scope, so
                    anything zone-based falls back to estimates until you
                    reconnect.
                </Banner>
            ),
        },
    ],
} satisfies CatalogueEntry;
