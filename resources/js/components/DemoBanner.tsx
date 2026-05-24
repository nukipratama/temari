import { Icon } from '@iconify/react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/inertia';

export default function DemoBanner() {
    const { props } = usePage<SharedProps>();
    if (!props.demoLoginEnabled || props.auth.user === null) return null;

    return (
        <div className="border-b border-horizon/40 bg-horizon/15 px-4 py-2 text-center text-xs font-medium text-ember-deep">
            <Icon icon="mdi:flask-outline" width={14} height={14} className="-mt-0.5 mr-1 inline-block align-middle" aria-hidden />
            Mode demo aktif — semua data di halaman ini adalah dummy.
        </div>
    );
}
