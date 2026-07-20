import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { appLayout, bareLayout } from './appLayout';
import { makeUser, setMockPage } from '@/test/setup';

describe('appLayout', () => {
    it('wraps the page in the full shell', () => {
        setMockPage({ auth: { user: makeUser() }, flash: {}, demoLoginEnabled: false });
        render(appLayout(<p>page body</p>));

        expect(screen.getByText('page body')).toBeInTheDocument();
        ['Hari Ini', 'Koleksi', 'Riwayat', 'Aku'].forEach((label) => {
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
        });
    });

    it('wraps the page without nav chrome in the bare variant', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        render(bareLayout(<p>login body</p>));

        expect(screen.getByText('login body')).toBeInTheDocument();
        expect(screen.queryByText('Hari Ini')).not.toBeInTheDocument();
    });

    // Inertia compares the layout by reference to decide whether to keep the
    // shell mounted across a visit. A fresh function per render would defeat
    // the whole point of the persistent layout, so these must be stable
    // module-level constants.
    it('exposes stable references so Inertia keeps the shell mounted', () => {
        expect(appLayout).toBe(appLayout);
        expect(bareLayout).toBe(bareLayout);
        expect(appLayout).not.toBe(bareLayout);
    });
});
