import {MapFilters} from '../map/filters.js';
import {cleanFilters, filtersFromEntries, filterCount, sameOriginUrl} from '../map/state.js';
import {REPORT_FILTER_KEYS} from '../reports/links.js';

const palette = ['#2e795b', '#8caf70', '#d2a657', '#558c9a', '#ac786a', '#8189ae', '#bbc79a', '#456854', '#cb9971', '#8dbab1', '#b3b7bc'];
const format = new Intl.NumberFormat('es-CO');

/** Protege hojas de cálculo ante etiquetas que comiencen como una fórmula. */
export function csvCell(value) {
    let text = String(value ?? '');
    if (/^[\s\uFEFF]*[=+@-]/u.test(text)) text = `'${text}`;
    return `"${text.replaceAll('"', '""')}"`;
}

export function chartCsv(chart, context) {
    const lines = [
        ['SYSFOREST', chart.title], ...(chart.context ? [['Contexto', chart.context]] : []),
        ['Alcance', context.scopeLabel], ['Generado', context.generatedAt],
        ...context.appliedFilters.map(item => [item.label, item.value]),
        ['Unidad', chart.unit], ...chart.notices.map(notice => ['Nota', notice]), [],
        chart.columns.map(column => column.label),
        ...chart.rows.map(row => chart.columns.map(column => row[column.key] ?? '')),
    ];
    return '\uFEFF' + lines.map(row => row.map(csvCell).join(';')).join('\r\n');
}

function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function wrapText(context, value, width) {
    const lines = [];
    let current = '';
    const words = String(value).split(/\s+/).flatMap(word => {
        const chunks = [];
        let chunk = '';
        for (const character of word) {
            if (chunk && context.measureText(chunk + character).width > width) {
                chunks.push(chunk);
                chunk = '';
            }
            chunk += character;
        }
        if (chunk) chunks.push(chunk);
        return chunks;
    });
    for (const word of words) {
        const candidate = current ? `${current} ${word}` : word;
        if (current && context.measureText(candidate).width > width) {
            lines.push(current);
            current = word;
        } else current = candidate;
    }
    if (current) lines.push(current);
    return lines;
}

/** Un dato ausente no equivale a cero en la imagen ni en las ayudas del gráfico. */
export function chartValueLabel(value, unit) {
    if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return 'Sin estimación';
    return format.format(Number(value)) + ' ' + unit;
}

function exportPng(instance, data, context) {
    const source = instance.canvas;
    const output = document.createElement('canvas');
    const width = Math.max(900, source.width);
    output.width = width;
    let painter = output.getContext('2d');
    painter.font = 'bold 26px Arial';
    const title = wrapText(painter, data.title, width - 72);
    painter.font = '18px Arial';
    const filterText = context.appliedFilters.map(item => `${item.label}: ${item.value}`).join(' · ') || 'Sin filtros adicionales';
    const notes = [
        ...(data.context ? wrapText(painter, data.context, width - 72) : []),
        ...wrapText(painter, `${context.scopeLabel} · ${filterText}`, width - 72),
    ];
    const values = data.labels.flatMap((label, index) =>
        wrapText(painter, `${label}: ${chartValueLabel(data.values[index], data.unit)}`, width - 94)
            .map((text, line) => ({text, color: data.type === 'doughnut' && line === 0
                ? data.colors?.[index] || palette[index % palette.length] : null})));
    const foot = ['Valores representados', ...values, '', ...wrapText(painter,
        [...data.notices, `Consulta: ${context.generatedAt}. Fuente: SysForest.`].join(' '), width - 72)];
    const graphHeight = Math.ceil(source.height * ((width - 72) / source.width));
    const titleHeight = title.length * 32;
    const top = 64 + titleHeight + notes.length * 24;
    output.height = top + graphHeight + 48 + foot.length * 24;
    painter = output.getContext('2d');
    painter.fillStyle = '#ffffff';
    painter.fillRect(0, 0, output.width, output.height);
    painter.textAlign = 'center';
    painter.fillStyle = '#204c39';
    painter.font = 'bold 26px Arial';
    title.forEach((line, index) => painter.fillText(line, width / 2, 40 + index * 32));
    painter.fillStyle = '#65766b';
    painter.font = '18px Arial';
    notes.forEach((line, index) => painter.fillText(line, width / 2, 52 + titleHeight + index * 24));
    painter.drawImage(source, 36, top, width - 72, graphHeight);
    painter.textAlign = 'left';
    foot.forEach((line, index) => {
        const y = top + graphHeight + 24 + index * 24;
        if (line.color) {
            painter.fillStyle = line.color;
            painter.fillRect(36, y - 12, 12, 12);
        }
        painter.fillStyle = '#65766b';
        painter.fillText(typeof line === 'string' ? line : line.text, typeof line === 'string' ? 36 : 58, y);
    });
    output.toBlob(blob => { if (blob) downloadBlob(blob, `sysforest-${data.key}.png`); }, 'image/png');
}

/** Conserva las etiquetas completas y deja espacio para sus líneas en pantallas estrechas. */
function barLabelLines(chart, label) {
    const context = chart.ctx;
    context.save();
    context.font = '12px Arial';
    const lines = wrapText(context, label, Math.max(65, Math.min(190, chart.width * 0.36)));
    context.restore();
    return lines;
}

function fitBars(chart, data) {
    if (data.type !== 'bar') return;
    const linesPerRow = data.labels.reduce((maximum, label) => Math.max(maximum, barLabelLines(chart, label).length + 1), 2);
    const height = Math.max(300, data.labels.length * linesPerRow * 15 + 48);
    const wrapper = chart.canvas.parentElement;
    if (wrapper.style.height !== `${height}px`) wrapper.style.height = `${height}px`;
}

function addLegend(card, data) {
    if (data.type !== 'doughnut' || card.querySelector('[data-chart-legend]')) return;
    const list = document.createElement('ul');
    list.className = 'analytics-chart-legend';
    list.dataset.chartLegend = '';
    list.setAttribute('aria-label', 'Valores de la gráfica');
    data.labels.forEach((label, index) => {
        const row = document.createElement('li');
        const dot = document.createElement('i');
        dot.setAttribute('aria-hidden', 'true');
        dot.style.backgroundColor = data.colors?.[index] || palette[index % palette.length];
        const text = document.createElement('span');
        text.textContent = label;
        const value = document.createElement('strong');
        value.textContent = chartValueLabel(data.values[index], data.unit);
        row.append(dot, text, value);
        list.append(row);
    });
    card.querySelector('[data-chart-visual]').after(list);
}

function makeChart(canvas, data) {
    const doughnut = data.type === 'doughnut';
    const line = data.type === 'line';
    return new window.Chart(canvas, {
        type: data.type,
        data: {
            labels: data.labels,
            datasets: [{label: data.unit, data: data.values,
                backgroundColor: line ? '#3b896022' : (data.colors?.length ? data.colors : palette),
                borderColor: line ? '#2e795b' : '#fff', borderWidth: doughnut ? 3 : line ? 2 : 0,
                borderRadius: doughnut || line ? 0 : 4, maxBarThickness: 25,
                fill: line, tension: 0, pointRadius: line ? 3 : undefined,
            }],
        },
        plugins: [{id: 'readableBarLabels', beforeLayout: chart => fitBars(chart, data)}],
        options: {
            responsive: true, maintainAspectRatio: false,
            animation: false,
            indexAxis: doughnut || line ? 'x' : 'y',
            locale: 'es-CO',
            cutout: doughnut ? '70%' : undefined,
            plugins: {
                legend: {display: false},
                tooltip: {backgroundColor: '#214637', padding: 12,
                    callbacks: {label: item => chartValueLabel(item.raw, data.unit)}},
            },
            scales: doughnut ? {} : {
                x: {beginAtZero: !line, border: {display: false}, grid: {display: !line, color: '#edf1eb'},
                    ticks: line ? {maxRotation: 45, autoSkip: true, maxTicksLimit: 12, color: '#6b7b70', font: {size: 10}}
                        : {precision: 0, color: '#7b8a7e', font: {size: 10}}},
                y: {beginAtZero: line, border: {display: false}, grid: {display: line, color: '#edf1eb'},
                    ticks: {precision: 0, color: '#526958', font: {size: 12, family: 'Arial'}, autoSkip: false,
                        callback(value) {
                            if (line) return format.format(value);
                            return barLabelLines(this.chart, this.getLabelForValue(value));
                        }}},
            },
        },
    });
}

function initialize() {
    const root = document.querySelector('[data-analytics-page]');
    if (!root) return;
    const status = root.querySelector('[data-analytics-status]');
    const showError = message => { status.textContent = message; status.hidden = false; };
    try {
        const boot = JSON.parse(document.getElementById('analytics-bootstrap').textContent);
        const config = boot.config;
        const form = root.querySelector('[data-analytics-filters]');
        const filterKeys = (config.filter_keys ?? REPORT_FILTER_KEYS).filter(key => REPORT_FILTER_KEYS.includes(key));
        const normalize = input => cleanFilters({...input, scope: config.scope.mode}, filterKeys);
        const applied = normalize(boot.filters);
        const hasDomain = (filters, domain) => [`${domain}_condition_ids`, `${domain}_condition_category_ids`].some(key => filters[key]?.length > 0);
        const conditionTopic = filters => hasDomain(filters, 'physical') ? 'physical'
            : hasDomain(filters, 'phytosanitary') ? 'phytosanitary' : null;
        if (window.jQuery?.fn.select2) {
            const controls = new MapFilters({form, config, document, origin: location.origin, $: window.jQuery, filterKeys,
                getAppliedFilters: () => applied,
                onApply: () => {
                    const url = sameOriginUrl(form.action, location.origin);
                    const filters = normalize(filtersFromEntries(new FormData(form).entries(), filterKeys));
                    url.search = '';
                    for (const [key, value] of Object.entries(filters)) {
                        if (Array.isArray(value)) value.forEach(item => url.searchParams.append(`${key}[]`, item));
                        else url.searchParams.set(key, value);
                    }
                    const changedDomain = ['physical', 'phytosanitary'].find(domain =>
                        hasDomain(filters, domain) && ['ids', 'category_ids'].some(suffix =>
                            JSON.stringify(filters[`${domain}_condition_${suffix}`] ?? []) !== JSON.stringify(applied[`${domain}_condition_${suffix}`] ?? [])));
                    url.hash = changedDomain || location.hash || conditionTopic(filters) || 'composition';
                    location.assign(url.href);
                }, onError: showError, onSearch: () => {},
            });
            controls.initialize();
            controls.sync(applied);
            root.querySelector('[data-map-filter-count]').textContent = String(filterCount(applied, filterKeys));
            window.addEventListener('pagehide', () => controls.cancelLookups());
        }
        const instances = new Map();
        const data = new Map(boot.charts.map(chart => [chart.key, chart]));
        const cards = [...root.querySelectorAll('[data-chart-card]')];
        const tabs = root.querySelector('[data-analytics-tabs]');
        const sections = [...root.querySelectorAll('[data-chart-section]')];
        const categories = [...sections.map(section => section.dataset.chartSection), 'all'];
        const selectedCategory = () => {
            const hash = location.hash.slice(1);
            // Los enlaces anteriores a la reorganización siguen llevando a condiciones.
            const requested = hash === 'structure' ? (conditionTopic(applied) || 'physical') : hash;
            return categories.includes(requested) ? requested : conditionTopic(applied) || categories[0];
        };
        const render = category => {
            sections.forEach(section => { section.hidden = category !== 'all' && section.dataset.chartSection !== category; });
            let count = 0;
            cards.forEach(card => {
                const visible = category === 'all' || card.dataset.chartCategory === category;
                card.hidden = !visible;
                if (!visible) return;
                count += 1;
                const canvas = card.querySelector('[data-chart]');
                if (!canvas || !window.Chart) return;
                card.querySelector('[data-chart-visual]').hidden = false;
                const chart = data.get(canvas.dataset.chart);
                addLegend(card, chart);
                if (!instances.has(canvas.dataset.chart)) instances.set(canvas.dataset.chart, makeChart(canvas, chart));
                else instances.get(canvas.dataset.chart).resize();
                card.querySelector('[data-chart-png]')?.removeAttribute('hidden');
            });
            root.querySelector('[data-category-empty]').hidden = count > 0;
            tabs.querySelectorAll('button').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.category === category)));
        };
        tabs.hidden = false;
        tabs.querySelectorAll('button').forEach(button => button.addEventListener('click', () => {
            render(button.dataset.category);
            const url = new URL(location.href);
            url.hash = button.dataset.category;
            history.replaceState(null, '', url);
        }));
        render(selectedCategory());
        window.addEventListener('hashchange', () => render(selectedCategory()));
        if (!window.Chart) showError('Las gráficas no pudieron cargarse. Las tablas y las descargas de datos siguen disponibles.');
        cards.forEach(card => {
            const chart = data.get(card.dataset.chartCard);
            if (chart.rows.length > 100) {
                const table = card.querySelector('tbody');
                const navigation = document.createElement('div');
                navigation.className = 'analytics-table-pages';
                const previous = document.createElement('button');
                const next = document.createElement('button');
                const caption = document.createElement('span');
                previous.type = next.type = 'button';
                previous.className = next.className = 'analytics-action';
                previous.textContent = '← Anterior';
                next.textContent = 'Siguiente →';
                caption.setAttribute('role', 'status');
                let page = 0;
                const showPage = () => {
                    const start = page * 100;
                    const visible = chart.rows.slice(start, start + 100);
                    table.replaceChildren(...visible.map(row => {
                        const tr = document.createElement('tr');
                        chart.columns.forEach(column => {
                            const td = document.createElement('td');
                            td.textContent = column.type === 'integer' ? format.format(Number(row[column.key] ?? 0)) : String(row[column.key] ?? '—');
                            tr.append(td);
                        });
                        return tr;
                    }));
                    previous.disabled = page === 0;
                    next.disabled = start + visible.length >= chart.rows.length;
                    caption.textContent = `${start + 1}–${start + visible.length} de ${format.format(chart.rows.length)}`;
                };
                previous.addEventListener('click', () => { page -= 1; showPage(); });
                next.addEventListener('click', () => { page += 1; showPage(); });
                navigation.append(previous, caption, next);
                card.querySelector('.analytics-data').append(navigation);
                showPage();
            }
            const csv = card.querySelector('[data-chart-csv]');
            if (csv) {
                csv.hidden = false;
                csv.addEventListener('click', () => downloadBlob(new Blob([chartCsv(chart, boot)], {type: 'text/csv;charset=utf-8'}), `sysforest-${chart.key}.csv`));
            }
            card.querySelector('[data-chart-png]')?.addEventListener('click', () => {
                const instance = instances.get(chart.key);
                if (instance) exportPng(instance, chart, boot);
            });
        });
        window.addEventListener('pagehide', () => instances.forEach(instance => instance.destroy()));
        window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
    } catch {
        showError('No se pudo iniciar la vista gráfica. Consulta las tablas o vuelve a cargar la página.');
    }
}

if (document.readyState === 'complete') initialize();
else document.addEventListener('DOMContentLoaded', initialize, {once: true});
