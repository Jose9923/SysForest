import test from 'node:test';
import assert from 'node:assert/strict';
import {reportPageUrl, updateReportLinks, PUBLIC_REPORT_FILTER_KEYS, REPORT_FILTER_KEYS} from '../../public/js/reports/links.js';

const origin = 'https://forest.test';

test('reporte público conserva códigos, No y condiciones sin copiar claves privadas ni límites del mapa', () => {
    const url = new URL(reportPageUrl('/portal/reports', {organization_id: 'AMVA', inventory_id: 'AMVA~2026',
        is_protected: false, physical_condition_ids: ['PHY_ROOT_GOOD', 'PHY_STEM_CRACK'], condition_mode: 'all',
        scope: 'national', tree_id: 'uuid', trashed: 'only', maintenance_priority: 'high', has_pending_maintenance: true,
        page: 3, render_mode: 'heat', term: 'consulta', limit: 2000, _token: 'secret'},
    {origin, isPublic: true, report: 'physical_conditions'}), origin);
    assert.equal(url.pathname, '/portal/reports');
    assert.equal(url.searchParams.get('report'), 'physical_conditions');
    assert.equal(url.searchParams.get('inventory_id'), 'AMVA~2026');
    assert.equal(url.searchParams.get('is_protected'), '0');
    assert.deepEqual(url.searchParams.getAll('physical_condition_ids[]'), ['PHY_ROOT_GOOD', 'PHY_STEM_CRACK']);
    assert.equal(url.searchParams.get('condition_mode'), 'all');
    for (const key of ['scope', 'tree_id', 'trashed', 'maintenance_priority', 'has_pending_maintenance', 'page', 'render_mode', 'term', 'limit', '_token']) assert.equal(url.searchParams.has(key), false);
});

test('enlace institucional conserva alcance explícito y no convierte la selección en consulta nacional', () => {
    const url = new URL(reportPageUrl('/reports?scope=national', {scope: 'organization', species_id: 'uuid-species',
        maintenance_status: 'pending', maintenance_priority: 'high', sort: 'scientific_name', direction: 'desc'}, {origin}), origin);
    assert.equal(url.searchParams.get('scope'), 'organization');
    assert.equal(url.searchParams.get('maintenance_priority'), 'high');
    assert.equal(url.searchParams.get('sort'), 'scientific_name');
    assert.equal(url.searchParams.get('direction'), 'desc');
    assert.equal(url.searchParams.get('report'), 'inventory');
});

test('rangos y radio explícitos conservan precisión textual en reportes sin cálculos forestales JS', () => {
    const url = new URL(reportPageUrl('/portal/reports', {dap_m_min: '0.490197224723', total_height_m_max: '12.000000000000',
        diameter_class_number: '9223372036854775807', radius_latitude: '4.154', radius_longitude: '-73.633', radius_meters: '200'},
    {origin, isPublic: true, filterKeys: PUBLIC_REPORT_FILTER_KEYS}), origin);
    assert.equal(url.searchParams.get('dap_m_min'), '0.490197224723');
    assert.equal(url.searchParams.get('total_height_m_max'), '12.000000000000');
    assert.equal(url.searchParams.get('diameter_class_number'), '9223372036854775807');
    assert.equal(url.searchParams.get('radius_longitude'), '-73.633');
    assert.equal(url.searchParams.get('radius_meters'), '200');
});

test('el enlace respeta la lista de claves del servidor y rechaza destinos externos o de otra interfaz', () => {
    const url = new URL(reportPageUrl('/portal/reports', {q: 'Oití', species_id: 'SPECIES'}, {origin, isPublic: true, filterKeys: ['q', 'scope']}), origin);
    assert.equal(url.searchParams.get('q'), 'Oití');
    assert.equal(url.searchParams.has('species_id'), false);
    for (const base of ['/reports', '/portal/reports/download/pdf', 'https://external.test/portal/reports', 'javascript:alert(1)']) {
        assert.throws(() => reportPageUrl(base, {}, {origin, isPublic: true}));
    }
    assert.throws(() => reportPageUrl('/portal/reports', {}, {origin, isPublic: false}));
});

test('limpiar selección conserva únicamente el alcance indicado y descarta parámetros anteriores', () => {
    const privateUrl = reportPageUrl('/reports?species_id=old&page=4&format=xlsx#old', {scope: 'department'},
        {origin, filterKeys: REPORT_FILTER_KEYS, report: 'species'});
    assert.equal(privateUrl, '/reports?scope=department&report=species');
    assert.equal(reportPageUrl('/portal/reports?scope=national', {}, {origin, isPublic: true}), '/portal/reports?report=inventory');
});

test('actualización del mapa enlaza los filtros aplicados y no usa la colección limitada de puntos', () => {
    const link = {dataset: {reportLink: '/portal/reports'}, href: '', removeAttribute() {}, setAttribute() {}};
    const root = {querySelectorAll: () => [link]};
    updateReportLinks(root, {species_id: 'SP_OITI', is_heritage: '0'}, {origin, isPublic: true, filterKeys: PUBLIC_REPORT_FILTER_KEYS});
    assert.match(link.href, /species_id=SP_OITI/);
    assert.match(link.href, /is_heritage=0/);
    updateReportLinks(root, {}, {origin, isPublic: true, filterKeys: PUBLIC_REPORT_FILTER_KEYS});
    assert.equal(link.href, '/portal/reports?report=inventory');
});
