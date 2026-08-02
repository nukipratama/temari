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
        vibeLabel: 'Membara',
        vibeEmoji: '💥',
        mascotVoice: { ...payload(content), ...extra },
        featuredKartuVoice: payload('Kartu keren.'),
        featuredCardId: null,
        recoveryLabel: 'Pemulihan: 41j',
        recoveryTone: 'positive',
        recoveryHoursLabel: '41j',
        recoveryHours: 41,
        streakLabel: 'Lari hari ini',
        sigilPattern: 'orct',
        accessory: null,
        mood: 'nyala',
    };
}

const runWithWeather: ActivityDetail = {
    id: 1,
    activity_id: 99,
    name: 'Pagi',
    start_date_local: '2026-05-20T07:00',
    distance: 5000,
    moving_time: 1800,
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
                briefing={briefingWith('“Lari santai aja hari ini.”')}
                pose="observational"
                lastRun={null}
            />,
        );
        expect(screen.getByText('Kata Temari hari ini')).toBeInTheDocument();
        expect(
            screen.getByText(/Lari santai aja hari ini\./),
        ).toBeInTheDocument();
    });

    it('splits the merged voice into a session title and Temari’s reasoning', () => {
        render(
            <KataTemariCard
                briefing={briefingWith(
                    'Tempo ringan hari ini.\n\nDua sesi terakhirmu easy semua, jadi jaga pace di zona 2 selama 40 menit.',
                )}
                pose="observational"
                lastRun={null}
            />,
        );
        expect(screen.getByText('Tempo ringan hari ini.')).toBeInTheDocument();
        expect(
            screen.getByText(/Dua sesi terakhirmu easy semua/),
        ).toBeInTheDocument();
    });

    it('renders a weather chip from the last run', () => {
        render(
            <KataTemariCard
                briefing={briefingWith('Tempo ringan.')}
                pose="observational"
                lastRun={runWithWeather}
            />,
        );
        expect(screen.getByText('28°C · 70%')).toBeInTheDocument();
    });

    it('emits no chip when there is no last run', () => {
        render(
            <KataTemariCard
                briefing={briefingWith('Tempo ringan.')}
                pose="observational"
                lastRun={null}
            />,
        );
        expect(screen.queryByText(/°C/)).not.toBeInTheDocument();
    });

    it('flips "Saran lain" to its pending label when triggered', async () => {
        render(
            <KataTemariCard
                briefing={briefingWith('Tempo ringan.')}
                pose="observational"
                lastRun={null}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Saran lain' }));
        expect(
            screen.getByRole('button', { name: 'Lagi mikir…' }),
        ).toBeInTheDocument();
        // The global default fetch mock (a 404) still resolves for real, so the
        // trigger's catch/finally (setStatus('failed'), setPending(false)) fires
        // on a later microtask — wait for it to settle back to the idle label
        // instead of leaving it to fire unmonitored after the test returns.
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Saran lain' }),
            ).toBeInTheDocument(),
        );
    });

    it('disables "Saran lain" and shows a countdown while on cooldown', () => {
        render(
            <KataTemariCard
                briefing={briefingWith('Tempo ringan.', {
                    retry_after_seconds: 900,
                })}
                pose="observational"
                lastRun={null}
            />,
        );
        const button = screen.getByRole('button', {
            name: 'Tunggu 15:00 sebelum minta saran lain',
        });
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('15:00');
    });

    it('hides the "Saran lain" button when AI is globally paused', () => {
        setMockPage({ aiPaused: true });
        render(
            <KataTemariCard
                briefing={briefingWith('Tempo ringan.')}
                pose="observational"
                lastRun={null}
            />,
        );
        expect(
            screen.queryByRole('button', { name: /Saran lain/ }),
        ).not.toBeInTheDocument();
    });

    it('shows a long body in full, with nothing to expand', () => {
        const longBody =
            'Jaga pace di zona 2 selama empat puluh menit penuh, '.repeat(4);
        render(
            <KataTemariCard
                briefing={briefingWith(`Tempo ringan hari ini.\n\n${longBody}`)}
                pose="observational"
                lastRun={null}
            />,
        );
        expect(screen.getByText(longBody.trim())).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Baca selengkapnya' }),
        ).not.toBeInTheDocument();
    });
});
