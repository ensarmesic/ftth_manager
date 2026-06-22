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

    /* ─── IndexedDB persistencija ───────────────────────────────── */
    // Čuva GeoJSON podatke trajno u browseru — preživi reload stranice.

    const DxfStore = (function () {
        let _db   = null;
        const DB  = 'ftth_dxf_v1';
        const VER = 1;
        const ST  = 'layers';

        function open() {
            if (_db) return Promise.resolve(_db);
            return new Promise((res, rej) => {
                const r = indexedDB.open(DB, VER);
                r.onupgradeneeded = e => {
                    const db = e.target.result;
                    if (!db.objectStoreNames.contains(ST)) {
                        db.createObjectStore(ST, { keyPath: 'dbId' });
                    }
                };
                r.onsuccess = e => { _db = e.target.result; res(_db); };
                r.onerror   = e => rej(e.target.error);
            });
        }

        return {
            async save(dbId, geojson, color, projectId) {
                const db = await open();
                return new Promise((res, rej) => {
                    const tx = db.transaction(ST, 'readwrite');
                    // cacheKey kao zasebno polje — lakše čitanje bez prolaska kroz cijeli geojson
                    const cacheKey = geojson._cache_key || null;
                    tx.objectStore(ST).put({ dbId, geojson, color, cacheKey, savedAt: Date.now(), projectId: projectId || null });
                    tx.oncomplete = res;
                    tx.onerror    = e => rej(e.target.error);
                });
            },
            async loadAll() {
                const db = await open();
                return new Promise((res, rej) => {
                    const tx = db.transaction(ST, 'readonly');
                    const rq = tx.objectStore(ST).getAll();
                    rq.onsuccess = e => res(e.target.result || []);
                    rq.onerror   = e => rej(e.target.error);
                });
            },
            async remove(dbId) {
                const db = await open();
                return new Promise((res, rej) => {
                    const tx = db.transaction(ST, 'readwrite');
                    tx.objectStore(ST).delete(dbId);
                    tx.oncomplete = res;
                    tx.onerror    = e => rej(e.target.error);
                });
            },
            async clear() {
                const db = await open();
                return new Promise((res, rej) => {
                    const tx = db.transaction(ST, 'readwrite');
                    tx.objectStore(ST).clear();
                    tx.oncomplete = res;
                    tx.onerror    = e => rej(e.target.error);
                });
            },
        };
    })();

    // Brzo izračunaj bounding box iz niza LatLng-ova
    function latLngsBbox(lls) {
        let minLat = lls[0].lat, maxLat = lls[0].lat;
        let minLng = lls[0].lng, maxLng = lls[0].lng;
        for (let i = 1; i < lls.length; i++) {
            const { lat, lng } = lls[i];
            if (lat < minLat) minLat = lat; else if (lat > maxLat) maxLat = lat;
            if (lng < minLng) minLng = lng; else if (lng > maxLng) maxLng = lng;
        }
        return { minLat, maxLat, minLng, maxLng };
    }

    /* ─── Canvas geometry layer ──────────────────────────────────── */
    // Sve linije i poligoni crtaju se na jednom <canvas> — nula Leaflet layer objekata.
    // Ovo je kljucna razlika od L.polyline pristupa koji kreira zasebni JS objekat po featuri.

    const DxfGeomLayer = L.Layer.extend({
        initialize(color) {
            this._lines     = []; // { lls: LatLng[], bbox: {minLat,maxLat,minLng,maxLng}, isFill }
            this._color     = color || '#d946ef';
            this._hasFill   = false;
            this._raf       = null;
            this._boundsArr = null;
        },

        setColor(color) {
            this._color = color;
            this._schedule();
        },

        setVisible(v) {
            if (this._canvas) this._canvas.style.display = v ? '' : 'none';
            if (v) this._schedule();
        },

        setHighlight(on) {
            this._highlighted = on;
            if (this._canvas) {
                this._canvas.style.outline = on ? '3px solid #3b82f6' : '';
                this._canvas.style.filter  = on ? 'brightness(1.4) saturate(1.6)' : '';
            }
        },

        // Vraca L.LatLngBounds za zoomTo
        getBounds() {
            if (!this._boundsArr) return null;
            const [s, w, n, e] = this._boundsArr;
            return L.latLngBounds([[s, w], [n, e]]);
        },

        // Dodaj batch linija i odmah nacrtaj progres
        addLines(batch) {
            for (const item of batch) {
                this._lines.push(item);
                if (item.isFill) this._hasFill = true;
                const b = item.bbox;
                if (!this._boundsArr) {
                    this._boundsArr = [b.minLat, b.minLng, b.maxLat, b.maxLng];
                } else {
                    if (b.minLat < this._boundsArr[0]) this._boundsArr[0] = b.minLat;
                    if (b.minLng < this._boundsArr[1]) this._boundsArr[1] = b.minLng;
                    if (b.maxLat > this._boundsArr[2]) this._boundsArr[2] = b.maxLat;
                    if (b.maxLng > this._boundsArr[3]) this._boundsArr[3] = b.maxLng;
                }
            }
            this._schedule();
        },

        onAdd(map) {
            this._map = map;
            const c = this._canvas = document.createElement('canvas');
            c.style.cssText = 'position:absolute;top:0;left:0;pointer-events:none;z-index:400';
            map.getContainer().appendChild(c);
            map.on('zoomanim',          this._onZoomAnim, this);
            map.on('zoomend viewreset', this._draw,       this);
            map.on('move',              this._schedule,   this);
            map.on('resize',            this._onResize,   this);
            this._onResize();
        },

        onRemove(map) {
            if (this._canvas) { this._canvas.remove(); this._canvas = null; }
            if (this._raf)    { cancelAnimationFrame(this._raf); this._raf = null; }
            map.off('zoomanim',          this._onZoomAnim, this);
            map.off('zoomend viewreset', this._draw,       this);
            map.off('move',              this._schedule,   this);
            map.off('resize',            this._onResize,   this);
        },

        _onResize() {
            const s = this._map.getSize();
            this._canvas.width  = s.x;
            this._canvas.height = s.y;
            this._draw();
        },

        _onZoomAnim(e) {
            if (!this._canvas || !this._map) return;
            const scale  = this._map.getZoomScale(e.zoom);
            const offset = this._map._getCenterOffset(e.center)
                               ._multiplyBy(-scale)
                               .subtract(this._map._getMapPanePos());
            L.DomUtil.setTransform(this._canvas, offset, scale);
        },

        _schedule() {
            if (this._raf) return;
            this._raf = requestAnimationFrame(() => { this._raf = null; this._draw(); });
        },

        _draw() {
            if (!this._canvas || !this._map || !this._lines.length) return;
            L.DomUtil.setTransform(this._canvas, new L.Point(0, 0), 1);

            const map    = this._map;
            const canvas = this._canvas;
            const ctx    = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            const vb = map.getBounds().pad(0.15);
            const vS = vb.getSouth(), vN = vb.getNorth();
            const vW = vb.getWest(),  vE = vb.getEast();
            const lngPerPx = (vE - vW) / canvas.width;
            const latPerPx = (vN - vS) / canvas.height;

            ctx.strokeStyle = this._color;
            ctx.lineWidth   = 1.5;
            ctx.globalAlpha = 0.85;
            ctx.lineJoin    = 'round';
            ctx.beginPath();

            for (const { lls, bbox, isFill } of this._lines) {
                if (isFill) continue;
                if (bbox.maxLat < vS || bbox.minLat > vN ||
                    bbox.maxLng < vW || bbox.minLng > vE) continue;
                if ((bbox.maxLng - bbox.minLng) / lngPerPx < 1.0 &&
                    (bbox.maxLat - bbox.minLat) / latPerPx < 1.0) continue;
                const p0 = map.latLngToContainerPoint(lls[0]);
                ctx.moveTo(p0.x, p0.y);
                for (let i = 1; i < lls.length; i++) {
                    const p = map.latLngToContainerPoint(lls[i]);
                    ctx.lineTo(p.x, p.y);
                }
            }
            ctx.stroke();

            if (this._hasFill) {
                ctx.fillStyle   = this._color;
                ctx.globalAlpha = 0.06;
                ctx.beginPath();
                for (const { lls, bbox, isFill } of this._lines) {
                    if (!isFill) continue;
                    if (bbox.maxLat < vS || bbox.minLat > vN ||
                        bbox.maxLng < vW || bbox.minLng > vE) continue;
                    if ((bbox.maxLng - bbox.minLng) / lngPerPx < 1.0 &&
                        (bbox.maxLat - bbox.minLat) / latPerPx < 1.0) continue;
                    const p0 = map.latLngToContainerPoint(lls[0]);
                    ctx.moveTo(p0.x, p0.y);
                    for (let i = 1; i < lls.length; i++) {
                        const p = map.latLngToContainerPoint(lls[i]);
                        ctx.lineTo(p.x, p.y);
                    }
                    ctx.closePath();
                }
                ctx.fill('evenodd');
            }
        },
    });

    /* ─── Canvas text layer ──────────────────────────────────────── */

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
            map.on('zoomanim',          this._onZoomAnim, this);
            map.on('zoomend viewreset', this._draw,       this);
            map.on('move',              this._schedule,   this);
            map.on('resize',            this._onResize,   this);
            this._onResize();
        },

        onRemove(map) {
            if (this._canvas) { this._canvas.remove(); this._canvas = null; }
            if (this._raf)    { cancelAnimationFrame(this._raf); this._raf = null; }
            map.off('zoomanim',          this._onZoomAnim, this);
            map.off('zoomend viewreset', this._draw,       this);
            map.off('move',              this._schedule,   this);
            map.off('resize',            this._onResize,   this);
        },

        _onZoomAnim(e) {
            if (!this._canvas || !this._map) return;
            const scale  = this._map.getZoomScale(e.zoom);
            const offset = this._map._getCenterOffset(e.center)
                               ._multiplyBy(-scale)
                               .subtract(this._map._getMapPanePos());
            L.DomUtil.setTransform(this._canvas, offset, scale);
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

        setVisible(v) {
            if (this._canvas) this._canvas.style.display = v ? '' : 'none';
            if (v) this._draw();
        },

        _draw() {
            if (!this._canvas) return;
            L.DomUtil.setTransform(this._canvas, new L.Point(0, 0), 1);
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
    });

    /* ─── HTML escape ────────────────────────────────────────────── */

    function esc(s) {
        return String(s).replace(/[&<>"']/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
        );
    }

    /* ─── Build canvas layer iz GeoJSON ──────────────────────────── */
    // Ne kreira L.polyline objekte — samo plain JS nizove LatLng-ova.
    // geomLayer.addLines() se poziva po paketu da se vide progresivno na mapi.

    async function buildCanvasLayer(geojson, color, geomLayer, onProgress) {
        const srcProj = detectProj(geojson.features);
        const textPts = [];
        let   rendered = 0;
        const skip = { longSegment: 0, tooFewPoints: 0, badCoord: 0, emptyText: 0 };

        const CHUNK    = 2000;
        const features = geojson.features;

        for (let chunkStart = 0; chunkStart < features.length; chunkStart += CHUNK) {
            const chunkEnd = Math.min(chunkStart + CHUNK, features.length);
            const batch = [];

            for (let fi = chunkStart; fi < chunkEnd; fi++) {
                const f = features[fi];
                const g = f.geometry;
                if (!g) continue;

                if (g.type === 'LineString') {
                    const lls = coordsToLatLngs(g.coordinates, srcProj);
                    if (lls.length < 2) { skip.tooFewPoints++; continue; }
                    if (hasLongSegment(lls)) {
                        const segs = splitOnLongSegments(lls);
                        if (!segs.length) { skip.longSegment++; continue; }
                        segs.forEach(seg => {
                            batch.push({ lls: seg, bbox: latLngsBbox(seg), isFill: false });
                            rendered++;
                        });
                        continue;
                    }
                    batch.push({ lls, bbox: latLngsBbox(lls), isFill: false });
                    rendered++;

                } else if (g.type === 'Polygon') {
                    const outer = coordsToLatLngs(g.coordinates[0], srcProj);
                    if (outer.length < 3) { skip.tooFewPoints++; continue; }
                    if (hasLongSegment(outer)) {
                        const segs = splitOnLongSegments(outer);
                        if (!segs.length) { skip.longSegment++; continue; }
                        segs.forEach(seg => {
                            if (seg.length >= 2) {
                                batch.push({ lls: seg, bbox: latLngsBbox(seg), isFill: false });
                                rendered++;
                            }
                        });
                        continue;
                    }
                    batch.push({ lls: outer, bbox: latLngsBbox(outer), isFill: true });
                    rendered++;

                } else if (g.type === 'Point') {
                    const ll     = toLatLng(g.coordinates[0], g.coordinates[1], srcProj);
                    const text   = f.properties?.text || '';
                    const height = f.properties?.height ?? null;
                    if (!ll)   { skip.badCoord++;  continue; }
                    if (!text) { skip.emptyText++; continue; }
                    textPts.push({ ll, text, height });
                    rendered++;
                }
            }

            // Odmah dodaj batch na canvas — progresivno se prikazuje dok se ucitava
            if (batch.length) geomLayer.addLines(batch);
            if (onProgress) onProgress(chunkEnd, features.length);
            await new Promise(r => setTimeout(r, 0));
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
        console.info(`DXF: ${rendered} entiteta, ${textPts.length} tekst labela`);

        return { textPts, rendered, skip };
    }

    // dbId: proslijedi postojeći ID pri restore-u, null = novi layer (generiše novi ID)
    async function addLayer(geojson, color, dbId = null, projectId = null) {
        const id    = ++layerCounter;
        const _dbId = dbId ?? `dxf-${Date.now()}-${Math.random().toString(36).slice(2)}`;

        // Kreiraj canvas layer odmah — feature-i se pojavljuju progresivno
        const geomLayer = new DxfGeomLayer(color).addTo(map);

        layers[id] = {
            id,
            _dbId,
            projectId,
            name:      geojson.name || ('Layer ' + id),
            color,
            visible:   true,
            geomLayer,
            textLayer: null,
            textPts:   [],
            total:     geojson.features.length,
            rendered:  0,
            loading:   true,
        };
        render();

        // Sačuvaj odmah (ne čekaj canvas build) — novi layeri; restore preskače
        if (!dbId) {
            DxfStore.save(_dbId, geojson, color, projectId).catch(e => console.warn('DXF save:', e));
        }

        const onProgress = (done, total) => {
            if (!layers[id]) return;
            layers[id].rendered = done;
            render();
        };

        const { textPts, rendered } = await buildCanvasLayer(geojson, color, geomLayer, onProgress);

        if (!layers[id]) return id; // Obrisan tokom ucitavanja

        let textLayer = null;
        if (textPts.length) {
            textLayer = new DxfTextLayer(textPts, color).addTo(map);
        }

        Object.assign(layers[id], { textLayer, textPts, rendered, loading: false });
        render();
        return id;
    }

    function removeLayer(id) {
        const ly = layers[id];
        if (!ly) return;
        if (ly._dbId) DxfStore.remove(ly._dbId).catch(console.warn);
        if (ly.geomLayer) { try { ly.geomLayer.remove(); ly.geomLayer._lines = []; } catch {} }
        if (ly.textLayer) { try { ly.textLayer.remove(); ly.textLayer._pts   = []; } catch {} }
        delete layers[id];
        render();
    }

    function clearAllLayers() {
        Object.keys(layers).forEach(id => removeLayer(parseInt(id)));
    }

    function toggleLayer(id) {
        const ly = layers[id];
        if (!ly) return;
        ly.visible = !ly.visible;
        if (ly.geomLayer) try { ly.geomLayer.setVisible(ly.visible); } catch {}
        if (ly.textLayer) try { ly.textLayer.setVisible(ly.visible); } catch {}
        render();
    }

    function setColor(id, color) {
        const ly = layers[id];
        if (!ly) return;
        ly.color = color;
        if (ly.geomLayer) try { ly.geomLayer.setColor(color); } catch {}
        if (ly.textLayer) try { ly.textLayer.setColor(color); } catch {}
    }

    function zoomTo(id) {
        const ly = layers[id];
        if (!ly) return;
        try {
            let bounds = ly.geomLayer?.getBounds() || null;
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

        el.innerHTML = `<div style="padding:4px 6px 2px;text-align:right">
            <button onclick="window._ftthDxfClearAll()" style="background:none;border:none;cursor:pointer;font-size:10px;color:#94a3b8;padding:2px 4px;border-radius:4px" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">Ukloni sve</button>
        </div>` + ids.map(id => {
            const ly  = layers[id];
            const dim = !ly.visible;
            const pct = ly.total > 0 ? Math.round(ly.rendered / ly.total * 100) : 0;
            const sub = ly.loading
                ? `<span style="color:#f59e0b">Učitavam... ${pct}%</span>`
                : ly.rendered < ly.total
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

        window._ftthDxfClearAll = clearAllLayers;

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

    const PALETTE = ['#d946ef', '#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4'];

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

            const color     = PALETTE[layerCounter % PALETTE.length];
            const projectId = document.getElementById('active-project-id')?.value || null;
            const id        = await addLayer(data, color, null, projectId);
            zoomTo(id);

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
        // Vrati podatke za DXF export — samo cache_key (server čita iz fajla)
        async getLayersForExport() {
            const saved = await DxfStore.loadAll().catch(() => []);
            const result = [];
            let missingCacheKey = 0;

            for (const s of saved) {
                // Čitaj cache_key: novo top-level polje ILI staro unutar geojson
                const ck = s.cacheKey || s.geojson?._cache_key || null;
                if (ck) {
                    result.push({ cache_key: ck, color: s.color });
                } else if (s.geojson?.features?.length) {
                    // Stari IndexedDB zapis bez cache_key — DXF importovan prije
                    // implementacije cache mehanizma. Potrebno ponovo importovati.
                    missingCacheKey++;
                }
            }

            if (missingCacheKey > 0) {
                // Upozori korisnika vidljivo
                const cmd = document.getElementById('cad-command');
                if (cmd) cmd.textContent =
                    `⚠ ${missingCacheKey} DXF podloga nema cache ključ — klikni "Ukloni sve" u DXF panelu i ponovo importuj fajl da se uključi u export.`;
            }

            return result;
        },

        // Vrati listu učitanih DXF layera za box-select
        getSelectableItems() {
            return Object.values(layers)
                .filter(ly => ly.geomLayer && !ly.loading)
                .map(ly => ({
                    dxfId:  ly.id,
                    name:   ly.name,
                    bounds: ly.geomLayer.getBounds(),
                    geomLayer: ly.geomLayer,
                    textLayer: ly.textLayer,
                }))
                .filter(item => item.bounds);
        },

        // Ukloni DXF layer po ID-u (koristi box-select brisanje)
        removeLayerById(id) {
            removeLayer(id);
        },

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

            // Restore sačuvanih layera iz prethodne sesije — filtrirano po aktivnom projektu
            const currentProjectId = document.getElementById('active-project-id')?.value || null;
            DxfStore.loadAll().then(async saved => {
                if (!saved.length) return;
                const filtered = saved.filter(item => item.projectId === currentProjectId);
                filtered.sort((a, b) => (a.savedAt || 0) - (b.savedAt || 0));
                for (const item of filtered) {
                    if (item.geojson?.features?.length) {
                        await addLayer(item.geojson, item.color, item.dbId, item.projectId);
                    }
                }
            }).catch(e => console.warn('DXF restore:', e));
        },
    };
})();
