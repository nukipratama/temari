import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Welcome from './Welcome';
import { setMockPage } from '@/test/setup';

describe('Welcome', () => {
    it('renders the brand hero (no mascot reveal pre-auth)', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        render(<Welcome />);
        expect(screen.getByText('TemanLari')).toBeInTheDocument();
        expect(screen.getByText('Setiap Langkah Berarti')).toBeInTheDocument();
        expect(screen.getByText('Mulai').getAttribute('href')).toBe('/login');
        expect(screen.queryByText(/Temari/i)).not.toBeInTheDocument();
    });
});
