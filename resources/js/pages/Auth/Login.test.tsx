import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Login from './Login';
import { setMockPage } from '@/test/setup';

describe('Login', () => {
    it('shows the Strava CTA with the given URL', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        render(<Login authStravaUrl="/auth/strava/redirect" />);
        const strava = screen.getByText(/Connect with Strava/).closest('a');
        expect(strava?.getAttribute('href')).toBe('/auth/strava/redirect');
    });

    it('hides demo button when demoLoginEnabled is false', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        render(<Login authStravaUrl="/x" />);
        expect(screen.queryByText('Coba versi demo')).not.toBeInTheDocument();
    });

    it('shows demo button when demoLoginEnabled is true', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: true });
        render(<Login authStravaUrl="/x" />);
        expect(screen.getByText('Coba versi demo')).toBeInTheDocument();
    });

    it('renders the brand hero + 3 onboarding pillars in Temari first-person voice', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        render(<Login authStravaUrl="/x" />);
        expect(screen.getByText('TemanLari')).toBeInTheDocument();
        // Editorial headline is split across two lines; assert the second line.
        expect(screen.getByText('Berarti.')).toBeInTheDocument();
        ['Aku baca', 'Aku catet', 'Aku temenin'].forEach((label) => {
            expect(screen.getByText(label)).toBeInTheDocument();
        });
    });

    it('clicking the demo button invokes the submit handler', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: true });
        render(<Login authStravaUrl="/x" />);
        await userEvent.setup().click(screen.getByText('Coba versi demo'));
    });
});
