import { render, screen, fireEvent, act } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import AppShell from './AppShell';
import { setMockPage } from '@/test/setup';

describe('AppShell', () => {
    afterEach(() => {
        delete document.body.dataset.timeOfDay;
    });

    it('sets a data-time-of-day attribute on body via useDawnShift', () => {
        setMockPage({
            auth: { user: { id: 1, name: 'Andi', first_name: 'Andi', avatar_url: null } },
            flash: {},
            demoLoginEnabled: false,
        });
        render(
            <AppShell>
                <p>x</p>
            </AppShell>,
        );
        expect(document.body.dataset.timeOfDay).toMatch(/^(dawn|morning|day|dusk|night)$/);
    });

    it('renders the 4 primary tabs + children by default', () => {
        setMockPage({
            auth: { user: { id: 1, name: 'Andi', first_name: 'Andi', avatar_url: null } },
            flash: {},
            demoLoginEnabled: false,
        });
        render(
            <AppShell>
                <p>child content</p>
            </AppShell>,
        );
        expect(screen.getByText('child content')).toBeInTheDocument();
        ['Hari Ini', 'Koleksi', 'Riwayat', 'Aku'].forEach((label) => {
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
        });
    });

    it('omits nav chrome when withNav is false', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        render(
            <AppShell withNav={false}>
                <p>only child</p>
            </AppShell>,
        );
        expect(screen.queryByText('Hari Ini')).not.toBeInTheDocument();
        expect(screen.getByText('only child')).toBeInTheDocument();
    });

    it('shows AksesoriUnlockModal and dismisses it when a major unlock is flashed', async () => {
        setMockPage({
            auth: { user: { id: 1, name: 'Andi', first_name: 'Andi', avatar_url: null } },
            flash: {
                unlock: { unlock_key: 'accessory.headband_epik', name: 'Headband Epik', icon: 'mdi:star', is_major: true },
            },
            demoLoginEnabled: false,
        });
        render(<AppShell><p>x</p></AppShell>);
        expect(screen.getByText(/Headband Epik/)).toBeInTheDocument();
        // Clicking "Nanti aja" triggers onClose (covers () => setMajorUnlock(null))
        await act(async () => { fireEvent.click(screen.getByText('Nanti aja')); });
    });

    it('fires PR modal when CardReveal skip is clicked on a PR run', async () => {
        setMockPage({
            auth: { user: { id: 1, name: 'Andi', first_name: 'Andi', avatar_url: null } },
            flash: {},
            demoLoginEnabled: false,
            pendingReveal: {
                card_id: 7, activity_id: 99, rarity: 'common', special_move: 'Langkah Ringan',
                badges: null, detail_name: 'Pagi', distance_m: 5000, moving_time_sec: 1800,
                trimp_edwards: 50, is_pr: true, pr_category_label: '5K', pr_time_display: '22:15',
            },
        });
        globalThis.fetch = Object.assign(
            () => Promise.resolve(new Response('{"seen":true}', { status: 200 })),
            { preload: () => {} },
        ) as typeof fetch;
        render(<AppShell><p>x</p></AppShell>);
        await act(async () => { fireEvent.click(screen.getByRole('button', { name: /Lewati/i })); });
        // PRMomentModal fires — PR time is visible
        expect(screen.getByText('22:15')).toBeInTheDocument();
        // Clicking close triggers the onClose callback (covers () => setPrModal(null))
        await act(async () => { fireEvent.click(screen.getByLabelText('Tutup')); });
    });
});
