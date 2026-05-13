import { Icon } from '@iconify/react';
import { useSidebar } from '@/contexts/SidebarContext';

/**
 * Hamburger button — visible only on `< lg`. Opens the mobile sidebar
 * `<dialog>` via context. Native dialog provides focus trap + Esc-close
 * + backdrop click, so we just call `showModal()` and let the platform
 * handle a11y.
 */
export default function SidebarTrigger() {
    const { open } = useSidebar();
    return (
        <button
            type="button"
            onClick={open}
            aria-label="Buka menu navigasi"
            className="flex h-10 w-10 items-center justify-center rounded-lg border border-line text-ink transition hover:bg-line/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:border-line-dark dark:text-ink-dark dark:hover:bg-line-dark lg:hidden"
        >
            <Icon icon="mdi:menu" width={22} height={22} aria-hidden />
        </button>
    );
}
