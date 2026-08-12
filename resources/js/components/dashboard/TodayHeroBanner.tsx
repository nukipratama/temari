import { Icon } from '@iconify/react';

import type { ActivityDetail, BriefingResult } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Temari from '@/components/temari/Temari';
import { type TemariPose } from '@/components/temari/TemariProto';
import Chip from '@/components/ui/Chip';
import PageHero from '@/components/ui/PageHero';
import SectionLabel from '@/components/ui/SectionLabel';
import { useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import {
    cooldownAriaLabel,
    useCooldownCountdown,
} from '@/hooks/useCooldownCountdown';
import { formatDurationHMS } from '@/lib/pace';
import { renderBold } from '@/lib/richText';
import { formatWeather } from '@/pages/Today/helpers';

/**
 * Renders the day's Temari voice as a structured 2-part block:
 *  - First paragraph = title (bold display, ends with a period).
 *  - Remaining paragraphs = body, collapsed into one flowing paragraph
 *    (this card is narrow, a two-up gap read as too much whitespace).
 * Falls back to a single paragraph if the LLM didn't follow the format.
 */
function VoiceContent({ text }: Readonly<{ text: string }>) {
    const parts = text
        .split(/\n\n+/)
        .map((s) => s.trim())
        .filter(Boolean);
    if (parts.length === 0) {
        return null;
    }
    const [titleRaw, ...rest] = parts;
    const title = titleRaw.replace(/^[""]|[""]$/g, '');
    const body = rest.join(' ');

    return (
        <div className="space-y-2.5">
            <h3 className="font-display text-display-xs leading-tight tracking-[-0.01em] text-ink">
                {renderBold(title)}
            </h3>
            {body !== '' && (
                <p className="font-sans text-sm leading-relaxed text-ink-2">
                    {renderBold(body)}
                </p>
            )}
        </div>
    );
}

interface TodayHeroBannerProps {
    firstName: string;
    dateLine: string;
    vibeSubtitle: string;
    briefing: BriefingResult;
    pose: TemariPose;
    lastRun: ActivityDetail | null;
}

/**
 * Today's full-width banner: the day's greeting plus Temari's forward-looking
 * guidance for it, merged into one block instead of a separate sidebar card.
 */
export default function TodayHeroBanner({
    firstName,
    dateLine,
    vibeSubtitle,
    briefing,
    pose,
    lastRun,
}: Readonly<TodayHeroBannerProps>) {
    const { trigger, pending, retryAfterSeconds, paused } = useAnalysisTrigger(
        briefing.mascotVoice,
        ['briefing'],
    );
    const cooldownRemaining = useCooldownCountdown(retryAfterSeconds);
    const cooling = cooldownRemaining > 0;

    let label = 'Another take';
    if (cooling) {
        label = formatDurationHMS(cooldownRemaining);
    } else if (pending) {
        label = 'Thinking…';
    }

    const weatherChipLabel = lastRun
        ? formatWeather(
              lastRun.weather_temp_c ?? null,
              lastRun.weather_humidity_pct ?? null,
              lastRun.weather_rain_detected ?? null,
          )
        : null;

    return (
        <div className="flex flex-col gap-6">
            <PageHero size="2xl" eyebrow={dateLine}>
                Hey, {firstName}
                <br />
                <span className="italic text-horizon">{vibeSubtitle}</span>
            </PageHero>
            <div className="flex flex-col gap-3">
                <SectionLabel dot className="mb-0">
                    Today from Temari
                </SectionLabel>
                <div className="flex items-start gap-3">
                    <Temari pose={pose} size={48} animate={false} />
                    <div className="min-w-0 flex-1">
                        <AnalysisStatus
                            analysis={briefing.mascotVoice}
                            inertiaReloadProps={['briefing']}
                            allowReanalyze={false}
                            renderContent={(text) => (
                                <VoiceContent text={text} />
                            )}
                        />
                    </div>
                </div>
                {weatherChipLabel && (
                    <div className="flex flex-wrap gap-1.5">
                        <Chip>{weatherChipLabel}</Chip>
                    </div>
                )}
                {!paused && (
                    <button
                        type="button"
                        onClick={trigger}
                        disabled={pending || cooling}
                        aria-label={cooldownAriaLabel(
                            cooldownRemaining,
                            'asking for another take',
                        )}
                        className="focus-ring inline-flex items-center self-start gap-1 rounded text-xs text-ink-3 transition-colors hover:text-leaf-deep disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:text-ink-3"
                    >
                        <Icon icon="mdi:auto-awesome" aria-hidden />
                        <span>{label}</span>
                    </button>
                )}
            </div>
        </div>
    );
}
