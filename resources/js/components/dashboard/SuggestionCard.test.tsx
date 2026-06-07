import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import SuggestionCard from './SuggestionCard';
import type { ActivityDetail, AnalysisPayload } from '@/types/inertia';

function suggestion(content: string): AnalysisPayload {
    return {
        id: 2,
        status: 'done',
        content,
        type: 'briefing_suggestion',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '2026-05-18',
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

describe('SuggestionCard', () => {
    it('renders the section label and a title-only suggestion', () => {
        render(<SuggestionCard suggestion={suggestion('“Lari santai aja hari ini.”')} lastRun={null} />);
        expect(screen.getByText('Saran sesi dari Temari')).toBeInTheDocument();
        expect(screen.getByText(/Lari santai aja hari ini\./)).toBeInTheDocument();
    });

    it('splits a two-paragraph suggestion into title + body', () => {
        render(
            <SuggestionCard
                suggestion={suggestion('Tempo ringan hari ini.\n\nJaga pace di zona 2 selama 40 menit.')}
                lastRun={null}
            />,
        );
        expect(screen.getByText('Tempo ringan hari ini.')).toBeInTheDocument();
        expect(screen.getByText(/Jaga pace di zona 2/)).toBeInTheDocument();
    });

    it('renders a weather chip from the last run', () => {
        render(<SuggestionCard suggestion={suggestion('Tempo ringan.')} lastRun={runWithWeather} />);
        expect(screen.getByText('28°C · 70%')).toBeInTheDocument();
    });

    it('emits no chip when there is no last run', () => {
        render(<SuggestionCard suggestion={suggestion('Tempo ringan.')} lastRun={null} />);
        expect(screen.queryByText(/°C/)).not.toBeInTheDocument();
    });

    it('flips "Saran lain" to its pending label when triggered', () => {
        render(<SuggestionCard suggestion={suggestion('Tempo ringan.')} lastRun={null} />);
        fireEvent.click(screen.getByRole('button', { name: 'Saran lain' }));
        expect(screen.getByRole('button', { name: 'Lagi mikir…' })).toBeInTheDocument();
    });
});
