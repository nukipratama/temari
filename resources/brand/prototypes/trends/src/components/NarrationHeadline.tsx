import { Sparkles } from 'lucide-react';

import { headlineNarration, type RangeKey } from '@/data/mock';

/**
 * The one narrated read at the top of the page, everything below is evidence
 * for it. The copy is fixture text (see `headlineNarration` in data/mock.ts),
 * not a live model call — see the caption below for what stands in for it in
 * the real app.
 */
export function NarrationHeadline({ range }: Readonly<{ range: RangeKey }>) {
    return (
        <div className="rounded-(--r-card) border border-horizon-ink/25 bg-horizon/10 p-6 sm:p-8">
            <div className="flex items-center gap-1.5 text-horizon-ink">
                <Sparkles className="size-3.5" aria-hidden />
                <span className="eyebrow text-[11px]">Temari&apos;s read</span>
            </div>
            <p className="display mt-3 max-w-prose text-xl leading-snug text-ink sm:text-2xl">
                {headlineNarration[range]}
            </p>
            <p className="mt-4 max-w-prose text-xs text-ink-3">
                Fixture copy, hand-written in Temari&apos;s narration voice —
                not a live model call. In the app, a block like this would be
                produced by the same pipeline that already narrates weekly
                recaps: an Analysis row, a Narrator prompt like this one, a
                rule-based fallback when Azure isn&apos;t configured, and a
                cost-ceiling degrade on expensive days.
            </p>
        </div>
    );
}
