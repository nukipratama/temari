import { type ReactNode } from 'react';
import BrandMark from '@/components/BrandMark';
import DemoBanner from '@/components/DemoBanner';
import Sidebar from '@/components/Sidebar';
import SidebarTrigger from '@/components/SidebarTrigger';
import FloatingTemari from '@/components/temari/FloatingTemari';
import UnlockToast from '@/components/temari/UnlockToast';
import { SidebarProvider } from '@/contexts/SidebarContext';
import { useDawnShift } from '@/hooks/useDawnShift';

interface AppShellProps {
    children: ReactNode;
    showSidebar?: boolean;
}

export default function AppShell({ children, showSidebar = true }: Readonly<AppShellProps>) {
    useDawnShift();

    if (!showSidebar) {
        return (
            <div className="min-h-screen bg-surface text-ink dark:bg-surface-dark dark:text-ink-dark">
                <DemoBanner />
                {children}
            </div>
        );
    }

    return (
        <SidebarProvider>
            <div className="min-h-screen bg-surface text-ink dark:bg-surface-dark dark:text-ink-dark">
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-brand-500 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg"
                >
                    Lompat ke konten
                </a>

                <DemoBanner />
                <Sidebar />

                <div className="sticky top-0 z-10 flex items-center gap-3 border-b border-line bg-surface-elev/80 px-4 py-3 backdrop-blur dark:border-line-dark dark:bg-surface-dark-elev/80 lg:hidden">
                    <SidebarTrigger />
                    <BrandMark size="compact" />
                </div>

                <div id="main-content" className="lg:ml-64">{children}</div>

                <FloatingTemari />
                <UnlockToast />
            </div>
        </SidebarProvider>
    );
}
