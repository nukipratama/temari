import { Link, usePage } from '@inertiajs/react';
import BrandMark from '@/components/BrandMark';
import StravaSyncBadge from '@/components/StravaSyncBadge';
import UserMenu from '@/components/UserMenu';
import type { SharedProps } from '@/types/inertia';

/**
 * Compact header for `< lg` viewports. The desktop TopNav already covers brand
 * mark + sync status + avatar; on mobile that bar is hidden so this one carries
 * the same identity at the top while MobileBottomNav handles tab switching.
 *
 * Installed as a PWA the page runs edge-to-edge (`viewport-fit=cover`), so the
 * top padding grows to `env(safe-area-inset-top)` and this bar's cream fills the
 * notch / status-bar strip instead of letting content slide under it. The inset
 * is 0 in a normal browser tab, where the fallback padding applies.
 */
export default function MobileTopBar() {
    const { props } = usePage<SharedProps>();
    const user = props.auth.user;
    const stravaSync = props.stravaSync ?? null;

    return (
        <header className="flex items-center justify-between gap-3 border-b border-line bg-cream-deep px-5 pb-3 pt-[max(0.75rem,env(safe-area-inset-top))] lg:hidden">
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
