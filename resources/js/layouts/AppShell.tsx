import type { ReactNode } from 'react';
import AppHeader from '@/components/AppHeader';
import DemoBanner from '@/components/DemoBanner';

interface AppShellProps {
    children: ReactNode;
    showHeader?: boolean;
}

/**
 * Authenticated app shell — header + demo banner + main content.
 * Pages opt out of the header (e.g. login/welcome) by passing `showHeader={false}`.
 */
export default function AppShell({ children, showHeader = true }: AppShellProps) {
    return (
        <div className="min-h-screen bg-surface text-ink dark:bg-surface-dark dark:text-ink-dark">
            <DemoBanner />
            {showHeader && <AppHeader />}
            {children}
        </div>
    );
}
