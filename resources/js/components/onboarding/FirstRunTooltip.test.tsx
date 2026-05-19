import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import FirstRunTooltip from './FirstRunTooltip';
import { setMockPage } from '@/test/setup';

const STORAGE_KEY = 'tl.onboarding.dismissed';
const BASE_AUTH = { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } };
const BASE_FLASH = { success: null, error: null, info: null };

function setPage(forceShow: boolean) {
    setMockPage({
        auth: BASE_AUTH,
        flash: BASE_FLASH,
        demoLoginEnabled: false,
        onboarding: { forceShow },
    });
}

beforeEach(() => {
    globalThis.localStorage.clear();
});
afterEach(() => {
    globalThis.localStorage.clear();
});

describe('FirstRunTooltip — normal mode', () => {
    it('shows the welcome card when user has zero runs and no dismissal flag', () => {
        setPage(false);
        render(<FirstRunTooltip recentRunCount={0} />);
        expect(screen.getByText('Hai! Strava udah nyambung.')).toBeInTheDocument();
    });

    it('hides when user has runs', () => {
        setPage(false);
        render(<FirstRunTooltip recentRunCount={3} />);
        expect(screen.queryByText('Hai! Strava udah nyambung.')).not.toBeInTheDocument();
    });

it('hides when localStorage dismissal flag is set', () => {
        globalThis.localStorage.setItem(STORAGE_KEY, '1');
        setPage(false);
        render(<FirstRunTooltip recentRunCount={0} />);
        expect(screen.queryByText('Hai! Strava udah nyambung.')).not.toBeInTheDocument();
    });

    it('persists dismissal to localStorage on click', () => {
        setPage(false);
        render(<FirstRunTooltip recentRunCount={0} />);
        fireEvent.click(screen.getByRole('button', { name: /Oke, ditunggu/ }));
        expect(globalThis.localStorage.getItem(STORAGE_KEY)).toBe('1');
    });
});

describe('FirstRunTooltip — force-show mode', () => {
    it('renders regardless of run count', () => {
        setPage(true);
        render(<FirstRunTooltip recentRunCount={99} />);
        expect(screen.getByText('Hai! Strava udah nyambung.')).toBeInTheDocument();
    });

    it('dismissal does NOT write to localStorage', () => {
        setPage(true);
        render(<FirstRunTooltip recentRunCount={5} />);
        fireEvent.click(screen.getByRole('button', { name: /Oke, ditunggu/ }));
        expect(globalThis.localStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it('renders even when localStorage dismissal flag is set', () => {
        globalThis.localStorage.setItem(STORAGE_KEY, '1');
        setPage(true);
        render(<FirstRunTooltip recentRunCount={0} />);
        expect(screen.getByText('Hai! Strava udah nyambung.')).toBeInTheDocument();
    });
});
