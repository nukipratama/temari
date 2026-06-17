import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import RouteGlyph from './RouteGlyph';

const SAMPLE_POLYLINE = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';

describe('RouteGlyph', () => {
    it('draws a route path from a polyline', () => {
        const { container } = render(<RouteGlyph rarity="rare" polyline={SAMPLE_POLYLINE} />);
        const svg = container.querySelector('[data-variant="route"]');
        expect(svg).not.toBeNull();
        expect(svg?.querySelector('path')).not.toBeNull();
    });

    it('draws pace bars when there is no polyline but pace data exists', () => {
        const { container } = render(<RouteGlyph rarity="rare" paceShape={[300, 290, 280, 295]} />);
        const svg = container.querySelector('[data-variant="pace"]');
        expect(svg).not.toBeNull();
        expect(svg?.querySelectorAll('rect').length).toBe(4);
    });

    it('falls back to the bunny glyph when there is no route or pace data', () => {
        const { container } = render(<RouteGlyph rarity="rare" />);
        expect(container.querySelector('[data-variant="glyph"]')).not.toBeNull();
    });

    it('treats an empty polyline as no route and falls back to the glyph', () => {
        const { container } = render(<RouteGlyph rarity="rare" polyline="" />);
        expect(container.querySelector('[data-variant="route"]')).toBeNull();
        expect(container.querySelector('[data-variant="glyph"]')).not.toBeNull();
    });

    it('treats a single-point polyline (too short to draw) as no route and falls back to the glyph', () => {
        // One decoded coordinate (< 2 points) cannot form a path, so projectPolyline
        // returns null and the component drops to the glyph watermark.
        const { container } = render(<RouteGlyph rarity="rare" polyline="_p~iF~ps|U" />);
        expect(container.querySelector('[data-variant="route"]')).toBeNull();
        expect(container.querySelector('[data-variant="glyph"]')).not.toBeNull();
    });
});
