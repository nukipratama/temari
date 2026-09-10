import { render, screen } from '@testing-library/react';
import { HeartPulse, RotateCcwClock, X } from 'lucide-react';
import { describe, expect, it, vi } from 'vitest';

import { StravaIcon, TelegramIcon } from '@/components/ui/Icon';

import { Icon } from './Icon';

// The global setup.ts mock stubs this module for every OTHER test in the
// suite (so tests assert which icon key was requested, not lucide's SVG
// output); this file tests the real implementation the stub stands in for.
vi.unmock('@/components/ui/Icon');

describe('Icon', () => {
    it('renders the icon component it is handed', () => {
        const { container } = render(
            <Icon icon={HeartPulse} className="text-leaf-ink" />,
        );

        const svg = container.querySelector('svg');
        expect(svg).toBeInTheDocument();
        expect(svg).toHaveClass('text-leaf-ink');
    });

    it('sizes from width, falling back to height, then 24', () => {
        const { container: byWidth } = render(
            <Icon icon={X} width={18} height={30} />,
        );
        expect(byWidth.querySelector('svg')).toHaveAttribute('width', '18');

        const { container: byHeight } = render(<Icon icon={X} height={20} />);
        expect(byHeight.querySelector('svg')).toHaveAttribute('width', '20');

        const { container: byDefault } = render(<Icon icon={X} />);
        expect(byDefault.querySelector('svg')).toHaveAttribute('width', '24');
    });

    it('passes through arbitrary SVG props', () => {
        render(
            <Icon
                icon={RotateCcwClock}
                role="img"
                aria-label="RotateCcwClock"
                style={{ transform: 'rotate(4deg)' }}
            />,
        );

        const svg = screen.getByRole('img', { name: 'RotateCcwClock' });
        expect(svg).toHaveStyle({ transform: 'rotate(4deg)' });
    });

    it('renders the Strava and Telegram brand marks as their own fixed path, never a lucide icon', () => {
        const { container: strava } = render(<Icon icon={StravaIcon} />);
        const { container: telegram } = render(<Icon icon={TelegramIcon} />);

        expect(strava.querySelector('path')?.getAttribute('d')).toContain(
            'M14.92 17.16',
        );
        expect(telegram.querySelector('path')?.getAttribute('d')).toContain(
            'M9.78 18.65',
        );
    });
});
