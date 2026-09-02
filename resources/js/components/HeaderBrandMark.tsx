import TemariMark from '@/components/TemariMark';
import { cn } from '@/lib/cn';

interface HeaderBrandMarkProps {
    className?: string;
    /** Extra classes on the wordmark span — lets a cramped host (e.g. the mobile top bar) hide it responsively. */
    wordmarkClassName?: string;
}

/**
 * The persistent shell header's brand mark: the prototype's ring glyph plus a
 * lowercase wordmark, matching its `AppTopbar`.
 */
export default function HeaderBrandMark({
    className,
    wordmarkClassName,
}: Readonly<HeaderBrandMarkProps>) {
    return (
        <div className={cn('flex items-center gap-2', className)}>
            <TemariMark size={22} className="shrink-0" />
            <span
                className={cn(
                    'text-sm leading-none font-extrabold tracking-tight text-foreground',
                    wordmarkClassName,
                )}
            >
                temari
            </span>
        </div>
    );
}
