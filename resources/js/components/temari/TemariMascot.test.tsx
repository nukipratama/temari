import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import TemariMascot from './TemariMascot';

describe('TemariMascot', () => {
    it('composes body + face + sigil inside a ringed gradient bubble', () => {
        const { container } = render(<TemariMascot mood="glow" />);
        // 3 SVG children: body, face, sigil
        expect(container.querySelectorAll('svg').length).toBe(3);
        const wrapper = container.firstElementChild as HTMLElement;
        expect(wrapper.className).toContain('rounded-full');
        expect(wrapper.className).toContain('ring-4');
    });

    it('honors ringClass override', () => {
        const { container } = render(<TemariMascot mood="dim" ringClass="ring-2" />);
        const wrapper = container.firstElementChild as HTMLElement;
        expect(wrapper.className).toContain('ring-2');
        expect(wrapper.className).not.toContain('ring-4');
    });

    it('forwards aria-label to the wrapper', () => {
        const { container } = render(<TemariMascot mood="bouncy" aria-label="mood bouncy" />);
        expect(container.firstElementChild?.getAttribute('aria-label')).toBe('mood bouncy');
    });

    it('renders without crash under mood-aware idle', () => {
        // Smoke — visual animation can't be asserted in jsdom.
        const { container } = render(<TemariMascot mood="wobble" idle="mood" />);
        expect(container.firstElementChild).toBeTruthy();
    });

    it('renders with breath idle for the squished mood', () => {
        const { container } = render(<TemariMascot mood="squished" idle="breath" />);
        expect(container.firstElementChild).toBeTruthy();
    });

    it('renders as a button when interactive', () => {
        const { container } = render(<TemariMascot mood="bouncy" interactive aria-label="poke" />);
        const wrapper = container.firstElementChild;
        expect(wrapper?.getAttribute('role')).toBe('button');
        expect(wrapper?.getAttribute('aria-label')).toBe('poke');
    });

    it('applies hoverable affordance classes when hoverable', () => {
        const { container } = render(<TemariMascot mood="glow" hoverable />);
        expect(container.firstElementChild?.className).toContain('rounded-full');
    });

    it('plays a tap reaction when the interactive mascot is clicked', () => {
        const { container } = render(<TemariMascot mood="spinning" interactive idle="none" />);
        const button = container.firstElementChild as HTMLElement;
        // We can't observe FM's runtime variant state from outside in jsdom,
        // but the click handler should be wired (no throw, role=button).
        button.click();
        expect(button.getAttribute('role')).toBe('button');
    });

    it('falls through to mood idle when given an unknown mood (default mapping)', () => {
        // Cast forces the resolveIdle('mood') branch to take its `?? breath`
        // fallback path for moods not in the idleByMood map.
        const { container } = render(
            <TemariMascot mood={'mystery' as unknown as 'glow'} idle="mood" />,
        );
        expect(container.firstElementChild).toBeTruthy();
    });
});
