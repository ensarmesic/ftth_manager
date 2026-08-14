// ── INITIALIZATION ────────────────────────────────────────────────────────────

// Tile layers (state.js declared them as let)
imagery = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxNativeZoom: 18,
    maxZoom: 24,
    attribution: 'Tiles &copy; Esri'
}).addTo(map);

osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 22, maxNativeZoom: 19, attribution: '&copy; OpenStreetMap' });
cartodbDark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxNativeZoom: 19, maxZoom: 22, attribution: '&copy; CARTO' });
L.control.layers({ 'Satelit': imagery, 'OpenStreetMap': osm, 'CAD tamni': cartodbDark }, {}, { position: 'bottomleft' }).addTo(map);

L.control.scale({ imperial: false, position: 'bottomright' }).addTo(map);

(function addNorthArrow() {
    const NorthArrow = L.Control.extend({
        options: { position: 'bottomright' },
        onAdd() {
            const div = L.DomUtil.create('div', '');
            div.style.cssText = 'background:rgba(255,255,255,.82);border:1px solid rgba(15,23,42,.35);border-radius:2px;padding:3px 5px;font:800 9px/1 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;color:#0f172a;text-align:center;cursor:default;user-select:none;';
            div.innerHTML = '<svg width="14" height="20" viewBox="0 0 14 20" style="display:block;margin:0 auto 1px"><polygon points="7,0 11,10 7,8 3,10" fill="#0f172a"/><polygon points="7,20 11,10 7,12 3,10" fill="#94a3b8"/></svg>N';
            L.DomEvent.disableClickPropagation(div);
            return div;
        },
    });
    new NorthArrow().addTo(map);
}());

const mapLegend = L.control({ position: 'bottomright' });
mapLegend.onAdd = () => {
    const box = L.DomUtil.create('div', 'cad-map-legend');
    box.innerHTML = `
        <b>CAD LEGENDA</b>
        <div style="color:${routeColor('trench')}"><span class="cad-line-sample dashed"></span><span>Glavni rov</span></div>
        <div style="color:${routeColor('distribution')}"><span class="cad-line-sample"></span><span>Sekundarni krak</span></div>
        <div style="color:${routeColor('drop')}"><span class="cad-line-sample dashed"></span><span>Drop trasa</span></div>
        <div><span class="cad-point-sample" style="background:#0f5fa8"></span><span>ODF</span></div>
        <div><span class="cad-point-sample" style="background:#16a34a"></span><span>FTTH</span></div>
        <div><span class="cad-point-sample circle" style="background:#16a34a"></span><span>Kuća</span></div>
        <b style="margin-top:4px">VLAKNA</b>
        <div style="color:#f59e0b"><span class="cad-line-sample"></span><span>≤4F</span></div>
        <div style="color:#16a34a"><span class="cad-line-sample"></span><span>12F</span></div>
        <div style="color:#2563eb"><span class="cad-line-sample"></span><span>24F</span></div>
        <div style="color:#ea580c"><span class="cad-line-sample"></span><span>48F</span></div>
        <div style="color:#dc2626"><span class="cad-line-sample"></span><span>96F+</span></div>
    `;
    return box;
};
mapLegend.addTo(map);
