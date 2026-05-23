import { type ReactNode } from 'react';
import DemoBanner from '@/components/DemoBanner';
import FloatingTemari from '@/components/temari/FloatingTemari';
import UnlockToast from '@/components/temari/UnlockToast';
import TopNav from '@/components/daybreak/TopNav';
import MobileBottomNav from '@/components/daybreak/MobileBottomNav';
import { useDawnShift } from '@/hooks/useDawnShift';

interface AppShellProps {
    children: ReactNode;
    /** Hides the TopNav + MobileBottomNav for standalone screens (e.g. Login). */
    withNav?: boolean;
}

export default function AppShell({ children, withNav = true }: Readonly<AppShellProps>) {
    useDawnShift();

    if (!withNav) {
        return (
            <div className="min-h-screen bg-cream text-ink">
                <DemoBanner />
                {children}
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-cream text-ink">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-leaf focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg"
            >
                Lompat ke konten
            </a>

            <DemoBanner />
            <TopNav />

            <main id="main-content" className="pb-24 lg:pb-0">
                {children}
            </main>

            <MobileBottomNav />
            <FloatingTemari />
            <UnlockToast />
        </div>
    );
}
