import { usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useCallback, useEffect, useState } from 'react';
import PillButton from '@/components/ui/PillButton';
import SettingsRow from '@/components/ui/SettingsRow';
import Toggle from '@/components/ui/Toggle';
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
 *
 * Renders as a `SettingsRow` rather than owning a section, so it sits in the
 * "Ke mana" group beside Telegram and both channels read as the same kind of
 * thing. Each of the eight states resolves to a description plus at most one
 * action; the states that are pure explanation (unsupported, needs-install,
 * denied) simply have no control.
 */
export default function PushNotificationToggle({
    muted = false,
    onMuteChange,
}: Readonly<{ muted?: boolean; onMuteChange?: (muted: boolean) => void }> = {}) {
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

    if (state === 'loading') {
        return null;
    }

    // Once subscribed, the row's control becomes the mute — the same shape as the
    // Telegram row beside it — and "Matikan", which actually drops the
    // subscription, moves below as the heavier, rarer action. Before that point
    // there is nothing to mute, so the subscribe/repair action keeps the slot.
    const subscribed = state === 'subscribed';

    let description = PUSH_DESCRIPTION[state];
    if (subscribed && muted) {
        description = 'Dibisukan di HP ini.';
    }

    return (
        <>
            <SettingsRow
                icon="mdi:cellphone-message"
                label="Notifikasi HP"
                description={description}
                control={
                    subscribed && onMuteChange !== undefined ? (
                        <Toggle label="Kirim ke HP" checked={!muted} onChange={(on) => onMuteChange(!on)} />
                    ) : (
                        <PushAction
                            state={state}
                            busy={busy}
                            onSubscribe={runSubscribe}
                            onUnsubscribe={runUnsubscribe}
                        />
                    )
                }
            />
            {subscribed && onMuteChange !== undefined && (
                <div className="-mt-1 pl-11">
                    <PushAction state={state} busy={busy} onSubscribe={runSubscribe} onUnsubscribe={runUnsubscribe} />
                </div>
            )}
            {status !== '' && (
                <p role="status" aria-live="polite" className="px-2 pb-1 text-[12px] text-ink-3">
                    {status}
                </p>
            )}
            <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
        </>
    );
}

/** One line per state, standing in for the old free-floating hint paragraphs. */
const PUSH_DESCRIPTION: Record<PushState, string> = {
    loading: '',
    unsupported: 'HP atau browser ini belum bisa nerima notifikasi Temari.',
    'needs-install-safari':
        'Tambahin Temari ke Home Screen dulu (Share → Add to Home Screen), baru bisa nyalain notifikasi.',
    'needs-install-other':
        'Buka Temari di Safari dulu, terus Share → Add to Home Screen — notifikasi HP cuma jalan dari sana.',
    denied: 'Notifikasi diblokir. Nyalain lagi dari Setelan HP → Notifikasi → Temari.',
    stale: 'Perlu didaftarin ulang di HP ini.',
    subscribed: 'Aktif di HP ini.',
    ready: 'Nyalain biar Temari bisa kabarin kamu di HP.',
};

/**
 * The single action a state offers, or nothing when the state is purely
 * explanatory and there is no button that would help.
 */
function PushAction({
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
        case 'stale':
            return (
                <PillButton tone="horizon" disabled={busy} onClick={onSubscribe}>
                    <Icon icon="mdi:bell-cog-outline" width={14} height={14} aria-hidden />
                    Perbaiki
                </PillButton>
            );
        case 'subscribed':
            return (
                <PillButton tone="outline" disabled={busy} onClick={onUnsubscribe}>
                    <Icon icon="mdi:bell-off-outline" width={14} height={14} aria-hidden />
                    Matikan
                </PillButton>
            );
        case 'ready':
            return (
                <PillButton tone="horizon" disabled={busy} onClick={onSubscribe}>
                    <Icon icon="mdi:bell-ring-outline" width={14} height={14} aria-hidden />
                    Nyalakan
                </PillButton>
            );
        default:
            return null;
    }
}
