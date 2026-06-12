import { render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { setMockPage } from '@/test/setup';
import Temari from './Temari';

// Capture the props TemariProto receives so we can assert what the wrapper
// derived from the shared `equippedAccessories` prop.
const protoSpy = vi.fn();
vi.mock('./TemariProto', () => ({
    default: (props: Record<string, unknown>) => {
        protoSpy(props);
        return null;
    },
}));

describe('Temari', () => {
    it('passes a null equipped set when the user has equipped nothing', () => {
        setMockPage({ equippedAccessories: null });
        render(<Temari pose="proud" size={100} />);
        expect(protoSpy).toHaveBeenCalledWith(
            expect.objectContaining({ pose: 'proud', size: 100, equipped: null }),
        );
    });

    it("maps the server-side equipped payload to TemariEquipped variants", () => {
        setMockPage({
            equippedAccessories: {
                ikat_kepala: 'accessory.ikat_kepala_legendaris',
                medal: 'accessory.medal_emas',
                kaus: 'accessory.kaus_hujan',
                celana: 'accessory.celana_split',
                sepatu: 'accessory.sepatu_cepat',
                aura: 'accessory.aura_jagoan',
            },
        });
        render(<Temari pose="glow" size={180} />);
        expect(protoSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                equipped: {
                    headband: 'legendaris',
                    medal: 'emas',
                    kaus: 'hujan',
                    celana: 'split',
                    sepatu: 'cepat',
                    aura: 'jagoan',
                },
            }),
        );
    });

    it("maps a null medal to 'none' so TemariProto reads it as bare", () => {
        setMockPage({
            equippedAccessories: {
                ikat_kepala: 'accessory.ikat_kepala_epik',
                medal: null,
                kaus: null,
                celana: null,
                sepatu: null,
                aura: null,
            },
        });
        render(<Temari pose="proud" size={120} />);
        expect(protoSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                equipped: {
                    headband: 'epik',
                    medal: 'none',
                    kaus: null,
                    celana: null,
                    sepatu: null,
                    aura: null,
                },
            }),
        );
    });
});
