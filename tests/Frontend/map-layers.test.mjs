import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import {MapLayers} from '../../public/js/map/layers.js';

const vendor = {performance};
runInNewContext(readFileSync(new URL('../../public/vendor/supercluster/supercluster.min.js', import.meta.url), 'utf8'), vendor);
const Supercluster = vendor.Supercluster;
const point = (id, longitude, latitude) => ({type: 'Feature', geometry: {type: 'Point', coordinates: [longitude, latitude]}, properties: {kind: 'tree', id, code: id, status: 'standing', status_label: 'En pie'}});

test('distribución Supercluster vendorizada agrupa coordenadas reales y conserva identidades', () => {
    const index = new Supercluster({radius: 60, maxZoom: 19}).load([point('one', -74, 4), point('two', -74.00001, 4.00001)]);
    const clusters = index.getClusters([-75, 3, -73, 5], 8);
    assert.equal(clusters.length, 1);
    assert.equal(clusters[0].properties.point_count, 2);
    assert.deepEqual(Array.from(index.getLeaves(clusters[0].properties.cluster_id, 10), feature => feature.properties.id).sort(), ['one', 'two']);
    assert.ok(index.getClusterExpansionZoom(clusters[0].properties.cluster_id) > 8);
});

function runtime() {
    const events = [];
    const map = {getZoom: () => 8, getMaxZoom: () => 20, removeLayer: layer => events.push(`remove:${layer.kind}`)};
    const node = () => ({style: {}, setAttribute() {}, append() {}, addEventListener() {}});
    const L = {
        layerGroup: () => ({kind: 'markers', addTo() { events.push('add:markers'); return this; }}),
        divIcon: data => data,
        marker: (coordinates, options) => ({coordinates, options, addTo() { events.push('marker'); return this; }, bindPopup() { return this; }, on() { return this; }}),
    };
    const layers = new MapLayers({map, L, Supercluster, heatGradient: {'0.2': '#123456', '1': '#abcdef'}, legend: [], document: {createElement: node}, csrf: '', origin: 'https://forest.test', heatLayer: (points, options) => ({kind: 'heat', points, options, addTo() { events.push('add:heat'); return this; }})});
    return {events, layers};
}

test('puntos, clusters y calor se sustituyen sin conservar simultáneamente las capas pesadas', () => {
    const {layers, events} = runtime();
    const collection = {features: [point('one', -74, 4), point('two', -74.00001, 4.00001)]};
    layers.render('points', collection);
    assert.equal(events.filter(event => event === 'marker').length, 2);
    layers.render('clusters', collection);
    assert.equal(events.filter(event => event === 'marker').length, 3);
    layers.render('heat', {features: [{...point('cell', -74, 4), properties: {weight: 125}}]});
    assert.deepEqual(layers.slot.layer.points, [[4, -74, 125]]);
    assert.equal(layers.slot.layer.options.max, 125);
    assert.deepEqual(layers.slot.layer.options.gradient, {'0.2': '#123456', '1': '#abcdef'});
    assert.deepEqual(events, ['add:markers', 'marker', 'marker', 'remove:markers', 'add:markers', 'marker', 'remove:markers', 'add:heat']);
    assert.equal(layers.index, null);
});

test('clusters agregados en servidor no se vuelven a agregar ni se convierten en árboles', () => {
    const {layers, events} = runtime();
    layers.render('clusters', {features: [{...point('aggregate', -74, 4), properties: {kind: 'cluster', count: 900, bounds: [[3, -75], [5, -73]]}}]});
    assert.equal(layers.index, null);
    assert.equal(events.filter(event => event === 'marker').length, 1);
});

test('geometrías ausentes no generan marcadores ni puntos de calor de relleno', () => {
    const {layers, events} = runtime();
    layers.render('points', {features: [{geometry: null, properties: {kind: 'tree'}}]});
    assert.equal(events.includes('marker'), false);
    layers.render('heat', {features: [{geometry: null, properties: {weight: 2}}]});
    assert.deepEqual(layers.slot.layer.points, []);
});
