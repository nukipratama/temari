import { router } from '@inertiajs/react';
import { Watch } from 'lucide-react';
import { Suspense, useState } from 'react';

import DemoBlockedModal from '@/components/DemoBlockedModal';
import SettingsDisconnectLink from '@/components/ui/SettingsDisconnectLink';
import SettingsRow from '@/components/ui/SettingsRow';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { lazyIsland } from '@/lib/lazyIsland';

const IntervalsIcuConnectForm = lazyIsland(
    () => import('@/components/settings/IntervalsIcuConnectForm'),
);

export interface IntervalsIcuPayload {
    connected: boolean;
    athlete_id: string | null;
    broken: boolean;
}

export const INTERVALS_ICU_DEMO_BLOCKED = {
    title: 'intervals.icu stays unplugged in the demo',
    body: 'this is the shared demo, so it has no watch to send runs to. sign in with your own Strava to connect intervals.icu.',
} as const;

function description(intervalsIcu: IntervalsIcuPayload): string {
    if (!intervalsIcu.connected) {
        return 'send your next run to your watch.';
    }
    if (intervalsIcu.broken) {
        return 'your key stopped working · reconnect to keep sending runs.';
    }
    return `connected · athlete ${intervalsIcu.athlete_id}`;
}

export default function IntervalsIcuPanel({
    intervalsIcu,
}: Readonly<{ intervalsIcu: IntervalsIcuPayload }>) {
    const [open, setOpen] = useState(false);
    const { open: demoOpen, setOpen: setDemoOpen, guard } = useDemoGuard();
    const healthy = intervalsIcu.connected && !intervalsIcu.broken;

    return (
        <>
            {healthy ? (
                <SettingsRow
                    icon={Watch}
                    label="intervals.icu"
                    description={description(intervalsIcu)}
                    control={<span aria-hidden />}
                />
            ) : (
                <SettingsRow
                    icon={Watch}
                    label="intervals.icu"
                    description={description(intervalsIcu)}
                    expanded={open}
                    onClick={() => guard(() => setOpen((v) => !v))}
                />
            )}

            {intervalsIcu.connected && (
                <div className="-mt-1 pl-11">
                    <SettingsDisconnectLink
                        onClick={() =>
                            guard(() =>
                                router.delete('/settings/intervals-icu', {
                                    preserveScroll: true,
                                }),
                            )
                        }
                    />
                </div>
            )}

            {!healthy && open && (
                <Suspense fallback={null}>
                    <IntervalsIcuConnectForm
                        athleteId={intervalsIcu.athlete_id ?? ''}
                        onConnected={() => setOpen(false)}
                    />
                </Suspense>
            )}

            <DemoBlockedModal
                open={demoOpen}
                onClose={() => setDemoOpen(false)}
                title={INTERVALS_ICU_DEMO_BLOCKED.title}
                body={INTERVALS_ICU_DEMO_BLOCKED.body}
            />
        </>
    );
}
