import DemoBlockedModal from '@/components/DemoBlockedModal';

export const SYNC_DEMO_BLOCKED = {
    title: "the demo doesn't sync",
    body: 'this is the shared demo, so its runs are a fixed set. connect your own Strava and sync now pulls in your latest runs.',
} as const;

export default function SyncDemoBlockedModal({
    open,
    onClose,
}: Readonly<{ open: boolean; onClose: () => void }>) {
    return (
        <DemoBlockedModal
            open={open}
            onClose={onClose}
            {...SYNC_DEMO_BLOCKED}
        />
    );
}
