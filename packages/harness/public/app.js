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
        clubs: Number(data.get('clubs')),
        facilitiesQuality: Number(data.get('facilitiesQuality')),
        overrides: collectOverrides(form),
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

/**
 * Ne garde que les champs dont la valeur differe de son defaut (pose en
 * attribut `data-default` sur chaque <input name="override[champ]"> par
 * index.php) - c'est ce qui permet au panneau d'afficher tous les champs de
 * Balance sans forcer une comparaison des qu'un seul d'entre eux n'est pas
 * touche.
 */
function collectOverrides(form) {
    const overrides = {};
    form.querySelectorAll('input[data-default]').forEach((input) => {
        const match = input.name.match(/^override\[(.+)]$/);
        if (!match) {
            return;
        }

        const value = Number(input.value);
        const defaultValue = Number(input.dataset.default);
        if (!Number.isNaN(value) && value !== defaultValue) {
            overrides[match[1]] = value;
        }
    });

    return overrides;
}

function activeCategories() {
    return Array.from(filtersEl.querySelectorAll('input[name="category"]:checked')).map((input) => input.value);
}

function bandVisible() {
    return document.getElementById('toggle-band').checked;
}

function renderAll() {
    if (!lastResponse) {
        return;
    }
    chartsEl.innerHTML = '';

    const isComparison = Boolean(lastResponse.modified);
    const categories = activeCategories();

    if (isComparison) {
        chartsEl.appendChild(renderEffectSummary(lastResponse.baseline, lastResponse.modified));
    }

    for (const category of CATEGORY_ORDER) {
        if (!categories.includes(category)) {
            continue;
        }
        const section = document.createElement('div');
        section.className = 'chart-block';
        const heading = document.createElement('h3');
        heading.textContent = `${CATEGORY_LABELS[category]} — competence moyenne par age`;
        section.appendChild(heading);

        // Une seule vue : la coupe transversale. La "courbe corrigee"
        // (methode delta) a ete retiree - elle supposait une cohorte fermee,
        // hypothese fausse depuis que YouthIntakeSystem injecte des joueurs
        // en continu (cf. docblock AggregateResult cote PHP).
        section.appendChild(renderCurveChart({
            baselineCurve: lastResponse.baseline.curves[category] || {},
            modifiedCurve: isComparison ? (lastResponse.modified.curves[category] || {}) : null,
            showBand: !isComparison && bandVisible(),
        }));

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

    const populationSection = document.createElement('div');
    populationSection.className = 'chart-block';
    const populationHeading = document.createElement('h3');
    populationHeading.textContent = 'Effectif actif par année';
    populationSection.appendChild(populationHeading);
    populationSection.appendChild(renderLineChart(
        lastResponse.baseline.populationByYear,
        isComparison ? lastResponse.modified.populationByYear : null,
    ));
    chartsEl.appendChild(populationSection);

    const pyramidSection = document.createElement('div');
    pyramidSection.className = 'chart-block';
    const pyramidHeading = document.createElement('h3');
    pyramidHeading.textContent = 'Pyramide des âges (dernière année simulée)';
    pyramidSection.appendChild(pyramidHeading);
    pyramidSection.appendChild(renderHistogramChart(
        lastResponse.baseline.finalAgeHistogram,
        isComparison ? lastResponse.modified.finalAgeHistogram : null,
    ));
    chartsEl.appendChild(pyramidSection);

    if (isComparison) {
        const legend = document.createElement('p');
        legend.className = 'legend';
        legend.innerHTML = '<span class="swatch baseline"></span> baseline &nbsp; <span class="swatch modified"></span> modifie';
        chartsEl.insertBefore(legend, chartsEl.firstChild);
    }
}

const EFFECT_SUMMARY_AGES = [20, 25, 30, 35];

/**
 * Tableau chiffre baseline vs modifie - repond directement au critere de
 * sortie Phase 1 (docs/15- §4 : "voir l'effet chiffre en moins de 5
 * minutes"). Lit la moyenne transversale a quelques ages de reference,
 * l'age moyen de retraite et l'effectif final - aucun nouveau calcul
 * serveur, tout est deja dans la reponse JSON.
 */
function renderEffectSummary(baseline, modified) {
    const wrapper = document.createElement('div');
    wrapper.className = 'chart-block effect-summary';
    const heading = document.createElement('h3');
    heading.textContent = "Résumé de l'effet (modifié − baseline)";
    wrapper.appendChild(heading);

    const rows = [];

    for (const category of CATEGORY_ORDER) {
        const baselineCurve = baseline.curves[category] || {};
        const modifiedCurve = modified.curves[category] || {};
        for (const age of EFFECT_SUMMARY_AGES) {
            const baselineStats = baselineCurve[age];
            const modifiedStats = modifiedCurve[age];
            if (baselineStats === undefined || modifiedStats === undefined) {
                continue;
            }
            rows.push([
                `${CATEGORY_LABELS[category]} à ${age} ans`,
                baselineStats.mean.toFixed(1),
                modifiedStats.mean.toFixed(1),
                formatDelta(modifiedStats.mean - baselineStats.mean),
            ]);
        }
    }

    const baselineRetirementAge = weightedMeanAge(baseline.retirementAgeHistogram);
    const modifiedRetirementAge = weightedMeanAge(modified.retirementAgeHistogram);
    if (baselineRetirementAge !== null && modifiedRetirementAge !== null) {
        rows.push([
            'Âge moyen de retraite',
            baselineRetirementAge.toFixed(1),
            modifiedRetirementAge.toFixed(1),
            formatDelta(modifiedRetirementAge - baselineRetirementAge),
        ]);
    }

    const baselinePopulation = lastYearValue(baseline.populationByYear);
    const modifiedPopulation = lastYearValue(modified.populationByYear);
    if (baselinePopulation !== null && modifiedPopulation !== null) {
        rows.push([
            'Effectif final',
            String(baselinePopulation),
            String(modifiedPopulation),
            formatDelta(modifiedPopulation - baselinePopulation),
        ]);
    }

    wrapper.appendChild(buildEffectSummaryTable(rows));

    return wrapper;
}

function buildEffectSummaryTable(rows) {
    const table = document.createElement('table');

    const headRow = document.createElement('tr');
    ['', 'Baseline', 'Modifié', 'Delta'].forEach((text) => {
        const th = document.createElement('th');
        th.textContent = text;
        headRow.appendChild(th);
    });
    table.appendChild(headRow);

    rows.forEach((cells) => {
        const row = document.createElement('tr');
        cells.forEach((text) => {
            const td = document.createElement('td');
            td.textContent = text;
            row.appendChild(td);
        });
        table.appendChild(row);
    });

    return table;
}

function formatDelta(value) {
    return `${value > 0 ? '+' : ''}${value.toFixed(1)}`;
}

/** @return {number|null} moyenne des ages ponderee par effectif, ou null si l'histogramme est vide */
function weightedMeanAge(histogram) {
    const ages = Object.keys(histogram).map(Number);
    const total = ages.reduce((sum, age) => sum + histogram[age], 0);
    if (total === 0) {
        return null;
    }

    const weighted = ages.reduce((sum, age) => sum + age * histogram[age], 0);

    return weighted / total;
}

/** @return {number|null} effectif de la derniere annee simulee, ou null si aucune donnee */
function lastYearValue(populationByYear) {
    const years = Object.keys(populationByYear).map(Number);
    if (years.length === 0) {
        return null;
    }

    return populationByYear[Math.max(...years)];
}

function scale(value, domainMin, domainMax, rangeMin, rangeMax) {
    if (domainMax === domainMin) {
        return rangeMin;
    }
    return rangeMin + ((value - domainMin) / (domainMax - domainMin)) * (rangeMax - rangeMin);
}

/**
 * baselineCurve/modifiedCurve : coupes transversales
 * (mean/p10/p50/p90/count par age) ; modifiedCurve est null hors comparaison.
 *
 * La ligne trace la **moyenne**, pas le p50, pour que le graphique et les
 * tableaux (resume d'effet, rapport console) parlent de la meme statistique -
 * la bande p10-p90 reste la vue distributionnelle autour d'elle.
 *
 * Retourne un wrapper (pas directement le svg) : le curseur au survol d'une
 * courbe a besoin d'une infobulle HTML positionnee en absolu par rapport a
 * ce conteneur.
 */
function renderCurveChart({ baselineCurve, modifiedCurve, showBand }) {
    const wrapper = document.createElement('div');
    wrapper.className = 'chart-wrapper';

    const baselineAges = Object.keys(baselineCurve || {}).map(Number);
    const modifiedAges = Object.keys(modifiedCurve || {}).map(Number);
    const ages = [...new Set([...baselineAges, ...modifiedAges])].sort((a, b) => a - b);
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

    const meansOf = (curve, sortedAges) => {
        const means = {};
        sortedAges.forEach((age) => { means[age] = curve[age].mean; });
        return means;
    };

    if (baselineCurve) {
        const sorted = baselineAges.sort((a, b) => a - b);
        if (showBand) {
            const bandPoints = sorted.map((age) => `${xOf(age)},${yOf(baselineCurve[age].p90)}`)
                .concat([...sorted].reverse().map((age) => `${xOf(age)},${yOf(baselineCurve[age].p10)}`));
            appendPolygon(svg, bandPoints, 'band');
        }
        appendPolyline(svg, sorted.map((age) => `${xOf(age)},${yOf(baselineCurve[age].mean)}`), 'line baseline');
        series.push({ label: modifiedCurve ? 'baseline' : 'moyenne', className: 'baseline', values: meansOf(baselineCurve, sorted) });
    }

    if (modifiedCurve) {
        const sorted = modifiedAges.sort((a, b) => a - b);
        appendPolyline(svg, sorted.map((age) => `${xOf(age)},${yOf(modifiedCurve[age].mean)}`), 'line modified');
        series.push({ label: 'modifie', className: 'modified', values: meansOf(modifiedCurve, sorted) });
    }

    attachCurveTooltip(svg, wrapper, ages, xOf, series);

    return wrapper;
}

/**
 * Effectif actif par annee simulee - meme type de courbe que
 * renderCurveChart (memes primitives SVG), mais un axe X en annees plutot
 * qu'en age et une seule valeur par point (pas de bande p10-p90 : c'est un
 * comptage, pas une distribution).
 */
function renderLineChart(baselineByYear, modifiedByYear) {
    const wrapper = document.createElement('div');
    wrapper.className = 'chart-wrapper';

    const baselineYears = Object.keys(baselineByYear).map(Number).sort((a, b) => a - b);
    const modifiedYears = modifiedByYear ? Object.keys(modifiedByYear).map(Number).sort((a, b) => a - b) : [];
    const years = [...new Set([...baselineYears, ...modifiedYears])].sort((a, b) => a - b);
    const svg = createSvg();
    wrapper.appendChild(svg);

    if (years.length === 0) {
        return wrapper;
    }

    const minYear = years[0];
    const maxYear = years[years.length - 1];
    const counts = [
        ...baselineYears.map((year) => baselineByYear[year]),
        ...modifiedYears.map((year) => modifiedByYear[year]),
    ];
    const maxCount = Math.max(1, ...counts);

    const x0 = PADDING.left;
    const x1 = CHART_WIDTH - PADDING.right;
    const y0 = CHART_HEIGHT - PADDING.bottom;
    const y1 = PADDING.top;

    drawAxes(svg, minYear, maxYear, 0, maxCount);

    const xOf = (year) => scale(year, minYear, maxYear, x0, x1);
    const yOf = (count) => scale(count, 0, maxCount, y0, y1);

    const series = [];

    appendPolyline(svg, baselineYears.map((year) => `${xOf(year)},${yOf(baselineByYear[year])}`), 'line baseline');
    series.push({ label: 'baseline', className: 'baseline', values: baselineByYear });

    if (modifiedByYear) {
        appendPolyline(svg, modifiedYears.map((year) => `${xOf(year)},${yOf(modifiedByYear[year])}`), 'line modified');
        series.push({ label: 'modifié', className: 'modified', values: modifiedByYear });
    }

    attachCurveTooltip(svg, wrapper, years, xOf, series, (year) => `Année ${year}`);

    return wrapper;
}

/**
 * Infobulle au survol : trouve le point (age ou annee, selon le graphique)
 * le plus proche du curseur (les donnees sont bucketees par entier, pas de
 * vraie interpolation necessaire), affiche une ligne de reperage verticale
 * + les valeurs de chaque serie visible a ce point. `formatPoint` habille
 * le libelle du point (`"20 ans"` pour une courbe par age, `"Annee 20"`
 * pour l'effectif par annee) sans dupliquer le reste de la logique.
 */
function attachCurveTooltip(svg, wrapper, xValues, xOf, series, formatPoint = (age) => `${age} ans`) {
    const points = xValues.map((age) => ({ age, x: xOf(age) }));

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

        tooltip.innerHTML = `<strong>${formatPoint(nearest.age)}</strong><br>${lines.join('<br>')}`;
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
