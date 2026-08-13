import { router } from '@inertiajs/react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import AccessoryUnlockModal from './AccessoryUnlockModal';

const epikUnlock = {
    unlock_key: 'accessory.headband_epic',
    name: 'Special Headband',
    icon: 'mdi:star',
    is_major: true,
};

const minorUnlock = {
    unlock_key: 'accessory.some_minor',
    name: 'Minor Thing',
    icon: 'mdi:gift',
    is_major: false,
};

describe('AccessoryUnlockModal', () => {
    it('renders nothing when unlock is null', () => {
        const { container } = render(
            <AccessoryUnlockModal unlock={null} onClose={vi.fn()} />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when unlock is not major', () => {
        const { container } = render(
            <AccessoryUnlockModal unlock={minorUnlock} onClose={vi.fn()} />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders the unlock name for a major unlock', () => {
        render(<AccessoryUnlockModal unlock={epikUnlock} onClose={vi.fn()} />);
        expect(screen.getByText(/Special Headband/)).toBeInTheDocument();
    });

    it('exposes a labelled modal dialog', () => {
        render(<AccessoryUnlockModal unlock={epikUnlock} onClose={vi.fn()} />);
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(dialog).toHaveAttribute(
            'aria-labelledby',
            'accessory-unlock-title',
        );
        expect(
            document.getElementById('accessory-unlock-title'),
        ).toBeInTheDocument();
    });

    it('closes on the Escape key', () => {
        const onClose = vi.fn();
        render(<AccessoryUnlockModal unlock={epikUnlock} onClose={onClose} />);
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('moves focus into the dialog when it opens', () => {
        render(<AccessoryUnlockModal unlock={epikUnlock} onClose={vi.fn()} />);
        const dialog = screen.getByRole('dialog');
        expect(dialog.contains(document.activeElement)).toBe(true);
    });

    it('calls onClose when "Not now" is clicked', () => {
        const onClose = vi.fn();
        render(<AccessoryUnlockModal unlock={epikUnlock} onClose={onClose} />);
        fireEvent.click(screen.getByText('Not now'));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('renders the "Equip now" button for the equip action', () => {
        render(<AccessoryUnlockModal unlock={epikUnlock} onClose={vi.fn()} />);
        expect(screen.getByText('Equip now')).toBeInTheDocument();
    });

    it('calls onClose when "Equip now" is clicked', () => {
        const onClose = vi.fn();
        vi.mocked(router.visit).mockReset();
        render(<AccessoryUnlockModal unlock={epikUnlock} onClose={onClose} />);
        fireEvent.click(screen.getByText('Equip now'));
        expect(onClose).toHaveBeenCalledOnce();
        expect(router.visit).toHaveBeenCalledWith('/accessories', {
            preserveScroll: false,
        });
    });
});
