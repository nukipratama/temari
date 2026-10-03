import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Card } from '@/components/ui/card';

import LaneStack from './LaneStack';

function Nothing() {
    return null;
}

describe('LaneStack', () => {
    it('wraps every lane in its own slot', () => {
        const { container } = render(
            <LaneStack>
                <section>one</section>
                <section>two</section>
            </LaneStack>,
        );
        const stack = container.firstElementChild as HTMLElement;

        expect(stack.children).toHaveLength(2);
        for (const slot of stack.children) {
            expect(slot).toHaveAttribute('data-slot', 'lane');
        }
        expect(screen.getByText('one').parentElement).toBe(stack.children[0]);
    });

    it('leaves a card lane its own border and padding', () => {
        render(
            <LaneStack>
                <section>hero</section>
                <Card className="border-border">race</Card>
            </LaneStack>,
        );
        const card = screen.getByText('race');

        expect(card.parentElement).toHaveAttribute('data-slot', 'lane');
        expect(card).toHaveClass('border', 'border-border', 'pad-card');
        expect(card.className).not.toMatch(/dashed|py-6|pt-6/);
    });

    it('draws the dashed divider and the lane gap on the slots, never on their children', () => {
        const { container } = render(
            <LaneStack>
                <section>one</section>
            </LaneStack>,
        );
        const classes = (container.firstElementChild as HTMLElement).className;

        expect(classes).not.toMatch(/divide-|\[&>\*\]/);
        expect(classes).toContain(
            '[&>:not(:empty)~:not(:empty)]:border-dashed',
        );
        expect(classes).toContain('[&>:not(:empty)~:not(:empty)]:pt-6');
        expect(classes).toContain('[&>:empty]:hidden');
    });

    it('gives each child of a fragment its own lane', () => {
        const { container } = render(
            <LaneStack>
                <section>one</section>
                <>
                    <section>two</section>
                    <section>three</section>
                </>
            </LaneStack>,
        );

        expect(container.firstElementChild?.children).toHaveLength(3);
        expect(screen.getByText('three').parentElement).toHaveAttribute(
            'data-slot',
            'lane',
        );
    });

    it('skips a conditional lane that is off, and hides a lane whose component renders nothing', () => {
        const { container } = render(
            <LaneStack>
                {false}
                {null}
                <section>one</section>
                <Nothing />
            </LaneStack>,
        );
        const slots = container.firstElementChild!.children;

        expect(slots).toHaveLength(2);
        expect(slots[1]).toBeEmptyDOMElement();
    });

    it('merges a caller className onto the stack', () => {
        const { container } = render(
            <LaneStack className="mt-6">
                <section>one</section>
            </LaneStack>,
        );

        expect(container.firstElementChild).toHaveClass('mt-6', 'flex-col');
    });
});
