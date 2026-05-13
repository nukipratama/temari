import { Icon } from '@iconify/react';

/**
 * Small warning chip — rendered only when an LLM-backed narrator fell back
 * to the rule-based secondary (i.e. result.degraded === true). When the
 * LLM env is not configured, narrator runs are intended rule-based and
 * never set degraded, so this chip stays hidden.
 */
export default function DegradedChip() {
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-mood-cooked/15 px-2 py-0.5 text-[10px] font-semibold text-mood-cooked">
            <Icon icon="mdi:tools" width={12} height={12} aria-hidden />
            mode darurat
        </span>
    );
}
