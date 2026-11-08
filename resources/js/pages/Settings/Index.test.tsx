import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { defined } from '@/test/defined';
import { makeUser, setMockPage } from '@/test/setup';

import Settings from './Index';

const prefs = {
    notifications_enabled: false,
    briefing_push_enabled: true,
    briefing_push_minute: null,
    telegram_enabled: true,
    push_enabled: true,
};

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Settings', () => {
    it('shows nothing about an AI pause, since it renders no narration', () => {
        setMockPage({ auth: { user: makeUser() }, aiPaused: true });

        render(Settings.layout(<Settings />));

        expect(
            screen.queryByText(/catching her breath/),
        ).not.toBeInTheDocument();
    });

    it('titles the page with exactly one h1', () => {
        render(<Settings />);

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    });

    it('never skips a heading level down the page', () => {
        render(<Settings />);

        const levels = screen
            .getAllByRole('heading')
            .map((heading) => Number(heading.tagName.slice(1)));

        expect(levels[0]).toBe(1);
        levels.slice(1).forEach((level, index) => {
            expect(level - defined(levels[index])).toBeLessThanOrEqual(1);
        });
    });

    it('renders the settings sections', () => {
        render(<Settings />);
        expect(screen.getByText('Notifications')).toBeInTheDocument();
        expect(screen.getByText('Telegram')).toBeInTheDocument();
        expect(screen.getByText('heart-rate zones')).toBeInTheDocument();
        expect(screen.getByText('delete account')).toBeInTheDocument();
    });

    it('draws its section labels as plain eyebrows, with no trailing line', () => {
        const { container } = render(<Settings />);

        for (const label of [
            'Appearance',
            'Notifications',
            'Integrations',
            'Running',
            'The fine print',
            'Account',
        ]) {
            expect(screen.getByText(label)).toHaveClass('text-label-small');
        }
        for (const label of ['Theme', 'Training preferences']) {
            expect(screen.getByText(label)).toHaveClass('text-label-micro');
        }
        expect(container.querySelector('.h-px.flex-1')).toBeNull();
    });

    it('expands the HR zones disclosure inline, without navigating', () => {
        render(<Settings />);
        expect(screen.queryByLabelText('Max HR')).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: /heart-rate zones/ }),
        );

        expect(screen.getByLabelText('Max HR')).toBeInTheDocument();
    });

    it('passes the server-supplied HR-zones profile into the disclosure', () => {
        render(
            <Settings
                hrZones={{
                    profile: {
                        max_hr: 200,
                        resting_hr: 48,
                        hr_zones: {
                            Z1: { lo: 122, hi: 143 },
                            Z2: { lo: 143, hi: 160 },
                            Z3: { lo: 160, hi: 175 },
                            Z4: { lo: 175, hi: 185 },
                            Z5: { lo: 185, hi: 999 },
                        },
                        optimal_cadence_spm: 172,
                    },
                    source: 'manual',
                    stravaSyncedLabel: null,
                    canSyncFromStrava: false,
                }}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: /heart-rate zones/ }),
        );
        expect(screen.getByLabelText('Max HR')).toHaveValue(200);
    });

    // The prototype's order: the open preferences card first, the collapsed
    // zones disclosure under it.
    it('puts training preferences above the zones disclosure', () => {
        render(<Settings />);

        const preferences = screen.getByText('Training preferences');
        const zones = screen.getByText('heart-rate zones');

        expect(
            preferences.compareDocumentPosition(zones) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('renders the data-use statement the server hands it', () => {
        render(
            <Settings
                dataUse={{
                    headline: 'your data',
                    points: ['Inference only, never training.'],
                }}
            />,
        );
        expect(screen.getByText('your data')).toBeInTheDocument();
        expect(
            screen.getByText('Inference only, never training.'),
        ).toBeInTheDocument();
    });

    it('links out to the legal pages under the fine print', () => {
        render(<Settings />);

        expect(screen.getByText('The fine print')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /privacy policy/ }),
        ).toHaveAttribute('href', '/privacy');
    });

    // The page uses the editorial header every other page shares, not a bare
    // title.
    it('opens with the editorial header rather than a bare title', () => {
        render(<Settings />);
        expect(screen.getAllByText('Settings').length).toBeGreaterThan(0);
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'tune it your way.',
        );
    });

    it('hands the notification prefs and the Telegram link state to the notifications section', () => {
        render(
            <Settings
                notificationPrefs={prefs}
                telegram={{
                    connected: true,
                    username: 'ada_runs',
                    connect_url: null,
                }}
            />,
        );

        expect(
            screen.getByRole('switch', { name: 'keep me posted' }),
        ).toHaveAttribute('aria-checked', 'false');
        expect(screen.getByText('Disconnect')).toBeInTheDocument();
    });

    // No breadcrumb-style back affordance in the page body: Settings is a
    // pushed screen and the shell topbar's back chevron owns the way out.
    it('has no breadcrumb-style back link', () => {
        const { container } = render(<Settings />);
        expect(container.querySelector('[data-icon="ArrowLeft"]')).toBeNull();
    });

    it('renders no in-page Me nav — the topbar chrome replaces it', () => {
        render(<Settings />);
        expect(
            screen.queryByRole('link', { name: 'Profile' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Accessories' }),
        ).not.toBeInTheDocument();
    });
});
