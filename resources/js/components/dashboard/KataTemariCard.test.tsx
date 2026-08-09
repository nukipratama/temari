import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type {
    ActivityDetail,
    AnalysisPayload,
    BriefingResult,
} from '@/types/inertia';

import { setMockPage } from '@/test/setup';

import KataTemariCard from './KataTemariCard';

function payload(content: string): AnalysisPayload {
    return {
        id: 2,
        status: 'done',
        content,
        type: 'briefing_mascot_voice',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '2026-05-18',
    };
}

function briefingWith(
    content: string,
    extra: Partial<AnalysisPayload> = {},
): BriefingResult {
    return {
        vibeState: 'pumped',
        vibeLabel: 'Pumped',
        vibeEmoji: '💥',
        mascotVoice: { ...payload(content), ...extra },
        featuredKartuVoice: payload('Cool card.'),
        featuredCardId: null,
        recoveryLabel: 'Recovery: 41h',
        recoveryTone: 'positive',
        recoveryHoursLabel: '41h',
        recoveryHours: 41,
        streakLabel: 'Ran today',
        sigilPattern: 'orct',
        accessory: null,
        mood: 'nyala',
    };
}

const runWithWeather: ActivityDetail = {
    id: 1,
    activity_id: 99,
    name: 'Morning',
    start_date_local: '2026-05-20T07:00',
    distance: 5000,
    elapsed_time: 1800,
    average_heartrate: 145,
    trimp_edwards: 60,
    weather_temp_c: 28,
    weather_humidity_pct: 70,
    weather_rain_detected: false,
};

describe('KataTemariCard', () => {
    it('renders the section label and a title-only voice', () => {
        render(
            <KataTemariCard
                briefing={briefingWith('“Just an easy run today.”')}
                pose="observational"
                lastRun={null}
            />,
        );
        expect(screen.getByText('Today from Temari')).toBeInTheDocument();
        expect(
            screen.getByText(/Just an easy run today\./),
        ).toBeInTheDocument();
    });

    it('splits the merged voice into a session title and Temari’s reasoning', () => {
        render(
            <KataTemariCard
                briefing={briefingWith(
                    'Light tempo today.\n\nYour last two sessions were both easy, so keep the pace in zone 2 for 40 minutes.',
                )}
                pose="observational"
                lastRun={null}
            />,
        );
        expect(screen.getByText('Light tempo today.')).toBeInTheDocument();
        expect(
            screen.getByText(/Your last two sessions were both easy/),
        ).toBeInTheDocument();
    });

    it('renders a weather chip from the last run', () => {
        render(
            <KataTemariCard
                briefing={briefingWith('Light tempo.')}
                pose="observational"
                lastRun={runWithWeather}
            />,
        );
        expect(screen.getByText('28°C · 70%')).toBeInTheDocument();
    });

    it('emits no chip when there is no last run', () => {
        render(
            <KataTemariCard
                briefing={briefingWith('Light tempo.')}
                pose="observational"
                lastRun={null}
            />,
        );
        expect(screen.queryByText(/°C/)).not.toBeInTheDocument();
    });

    it('flips "Another take" to its pending label when triggered', async () => {
        render(
            <KataTemariCard
                briefing={briefingWith('Light tempo.')}
                pose="observational"
                lastRun={null}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Another take' }));
        expect(
            screen.getByRole('button', { name: 'Thinking…' }),
        ).toBeInTheDocument();
        // The global default fetch mock (a 404) still resolves for real, so the
        // trigger's catch/finally (setStatus('failed'), setPending(false)) fires
        // on a later microtask — wait for it to settle back to the idle label
        // instead of leaving it to fire unmonitored after the test returns.
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Another take' }),
            ).toBeInTheDocument(),
        );
    });

    it('disables "Another take" and shows a countdown while on cooldown', () => {
        render(
            <KataTemariCard
                briefing={briefingWith('Light tempo.', {
                    retry_after_seconds: 900,
                })}
                pose="observational"
                lastRun={null}
            />,
        );
        const button = screen.getByRole('button', {
            name: 'Wait 15:00 before asking for another take',
        });
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('15:00');
    });

    it('hides the "Another take" button when AI is globally paused', () => {
        setMockPage({ aiPaused: true });
        render(
            <KataTemariCard
                briefing={briefingWith('Light tempo.')}
                pose="observational"
                lastRun={null}
            />,
        );
        expect(
            screen.queryByRole('button', { name: /Another take/ }),
        ).not.toBeInTheDocument();
    });

    it('shows a long body in full, with nothing to expand', () => {
        const longBody =
            'Keep the pace in zone 2 for the full forty minutes, '.repeat(4);
        render(
            <KataTemariCard
                briefing={briefingWith(`Light tempo today.\n\n${longBody}`)}
                pose="observational"
                lastRun={null}
            />,
        );
        expect(screen.getByText(longBody.trim())).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Read more' }),
        ).not.toBeInTheDocument();
    });
});
