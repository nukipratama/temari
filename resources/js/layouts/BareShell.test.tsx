import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import { appLayout } from './appLayout';
import BareShell, { bareLayout } from './BareShell';

describe('BareShell', () => {
    it('renders its children without any nav chrome', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
        });
        render(
            <BareShell>
                <p>only child</p>
            </BareShell>,
        );

        expect(screen.getByText('only child')).toBeInTheDocument();
        expect(screen.queryByText('Today')).not.toBeInTheDocument();
        expect(screen.queryByTestId('mobile-top-bar')).not.toBeInTheDocument();
    });

    it('carries the error banner, since a connect denial lands on a bare screen', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
            errors: { strava: 'Strava connect was denied.' },
        });
        render(<BareShell>content</BareShell>);

        expect(
            screen.getByText('Strava connect was denied.'),
        ).toBeInTheDocument();
    });

    it('leaves the AI and Strava pipeline banners to AppShell', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
            aiPaused: true,
            aiCatchingUp: true,
            stravaPaused: true,
            stravaZoneScopeMissing: true,
        });
        render(<BareShell>content</BareShell>);

        expect(screen.queryByText(/resting for a bit/)).not.toBeInTheDocument();
        expect(
            screen.queryByText(/Still processing in the background/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/pull from Strava is paused/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/Reconnect Strava to auto-sync/),
        ).not.toBeInTheDocument();
    });

    it('pads past the notch itself, since it has no top bar to do it', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
        });
        const { container } = render(<BareShell>content</BareShell>);

        expect(container.querySelector('.min-h-screen')).toHaveClass(
            'pt-[max(1rem,env(safe-area-inset-top))]',
        );
    });

    it('clears the notch sideways too, for landscape', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
        });
        const { container } = render(<BareShell>content</BareShell>);

        expect(container.querySelector('.min-h-screen')).toHaveClass(
            'pl-[env(safe-area-inset-left)]',
            'pr-[env(safe-area-inset-right)]',
        );
    });

    it('wraps the page in the bare shell via bareLayout', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
        });
        render(bareLayout(<p>login body</p>));

        expect(screen.getByText('login body')).toBeInTheDocument();
        expect(screen.queryByText('Today')).not.toBeInTheDocument();
    });

    // Inertia compares the layout by reference, so these must be distinct,
    // stable module-level constants.
    it('exposes a stable reference distinct from appLayout', () => {
        expect(bareLayout).toBe(bareLayout);
        expect(bareLayout).not.toBe(appLayout);
    });
});
