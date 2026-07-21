import { Link, usePage } from '@inertiajs/react';
import BrandMark from '@/components/BrandMark';
import StravaSyncBadge from '@/components/StravaSyncBadge';
import UserMenu from '@/components/UserMenu';
import { useScrolled } from '@/hooks/useScrolled';
import { cn } from '@/lib/cn';
import type { SharedProps } from '@/types/inertia';

/**
 * Compact header for `< lg` viewports. The desktop TopNav already covers brand
 * mark + sync status + avatar; on mobile that bar is hidden so this one carries
 * the same identity at the top while MobileBottomNav handles tab switching.
 *
 * Sticky + translucent so content slides under it the way an iOS navigation bar
 * behaves, with the hairline appearing only once something is actually
 * underneath (see useScrolled).
 *
 * Rendered on **Aku only** (see AppShell). On the other tabs it was permanent
 * chrome carrying a decorative brand mark and an ambient sync chip, and the one
 * thing that genuinely needed reaching from anywhere — the account menu —
 * belongs on the profile tab the way it does in a native app.
 *
 * `pt-[max(0.75rem,env(safe-area-inset-top))]` is what keeps the row clear of
 * the notch. Under `black-translucent` that inset resolves to a real value and
 * the max() picks it; in a browser tab it collapses to the 0.75rem floor.
 */
export default function MobileTopBar() {
    const { props } = usePage<SharedProps>();
    const user = props.auth.user;
    const stravaSync = props.stravaSync ?? null;
    const scrolled = useScrolled();

    return (
        <header
            data-testid="mobile-top-bar"
            className={cn(
                'sticky top-0 z-30 flex items-center justify-between gap-3 border-b bg-cream-deep/85 px-5 pb-3 pt-[max(0.75rem,env(safe-area-inset-top))] backdrop-blur-xl transition-colors lg:hidden',
                scrolled ? 'border-line' : 'border-transparent',
            )}
        >
            <Link href="/" aria-label="Beranda" className="focus-ring rounded">
                <BrandMark wordmarkClassName="hidden min-[350px]:inline" />
            </Link>
            <div className="flex items-center gap-2">
                <StravaSyncBadge sync={stravaSync} density="compact" />
                {user && (
                    <UserMenu name={user.name} avatarUrl={user.avatar_url} />
                )}
            </div>
        </header>
    );
}
