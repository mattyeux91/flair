'use strict';

const CATEGORY_LABELS = { physical: 'Physique', technical: 'Technique', mental: 'Mental' };
const CATEGORY_ORDER = ['physical', 'technical', 'mental'];
const SVG_NS = 'http://www.w3.org/2000/svg';
const CHART_WIDTH = 720;
const CHART_HEIGHT = 260;
const PADDING = { top: 16, right: 16, bottom: 32, left: 40 };

let lastResponse = null;

const form = document.getElementById('run-form');
const statusEl = document.getElementById('status');
const filtersEl = document.getElementById('filters');
const chartsEl = document.getElementById('charts');

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = new FormData(form);
    const payload = {
        players: Number(data.get('players')),
        years: Number(data.get('years')),
        seed: Number(data.get('seed')),
        compareField: data.get('compareField') || '',
        compareValue: data.get('compareValue') || null,
    };

    statusEl.textContent = 'Simulation en cours…';
    try {
        const response = await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        lastResponse = await response.json();
        statusEl.textContent = '';
        filtersEl.hidden = false;
        renderAll();
    } catch (error) {
        statusEl.textContent = `Erreur : ${error.message}`;
    }
});

filtersEl.addEventListener('change', renderAll);

function activeCategories() {
    return Array.from(filtersEl.querySelectorAll('input[name="category"]:checked')).map((input) => input.value);
}

function bandVisible() {
    return document.getElementById('toggle-band').checked;
}

function chainedVisible() {
    return document.getElementById('toggle-chained').checked;
}

function renderAll() {
    if (!lastResponse) {
        return;
    }
    chartsEl.innerHTML = '';

    const isComparison = Boolean(lastResponse.modified);
    const categories = activeCategories();

    for (const category of CATEGORY_ORDER) {
        if (!categories.includes(category)) {
            continue;
        }
        const section = document.createElement('div');
        section.className = 'chart-block';
        const heading = document.createElement('h3');
        heading.textContent = `${CATEGORY_LABELS[category]} — competence moyenne par age`;
        section.appendChild(heading);

        if (isComparison) {
            // En comparaison, les lignes superposees sont les courbes
            // corrigees (chainedCurves), pas le p50 brut - sinon le biais de
            // survie se melangerait a l'effet du parametre teste.
            const baselineChained = lastResponse.baseline.chainedCurves[category] || {};
            const modifiedChained = lastResponse.modified.chainedCurves[category] || {};
            section.appendChild(renderCurveChart({
                rawCurve: null,
                chainedCurve: baselineChained,
                modifiedChainedCurve: modifiedChained,
                showBand: false,
                showChained: true,
            }));
        } else {
            const curve = lastResponse.baseline.curves[category] || {};
            const chainedCurve = lastResponse.baseline.chainedCurves[category] || {};
            section.appendChild(renderCurveChart({
                rawCurve: curve,
                chainedCurve,
                modifiedChainedCurve: null,
                showBand: bandVisible(),
                showChained: chainedVisible(),
            }));
        }

        chartsEl.appendChild(section);
    }

    const retirementSection = document.createElement('div');
    retirementSection.className = 'chart-block';
    const retirementHeading = document.createElement('h3');
    retirementHeading.textContent = 'Distribution des ages de retraite';
    retirementSection.appendChild(retirementHeading);
    retirementSection.appendChild(renderHistogramChart(
        lastResponse.baseline.retirementAgeHistogram,
        isComparison ? lastResponse.modified.retirementAgeHistogram : null,
    ));
    chartsEl.appendChild(retirementSection);

    if (isComparison) {
        const legend = document.createElement('p');
        legend.className = 'legend';
        legend.innerHTML = '<span class="swatch baseline"></span> baseline &nbsp; <span class="swatch modified"></span> modifie';
        chartsEl.insertBefore(legend, chartsEl.firstChild);
    }
}

function scale(value, domainMin, domainMax, rangeMin, rangeMax) {
    if (domainMax === domainMin) {
        return rangeMin;
    }
    return rangeMin + ((value - domainMin) / (domainMax - domainMin)) * (rangeMax - rangeMin);
}

/**
 * rawCurve : coupe transversale brute (mean/p10/p50/p90/count par age) ou
 * null en mode comparaison. chainedCurve/modifiedChainedCurve : courbes
 * corrigees (methode delta, cf. DeltaCurveBuilder cote PHP) - age -> niveau.
 *
 * Retourne un wrapper (pas directement le svg) : le curseur au survol d'une
 * courbe a besoin d'une infobulle HTML positionnee en absolu par rapport a
 * ce conteneur.
 */
function renderCurveChart({ rawCurve, chainedCurve, modifiedChainedCurve, showBand, showChained }) {
    const wrapper = document.createElement('div');
    wrapper.className = 'chart-wrapper';

    const rawAges = rawCurve ? Object.keys(rawCurve).map(Number) : [];
    const chainedAges = Object.keys(chainedCurve || {}).map(Number);
    const modifiedAges = Object.keys(modifiedChainedCurve || {}).map(Number);
    const ages = [...new Set([...rawAges, ...chainedAges, ...modifiedAges])].sort((a, b) => a - b);
    const svg = createSvg();
    wrapper.appendChild(svg);

    if (ages.length === 0) {
        return wrapper;
    }

    const minAge = ages[0];
    const maxAge = ages[ages.length - 1];
    const x0 = PADDING.left;
    const x1 = CHART_WIDTH - PADDING.right;
    const y0 = CHART_HEIGHT - PADDING.bottom;
    const y1 = PADDING.top;

    drawAxes(svg, minAge, maxAge, 0, 100);

    const xOf = (age) => scale(age, minAge, maxAge, x0, x1);
    const yOf = (value) => scale(value, 0, 100, y0, y1);

    const series = [];

    if (rawCurve) {
        const sortedRawAges = rawAges.sort((a, b) => a - b);
        if (showBand) {
            const bandPoints = sortedRawAges.map((age) => `${xOf(age)},${yOf(rawCurve[age].p90)}`)
                .concat([...sortedRawAges].reverse().map((age) => `${xOf(age)},${yOf(rawCurve[age].p10)}`));
            appendPolygon(svg, bandPoints, 'band');
        }
        appendPolyline(svg, sortedRawAges.map((age) => `${xOf(age)},${yOf(rawCurve[age].p50)}`), 'line baseline');

        const p50 = {};
        sortedRawAges.forEach((age) => { p50[age] = rawCurve[age].p50; });
        series.push({ label: 'brut (p50)', className: 'baseline', values: p50 });
    }

    if (showChained && chainedCurve) {
        const sortedChainedAges = chainedAges.sort((a, b) => a - b);
        const className = rawCurve ? 'line chained' : 'line baseline';
        appendPolyline(svg, sortedChainedAges.map((age) => `${xOf(age)},${yOf(chainedCurve[age])}`), className);
        series.push({ label: rawCurve ? 'corrige' : 'baseline', className: rawCurve ? 'chained' : 'baseline', values: chainedCurve });
    }

    if (modifiedChainedCurve) {
        const sortedModifiedAges = modifiedAges.sort((a, b) => a - b);
        appendPolyline(svg, sortedModifiedAges.map((age) => `${xOf(age)},${yOf(modifiedChainedCurve[age])}`), 'line modified');
        series.push({ label: 'modifie', className: 'modified', values: modifiedChainedCurve });
    }

    attachCurveTooltip(svg, wrapper, ages, xOf, series);

    return wrapper;
}

/**
 * Infobulle au survol : trouve l'age le plus proche du curseur (les donnees
 * sont bucketees par age entier, pas de vraie interpolation necessaire),
 * affiche une ligne de reperage verticale + les valeurs de chaque serie
 * visible a cet age.
 */
function attachCurveTooltip(svg, wrapper, ages, xOf, series) {
    const points = ages.map((age) => ({ age, x: xOf(age) }));

    const tooltip = document.createElement('div');
    tooltip.className = 'chart-tooltip';
    tooltip.hidden = true;
    wrapper.appendChild(tooltip);

    const crosshair = document.createElementNS(SVG_NS, 'line');
    crosshair.setAttribute('class', 'crosshair');
    crosshair.setAttribute('y1', String(PADDING.top));
    crosshair.setAttribute('y2', String(CHART_HEIGHT - PADDING.bottom));
    crosshair.style.display = 'none';
    svg.appendChild(crosshair);

    function toSvgPoint(clientX, clientY) {
        const ctm = svg.getScreenCTM();
        if (!ctm) {
            return null;
        }
        const point = svg.createSVGPoint();
        point.x = clientX;
        point.y = clientY;
        return point.matrixTransform(ctm.inverse());
    }

    function hide() {
        tooltip.hidden = true;
        crosshair.style.display = 'none';
    }

    svg.addEventListener('mousemove', (event) => {
        const svgPoint = toSvgPoint(event.clientX, event.clientY);
        if (!svgPoint) {
            return;
        }

        let nearest = points[0];
        for (const candidate of points) {
            if (Math.abs(candidate.x - svgPoint.x) < Math.abs(nearest.x - svgPoint.x)) {
                nearest = candidate;
            }
        }

        const lines = series
            .filter((entry) => entry.values[nearest.age] !== undefined)
            .map((entry) => `<span class="tooltip-swatch ${entry.className}"></span>${entry.label} : ${entry.values[nearest.age].toFixed(1)}`);

        if (lines.length === 0) {
            hide();
            return;
        }

        tooltip.innerHTML = `<strong>${nearest.age} ans</strong><br>${lines.join('<br>')}`;
        tooltip.hidden = false;

        const wrapperRect = wrapper.getBoundingClientRect();
        tooltip.style.left = `${event.clientX - wrapperRect.left + 12}px`;
        tooltip.style.top = `${event.clientY - wrapperRect.top + 12}px`;

        crosshair.setAttribute('x1', String(nearest.x));
        crosshair.setAttribute('x2', String(nearest.x));
        crosshair.style.display = '';
    });

    svg.addEventListener('mouseleave', hide);
}

function renderHistogramChart(histogram, modifiedHistogram) {
    const ages = Object.keys(histogram).map(Number);
    if (modifiedHistogram) {
        ages.push(...Object.keys(modifiedHistogram).map(Number));
    }
    const uniqueAges = [...new Set(ages)].sort((a, b) => a - b);
    const svg = createSvg();

    if (uniqueAges.length === 0) {
        return svg;
    }

    const maxCount = Math.max(
        ...uniqueAges.map((age) => histogram[age] || 0),
        ...(modifiedHistogram ? uniqueAges.map((age) => modifiedHistogram[age] || 0) : [0]),
    );

    const x0 = PADDING.left;
    const x1 = CHART_WIDTH - PADDING.right;
    const y0 = CHART_HEIGHT - PADDING.bottom;
    const y1 = PADDING.top;

    drawAxes(svg, uniqueAges[0], uniqueAges[uniqueAges.length - 1], 0, maxCount);

    const slot = (x1 - x0) / uniqueAges.length;
    const barGroupWidth = slot * 0.7;
    const barWidth = modifiedHistogram ? barGroupWidth / 2 : barGroupWidth;

    uniqueAges.forEach((age, index) => {
        const groupX = x0 + index * slot + (slot - barGroupWidth) / 2;

        const baselineCount = histogram[age] || 0;
        const baselineHeight = scale(baselineCount, 0, maxCount, y0, y1);
        appendRect(svg, groupX, baselineHeight, barWidth, y0 - baselineHeight, 'bar baseline', `${age} ans : ${baselineCount}`);

        if (modifiedHistogram) {
            const modifiedCount = modifiedHistogram[age] || 0;
            const modifiedHeight = scale(modifiedCount, 0, maxCount, y0, y1);
            appendRect(svg, groupX + barWidth, modifiedHeight, barWidth, y0 - modifiedHeight, 'bar modified', `${age} ans (modifie) : ${modifiedCount}`);
        }
    });

    return svg;
}

function createSvg() {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${CHART_WIDTH} ${CHART_HEIGHT}`);
    svg.setAttribute('class', 'chart');
    return svg;
}

function drawAxes(svg, xMin, xMax, yMin, yMax) {
    const x0 = PADDING.left;
    const x1 = CHART_WIDTH - PADDING.right;
    const y0 = CHART_HEIGHT - PADDING.bottom;
    const y1 = PADDING.top;

    const axis = document.createElementNS(SVG_NS, 'path');
    axis.setAttribute('d', `M${x0},${y1} L${x0},${y0} L${x1},${y0}`);
    axis.setAttribute('class', 'axis');
    svg.appendChild(axis);

    const steps = 5;
    for (let i = 0; i <= steps; i++) {
        const age = Math.round(xMin + (i / steps) * (xMax - xMin));
        const x = scale(age, xMin, xMax, x0, x1);
        appendText(svg, x, y0 + 16, String(age), 'tick');

        const value = Math.round(yMin + (i / steps) * (yMax - yMin));
        const y = scale(value, yMin, yMax, y0, y1);
        appendText(svg, x0 - 8, y + 4, String(value), 'tick tick-y');
    }
}

function appendPolyline(svg, points, className) {
    const el = document.createElementNS(SVG_NS, 'polyline');
    el.setAttribute('points', points.join(' '));
    el.setAttribute('class', className);
    svg.appendChild(el);
}

function appendPolygon(svg, points, className) {
    const el = document.createElementNS(SVG_NS, 'polygon');
    el.setAttribute('points', points.join(' '));
    el.setAttribute('class', className);
    svg.appendChild(el);
}

function appendRect(svg, x, y, width, height, className, title) {
    const el = document.createElementNS(SVG_NS, 'rect');
    el.setAttribute('x', String(x));
    el.setAttribute('y', String(y));
    el.setAttribute('width', String(Math.max(0, width)));
    el.setAttribute('height', String(Math.max(0, height)));
    el.setAttribute('class', className);
    if (title) {
        const titleEl = document.createElementNS(SVG_NS, 'title');
        titleEl.textContent = title;
        el.appendChild(titleEl);
    }
    svg.appendChild(el);
}

function appendText(svg, x, y, text, className) {
    const el = document.createElementNS(SVG_NS, 'text');
    el.setAttribute('x', String(x));
    el.setAttribute('y', String(y));
    el.setAttribute('class', className);
    el.textContent = text;
    svg.appendChild(el);
}
