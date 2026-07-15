import { useState } from 'react';
import { Icon } from '@iconify/react';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import ReadMoreToggle from '@/components/ui/ReadMoreToggle';
import SectionLabel from '@/components/ui/SectionLabel';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import { cooldownAriaLabel, useCooldownCountdown } from '@/hooks/useCooldownCountdown';
import { cn } from '@/lib/cn';
import { formatDurationHMS } from '@/lib/pace';
import { renderBold } from '@/lib/richText';
import { formatWeather } from '@/pages/HariIni/helpers';
import type { ActivityDetail, AnalysisPayload } from '@/types/inertia';

/**
 * Renders the LLM suggestion as a structured 2-part block:
 *  - First paragraph = title (bold display, ends with a period).
 *  - Remaining paragraphs = body, separated by `\n\n`, rendered with
 *    `whitespace-pre-line` so paragraph breaks survive.
 * Falls back to a single paragraph if the LLM didn't follow the format.
 */
function SuggestionContent({ text }: Readonly<{ text: string }>) {
    const [expanded, setExpanded] = useState(false);
    const parts = text.split(/\n\n+/).map((s) => s.trim()).filter(Boolean);
    if (parts.length === 0) {
        return null;
    }
    const [titleRaw, ...rest] = parts;
    const title = titleRaw.replace(/^[""]|[""]$/g, '');
    const body = rest.join('\n\n');

    return (
        <div className="space-y-2.5">
            <h3 className="font-display text-display-xs leading-tight tracking-[-0.01em] text-ink">
                {renderBold(title)}
            </h3>
            {body !== '' && (
                <div>
                    <p className={cn('whitespace-pre-line font-sans text-sm leading-relaxed text-ink-2', !expanded && 'line-clamp-3')}>
                        {renderBold(body)}
                    </p>
                    {body.length > 150 && <ReadMoreToggle expanded={expanded} onToggle={() => setExpanded(!expanded)} />}
                </div>
            )}
        </div>
    );
}

export default function SuggestionCard({ suggestion, lastRun }: Readonly<{ suggestion: AnalysisPayload; lastRun: ActivityDetail | null }>) {
    const { trigger, pending, retryAfterSeconds, paused } = useAnalysisTrigger(suggestion, ['briefing']);
    const cooldownRemaining = useCooldownCountdown(retryAfterSeconds);
    const cooling = cooldownRemaining > 0;

    let label = 'Saran lain';
    if (cooling) {
        label = formatDurationHMS(cooldownRemaining);
    } else if (pending) {
        label = 'Lagi mikir…';
    }

    const weatherChipLabel = lastRun
        ? formatWeather(
            lastRun.weather_temp_c ?? null,
            lastRun.weather_humidity_pct ?? null,
            lastRun.weather_rain_detected ?? null,
        )
        : null;

    return (
        <Card padding="md" as="section" className="flex h-full flex-col gap-3">
            <SectionLabel dot className="mb-0">Saran sesi dari Temari</SectionLabel>
            <AnalysisStatus
                analysis={suggestion}
                inertiaReloadProps={['briefing']}
                allowReanalyze={false}
                renderContent={(text) => <SuggestionContent text={text} />}
            />
            {weatherChipLabel && (
                <div className="flex flex-wrap gap-1.5">
                    <Chip>{weatherChipLabel}</Chip>
                </div>
            )}
            {!paused && (
                <div className="mt-auto pt-2">
                    <button
                        type="button"
                        onClick={trigger}
                        disabled={pending || cooling}
                        aria-label={cooldownAriaLabel(cooldownRemaining, 'minta saran lain')}
                        className="focus-ring rounded inline-flex items-center self-start gap-1 text-xs text-ink-3 hover:text-leaf-deep transition-colors disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:text-ink-3"
                    >
                        <Icon icon="mdi:auto-awesome" aria-hidden />
                        <span>{label}</span>
                    </button>
                </div>
            )}
        </Card>
    );
}
