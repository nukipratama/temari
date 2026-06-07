import { useState } from 'react';
import { cn } from '@/lib/cn';
import { renderBold } from '@/lib/richText';

export default function ExpandableQuote({ text }: Readonly<{ text: string }>) {
    const [expanded, setExpanded] = useState(false);
    return (
        <div>
            <p className={cn('whitespace-pre-line font-display text-base italic leading-relaxed text-ink', !expanded && 'line-clamp-3')}>
                &ldquo;{renderBold(text)}&rdquo;
            </p>
            {text.length > 150 && (
                <button
                    type="button"
                    onClick={() => setExpanded(!expanded)}
                    className="focus-ring mt-1 rounded font-mono text-[11px] font-semibold text-horizon transition hover:text-horizon/80"
                >
                    {expanded ? 'Tutup' : 'Baca selengkapnya'}
                </button>
            )}
        </div>
    );
}
