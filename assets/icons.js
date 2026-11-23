const SUN_RAYS = 'M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4';
const CLOUD = 'M7.5 19h9a4 4 0 0 0 .6-7.95A5.5 5.5 0 0 0 6.6 10 4.5 4.5 0 0 0 7.5 19z';
const CLOUD_UP = 'M7.5 15h9a4 4 0 0 0 .6-7.95A5.5 5.5 0 0 0 6.6 6 4.5 4.5 0 0 0 7.5 15z';

const ICONS = {
    '01d': `<circle cx="12" cy="12" r="4"/><path d="${SUN_RAYS}"/>`,
    '01n': '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/>',
    '02d': '<circle cx="8.5" cy="8.5" r="2.8"/><path d="M8.5 2.5v1.5M2.5 8.5H4M4.3 4.3l1 1M12.7 4.3l-1 1"/><path d="M9.5 20h8a3.5 3.5 0 0 0 .5-6.95A4.8 4.8 0 0 0 9.6 12.4 3.8 3.8 0 0 0 9.5 20z"/>',
    '02n': '<path d="M11 3.5A5 5 0 1 0 14.5 11 4.5 4.5 0 0 1 11 3.5z"/><path d="M9.5 20h8a3.5 3.5 0 0 0 .5-6.95A4.8 4.8 0 0 0 9.6 12.4 3.8 3.8 0 0 0 9.5 20z"/>',
    '03': `<path d="${CLOUD}"/>`,
    '04': `<path d="${CLOUD}"/><path d="M16 6.3a3.5 3.5 0 0 1 5 3"/>`,
    '09': `<path d="${CLOUD_UP}"/><path d="M8 18v2.5M12 18v3.5M16 18v2.5"/>`,
    '10': `<path d="${CLOUD_UP}"/><path d="M8.5 17.5l-1.2 3.5M12.5 17.5l-1.2 3.5M16.5 17.5l-1.2 3.5"/>`,
    '11': `<path d="${CLOUD_UP}"/><path d="M12.8 14.5l-3 4h4l-2.3 4"/>`,
    '13': `<path d="${CLOUD_UP}"/><path d="M8 17.5v3.5M6.3 19.2h3.4M12 17.5v3.5M10.3 19.2h3.4M16 17.5v3.5M14.3 19.2h3.4"/>`,
    '50': '<path d="M3.5 8h17M6 12h12M3.5 16h17M8 20h8"/>',
};

/**
 * Builds a monochrome line icon for an OpenWeatherMap icon code.
 * An explicit size (px) sets width/height attributes, for icons nested in another SVG.
 */
export function weatherIcon(code, size = null) {
    const key = String(code || '');
    const night = key.endsWith('n');
    const base = key.slice(0, 2);
    const markup = ICONS[key] ?? ICONS[night ? `${base}n` : `${base}d`] ?? ICONS[base] ?? ICONS['03'];

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.5');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    if (size) {
        svg.setAttribute('width', String(size));
        svg.setAttribute('height', String(size));
    } else {
        svg.classList.add('icon');
    }
    // Static markup from the table above, never API data.
    svg.innerHTML = markup;

    return svg;
}
