import { Link } from '@inertiajs/react';

import { cn } from '@/lib/cn';
import { pillButtonVariants } from '@/lib/variants';

export default function AddRacePrompt({
    className,
}: Readonly<{ className?: string }>) {
    return (
        <div className={cn('flex flex-col items-start gap-2', className)}>
            <p className="text-sm text-text-2">
                add a race and the plan builds base → build → peak → taper to
                it.
            </p>
            <Link
                href="/race"
                className={pillButtonVariants({ tone: 'muted', size: 'xs' })}
            >
                + add a race
            </Link>
        </div>
    );
}
