import { useState } from 'react';
import ReadMoreToggle from '@/components/ui/ReadMoreToggle';
import { cn } from '@/lib/cn';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';

export default function ExpandableQuote({ text, onSky = false }: Readonly<{ text: string; onSky?: boolean }>) {
    const [expanded, setExpanded] = useState(false);
    return (
        <div>
            <p className={cn('whitespace-pre-line font-display text-base italic leading-relaxed', onSky ? 'text-cream' : 'text-ink', !expanded && 'line-clamp-3')}>
                {/* stripEdgeQuotes: narration that opens by quoting a card name
                    ("Full Send" sekali seumur…) would otherwise collide with this
                    decorative frame and render as a doubled opening quote. */}
                &ldquo;{renderBold(stripEdgeQuotes(text))}&rdquo;
            </p>
            {text.length > 150 && <ReadMoreToggle expanded={expanded} onToggle={() => setExpanded(!expanded)} />}
        </div>
    );
}
