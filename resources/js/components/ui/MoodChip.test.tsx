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
});
