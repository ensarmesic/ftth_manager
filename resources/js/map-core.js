export function escapeHtml(value) {
    return String(value ?? '').replace(
        /[&<>'"]/g,
        (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[character],
    );
}

export function cabinetOccupancyColor(usedPorts, capacity) {
    const percent = (Number(usedPorts) || 0) / Math.max(Number(capacity) || 1, 1);
    if (percent >= 1) return '#dc2626';
    if (percent >= 0.8) return '#ea580c';
    if (percent >= 0.6) return '#f59e0b';
    return '#16a34a';
}

export function fiberCountColor(fibers) {
    const count = Number(fibers) || 0;
    if (count <= 4) return '#f59e0b';
    if (count <= 12) return '#16a34a';
    if (count <= 24) return '#2563eb';
    if (count <= 48) return '#ea580c';
    return '#dc2626';
}

export function routeLabelSpecs(route) {
    const parts = [],
        name = String(route.name || '').toLowerCase(),
        fibers = route.fiber_count || route.fibers;
    if (fibers && !name.includes(`${fibers}f`)) parts.push(`${fibers}F`);
    const microduct = route.microduct_type || route.microduct;
    if (microduct && !name.includes(String(microduct).toLowerCase())) parts.push(microduct);
    return parts.length ? parts.join('·') : null;
}
