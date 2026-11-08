import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';

const LEGAL_ROWS: ReadonlyArray<{ href: string; label: string }> = [
    { href: '/terms', label: 'terms of use' },
    { href: '/privacy', label: 'privacy policy' },
    { href: '/training-disclaimer', label: 'training disclaimer' },
];

export default function LegalLinks() {
    return (
        <>
            {LEGAL_ROWS.map((row, index) => (
                <Link
                    key={row.href}
                    href={row.href}
                    className={cn(
                        'pressable focus-ring flex items-center justify-between gap-2 py-3.5 font-sans text-xs font-semibold text-foreground transition hover:text-horizon-ink',
                        index !== LEGAL_ROWS.length - 1 &&
                            'border-b border-dashed border-border',
                    )}
                >
                    {row.label}
                    <Icon
                        icon={ChevronRight}
                        width={16}
                        height={16}
                        className="shrink-0 text-text-3"
                        aria-hidden
                    />
                </Link>
            ))}
        </>
    );
}
