import { Link, router } from '@inertiajs/react';
import { LogOut, UserX } from 'lucide-react';
import { useState } from 'react';

import TemariNudgeModal from '@/components/temari/TemariNudgeModal';
import { Icon } from '@/components/ui/Icon';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { releaseDeviceSubscription } from '@/lib/webPush';

async function signOut(): Promise<void> {
    const endpoint = await releaseDeviceSubscription();

    router.post(
        '/logout',
        endpoint === undefined ? {} : { push_endpoint: endpoint },
    );
}

/**
 * The prototype's `AccountActions`: a button pair, not a settings list — a
 * bordered full-width "Log out" that becomes an auto-width row item above
 * 900px, and a bare destructive "Delete account" beneath it.
 */
export default function AccountActions() {
    const [confirmOpen, setConfirmOpen] = useState(false);
    const { isDemo } = useDemoGuard();

    return (
        <div className="mt-3 mb-2 flex flex-col items-center gap-3 min-[900px]:flex-row min-[900px]:justify-center">
            <button
                type="button"
                onClick={() => void signOut()}
                className="pressable focus-ring flex w-full items-center justify-center gap-2 rounded-lg border border-border-strong bg-card py-3 font-sans text-xs font-bold text-foreground transition hover:bg-cream-deep/30 min-[900px]:w-auto min-[900px]:px-6"
            >
                <Icon icon={LogOut} width={16} height={16} aria-hidden />
                log out
            </button>
            {!isDemo && (
                <>
                    <button
                        type="button"
                        onClick={() => setConfirmOpen(true)}
                        className="pressable focus-ring rounded-xs p-1 font-sans text-xs font-bold text-ember-ink transition hover:opacity-80"
                    >
                        delete account
                    </button>
                    <TemariNudgeModal
                        open={confirmOpen}
                        onClose={() => setConfirmOpen(false)}
                        pose="concerned"
                        title="sure you want to delete your account?"
                        body={
                            <>
                                your runs, cards and notes go, your Strava
                                connection is unlinked, and it can&apos;t be
                                undone. if you just want to switch Strava
                                accounts, this is also how.{' '}
                                <Link
                                    href="/privacy"
                                    className="active:opacity-70 underline underline-offset-2"
                                >
                                    what&apos;s kept afterwards
                                </Link>
                            </>
                        }
                        primaryLabel="yes, delete my account"
                        primaryIcon={UserX}
                        primaryTone="danger"
                        onPrimary={() => router.delete('/account')}
                    />
                </>
            )}
        </div>
    );
}
