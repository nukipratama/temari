import { type ReactNode } from 'react';
import BrandMark from '@/components/BrandMark';
import DemoBanner from '@/components/DemoBanner';
import Sidebar from '@/components/Sidebar';
import SidebarTrigger from '@/components/SidebarTrigger';
import { SidebarProvider } from '@/contexts/SidebarContext';

interface AppShellProps {
    children: ReactNode;
    /** Skip the sidebar (used by pre-auth pages: Welcome/Login). */
    showSidebar?: boolean;
}

/**
 * Authenticated app shell — Sidebar (persistent on `lg+`, drawer on `< lg`)
 * + DemoBanner + main content. Pages opt out of the sidebar via
 * `showSidebar={false}` for pre-auth screens.
 */
export default function AppShell({ children, showSidebar = true }: Readonly<AppShellProps>) {
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
                {/* Skip link — visible only on keyboard focus. Lands keyboard
                    users straight at page content without tabbing through the
                    full sidebar nav. */}
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-brand-500 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg"
                >
                    Lompat ke konten
                </a>

                <DemoBanner />
                <Sidebar />

                {/* Mobile top bar — hamburger + brand. Hidden on `lg+` where the
                    persistent sidebar already shows the brand. */}
                <div className="sticky top-0 z-10 flex items-center gap-3 border-b border-line bg-surface-elev/80 px-4 py-3 backdrop-blur dark:border-line-dark dark:bg-surface-dark-elev/80 lg:hidden">
                    <SidebarTrigger />
                    <BrandMark size="compact" />
                </div>

                {/* No `<main>` here — pages provide their own (often `<motion.main>`
                    for the enter-fade). `lg:ml-64` shifts page content past the
                    persistent sidebar. */}
                <div id="main-content" className="lg:ml-64">{children}</div>
            </div>
        </SidebarProvider>
    );
}
