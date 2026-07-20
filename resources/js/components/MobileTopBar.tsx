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
 * The strip around the notch itself is not painted here: under
 * `apple-mobile-web-app-status-bar-style: default` iOS reserves that region and
 * fills it with the `theme-color` meta, which is pinned to this bar's cream-deep
 * in app.blade.php. The `env(safe-area-inset-top)` padding stays as the fallback
 * for browsers that do hand us the inset, where it keeps the row clear of the
 * notch; it resolves to the 0.75rem floor everywhere else.
 */
export default function MobileTopBar() {
    const { props } = usePage<SharedProps>();
    const user = props.auth.user;
    const stravaSync = props.stravaSync ?? null;
    const scrolled = useScrolled();

    return (
        <header
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
