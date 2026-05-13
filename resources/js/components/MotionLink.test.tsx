import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import MotionLink from './MotionLink';

describe('MotionLink', () => {
    it('renders a link with the given href + children', () => {
        render(
            <MotionLink href="/runs">
                Runs
            </MotionLink>,
        );
        const link = screen.getByText('Runs');
        expect(link.tagName.toLowerCase()).toBe('a');
        expect(link.getAttribute('href')).toBe('/runs');
    });
});
