/**
 * FTTH DXF/DWG Layer Manager
 * Koordinatna transformacija: MGI Gauss-Krüger (zone 6/7) → WGS84
 * Zahtijeva proj4.js
 */
(function () {
    'use strict';

    const WGS84  = 'WGS84';
    const MGI_Z6 = '+proj=tmerc +lat_0=0 +lon_0=18 +k=0.9999 +x_0=6500000 +y_0=0 +ellps=bessel +towgs84=682,-203,480,0,0,0,0 +units=m +no_defs';
    const MGI_Z7 = '+proj=tmerc +lat_0=0 +lon_0=21 +k=0.9999 +x_0=7500000 +y_0=0 +ellps=bessel +towgs84=682,-203,480,0,0,0,0 +units=m +no_defs';
    const MAX_SEGMENT_M = 80000;

    let map          = null;
    let layers       = {};
    let layerCounter = 0;

    /* ─── Koordinatna detekcija i transformacija ─────────────────── */

    function detectProj(features) {
        const votes = { z6: 0, z7: 0, wgs: 0 };
        let checked = 0;
        for (const f of features) {
            const c = firstCoord(f.geometry);
            if (!c) continue;
            const x = c[0];
            if (x > 6000000 && x < 7000000)      votes.z6++;
            else if (x > 7000000 && x < 8000000)  votes.z7++;
            else if (x >= -180 && x <= 180)        votes.wgs++;
            if (++checked >= 20) break;
        }
        if (votes.z7 > votes.z6 && votes.z7 > votes.wgs) return MGI_Z7;
        if (votes.wgs > votes.z6 && votes.wgs > votes.z7) return null;
        return MGI_Z6;
    }

    function firstCoord(g) {
        if (!g) return null;
        if (g.type === 'LineString') return g.coordinates[0];
        if (g.type === 'Polygon')    return g.coordinates[0]?.[0];
        if (g.type === 'Point')      return g.coordinates;
        return null;
    }

    function toLatLng(x, y, srcProj) {
        try {
            if (!srcProj || !window.proj4) {
                if (!isFinite(x) || !isFinite(y)) return null;
                return L.latLng(y, x);
            }
            const [lng, lat] = proj4(srcProj, WGS84, [x, y]);
            if (!isFinite(lat) || !isFinite(lng)) return null;
            if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
            return L.latLng(lat, lng);
        } catch { return null; }
    }

    function coordsToLatLngs(coords, srcProj) {
        return coords.map(c => toLatLng(c[0], c[1], srcProj)).filter(Boolean);
    }

    function hasLongSegment(ll) {
        for (let i = 1; i < ll.length; i++) {
            try { if (map.distance(ll[i - 1], ll[i]) > MAX_SEGMENT_M) return true; } catch { return true; }
        }
        return false;
    }

    // Rasjeca polyline na dugim segmentima i vraca samo validne dijelove
    function splitOnLongSegments(ll) {
        const result = [];
        let current = [ll[0]];
        for (let i = 1; i < ll.length; i++) {
            let long = false;
            try { long = map.distance(ll[i - 1], ll[i]) > MAX_SEGMENT_M; } catch { long = true; }
            if (long) {
                if (current.length >= 2) result.push(current);
                current = [ll[i]];
            } else {
                current.push(ll[i]);
            }
        }
        if (current.length >= 2) result.push(current);
        return result;
    }

    /* ─── Canvas text layer ──────────────────────────────────────── */
    // Crta sve tekst labele direktno na canvas (jedan DOM element za sve labele)

    const DxfTextLayer = L.Layer.extend({
        initialize(pts, color) {
            this._pts   = pts;
            this._color = color || '#d946ef';
            this._raf   = null;
        },

        setColor(color) {
            this._color = color;
            this._draw();
        },

        onAdd(map) {
            this._map = map;
            const c = this._canvas = document.createElement('canvas');
            c.style.cssText = 'position:absolute;top:0;left:0;pointer-events:none;z-index:450';
            map.getContainer().appendChild(c);
            // zoomstart → odmah obriši da stari tekst ne "visi" tokom animacije
            map.on('zoomstart', this._clear, this);
            map.on('zoomend viewreset', this._draw, this);
            map.on('move', this._schedule, this);
            map.on('resize', this._onResize, this);
            this._onResize();
        },

        onRemove(map) {
            if (this._canvas) { this._canvas.remove(); this._canvas = null; }
            if (this._raf)    { cancelAnimationFrame(this._raf); this._raf = null; }
            map.off('zoomstart', this._clear, this);
            map.off('zoomend viewreset', this._draw, this);
            map.off('move', this._schedule, this);
            map.off('resize', this._onResize, this);
        },

        _onResize() {
            const s = this._map.getSize();
            this._canvas.width  = s.x;
            this._canvas.height = s.y;
            this._draw();
        },

        _clear() {
            if (this._raf) { cancelAnimationFrame(this._raf); this._raf = null; }
            if (this._canvas) {
                const ctx = this._canvas.getContext('2d');
                ctx.clearRect(0, 0, this._canvas.width, this._canvas.height);
            }
        },

        _schedule() {
            if (this._raf) return;
            this._raf = requestAnimationFrame(() => { this._raf = null; this._draw(); });
        },

        _draw() {
            if (!this._canvas) return;
            const map    = this._map;
            const canvas = this._canvas;
            const ctx    = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            const zoom = map.getZoom();
            if (zoom < 17) return;

            const refLat = this._pts[0]?.ll?.lat ?? 44;
            const mpp = 156543.03392 * Math.cos(refLat * Math.PI / 180) / Math.pow(2, zoom);

            ctx.fillStyle    = this._color;
            ctx.shadowColor  = '#fff';
            ctx.shadowBlur   = 2;
            ctx.textBaseline = 'middle';

            const W = canvas.width, H = canvas.height;

            for (const { ll, text, height } of this._pts) {
                const p = map.latLngToContainerPoint(ll);
                // Preskoči labele koje su van viewport-a
                if (p.x < -50 || p.x > W + 50 || p.y < -20 || p.y > H + 20) continue;
                let fontSize;
                if (height && height > 0) {
                    fontSize = Math.max(7, Math.min(14, Math.round(height / mpp)));
                } else {
                    fontSize = Math.max(7, Math.round(7 + (zoom - 17) * 1.5));
                }
                ctx.font = `${fontSize}px Arial,sans-serif`;
                ctx.fillText(text, p.x, p.y);
            }
        },

        setVisible(v) {
            if (this._canvas) this._canvas.style.display = v ? '' : 'none';
            if (v) this._draw();
        },
    });

    /* ─── HTML escape ────────────────────────────────────────────── */

    function esc(s) {
        return String(s).replace(/[&<>"']/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
        );
    }

    /* ─── Build Leaflet layers iz GeoJSON ───────────────────────── */

    function buildLeafletLayer(geojson, color) {
        const srcProj  = detectProj(geojson.features);
        const items    = []; // L.polyline / L.polygon
        const textPts  = []; // {ll, text} za canvas
        let   rendered = 0;
        const skip = { longSegment: 0, tooFewPoints: 0, badCoord: 0, emptyText: 0 };

        geojson.features.forEach(f => {
            const g = f.geometry;
            if (!g) return;

            const style = { color, weight: 1.5, opacity: 0.85 };
            let lyr = null;

            if (g.type === 'LineString') {
                const ll = coordsToLatLngs(g.coordinates, srcProj);
                if (ll.length < 2) { skip.tooFewPoints++; return; }
                if (hasLongSegment(ll)) {
                    const segs = splitOnLongSegments(ll);
                    if (!segs.length) { skip.longSegment++; return; }
                    segs.forEach(seg => {
                        const l = L.polyline(seg, style).addTo(map);
                        items.push(l);
                        rendered++;
                    });
                    return;
                }
                lyr = L.polyline(ll, style);

            } else if (g.type === 'Polygon') {
                const rings = g.coordinates.map(r => coordsToLatLngs(r, srcProj));
                const outer = rings[0];
                if (!outer || outer.length < 3) { skip.tooFewPoints++; return; }
                if (hasLongSegment(outer)) {
                    const segs = splitOnLongSegments(outer);
                    if (!segs.length) { skip.longSegment++; return; }
                    segs.forEach(seg => {
                        if (seg.length >= 2) {
                            const l = L.polyline(seg, style).addTo(map);
                            items.push(l);
                            rendered++;
                        }
                    });
                    return;
                }
                lyr = L.polygon(rings, { ...style, fillOpacity: 0.06 });

            } else if (g.type === 'Point') {
                const ll     = toLatLng(g.coordinates[0], g.coordinates[1], srcProj);
                const text   = f.properties?.text || '';
                const height = f.properties?.height ?? null;
                if (!ll)   { skip.badCoord++;  return; }
                if (!text) { skip.emptyText++; return; }
                textPts.push({ ll, text, height });
                rendered++;
                return;
            } else {
                return;
            }

            if (lyr) {
                lyr.addTo(map);
                items.push(lyr);
                rendered++;
            }
        });

        // Jedan canvas layer za sve tekst labele
        let textLayer = null;
        if (textPts.length) {
            textLayer = new DxfTextLayer(textPts, color).addTo(map);
        }

        const totalSkipped = skip.longSegment + skip.tooFewPoints + skip.badCoord + skip.emptyText;
        if (totalSkipped) {
            console.info(
                `DXF preskočeno ${totalSkipped}:`,
                `dug segment=${skip.longSegment}`,
                `premalo tačaka=${skip.tooFewPoints}`,
                `loša koordinata=${skip.badCoord}`,
                `prazan tekst=${skip.emptyText}`
            );
        }
        console.info(`DXF: ${rendered} prikazano, ${textPts.length} tekst labela, ${items.length} geometrija`);

        return { items, textLayer, textPts, rendered, skip };
    }

    function addLayer(geojson, color) {
        const id = ++layerCounter;
        const { items, textLayer, textPts, rendered } = buildLeafletLayer(geojson, color);

        layers[id] = {
            id,
            name:      geojson.name || ('Layer ' + id),
            color,
            visible:   true,
            items,
            textLayer,
            textPts,
            total:     geojson.features.length,
            rendered,
        };

        render();
        return id;
    }

    function removeLayer(id) {
        const ly = layers[id];
        if (!ly) return;
        ly.items.forEach(l => { try { l.remove(); } catch {} });
        if (ly.textLayer) { try { ly.textLayer.remove(); } catch {} }
        delete layers[id];
        render();
    }

    function toggleLayer(id) {
        const ly = layers[id];
        if (!ly) return;
        ly.visible = !ly.visible;
        ly.items.forEach(l => {
            try { ly.visible ? l.addTo(map) : l.remove(); } catch {}
        });
        if (ly.textLayer) {
            try { ly.textLayer.setVisible(ly.visible); } catch {}
        }
        render();
    }

    function setColor(id, color) {
        const ly = layers[id];
        if (!ly) return;
        ly.color = color;
        ly.items.forEach(l => {
            try { if (typeof l.setStyle === 'function') l.setStyle({ color }); } catch {}
        });
        if (ly.textLayer) {
            try { ly.textLayer.setColor(color); } catch {}
        }
    }

    function zoomTo(id) {
        const ly = layers[id];
        if (!ly) return;
        try {
            let bounds = null;
            if (ly.items.length) {
                const b = L.featureGroup(ly.items).getBounds();
                if (b.isValid()) bounds = b;
            }
            for (const { ll } of (ly.textPts || [])) {
                bounds = bounds ? bounds.extend(ll) : L.latLngBounds([ll, ll]);
            }
            if (bounds && bounds.isValid()) map.fitBounds(bounds.pad(0.05));
        } catch {}
    }

    /* ─── UI ─────────────────────────────────────────────────────── */

    function render() {
        const el = document.getElementById('dxf-layer-list');
        if (!el) return;

        const ids = Object.keys(layers);
        if (!ids.length) {
            el.innerHTML = '<p style="padding:12px 8px;text-align:center;font-size:10px;color:#94a3b8;margin:0">Nema učitanih layera.</p>';
            return;
        }

        el.innerHTML = ids.map(id => {
            const ly  = layers[id];
            const dim = !ly.visible;
            const sub = ly.rendered < ly.total
                ? `${ly.rendered} / ${ly.total} entiteta`
                : `${ly.rendered} entiteta`;
            return `<div style="display:flex;align-items:center;gap:6px;padding:5px 6px;border-radius:6px" data-lid="${ly.id}" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                <input type="color" value="${ly.color}" class="dxf-color" style="width:18px;height:18px;padding:0;border:1px solid #e2e8f0;border-radius:4px;cursor:pointer;flex-shrink:0">
                <button class="dxf-toggle" style="flex:1;min-width:0;background:none;border:none;cursor:pointer;text-align:left;padding:0">
                    <div style="font-size:11px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:${dim ? '#94a3b8' : '#1e293b'};${dim ? 'text-decoration:line-through' : ''}">${esc(ly.name)}</div>
                    <div style="font-size:9px;color:#94a3b8">${sub}</div>
                </button>
                <button class="dxf-zoom" style="background:none;border:none;cursor:pointer;padding:2px;color:#94a3b8;flex-shrink:0" title="Zoom">🔍</button>
                <button class="dxf-del"  style="background:none;border:none;cursor:pointer;padding:2px;color:#94a3b8;flex-shrink:0" title="Ukloni">✕</button>
            </div>`;
        }).join('');

        el.querySelectorAll('[data-lid]').forEach(row => {
            const id = parseInt(row.dataset.lid);
            row.querySelector('.dxf-toggle')?.addEventListener('click', () => toggleLayer(id));
            row.querySelector('.dxf-del')?.addEventListener('click',    () => removeLayer(id));
            row.querySelector('.dxf-zoom')?.addEventListener('click',   () => zoomTo(id));
            row.querySelector('.dxf-color')?.addEventListener('input',  e  => setColor(id, e.target.value));
        });
    }

    function showErr(msg) {
        const el = document.getElementById('dxf-error');
        if (!el) return;
        el.textContent = msg;
        el.style.display = 'block';
        setTimeout(() => { el.style.display = 'none'; }, 8000);
    }

    function setBusy(busy) {
        const btn = document.getElementById('dxf-upload-btn');
        const sp  = document.getElementById('dxf-spinner');
        if (btn) btn.disabled = busy;
        if (sp)  sp.style.display = busy ? 'inline' : 'none';
    }

    /* ─── Upload ─────────────────────────────────────────────────── */

    const PALETTE = ['#d946ef', '#d946ef', '#d946ef', '#d946ef', '#d946ef', '#d946ef'];

    async function upload(file) {
        if (!/\.(dxf|dwg)$/i.test(file.name)) {
            showErr('Molimo odaberi DXF ili DWG fajl.');
            return;
        }
        setBusy(true);
        const errEl = document.getElementById('dxf-error');
        if (errEl) errEl.style.display = 'none';

        const fd   = new FormData();
        fd.append('file', file);
        const csrf = document.querySelector('meta[name="csrf-token"]');

        try {
            const res  = await fetch('/mapa/dxf-layer', {
                method: 'POST',
                body:   fd,
                headers: {
                    'X-CSRF-TOKEN':     csrf ? csrf.content : '',
                    'Accept':           'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            const text = await res.text();
            if (!text.trim()) {
                showErr('Server vratio prazan odgovor (HTTP ' + res.status + ').');
                return;
            }

            let data;
            try { data = JSON.parse(text); }
            catch {
                console.error('DXF: nije JSON:', text.slice(0, 300));
                showErr('Server nije vratio JSON (HTTP ' + res.status + ').');
                return;
            }

            if (!res.ok || data.error) {
                showErr(data.error || data.message || 'HTTP greška ' + res.status);
                return;
            }
            if (!data.features?.length) {
                showErr('Fajl ne sadrži podržane entitete (LINE / POLYLINE / TEXT / MTEXT).');
                return;
            }

            const color = PALETTE[layerCounter % PALETTE.length];
            const id    = addLayer(data, color);
            setTimeout(() => zoomTo(id), 200);

            const inp = document.getElementById('dxf-file-input');
            if (inp) inp.value = '';

        } catch (e) {
            showErr('Mrežna greška: ' + e.message);
        } finally {
            setBusy(false);
        }
    }

    /* ─── Init ───────────────────────────────────────────────────── */

    window.ftthDxfLayer = {
        init(leafletMap) {
            map = leafletMap;
            render();

            document.getElementById('dxf-file-input')?.addEventListener('change', e => {
                const f = e.target.files[0];
                if (f) upload(f);
            });
            document.getElementById('dxf-upload-btn')?.addEventListener('click', () => {
                document.getElementById('dxf-file-input')?.click();
            });

            const drop = document.getElementById('dxf-dropzone');
            if (drop) {
                drop.addEventListener('dragover', e => {
                    e.preventDefault();
                    drop.style.background  = '#fce7f3';
                    drop.style.borderColor = '#d946ef';
                });
                drop.addEventListener('dragleave', () => {
                    drop.style.background  = '#eef2ff';
                    drop.style.borderColor = '#a5b4fc';
                });
                drop.addEventListener('drop', e => {
                    e.preventDefault();
                    drop.style.background  = '#eef2ff';
                    drop.style.borderColor = '#a5b4fc';
                    const f = e.dataTransfer.files[0];
                    if (f) upload(f);
                });
            }
        },
    };
})();
