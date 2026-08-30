import { motion } from 'framer-motion';
import { useId } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { pressShrink } from '@/lib/motion';

/**
 * A single tappable option row for a preference question (experience level,
 * goal type) — icon chip, bold label, supporting description. The
 * description is wired via `aria-label` + `aria-describedby` rather than
 * left to the default subtree-text computation, so the accessible name
 * stays just the label instead of the label and description concatenated.
 */
export default function IconChoiceCard({
    icon,
    label,
    description,
    active,
    onClick,
}: Readonly<{
    icon: string;
    label: string;
    description?: string;
    active: boolean;
    onClick: () => void;
}>) {
    const descriptionId = useId();

    return (
        <motion.button
            type="button"
            onClick={onClick}
            whileTap={pressShrink}
            aria-pressed={active}
            aria-label={label}
            aria-describedby={description ? descriptionId : undefined}
            className={cn(
                'focus-ring flex w-full items-center gap-3 rounded-xl border px-3.5 py-3 text-left transition',
                active
                    ? 'border-icon-accent bg-horizon/10'
                    : 'border-border-strong bg-card shadow-e1',
            )}
        >
            <span
                className={cn(
                    'flex size-9 flex-none items-center justify-center rounded-full',
                    active
                        ? 'bg-icon-accent text-btn-primary-fg'
                        : 'bg-muted text-foreground',
                )}
            >
                <Icon icon={icon} width={18} height={18} aria-hidden />
            </span>
            <span className="min-w-0 flex-1">
                <span
                    className={cn(
                        'block font-sans text-sm font-bold',
                        active ? 'text-icon-accent' : 'text-foreground',
                    )}
                >
                    {label}
                </span>
                {description && (
                    <span
                        id={descriptionId}
                        className="mt-0.5 block text-xs leading-snug text-text-2"
                    >
                        {description}
                    </span>
                )}
            </span>
        </motion.button>
    );
}
