import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import DemoBanner from './DemoBanner';
import { makeUser, setMockPage } from '@/test/setup';

describe('DemoBanner', () => {
    it('renders nothing when demoLoginEnabled is false', () => {
        setMockPage({
            auth: { user: makeUser({ name: 'A', first_name: 'A' }) },
            flash: {},
            demoLoginEnabled: false,
        });
        const { container } = render(<DemoBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when user is not logged in (even if flag on)', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: true });
        const { container } = render(<DemoBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('renders banner when demo flag is on and user logged in', () => {
        setMockPage({
            auth: { user: makeUser({ name: 'Demo User', first_name: 'Demo' }) },
            flash: {},
            demoLoginEnabled: true,
        });
        render(<DemoBanner />);
        expect(screen.getByText(/Mode demo aktif/)).toBeInTheDocument();
    });
});
