import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
    type CardPadding,
    type CardTone,
} from './card';

describe('Card', () => {
    it('renders the default tone on the panel corner with a hairline edge and resting elevation', () => {
        render(<Card>Body</Card>);
        const card = screen.getByText('Body');
        expect(card).toHaveAttribute('data-slot', 'card');
        expect(card).toHaveAttribute('data-tone', 'default');
        expect(card).toHaveClass(
            'rounded-panel',
            'border',
            'border-border',
            'bg-card',
            'shadow-e1',
            'pad-card',
        );
    });

    it.each([
        ['default', 'border-border'],
        ['empty', 'border-border-strong'],
    ] satisfies [CardTone, string][])(
        'renders tone %s with its edge class',
        (tone, expected) => {
            render(<Card tone={tone}>x</Card>);
            expect(screen.getByText('x')).toHaveClass(expected);
        },
    );

    it.each([
        ['panel', 'pad-panel'],
        ['card', 'pad-card'],
        ['hero', 'pad-hero'],
    ] satisfies [CardPadding, string][])(
        'maps padding %s onto the %s role',
        (padding, expected) => {
            render(<Card padding={padding}>x</Card>);
            expect(screen.getByText('x')).toHaveClass(expected);
        },
    );

    it('drops the padding role under padding="none"', () => {
        render(<Card padding="none">x</Card>);
        expect(screen.getByText('x').className).not.toMatch(/pad-/);
    });

    it('renders through the element passed as render', () => {
        render(
            <ul>
                <Card render={<li />}>x</Card>
            </ul>,
        );
        expect(screen.getByText('x').tagName).toBe('LI');
    });

    it('renders as a link when given an anchor', () => {
        render(<Card render={<a href="/race" />}>go</Card>);
        const link = screen.getByRole('link', { name: 'go' });
        expect(link).toHaveAttribute('href', '/race');
        expect(link).toHaveClass('bg-card');
    });

    it('lets a caller className win over the base classes', () => {
        render(<Card className="bg-popover custom-extra">x</Card>);
        const card = screen.getByText('x');
        expect(card).toHaveClass('bg-popover', 'custom-extra');
        expect(card).not.toHaveClass('bg-card');
    });

    it('renders every subcomponent in a header/content/footer layout', () => {
        render(
            <Card>
                <CardHeader>
                    <CardTitle>Title</CardTitle>
                    <CardDescription>Subtitle</CardDescription>
                </CardHeader>
                <CardContent>Content</CardContent>
                <CardFooter>Footer</CardFooter>
            </Card>,
        );

        expect(screen.getByText('Title')).toBeInTheDocument();
        expect(screen.getByText('Subtitle')).toBeInTheDocument();
        expect(screen.getByText('Content')).toHaveAttribute(
            'data-slot',
            'card-content',
        );
        expect(screen.getByText('Footer')).toHaveAttribute(
            'data-slot',
            'card-footer',
        );
    });
});
