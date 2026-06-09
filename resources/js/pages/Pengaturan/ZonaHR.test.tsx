import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import ZonaHR, { deriveZones } from './ZonaHR';
import { makeUser, setMockPage } from '@/test/setup';

const DEFAULT_PROFILE = {
    max_hr: 180,
    resting_hr: 55,
    hr_zones: {
        Z1: { lo: 116, hi: 138 },
        Z2: { lo: 138, hi: 154 },
        Z3: { lo: 154, hi: 168 },
        Z4: { lo: 168, hi: 176 },
        Z5: { lo: 176, hi: 999 },
    },
    optimal_cadence_spm: 170,
};

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        errors: {},
        demoLoginEnabled: false,
    });
});

describe('deriveZones', () => {
    it('reproduces the config defaults at max 180 resting 55', () => {
        expect(deriveZones(180, 55)).toEqual(DEFAULT_PROFILE.hr_zones);
    });
});

describe('ZonaHR', () => {
    it('renders the forward-only note', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);
        expect(screen.getByText(/dipakai ke semua lari berikutnya/i)).toBeInTheDocument();
    });

    it('updates the derived-zone preview when the max HR changes', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);

        const previewZ1 = screen.getByTestId('preview-Z1');
        expect(previewZ1.textContent).toContain('116');

        fireEvent.change(screen.getByLabelText('Max HR'), { target: { value: '200' } });

        const expected = deriveZones(200, 55);
        expect(screen.getByTestId('preview-Z1').textContent).toContain(String(expected.Z1.lo));
        expect(screen.getByTestId('preview-Z5').textContent).toContain(String(expected.Z5.lo));
    });

    it('lets the user override a manual boundary', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);

        const z2Lo = screen.getByTestId('zone-Z2-lo') as HTMLInputElement;
        expect(z2Lo.value).toBe('138');

        fireEvent.change(z2Lo, { target: { value: '142' } });

        expect((screen.getByTestId('zone-Z2-lo') as HTMLInputElement).value).toBe('142');
    });

    it('shows the custom-profile copy when one exists', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile />);
        expect(screen.getByText(/udah punya zona custom/i)).toBeInTheDocument();
    });

    it('surfaces a server field error under the input', () => {
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            errors: { max_hr: 'Max HR harus di antara 120 dan 220 bpm.' },
            demoLoginEnabled: false,
        });

        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);
        const maxHrField = screen.getByLabelText('Max HR').closest('label');
        expect(within(maxHrField as HTMLElement).getByText(/di antara 120 dan 220/i)).toBeInTheDocument();
    });
});
