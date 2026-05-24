import { Icon } from '@iconify/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { ICON_TONE, type Tone } from '@/lib/tones';

interface SectionHeadingProps {
    icon?: string;
    title: string;
    subtitle?: ReactNode;
    tone?: Tone;
    className?: string;
}

const ACCENT_RULE: Record<Tone, string> = {
    brand: 'before:bg-leaf',
    accent: 'before:bg-horizon',
    pop: 'before:bg-citrus',
    neutral: 'before:bg-line',
};

// Section heading with icon-in-tinted-circle + a thin colored accent
// underline. Makes each h2 feel like the entry to its own "room"
// rather than just stacked text. Pass `subtitle` for the supporting
// caption underneath.
export default function SectionHeading({
    icon,
    title,
    subtitle,
    tone = 'brand',
    className,
}: Readonly<SectionHeadingProps>) {
    return (
        <div className={cn('flex items-start gap-3', className)}>
            {icon !== undefined && icon !== '' && (
                <span
                    className={cn(
                        'mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl',
                        ICON_TONE[tone],
                    )}
                    aria-hidden
                >
                    <Icon icon={icon} width={18} height={18} />
                </span>
            )}
            <div className="min-w-0">
                <h2
                    className={cn(
                        'relative inline-block pb-1.5 text-lg font-bold tracking-tight text-ink',
                        "before:absolute before:bottom-0 before:left-0 before:h-[2px] before:w-8 before:rounded-full before:content-['']",
                        ACCENT_RULE[tone],
                    )}
                >
                    {title}
                </h2>
                {subtitle !== undefined && subtitle !== null && (
                    <p className="mt-2 text-sm leading-relaxed text-ink-2">{subtitle}</p>
                )}
            </div>
        </div>
    );
}
