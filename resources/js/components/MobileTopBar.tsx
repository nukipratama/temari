import { Link, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import BrandMark from '@/components/BrandMark';
import StravaSyncBadge from '@/components/StravaSyncBadge';
import UserMenu from '@/components/UserMenu';
import { useScrolled } from '@/hooks/useScrolled';
import { cn } from '@/lib/cn';
import type { SharedProps } from '@/types/inertia';

/**
 * Screens that were pushed onto a tab rather than being one, mapped to the
 * parent they return to. Presence here is what makes the bar show a back
 * button instead of the brand mark.
 *
 * Deliberately an explicit map rather than something derived from
 * `activeTabFromUrl`: `/kalender`, `/rekor`, `/aksesori` and `/target` all
 * resolve to a tab too, but they are reached through in-page tab strips, so
 * they are siblings of their tab root and must keep the brand mark.
 *
 * `ZonaHR` points at `/pengaturan`, not the `/profil` its old in-page
 * breadcrumb used — that link read "Aku · Pengaturan" as a trail but skipped
 * straight past its actual parent.
 */
const BACK_TARGETS: Record<string, { href: string; label: string }> = {
    'Runs/Show': { href: '/aktivitas', label: 'Riwayat' },
    // Pengaturan is deliberately absent: it is one tap from the Aku tab and
    // from the avatar menu on every page, so it reads as a root, not a push.
    'Pengaturan/ZonaHR': { href: '/pengaturan', label: 'Pengaturan' },
};

/**
 * Compact header for `< lg` viewports. The desktop TopNav already covers brand
 * mark + sync status + avatar; on mobile that bar is hidden so this one carries
 * the same identity at the top while MobileBottomNav handles tab switching.
 *
 * Sticky + translucent so content slides under it the way an iOS navigation bar
 * behaves, with the hairline appearing only once something is actually
 * underneath (see useScrolled).
 *
 * On a pushed screen the brand mark gives way to a back button, which is the
 * native split: roots show identity, pushes show a way out. The right-hand side
 * (sync chip + avatar) stays put on every screen so the bar never reshuffles.
 *
 * `pt-[max(0.75rem,env(safe-area-inset-top))]` is what keeps the row clear of
 * the notch. Under `black-translucent` that inset resolves to a real value and
 * the max() picks it; in a browser tab it collapses to the 0.75rem floor.
 */
export default function MobileTopBar() {
    const page = usePage<SharedProps>();
    const user = page.props.auth.user;
    const stravaSync = page.props.stravaSync ?? null;
    const scrolled = useScrolled();
    const back = BACK_TARGETS[page.component];

    return (
        <header
            data-testid="mobile-top-bar"
            className={cn(
                'sticky top-0 z-30 flex items-center justify-between gap-3 border-b bg-cream-deep/85 px-5 pb-3 pt-[max(0.75rem,env(safe-area-inset-top))] backdrop-blur-xl transition-colors lg:hidden',
                scrolled ? 'border-line' : 'border-transparent',
            )}
        >
            {back ? (
                // A real href rather than history.back(): a notification deep
                // link opens the run detail cold, with nothing behind it, and
                // history.back() would strand the user or exit the app.
                // useSwipeBack stays as the gesture equivalent.
                <Link
                    href={back.href}
                    aria-label={`Kembali ke ${back.label}`}
                    className="pressable focus-ring -ml-1 inline-flex min-w-0 items-center gap-1 rounded py-1 pl-1 pr-2 font-mono text-xs font-bold uppercase tracking-[0.14em] text-ink-2 transition hover:text-ink"
                >
                    <Icon icon="mdi:chevron-left" width={18} height={18} aria-hidden className="shrink-0" />
                    <span className="truncate">{back.label}</span>
                </Link>
            ) : (
                <Link href="/" aria-label="Beranda" className="focus-ring rounded">
                    <BrandMark wordmarkClassName="hidden min-[350px]:inline" />
                </Link>
            )}
            <div className="flex items-center gap-2">
                <StravaSyncBadge sync={stravaSync} density="compact" />
                {user && (
                    <UserMenu name={user.name} avatarUrl={user.avatar_url} />
                )}
            </div>
        </header>
    );
}
