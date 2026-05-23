import { render, screen } from '@testing-library/react';
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
});
