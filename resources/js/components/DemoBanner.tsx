import { Icon } from '@iconify/react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/inertia';

/**
 * Conditional banner — only renders when demoLoginEnabled is true AND the
 * authenticated user is the demo user (currently inferred via auth.user
 * being present alongside the flag, since middleware shares the flag only
 * when the demo user actually logged in via /auth/demo).
 *
 * For the bigger revamp we keep the banner deliberately simple: the demo
 * gate logic stays server-side, the FE only renders the chip.
 */
export default function DemoBanner() {
    const { props } = usePage<SharedProps>();
    if (!props.demoLoginEnabled || props.auth.user === null) return null;

    return (
        <div className="border-b border-accent-300/60 bg-accent-100 px-4 py-2 text-center text-xs font-medium text-accent-900 dark:border-accent-700/60 dark:bg-accent-900/40 dark:text-accent-100">
            <Icon icon="mdi:flask-outline" width={14} height={14} className="-mt-0.5 mr-1 inline-block align-middle" aria-hidden />
            Mode demo aktif — semua data di halaman ini adalah dummy.
        </div>
    );
}
