import test from 'node:test';
import assert from 'node:assert/strict';
import { cabinetOccupancyColor, escapeHtml, fiberCountColor, routeLabelSpecs } from '../../resources/js/map-core.js';

test('escapeHtml neutralizes markup', () => assert.equal(escapeHtml('<b>"x"</b>'), '&lt;b&gt;&quot;x&quot;&lt;/b&gt;'));
test('occupancy colors represent thresholds', () => {
    assert.equal(cabinetOccupancyColor(5, 10), '#16a34a');
    assert.equal(cabinetOccupancyColor(8, 10), '#ea580c');
    assert.equal(cabinetOccupancyColor(10, 10), '#dc2626');
});
test('fiber colors represent capacity classes', () => {
    assert.equal(fiberCountColor(4), '#f59e0b');
    assert.equal(fiberCountColor(24), '#2563eb');
    assert.equal(fiberCountColor(96), '#dc2626');
});
test('route label omits details already present in name', () => {
    assert.equal(routeLabelSpecs({ name: 'Trasa 24F', fiber_count: 24, microduct_type: '14/10' }), '14/10');
});
