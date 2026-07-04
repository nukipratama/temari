import { router } from '@inertiajs/react';
import TemariNudgeModal from '@/components/temari/TemariNudgeModal';

interface DemoBlockedModalProps {
    open: boolean;
    onClose: () => void;
}

/**
 * Friendly front door for a demo visitor hitting a blocked Telegram action.
 * The `block-demo-telegram` middleware is the real guard; this is the soft
 * upsell shown instead of a silent 403/redirect. Uses the shared
 * {@see TemariNudgeModal} shell (a calm nudge, not a win celebration).
 */
export default function DemoBlockedModal({ open, onClose }: Readonly<DemoBlockedModalProps>) {
    return (
        <TemariNudgeModal
            open={open}
            onClose={onClose}
            title="Telegram-nya lagi istirahat dulu"
            body={
                <>
                    Ini kan masih demo, jadi Telegram aku matiin biar bot bareng-bareng ini gak kepencet
                    orang lain. Sambungin Strava-mu sendiri, nanti notif beneran masuk ke HP kamu.
                </>
            }
            primaryLabel="Masuk pakai Strava"
            onPrimary={() => router.post('/logout')}
        />
    );
}
