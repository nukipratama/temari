import TemariMark from '@/components/TemariMark';
import { cn } from '@/lib/cn';

interface BrandMarkProps {
    /** Wordmark color tone — flip to 'cream' when the mark sits on a dark hero surface. */
    tone?: 'ink' | 'cream';
    className?: string;
    /** Extra classes on the wordmark span — lets a cramped host (e.g. the mobile top bar) hide it responsively. */
    wordmarkClassName?: string;
}

export default function BrandMark({
    tone = 'ink',
    className,
    wordmarkClassName,
}: Readonly<BrandMarkProps>) {
    const isCream = tone === 'cream';

    return (
        <div className={cn('flex items-center gap-2.5', className)}>
            <TemariMark
                size={28}
                color={
                    isCream ? 'var(--color-cream)' : 'var(--color-foreground)'
                }
                className="shrink-0"
            />
            <span
                className={cn(
                    'font-mono text-xl font-bold leading-none tracking-[-0.02em]',
                    isCream ? 'text-cream' : 'text-foreground',
                    wordmarkClassName,
                )}
            >
                temari
            </span>
        </div>
    );
}
