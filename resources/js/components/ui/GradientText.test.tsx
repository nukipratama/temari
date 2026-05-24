import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import GradientText from './GradientText';

describe('GradientText', () => {
    it('renders the children content with the horizon preset', () => {
        render(
            <GradientText preset="horizon" fontSize="48px">
                544.1
            </GradientText>,
        );
        expect(screen.getByText('544.1')).toBeInTheDocument();
    });

    it('applies the requested fontSize + cross-browser background-clip style', () => {
        render(
            <GradientText preset="cream-sun" fontSize="200px">
                29:11
            </GradientText>,
        );
        const node = screen.getByText('29:11');
        expect(node.style.fontSize).toBe('200px');
        expect(node.style.background).toContain('linear-gradient');
        expect(node.style.color).toBe('transparent');
    });
});
