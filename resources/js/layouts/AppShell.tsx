import { type ReactNode, useEffect, useState } from 'react';
import { MotionConfig } from 'framer-motion';
import { usePage } from '@inertiajs/react';
import DemoBanner from '@/components/DemoBanner';
import UnlockToast from '@/components/temari/UnlockToast';
import CardReveal from '@/components/card/CardReveal';
import PRMomentModal from '@/components/celebrations/PRMomentModal';
import AksesoriUnlockModal from '@/components/celebrations/AksesoriUnlockModal';
import TopNav from '@/components/TopNav';
import MobileTopBar from '@/components/MobileTopBar';
import MobileBottomNav from '@/components/MobileBottomNav';
import { useDawnShift } from '@/hooks/useDawnShift';
import type { SharedProps, UnlockFlash } from '@/types/inertia';

interface AppShellProps {
    children: ReactNode;
    /** Hides the TopNav + MobileBottomNav for standalone screens (e.g. Login). */
    withNav?: boolean;
}

type PrModalData = { activityId: number; categoryLabel: string; timeDisplay: string };

export default function AppShell({ children, withNav = true }: Readonly<AppShellProps>) {
    useDawnShift();
    const { pendingReveal, flash } = usePage<SharedProps>().props;
    const pending = pendingReveal ?? null;
    const [prModal, setPrModal] = useState<PrModalData | null>(null);
    const [majorUnlock, setMajorUnlock] = useState<UnlockFlash | null>(null);

    const unlock = flash?.unlock ?? null;
    useEffect(() => {
        if (unlock?.is_major) {
            setMajorUnlock(unlock);
        }
    }, [unlock]);

    const handlePrMoment = () => {
        if (pending?.is_pr && pending.pr_category_label && pending.pr_time_display) {
            setPrModal({
                activityId: pending.activity_id,
                categoryLabel: pending.pr_category_label,
                timeDisplay: pending.pr_time_display,
            });
        }
    };

    if (!withNav) {
        return (
            <MotionConfig reducedMotion="user">
                <div className="min-h-screen bg-cream-deep text-ink">
                    <DemoBanner />
                    {children}
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

            <DemoBanner />
            <TopNav />
            <MobileTopBar />

            <main id="main-content" className="pb-28 lg:pb-0">
                {children}
            </main>

            <MobileBottomNav />
            <UnlockToast />
            {pending && <CardReveal pending={pending} onPrMoment={handlePrMoment} />}
            <PRMomentModal pr={prModal} onClose={() => setPrModal(null)} />
            <AksesoriUnlockModal unlock={majorUnlock} onClose={() => setMajorUnlock(null)} />
        </div>
        </MotionConfig>
    );
}
