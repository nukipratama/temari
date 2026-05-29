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

    it("forwards the user's equipped accessories to the mascot", () => {
        setMockPage({
            equippedAccessories: { headband: 'legendaris', medal: 'emas', pita: true, aura: false },
        });
        render(<Temari pose="glow" size={180} />);
        expect(protoSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                equipped: { headband: 'legendaris', medal: 'emas', pita: true, aura: false },
            }),
        );
    });

    it("maps a null medal to 'none' so TemariProto reads it as bare", () => {
        setMockPage({
            equippedAccessories: { headband: 'epik', medal: null, pita: false, aura: false },
        });
        render(<Temari pose="proud" size={120} />);
        expect(protoSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                equipped: { headband: 'epik', medal: 'none', pita: false, aura: false },
            }),
        );
    });
});
