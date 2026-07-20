import { type ReactNode, useState } from 'react';
import { MotionConfig } from 'framer-motion';
import { usePage } from '@inertiajs/react';
import UnlockToast from '@/components/temari/UnlockToast';
import CardReveal from '@/components/card/CardReveal';
import AksesoriUnlockModal from '@/components/celebrations/AksesoriUnlockModal';
import TopNav from '@/components/TopNav';
import MobileTopBar from '@/components/MobileTopBar';
import MobileBottomNav from '@/components/MobileBottomNav';
import ErrorBanner from '@/components/ErrorBanner';
import StravaZoneReconnectBanner from '@/components/StravaZoneReconnectBanner';
import AiOutageBanner from '@/components/AiOutageBanner';
import { useDawnShift } from '@/hooks/useDawnShift';
import type { SharedProps, UnlockFlash } from '@/types/inertia';

interface AppShellProps {
    children: ReactNode;
    /** Hides the TopNav + MobileBottomNav for standalone screens (e.g. Login). */
    withNav?: boolean;
}

export default function AppShell({ children, withNav = true }: Readonly<AppShellProps>) {
    useDawnShift();
    const { component } = usePage<SharedProps>();
    const { pendingReveal, flash } = usePage<SharedProps>().props;
    const pending = pendingReveal ?? null;
    const unlock = flash?.unlock ?? null;
    const [majorUnlock, setMajorUnlock] = useState<UnlockFlash | null>(
        () => (unlock?.is_major ? unlock : null),
    );
    const [lastUnlock, setLastUnlock] = useState(unlock);

    // Capture a major unlock flash for the reveal — adjusted during render
    // (React-endorsed) so the sync setState isn't inside an effect.
    if (unlock !== lastUnlock) {
        setLastUnlock(unlock);
        if (unlock?.is_major) {
            setMajorUnlock(unlock);
        }
    }

    if (!withNav) {
        return (
            <MotionConfig reducedMotion="user">
                <div className="min-h-screen bg-cream-deep text-ink">
                    <ErrorBanner />
                    <StravaZoneReconnectBanner />
                    <AiOutageBanner />
                    <div key={component} className="page-enter">
                        {children}
                    </div>
                </div>
            </MotionConfig>
        );
    }

    return (
        <MotionConfig reducedMotion="user">
        <div className="min-h-screen bg-cream-deep text-ink">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-leaf focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg"
            >
                Lompat ke konten
            </a>

            <TopNav />
            <MobileTopBar />

            <ErrorBanner />
            <StravaZoneReconnectBanner />
            <AiOutageBanner />

            {/* Keyed on the Inertia component name so a real navigation remounts
                the content and replays the enter animation, while a partial
                reload (filters, polling, `only:` refreshes) keeps the same key
                and stays perfectly still. */}
            <main key={component} id="main-content" className="page-enter pb-28 lg:pb-0">
                {children}
            </main>

            <MobileBottomNav />
            {/* Celebration overlays are sequenced, not stacked: CardReveal (a pack
                reveal) takes priority over the aksesori-unlock modal, which in turn
                takes priority over the UnlockToast, so a sync that fires more than
                one celebration plays them back-to-back instead of all at once. */}
            {!pending && majorUnlock === null && <UnlockToast />}
            {pending && <CardReveal pending={pending} />}
            <AksesoriUnlockModal unlock={pending ? null : majorUnlock} onClose={() => setMajorUnlock(null)} />
        </div>
        </MotionConfig>
    );
}
