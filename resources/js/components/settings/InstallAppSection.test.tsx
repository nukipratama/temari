import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { captureInstallPrompt } from '@/lib/installPrompt';

import InstallAppSection from './InstallAppSection';

captureInstallPrompt();

function offerInstall() {
    const prompt = vi.fn().mockResolvedValue(undefined);
    const event = Object.assign(new Event('beforeinstallprompt'), { prompt });
    act(() => {
        window.dispatchEvent(event);
    });
    return prompt;
}

describe('InstallAppSection', () => {
    it('renders nothing while the browser offers no install', () => {
        const { container } = render(<InstallAppSection />);

        expect(container).toBeEmptyDOMElement();
    });

    it('opens the browser install prompt from the install button, then hides', async () => {
        render(<InstallAppSection />);
        const prompt = offerInstall();

        fireEvent.click(screen.getByRole('button', { name: 'install' }));

        expect(prompt).toHaveBeenCalledOnce();
        expect(
            screen.queryByRole('button', { name: 'install' }),
        ).not.toBeInTheDocument();
    });

    it('hides once the app is installed another way', () => {
        render(<InstallAppSection />);
        offerInstall();

        act(() => {
            window.dispatchEvent(new Event('appinstalled'));
        });

        expect(
            screen.queryByRole('button', { name: 'install' }),
        ).not.toBeInTheDocument();
    });
});
