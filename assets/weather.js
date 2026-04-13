const input = document.getElementById('city-input');
const btn = document.getElementById('search-btn');
const current = document.getElementById('current');
const details = document.getElementById('details');
const forecast = document.getElementById('forecast');
const error = document.getElementById('error');
const loading = document.getElementById('loading');

async function fetchWeather(city) {
    [current, details, forecast, error].forEach(el => el.classList.remove('visible'));
    loading.classList.add('visible');

    try {
        const response = await fetch(`/api/weather/${encodeURIComponent(city)}`);
        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error);
        }

        const c = data.current;

        // Current weather
        document.getElementById('weather-temp').textContent = `${Math.round(c.temperature)}°`;
        document.getElementById('weather-icon').src = `https://openweathermap.org/img/wn/${c.icon}@2x.png`;
        document.getElementById('weather-icon').alt = c.description;
        document.getElementById('weather-desc').textContent = c.description;

        // Details
        document.getElementById('detail-humidity').textContent = `${c.humidity}%`;
        document.getElementById('detail-wind').textContent = `${Math.round(c.wind_speed * 3.6)} km/h`;
        document.getElementById('detail-feels').textContent = `${Math.round(c.feels_like)}°`;
        document.getElementById('detail-pressure').textContent = `${c.pressure} hPa`;

        // Forecast
        const forecastRow = document.getElementById('forecast-row');
        forecastRow.innerHTML = data.forecast.map(f => `
            <div class="forecast-item">
                <div class="forecast-time">${f.time}</div>
                <img src="https://openweathermap.org/img/wn/${f.icon}.png" alt="${f.description}">
                <div class="forecast-temp">${Math.round(f.temperature)}°</div>
            </div>
        `).join('');

        [current, details, forecast].forEach(el => el.classList.add('visible'));
    } catch (err) {
        error.textContent = err.message || 'Une erreur est survenue.';
        error.classList.add('visible');
    } finally {
        loading.classList.remove('visible');
    }
}

btn.addEventListener('click', () => {
    const city = input.value.trim();
    if (city.length >= 2) fetchWeather(city);
});

input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        const city = input.value.trim();
        if (city.length >= 2) fetchWeather(city);
    }
});
