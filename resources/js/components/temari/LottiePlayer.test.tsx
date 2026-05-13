import { render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('lottie-react', () => ({
    default: ({ animationData }: { animationData: unknown }) => (
        <div data-testid="lottie-stub" data-payload={JSON.stringify(animationData)} />
    ),
}));

import LottiePlayer from './LottiePlayer';

describe('LottiePlayer', () => {
    it('forwards animationData into the lottie-react component', () => {
        const data = { v: '5.7.0', fr: 60 };
        const { getByTestId } = render(<LottiePlayer animationData={data} />);
        expect(getByTestId('lottie-stub').dataset.payload).toBe(JSON.stringify(data));
    });
});
