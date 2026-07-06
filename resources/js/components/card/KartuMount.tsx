import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * The sky-gradient frame every in-app Kartu sits in outside a stat grid (the
 * activity-detail section, the Koleksi grid cells): a dark navy mount so the
 * card reads as "mounted" on a display case rather than floating bare on the
 * cream page background. The glow itself lives on the Kartu's own border
 * (`.kartu-glow`, tinted per rarity) — this mount stays a plain, unlit frame.
 */
export default function KartuMount({ children, className }: Readonly<{ children: ReactNode; className?: string }>) {
    return (
        <div
            className={cn('relative flex w-full items-center justify-center overflow-hidden rounded-3xl p-3', className)}
            style={{ background: 'linear-gradient(165deg, var(--color-sky-deep), var(--color-sky-2))' }}
        >
            <div className="relative w-full rounded-2xl shadow-2xl">{children}</div>
        </div>
    );
}
