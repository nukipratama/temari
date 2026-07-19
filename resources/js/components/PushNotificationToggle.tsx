import { usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { type ReactNode, useCallback, useEffect, useState } from 'react';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import DemoBlockedModal from '@/components/DemoBlockedModal';
import {
    currentSubscription,
    isIosNonSafari,
    isPushSupported,
    isStandalone,
    subscribe,
    unsubscribe,
} from '@/lib/webPush';
import type { SharedProps } from '@/types/inertia';

type PushState =
    | 'loading'
    | 'unsupported'
    | 'needs-install-safari'
    | 'needs-install-other'
    | 'denied'
    | 'ready'
    | 'stale'
    | 'subscribed';

/**
 * Device-level web-push control for the Pengaturan page. Detects where the user
 * is in the install/permission flow and shows the one right action — the payoff
 * of the PWA work: native lock-screen notifications on iPhone. Only rendered when
 * a VAPID public key is configured.
 */
export default function PushNotificationToggle() {
    const publicKey = usePage<SharedProps>().props.webPushPublicKey ?? '';
    const { open, setOpen, guard } = useDemoGuard();
    const [state, setState] = useState<PushState>('loading');
    const [busy, setBusy] = useState(false);
    const [status, setStatus] = useState('');

    const resolveState = useCallback(async () => {
        if (!isPushSupported()) {
            setState('unsupported');
            return;
        }
        if (!isStandalone()) {
            setState(isIosNonSafari() ? 'needs-install-other' : 'needs-install-safari');
            return;
        }
        if (Notification.permission === 'denied') {
            setState('denied');
            return;
        }
        const subscription = await currentSubscription();
        if (subscription !== null) {
            setState('subscribed');
        } else {
            // Permission granted but no live subscription = iOS evicted it (or a
            // half-finished subscribe): offer a re-register rather than a fresh one.
            setState(Notification.permission === 'granted' ? 'stale' : 'ready');
        }
    }, []);

    useEffect(() => {
        void resolveState();
    }, [resolveState]);

    const runSubscribe = () =>
        guard(async () => {
            setBusy(true);
            setStatus('Lagi nyalain notifikasi…');
            try {
                await subscribe(publicKey);
                setState('subscribed');
                setStatus('Notifikasi HP aktif.');
            } catch (error) {
                if (error instanceof Error && error.message === 'permission-denied') {
                    setState('denied');
                    setStatus('');
                } else {
                    setStatus('Gagal nyalain notifikasi, coba lagi ya.');
                }
            } finally {
                setBusy(false);
            }
        });

    const runUnsubscribe = () =>
        guard(async () => {
            setBusy(true);
            try {
                await unsubscribe();
                setState('ready');
                setStatus('Notifikasi HP dimatiin.');
            } finally {
                setBusy(false);
            }
        });

    if (publicKey === '') {
        return null;
    }

    return (
        <section className="mt-10">
            <SectionLabel>Notifikasi HP</SectionLabel>
            <div className="mt-3 flex flex-col gap-3 rounded-2xl border border-cream-deep bg-cream p-4">
                <PushBody state={state} busy={busy} onSubscribe={runSubscribe} onUnsubscribe={runUnsubscribe} />

                <p role="status" aria-live="polite" className="min-h-[1rem] text-sm text-ink-3">
                    {status}
                </p>

                <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
            </div>
        </section>
    );
}

function PushBody({
    state,
    busy,
    onSubscribe,
    onUnsubscribe,
}: Readonly<{
    state: PushState;
    busy: boolean;
    onSubscribe: () => void;
    onUnsubscribe: () => void;
}>) {
    switch (state) {
        case 'loading':
            return null;
        case 'unsupported':
            return <Hint>HP atau browser ini belum bisa nerima notifikasi Temari.</Hint>;
        case 'needs-install-safari':
            return <Hint>Tambahin Temari ke Home Screen dulu (tombol Share → Add to Home Screen), baru bisa nyalain notifikasi.</Hint>;
        case 'needs-install-other':
            return <Hint>Buka Temari di Safari dulu, terus Share → Add to Home Screen — notifikasi HP cuma jalan dari sana.</Hint>;
        case 'denied':
            return <Hint>Notifikasi diblokir. Nyalain lagi dari Setelan HP → Notifikasi → Temari.</Hint>;
        case 'stale':
            return (
                <>
                    <Hint>Notifikasi perlu didaftarin ulang di HP ini.</Hint>
                    <PillButton tone="horizon" disabled={busy} onClick={onSubscribe}>
                        <Icon icon="mdi:bell-cog-outline" width={14} height={14} aria-hidden />
                        Perbaiki
                    </PillButton>
                </>
            );
        case 'subscribed':
            return (
                <div className="flex flex-col gap-2">
                    <Hint>Notifikasi HP aktif. Tes kirimannya lewat "Kirim notifikasi tes" di atas.</Hint>
                    <PillButton tone="outline" disabled={busy} onClick={onUnsubscribe}>
                        <Icon icon="mdi:bell-off-outline" width={14} height={14} aria-hidden />
                        Matikan
                    </PillButton>
                </div>
            );
        case 'ready':
        default:
            return (
                <PillButton tone="horizon" disabled={busy} onClick={onSubscribe}>
                    <Icon icon="mdi:bell-ring-outline" width={14} height={14} aria-hidden />
                    Nyalakan notifikasi
                </PillButton>
            );
    }
}

function Hint({ children }: Readonly<{ children: ReactNode }>) {
    return <p className="text-sm text-ink-2">{children}</p>;
}
