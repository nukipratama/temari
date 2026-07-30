import { Icon } from '@iconify/react';
import Chip from '@/components/ui/Chip';
import SectionLabel from '@/components/ui/SectionLabel';
import Temari from '@/components/temari/Temari';
import { type TemariPose } from '@/components/temari/TemariProto';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import { cooldownAriaLabel, useCooldownCountdown } from '@/hooks/useCooldownCountdown';
import { cn } from '@/lib/cn';
import { formatDurationHMS } from '@/lib/pace';
import { renderBold } from '@/lib/richText';
import { formatWeather } from '@/pages/HariIni/helpers';
import type { ActivityDetail, BriefingResult } from '@/types/inertia';

/**
 * Renders the day's Temari voice as a structured 2-part block:
 *  - First paragraph = title (bold display, ends with a period).
 *  - Remaining paragraphs = body, separated by `\n\n`, rendered with
 *    `whitespace-pre-line` so paragraph breaks survive.
 * Falls back to a single paragraph if the LLM didn't follow the format.
 */
function VoiceContent({ text, onSky = false }: Readonly<{ text: string; onSky?: boolean }>) {
    const parts = text.split(/\n\n+/).map((s) => s.trim()).filter(Boolean);
    if (parts.length === 0) {
        return null;
    }
    const [titleRaw, ...rest] = parts;
    const title = titleRaw.replace(/^[""]|[""]$/g, '');
    const body = rest.join('\n\n');

    return (
        <div className="space-y-2.5">
            <h3 className={cn('font-display text-display-xs leading-tight tracking-[-0.01em]', onSky ? 'text-cream' : 'text-ink')}>
                {renderBold(title)}
            </h3>
            {body !== '' && (
                <p className={cn('whitespace-pre-line font-sans text-sm leading-relaxed', onSky ? 'text-ink-on-sky' : 'text-ink-2')}>
                    {renderBold(body)}
                </p>
            )}
        </div>
    );
}

export default function KataTemariCard({ briefing, pose, lastRun }: Readonly<{ briefing: BriefingResult; pose: TemariPose; lastRun: ActivityDetail | null }>) {
    const { trigger, pending, retryAfterSeconds, paused } = useAnalysisTrigger(briefing.mascotVoice, ['briefing']);
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
        <div className="flex items-start gap-3.5">
            <Temari pose={pose} size={48} animate={false} />
            <div className="flex min-w-0 flex-1 flex-col gap-3">
                <SectionLabel dot onSky className="mb-0">Kata Temari hari ini</SectionLabel>
                <AnalysisStatus
                    analysis={briefing.mascotVoice}
                    inertiaReloadProps={['briefing']}
                    allowReanalyze={false}
                    onSky
                    renderContent={(text) => <VoiceContent text={text} onSky />}
                />
                {weatherChipLabel && (
                    <div className="flex flex-wrap gap-1.5">
                        <Chip tone="onSky">{weatherChipLabel}</Chip>
                    </div>
                )}
                {!paused && (
                    <div className="pt-1">
                        <button
                            type="button"
                            onClick={trigger}
                            disabled={pending || cooling}
                            aria-label={cooldownAriaLabel(cooldownRemaining, 'minta saran lain')}
                            className="focus-ring-on-sky rounded inline-flex items-center self-start gap-1 text-xs text-ink-on-sky hover:text-cream transition-colors disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:text-ink-on-sky"
                        >
                            <Icon icon="mdi:auto-awesome" aria-hidden />
                            <span>{label}</span>
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
