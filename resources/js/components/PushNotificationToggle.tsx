import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import DemoBlockedModal from '@/components/DemoBlockedModal';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import SettingsRow from '@/components/ui/SettingsRow';
import Toggle from '@/components/ui/Switch';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import {
    currentSubscription,
    isIosNonSafari,
    isPushSupported,
    isStandalone,
    subscribe,
    unsubscribe,
} from '@/lib/webPush';

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
 * Device-level web-push control for the Settings page. Detects where the user
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
}: Readonly<{
    muted?: boolean;
    onMuteChange?: (muted: boolean) => void;
}> = {}) {
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
            setState(
                isIosNonSafari()
                    ? 'needs-install-other'
                    : 'needs-install-safari',
            );
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
            setStatus('turning on notifications…');
            try {
                await subscribe(publicKey);
                setState('subscribed');
                setStatus('push notifications are on.');
            } catch (error) {
                if (
                    error instanceof Error &&
                    error.message === 'permission-denied'
                ) {
                    setState('denied');
                    setStatus('');
                } else {
                    setStatus('failed to turn on notifications, try again.');
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
                setStatus('push notifications turned off.');
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
    // Telegram row beside it — and "Turn off", which actually drops the
    // subscription, moves below as the heavier, rarer action. Before that point
    // there is nothing to mute, so the subscribe/repair action keeps the slot.
    const subscribed = state === 'subscribed';

    let description = PUSH_DESCRIPTION[state];
    if (subscribed && muted) {
        description = 'muted on this device.';
    }

    return (
        <>
            <SettingsRow
                icon="mdi:cellphone-message"
                label="push notifications"
                description={description}
                control={
                    subscribed && onMuteChange !== undefined ? (
                        <Toggle
                            label="send run notifications to this device"
                            checked={!muted}
                            onChange={(on) => onMuteChange(!on)}
                        />
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
                    <PushAction
                        state={state}
                        busy={busy}
                        onSubscribe={runSubscribe}
                        onUnsubscribe={runUnsubscribe}
                    />
                </div>
            )}
            {status !== '' && (
                <p
                    role="status"
                    aria-live="polite"
                    className="px-2 pb-1 text-[12px] text-text-3"
                >
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
    unsupported:
        "this device or browser can't receive notifications from temari.",
    'needs-install-safari':
        'add temari to your Home Screen first (Share → Add to Home Screen), then you can turn on notifications.',
    'needs-install-other':
        'open temari in Safari first, then Share → Add to Home Screen, push notifications only work from there.',
    denied: 'notifications are blocked. turn them back on from Settings → Notifications → Temari on this device.',
    stale: 'needs to be re-registered on this device.',
    subscribed: 'active on this device.',
    ready: 'turn on so temari can reach you on this device.',
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
                <Button disabled={busy} onClick={onSubscribe}>
                    <Icon
                        icon="mdi:bell-cog-outline"
                        width={14}
                        height={14}
                        aria-hidden
                    />
                    Fix
                </Button>
            );
        case 'subscribed':
            return (
                <PillButton
                    tone="outline"
                    disabled={busy}
                    onClick={onUnsubscribe}
                >
                    <Icon
                        icon="mdi:bell-off-outline"
                        width={14}
                        height={14}
                        aria-hidden
                    />
                    Turn off
                </PillButton>
            );
        case 'ready':
            return (
                <Button disabled={busy} onClick={onSubscribe}>
                    <Icon
                        icon="mdi:bell-ring-outline"
                        width={14}
                        height={14}
                        aria-hidden
                    />
                    Turn on
                </Button>
            );
        default:
            return null;
    }
}
