import { weatherIcon } from './icons.js';
import { renderCurve } from './curve.js';

const $ = (id) => document.getElementById(id);

const stage = $('stage');
const form = $('search-form');
const input = $('city-input');
const submit = $('search-btn');
const status = $('status');
const loading = $('loading');
const errorRow = $('error');
const results = $('results');
const hero = $('hero');
const finder = $('finder');
const barSlot = $('bar-slot');
const curveHost = $('curve');

const API_URL = stage.dataset.apiUrl;
const pills = document.querySelectorAll('[data-city]');
const COOLDOWN_MAX_SECONDS = 120;
let requestId = 0;
let forecastItems = [];
let inFlight = false;
let cooldownTimer = 0;
let cooldownUntil = 0;
let shownKeys = new Set();

/* ---------- Live local clock ---------- */

const clock = $('clock');

function tickClock() {
    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    clock.textContent = `${hh}:${mm}`;
    clock.dateTime = `${hh}:${mm}`;
    window.setTimeout(tickClock, (60 - now.getSeconds()) * 1000 - now.getMilliseconds() + 20);
}
tickClock();

/* ---------- Helpers ---------- */

const capitalize = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);

/** Rounded degrees with a typographic minus: "−3°". */
function degrees(n) {
    const rounded = Math.round(n);
    return `${rounded < 0 ? '−' : ''}${Math.abs(rounded)}°`;
}

function formatVisibility(meters) {
    const km = meters / 1000;
    return km >= 10 ? String(Math.round(km)) : km.toFixed(1).replace('.', ',');
}

/** OpenWeatherMap icon code to one of the page skies. */
function skyFor(code) {
    const key = String(code || '');
    if (key.endsWith('n')) return 'night';
    switch (key.slice(0, 2)) {
        case '01':
        case '02':
            return 'clear-day';
        case '09':
        case '10':
            return 'rain';
        case '11':
            return 'storm';
        case '13':
            return 'snow';
        case '50':
            return 'mist';
        default:
            return 'clouds-day';
    }
}

function setMessage(text) {
    errorRow.replaceChildren();
    if (!text) {
        input.removeAttribute('aria-invalid');
        return;
    }
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('fill', 'none');
    icon.setAttribute('stroke', 'currentColor');
    icon.setAttribute('stroke-width', '1.75');
    icon.setAttribute('stroke-linecap', 'round');
    icon.setAttribute('stroke-linejoin', 'round');
    icon.setAttribute('aria-hidden', 'true');
    icon.setAttribute('focusable', 'false');
    icon.setAttribute('class', 'message-icon');
    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    circle.setAttribute('cx', '12');
    circle.setAttribute('cy', '12');
    circle.setAttribute('r', '9');
    const mark = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    mark.setAttribute('d', 'M12 7.5v5.5M12 16.4v.1');
    icon.append(circle, mark);

    const label = document.createElement('span');
    label.textContent = text;
    errorRow.append(icon, label);
    input.setAttribute('aria-invalid', 'true');
}

/** Comparison key for a city name: trimmed, lowercase, without accents. */
function normalize(name) {
    return name
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .trim()
        .replace(/\s+/g, ' ')
        .toLocaleLowerCase('fr');
}

const cooling = () => Date.now() < cooldownUntil;

/** Submit button and suggestion pills are inert while loading or cooling down. */
function syncControls() {
    const locked = inFlight || cooling();
    submit.disabled = locked;
    pills.forEach((pill) => pill.setAttribute('aria-disabled', locked ? 'true' : 'false'));
}

/** Locks new searches for `seconds` after a 429, then clears the stale message. */
function startCooldown(seconds, message) {
    window.clearTimeout(cooldownTimer);
    cooldownUntil = Date.now() + seconds * 1000;
    syncControls();
    cooldownTimer = window.setTimeout(() => {
        cooldownUntil = 0;
        syncControls();
        if (errorRow.textContent.trim() === message) setMessage('');
    }, seconds * 1000);
}

function setBusy(busy) {
    inFlight = busy;
    syncControls();
    submit.setAttribute('aria-busy', busy ? 'true' : 'false');
    form.setAttribute('aria-busy', busy ? 'true' : 'false');
    results.setAttribute('aria-busy', busy ? 'true' : 'false');
    results.classList.toggle('is-loading', busy);
}

/** Moves the search field from the home hero into the top bar on first result. */
function showDetail() {
    if (document.body.dataset.view === 'detail') return;
    const inputHadFocus = document.activeElement === input;
    document.body.dataset.view = 'detail';
    hero.hidden = true;
    results.hidden = false;
    barSlot.append(finder);
    if (inputHadFocus) input.focus({ preventScroll: true });
}

/* ---------- Rendering ---------- */

function reading(id, value, unit) {
    const node = $(id);
    const suffix = document.createElement('span');
    suffix.className = 'unit';
    suffix.textContent = unit ? ` ${unit}` : '';
    node.replaceChildren(document.createTextNode(value), suffix);
}

function renderCurrent(c) {
    $('place-city').textContent = c.city;
    $('place-country').textContent = c.country ?? '';

    const rounded = Math.round(c.temperature);
    $('temp-value').textContent = `${rounded < 0 ? '−' : ''}${Math.abs(rounded)}`;

    const label = document.createElement('span');
    label.textContent = capitalize(c.description);
    $('cond').replaceChildren(weatherIcon(c.icon), label);
    $('r-feels').textContent = degrees(c.feels_like);

    reading('r-humidity', String(c.humidity), '%');
    reading('r-wind', String(Math.round(c.wind_speed * 3.6)), `km/h ${c.wind_direction ?? ''}`.trim());
    reading('r-pressure', String(c.pressure), 'hPa');
    reading('r-visibility', formatVisibility(c.visibility), 'km');
}

function renderForecast(current, forecast, animate) {
    forecastItems = [
        { label: 'Maint.', value: current.temperature, text: degrees(current.temperature), icon: current.icon, description: current.description },
        ...forecast.map((f) => ({ label: f.time, value: f.temperature, text: degrees(f.temperature), icon: f.icon, description: f.description })),
    ];

    const body = $('curve-table');
    body.replaceChildren();
    for (const item of forecastItems) {
        const row = document.createElement('tr');
        for (const text of [item.label, item.text, capitalize(item.description)]) {
            const cell = document.createElement('td');
            cell.textContent = text;
            row.append(cell);
        }
        body.append(row);
    }

    drawCurve(animate);
}

function drawCurve(animate) {
    if (forecastItems.length < 2) {
        curveHost.replaceChildren();
        return;
    }
    renderCurve(curveHost, forecastItems, { animate });
}

let lastWidth = 0;
if ('ResizeObserver' in window) {
    new ResizeObserver(() => {
        const width = Math.round(curveHost.clientWidth);
        if (width && Math.abs(width - lastWidth) > 1) {
            const resized = lastWidth !== 0;
            lastWidth = width;
            if (resized && forecastItems.length) drawCurve(false);
        }
    }).observe(curveHost);
}

/* ---------- Search ---------- */

async function search(city) {
    if (inFlight || cooling()) return;

    const query = city.trim();
    if ([...query].length < 2) {
        setMessage('Saisissez au moins 2 caractères.');
        input.focus();
        return;
    }

    // Same city as the one on screen: keep the view, no new request.
    if (shownKeys.has(normalize(query))) {
        setMessage('');
        return;
    }

    const wasPill = document.activeElement && document.activeElement.classList.contains('pill');
    const current = ++requestId;
    setMessage('');
    loading.hidden = false;
    setBusy(true);
    status.textContent = '';

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ city: query }),
            credentials: 'same-origin',
        });
        let data = null;
        try {
            data = await response.json();
        } catch {
            data = null;
        }
        if (current !== requestId) return;

        if (!response.ok || !data || !data.current) {
            const error = new Error((data && data.error) || 'Une erreur est survenue, réessayez dans un instant.');
            error.retryAfter = response.status === 429 ? Number.parseInt(response.headers.get('Retry-After') ?? '', 10) : 0;
            throw error;
        }

        shownKeys = new Set([normalize(query), normalize(data.current.city)]);
        showDetail();
        input.value = data.current.city;
        document.body.dataset.sky = skyFor(data.current.icon);
        renderCurrent(data.current);
        lastWidth = Math.round(curveHost.clientWidth);
        renderForecast(data.current, data.forecast || [], true);
        document.title = `${data.current.city} — meteodash`;
        status.textContent = `Météo de ${data.current.city} chargée`;

        if (window.matchMedia('(pointer: coarse)').matches) input.blur();
        if (wasPill) $('place').focus({ preventScroll: true });
    } catch (err) {
        if (current !== requestId) return;
        setMessage(err.message);
        if (err.retryAfter > 0 && err.retryAfter <= COOLDOWN_MAX_SECONDS) startCooldown(err.retryAfter, err.message);
    } finally {
        if (current === requestId) {
            loading.hidden = true;
            setBusy(false);
        }
    }
}

form.addEventListener('submit', (event) => {
    event.preventDefault();
    search(input.value);
});

pills.forEach((button) => {
    button.addEventListener('click', () => {
        if (inFlight || cooling()) return;
        input.value = button.dataset.city;
        search(button.dataset.city);
    });
});

input.addEventListener('input', () => {
    if (errorRow.firstChild) setMessage('');
});
