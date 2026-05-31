import polylineCodec from '@mapbox/polyline';

/**
 * Decode + normalize a Google-encoded polyline into points fitted to a
 * `width`×`height` box (0-origin), preserving aspect ratio and flipping
 * latitude so north is up. Shared by the on-card SVG glyph ([RouteGlyph]) and
 * the canvas share renderer ([shareCard]) so the two renderings never drift.
 * Returns null when there's nothing drawable (missing / undecodable /
 * single-point polyline). Long routes are downsampled to `maxPoints`.
 *
 * @return points in box-local coordinates plus the first point (route start).
 */
export function projectPolyline(
    polyline: string | null | undefined,
    width: number,
    height: number,
    pad: number,
    maxPoints = 160,
): { points: Array<[number, number]>; start: [number, number] } | null {
    if (polyline == null || polyline === '') {
        return null;
    }

    let pts: Array<[number, number]>;
    try {
        pts = polylineCodec.decode(polyline) as Array<[number, number]>;
    } catch {
        return null;
    }
    if (pts.length < 2) {
        return null;
    }

    if (pts.length > maxPoints) {
        const stride = Math.ceil(pts.length / maxPoints);
        const sampled = pts.filter((_, i) => i % stride === 0);
        if (sampled[sampled.length - 1] !== pts[pts.length - 1]) {
            sampled.push(pts[pts.length - 1]);
        }
        pts = sampled;
    }

    const lats = pts.map((p) => p[0]);
    const lngs = pts.map((p) => p[1]);
    const minLat = Math.min(...lats);
    const maxLat = Math.max(...lats);
    const minLng = Math.min(...lngs);
    const maxLng = Math.max(...lngs);
    const spanLat = maxLat - minLat || 1;
    const spanLng = maxLng - minLng || 1;
    const innerW = width - pad * 2;
    const innerH = height - pad * 2;
    const scale = Math.min(innerW / spanLng, innerH / spanLat);
    const offX = pad + (innerW - spanLng * scale) / 2;
    const offY = pad + (innerH - spanLat * scale) / 2;

    const points = pts.map((p): [number, number] => [
        offX + (p[1] - minLng) * scale,
        offY + (maxLat - p[0]) * scale, // flip y: higher latitude = higher on screen
    ]);
    return { points, start: points[0] };
}
