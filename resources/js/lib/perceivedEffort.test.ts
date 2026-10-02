import { describe, expect, it } from 'vitest';

import {
    EFFORT_MAX,
    EFFORT_MIN,
    effortBand,
    effortPose,
    effortWord,
} from './perceivedEffort';

describe('effortWord', () => {
    it('names the CR-10 anchors', () => {
        expect(effortWord(1)).toBe('very easy');
        expect(effortWord(3)).toBe('moderate');
        expect(effortWord(5)).toBe('hard');
        expect(effortWord(7)).toBe('very hard');
        expect(effortWord(10)).toBe('maximal');
    });

    it('has a word for every score on the scale and none off it', () => {
        for (let score = EFFORT_MIN; score <= EFFORT_MAX; score++) {
            expect(effortWord(score)).not.toBe('');
        }
        expect(effortWord(0)).toBe('');
        expect(effortWord(11)).toBe('');
    });
});

describe('effortBand', () => {
    it('groups the scale 4 / 2 / 4 into the effort colours', () => {
        expect([1, 2, 3, 4].map(effortBand)).toEqual([
            'easy',
            'easy',
            'easy',
            'easy',
        ]);
        expect([5, 6].map(effortBand)).toEqual(['steady', 'steady']);
        expect([7, 8, 9, 10].map(effortBand)).toEqual([
            'hard',
            'hard',
            'hard',
            'hard',
        ]);
    });
});

describe('effortPose', () => {
    it('stays neutral until the slider is touched', () => {
        expect(effortPose(null)).toBe('neutral');
    });

    it.each([
        [1, 'chill'],
        [2, 'chill'],
        [3, 'easy'],
        [4, 'easy'],
        [5, 'blazing'],
        [6, 'blazing'],
        [7, 'gassed'],
        [8, 'gassed'],
        [9, 'overloaded'],
        [10, 'overloaded'],
    ] as const)('poses %i as %s', (score, pose) => {
        expect(effortPose(score)).toBe(pose);
    });
});
