import { weatherIcon } from './icons.js';

const NS = 'http://www.w3.org/2000/svg';

const HEIGHT = 180;
const PAD_X = 24;
const PLOT_TOP = 44;
const PLOT_BOTTOM = 112;
const AXIS_Y = 128;
const HOUR_Y = 150;
const ICON_Y = 158;
const ICON_SIZE = 16;
// Below this spread (°C) the curve is drawn as if it spanned it, so a 1° wobble is not exaggerated.
const MIN_SPREAD = 4;

function svgEl(name, attrs = {}) {
    const node = document.createElementNS(NS, name);
    for (const [key, value] of Object.entries(attrs)) {
        node.setAttribute(key, String(value));
    }
    return node;
}

function textEl(className, x, y, content) {
    const node = svgEl('text', { class: className, x, y });
    node.textContent = content;
    return node;
}

/**
 * Monotone cubic (Fritsch–Carlson) tangents for points sorted on x: the curve
 * never overshoots a data point, so a plotted peak is always a real reading.
 */
function tangents(points) {
    const n = points.length;
    const slopes = [];
    for (let i = 0; i < n - 1; i++) {
        slopes.push((points[i + 1].y - points[i].y) / (points[i + 1].x - points[i].x));
    }
    const m = new Array(n);
    m[0] = slopes[0];
    m[n - 1] = slopes[n - 2];
    for (let i = 1; i < n - 1; i++) {
        m[i] = slopes[i - 1] * slopes[i] <= 0 ? 0 : (slopes[i - 1] + slopes[i]) / 2;
    }
    for (let i = 0; i < n - 1; i++) {
        if (slopes[i] === 0) {
            m[i] = 0;
            m[i + 1] = 0;
            continue;
        }
        const a = m[i] / slopes[i];
        const b = m[i + 1] / slopes[i];
        const sum = a * a + b * b;
        if (sum > 9) {
            const tau = 3 / Math.sqrt(sum);
            m[i] = tau * a * slopes[i];
            m[i + 1] = tau * b * slopes[i];
        }
    }
    return m;
}

/** SVG path data (lines and cubic segments) through the points. */
export function monotonePath(points) {
    if (points.length < 2) return '';
    const m = tangents(points);
    const f = (v) => v.toFixed(2);
    let d = `M${f(points[0].x)} ${f(points[0].y)}`;
    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i];
        const p1 = points[i + 1];
        const h = (p1.x - p0.x) / 3;
        d += `C${f(p0.x + h)} ${f(p0.y + m[i] * h)} ${f(p1.x - h)} ${f(p1.y - m[i + 1] * h)} ${f(p1.x)} ${f(p1.y)}`;
    }
    return d;
}

/**
 * Draws the temperature curve into `container`.
 *
 * items: [{ label, value (number), text, icon, description }]
 * The SVG is sized in real pixels (viewBox = container width), so text keeps
 * its size on narrow screens; it is redrawn on resize by the caller.
 */
export function renderCurve(container, items, { animate = false } = {}) {
    const width = Math.max(240, Math.round(container.clientWidth || 640));
    const values = items.map((item) => item.value);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const spread = Math.max(max - min, MIN_SPREAD);
    const mid = (max + min) / 2;
    const low = mid - spread / 2;

    const step = (width - PAD_X * 2) / (items.length - 1);
    const points = items.map((item, i) => ({
        x: PAD_X + i * step,
        y: PLOT_BOTTOM - ((item.value - low) / spread) * (PLOT_BOTTOM - PLOT_TOP),
    }));

    const line = monotonePath(points);
    const first = points[0];
    const last = points[points.length - 1];
    const area = `${line}L${last.x.toFixed(2)} ${AXIS_Y}L${first.x.toFixed(2)} ${AXIS_Y}Z`;

    const svg = svgEl('svg', {
        class: animate ? 'curve-svg is-animated' : 'curve-svg',
        viewBox: `0 0 ${width} ${HEIGHT}`,
        role: 'img',
        'aria-label': `Prévisions : ${items.map((item) => `${item.label} ${item.text}`).join(', ')}`,
    });

    svg.append(
        svgEl('line', { class: 'curve-axis', x1: 0, y1: AXIS_Y, x2: width, y2: AXIS_Y }),
        svgEl('path', { class: 'curve-area', d: area, 'fill-opacity': 0.1 }),
    );

    const stroke = svgEl('path', { class: 'curve-line', d: line });
    svg.append(stroke);

    items.forEach((item, i) => {
        const { x, y } = points[i];
        const group = svgEl('g', { class: i === 0 ? 'pt is-now' : 'pt' });
        group.style.setProperty('--i', String(i));
        group.append(
            svgEl('circle', { class: 'curve-dot', cx: x.toFixed(2), cy: y.toFixed(2), r: i === 0 ? 5 : 4 }),
            textEl('curve-value', x.toFixed(2), (y - 16).toFixed(2), item.text),
            textEl('curve-hour', x.toFixed(2), HOUR_Y, item.label),
        );
        const icon = weatherIcon(item.icon, ICON_SIZE);
        icon.setAttribute('class', 'curve-icon');
        icon.setAttribute('x', (x - ICON_SIZE / 2).toFixed(2));
        icon.setAttribute('y', ICON_Y);
        group.append(icon);
        svg.append(group);
    });

    container.replaceChildren(svg);

    if (animate) {
        // Length is only known once the path is in the document.
        stroke.style.setProperty('--len', String(Math.ceil(stroke.getTotalLength())));
    }
}
