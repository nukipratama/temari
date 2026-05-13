import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import TemariSigil from './TemariSigil';

describe('TemariSigil', () => {
    it('renders an SVG with the given size', () => {
        const { container } = render(<TemariSigil size={64} />);
        const svg = container.querySelector('svg');
        expect(svg?.getAttribute('width')).toBe('64');
        expect(svg?.getAttribute('height')).toBe('64');
    });

    it.each(['o', 'r', 'c', 't', 's', 'w', 'v', 'p', 'l', 'f', 'h', 'x'])('renders stitch op "%s"', (op) => {
        // Only the first 4 chars are positioned (4 cardinal sites), so put the
        // target op first and pad the rest with the default dot ('d').
        const { container } = render(<TemariSigil pattern={op + 'ddd'} />);
        expect(container.querySelector('svg')).toBeTruthy();
    });

    it.each(['headband', 'mata-ngantuk', 'pita', null, 'unknown'] as const)('renders accessory variant %s', (kind) => {
        render(<TemariSigil accessory={kind} />);
    });

    it('pads short patterns to 4 chars (uses defaults beyond)', () => {
        const { container } = render(<TemariSigil pattern="o" />);
        expect(container.querySelector('svg')).toBeTruthy();
    });

    it('uses default pattern "dddd" when not provided', () => {
        const { container } = render(<TemariSigil />);
        expect(container.querySelector('svg')).toBeTruthy();
    });
});
