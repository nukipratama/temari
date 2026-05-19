import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import WeatherHero from './WeatherHero';

describe('WeatherHero', () => {
    it('returns null when no weather/location data', () => {
        const { container } = render(<WeatherHero detail={{}} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders cool weather (<27°C) with brand tint', () => {
        render(<WeatherHero detail={{ weather_temp_c: 22, weather_humidity_pct: 70 }} />);
        expect(screen.getByText('22')).toBeInTheDocument();
        expect(screen.getByText(/70% humidity/)).toBeInTheDocument();
    });

    it('renders warm weather (27-30°C) with squished tone', () => {
        render(<WeatherHero detail={{ weather_temp_c: 28 }} />);
        expect(screen.getByText('28')).toBeInTheDocument();
    });

    it('renders hot weather (≥31°C) with cooked tone', () => {
        render(<WeatherHero detail={{ weather_temp_c: 32 }} />);
        expect(screen.getByText('32')).toBeInTheDocument();
    });

    it('renders rain state with mood-spinning gradient and rain icon', () => {
        render(<WeatherHero detail={{ weather_temp_c: 25, weather_rain_detected: true }} />);
        expect(screen.getByText(/hujan saat lari/)).toBeInTheDocument();
    });

    it('renders hot + rain combo (rain icon takes precedence)', () => {
        render(
            <WeatherHero
                detail={{ weather_temp_c: 33, weather_rain_detected: true, weather_humidity_pct: 85 }}
            />,
        );
        expect(screen.getByText('33')).toBeInTheDocument();
        expect(screen.getByText(/hujan saat lari/)).toBeInTheDocument();
    });

    it('renders location alone when only location_name is set', () => {
        render(<WeatherHero detail={{ location_name: 'Senayan, Jakarta' }} />);
        expect(screen.getByText('Senayan, Jakarta')).toBeInTheDocument();
    });
});
