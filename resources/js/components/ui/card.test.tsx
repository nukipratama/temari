import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from './card';

describe('Card', () => {
    it('renders the card ground with the rounded-4xl shadcn treatment', () => {
        render(<Card>Body</Card>);
        const card = screen.getByText('Body');
        expect(card.className).toMatch(/bg-card/);
        expect(card.className).toMatch(/rounded-4xl/);
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
        expect(screen.getByText('Content')).toBeInTheDocument();
        expect(screen.getByText('Footer')).toBeInTheDocument();
    });

    it('shrinks --card-spacing under size="sm"', () => {
        render(<Card size="sm">Compact</Card>);
        expect(screen.getByText('Compact')).toHaveAttribute('data-size', 'sm');
    });
});
