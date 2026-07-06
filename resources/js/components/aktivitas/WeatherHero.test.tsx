import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import WeatherHero from './WeatherHero';

describe('WeatherHero', () => {
    it('returns null when no weather/location data', () => {
        const { container } = render(<WeatherHero detail={{}} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders cool weather (<27°C) with brand tint', () => {
        const { container } = render(<WeatherHero detail={{ weather_temp_c: 22, weather_humidity_pct: 70 }} />);
        const temp = screen.getByText('22');
        expect(temp).toBeInTheDocument();
        expect(temp).toHaveClass('text-leaf-deep');
        expect(screen.getByText(/70% humidity/)).toBeInTheDocument();
        expect(container.querySelector('[data-icon]')).toHaveAttribute('data-icon', 'mdi:weather-partly-cloudy');
    });

    it('renders warm weather (27-30°C) with squished tone', () => {
        const { container } = render(<WeatherHero detail={{ weather_temp_c: 28 }} />);
        const temp = screen.getByText('28');
        expect(temp).toBeInTheDocument();
        expect(temp).toHaveClass('text-mood-oleng');
        expect(container.querySelector('[data-icon]')).toHaveAttribute('data-icon', 'mdi:weather-partly-cloudy');
    });

    it('renders hot weather (≥31°C) with cooked tone', () => {
        const { container } = render(<WeatherHero detail={{ weather_temp_c: 32 }} />);
        const temp = screen.getByText('32');
        expect(temp).toBeInTheDocument();
        expect(temp).toHaveClass('text-mood-lemes');
        expect(container.querySelector('[data-icon]')).toHaveAttribute('data-icon', 'mdi:weather-sunny-alert');
    });

    it('renders rain state with mood-mumet gradient and rain icon', () => {
        const { container } = render(<WeatherHero detail={{ weather_temp_c: 25, weather_rain_detected: true }} />);
        expect(screen.getByText(/hujan saat lari/)).toBeInTheDocument();
        expect(container.querySelector('[data-icon]')).toHaveAttribute('data-icon', 'mdi:weather-rainy');
    });

    it('renders hot + rain combo (rain icon takes precedence)', () => {
        const { container } = render(
            <WeatherHero
                detail={{ weather_temp_c: 33, weather_rain_detected: true, weather_humidity_pct: 85 }}
            />,
        );
        const temp = screen.getByText('33');
        expect(temp).toBeInTheDocument();
        expect(temp).toHaveClass('text-mood-lemes');
        expect(screen.getByText(/hujan saat lari/)).toBeInTheDocument();
        expect(container.querySelector('[data-icon]')).toHaveAttribute('data-icon', 'mdi:weather-rainy');
    });

    it('renders location alone when only location_name is set', () => {
        render(<WeatherHero detail={{ location_name: 'Senayan, Jakarta' }} />);
        expect(screen.getByText('Senayan, Jakarta')).toBeInTheDocument();
    });

    it('appends the prakiraan hedge when rain is forecast rather than detected', () => {
        render(
            <WeatherHero
                detail={{ weather_temp_c: 25, weather_rain_detected: true, weather_rain_is_forecast: true }}
            />,
        );
        expect(screen.getByText(/hujan saat lari/)).toBeInTheDocument();
        expect(screen.getByText('(prakiraan)')).toBeInTheDocument();
    });

    it('omits the prakiraan hedge when rain is directly detected', () => {
        render(<WeatherHero detail={{ weather_temp_c: 25, weather_rain_detected: true }} />);
        expect(screen.getByText(/hujan saat lari/)).toBeInTheDocument();
        expect(screen.queryByText('(prakiraan)')).not.toBeInTheDocument();
    });
});
