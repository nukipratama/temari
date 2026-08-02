import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import StravaAction from './StravaAction';

const base = {
    auth: { user: null },
    flash: {},
    demoLoginEnabled: false,
} as const;

describe('StravaAction', () => {
    it('renders its child while Strava is enabled', () => {
        setMockPage({ ...base, stravaPaused: false });
        render(
            <StravaAction>
                <button type="button">Sync sekarang</button>
            </StravaAction>,
        );
        expect(
            screen.getByRole('button', { name: 'Sync sekarang' }),
        ).toBeInTheDocument();
    });

    it('renders its child when the prop is absent', () => {
        setMockPage({ ...base });
        render(
            <StravaAction>
                <button type="button">Sync sekarang</button>
            </StravaAction>,
        );
        expect(
            screen.getByRole('button', { name: 'Sync sekarang' }),
        ).toBeInTheDocument();
    });

    it('hides its child entirely while paused, rather than disabling it', () => {
        setMockPage({ ...base, stravaPaused: true });
        const { container } = render(
            <StravaAction>
                <button type="button">Sync sekarang</button>
            </StravaAction>,
        );
        expect(container.firstChild).toBeNull();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
