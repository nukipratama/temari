import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { Mood } from '@/types/inertia';

import MoodChip from './MoodChip';

describe('MoodChip', () => {
    it.each([
        'blazing',
        'easy',
        'gassed',
        'wobbly',
        'overloaded',
        'chill',
    ] satisfies Mood[])('renders default label for mood %s', (mood) => {
        const expected = {
            blazing: 'blazing',
            easy: 'easy',
            gassed: 'gassed',
            wobbly: 'wobbly',
            overloaded: 'overloaded',
            chill: 'chill',
        }[mood];
        render(<MoodChip mood={mood} />);
        expect(screen.getByText(expected)).toBeInTheDocument();
    });

    it('honours an explicit label override', () => {
        render(<MoodChip mood="blazing" label="Custom" />);
        expect(screen.getByText('Custom')).toBeInTheDocument();
    });

    // The soft fill is fixed identity and stays pale on the dark ground, so a
    // ground-reactive text colour on it renders near-white on near-white.
    it.each([
        'blazing',
        'easy',
        'gassed',
        'wobbly',
        'overloaded',
        'chill',
    ] satisfies Mood[])(
        'pins the %s label to its own ink, not the ground',
        (mood) => {
            render(<MoodChip mood={mood} />);

            const chip = screen.getByText(mood);
            expect(chip).toHaveClass(`text-mood-${mood}-ink`);
            expect(chip).not.toHaveClass('text-foreground');
        },
    );
});
