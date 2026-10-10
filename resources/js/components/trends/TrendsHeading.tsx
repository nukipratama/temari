import Eyebrow from '@/components/ui/Eyebrow';
import PageHero from '@/components/ui/PageHero';

export default function TrendsHeading() {
    return (
        <>
            <Eyebrow token="hero" tone="ink-2">
                Trends
            </Eyebrow>
            <PageHero size="quote-lg" italic className="mt-2">
                am i getting fitter,
                <br />
                <em className="italic text-icon-accent">and at what cost?</em>
            </PageHero>
        </>
    );
}
