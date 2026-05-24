import { Link, usePage } from '@inertiajs/react';
import BrandMark from '@/components/BrandMark';
import StravaSyncBadge from '@/components/StravaSyncBadge';
import UserAvatar from '@/components/UserAvatar';
import type { SharedProps } from '@/types/inertia';

/**
 * Compact header for `< lg` viewports. The desktop TopNav already covers brand
 * mark + sync status + avatar; on mobile that bar is hidden so this one carries
 * the same identity at the top while MobileBottomNav handles tab switching.
 */
export default function MobileTopBar() {
    const { props } = usePage<SharedProps>();
    const user = props.auth.user;
    const stravaSync = props.stravaSync ?? null;

    return (
        <header className="flex items-center justify-between gap-3 border-b border-cream-deep bg-cream px-5 py-3 lg:hidden">
            <Link href="/" aria-label="Beranda">
                <BrandMark />
            </Link>
            <div className="flex items-center gap-2">
                <StravaSyncBadge sync={stravaSync} density="compact" />
                {user && (
                    <UserAvatar
                        name={user.name}
                        avatarUrl={user.avatar_url}
                        size="sm"
                        className="ring-2 ring-cream-deep"
                    />
                )}
            </div>
        </header>
    );
}
