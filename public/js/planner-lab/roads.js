(function () {
    'use strict';

    // ── Koordinatna transformacija (ista kao ftth-dxf-layer.js) ────────────
    var WGS84  = 'WGS84';
    var MGI_Z6 = '+proj=tmerc +lat_0=0 +lon_0=18 +k=0.9999 +x_0=6500000 +y_0=0 +ellps=bessel +towgs84=682,-203,480,0,0,0,0 +units=m +no_defs';
    var MGI_Z7 = '+proj=tmerc +lat_0=0 +lon_0=21 +k=0.9999 +x_0=7500000 +y_0=0 +ellps=bessel +towgs84=682,-203,480,0,0,0,0 +units=m +no_defs';

    function detectProj(features) {
        var votes = { z6: 0, z7: 0, wgs: 0 }, checked = 0;
        for (var i = 0; i < features.length && checked < 20; i++) {
            var g = features[i].geometry;
            if (!g) continue;
            var c = g.type === 'LineString' ? g.coordinates[0]
                  : g.type === 'Polygon'    ? (g.coordinates[0] && g.coordinates[0][0])
                  : g.type === 'Point'      ? g.coordinates
                  : null;
            if (!c) continue;
            var x = c[0];
            if      (x > 6000000 && x < 7000000) votes.z6++;
            else if (x > 7000000 && x < 8000000) votes.z7++;
            else if (x >= -180   && x <= 180)     votes.wgs++;
            checked++;
        }
        if (votes.z7 > votes.z6 && votes.z7 > votes.wgs) return MGI_Z7;
        if (votes.wgs > votes.z6 && votes.wgs > votes.z7) return null;
        return MGI_Z6;
    }

    function toLatLng(x, y, srcProj) {
        try {
            if (!srcProj || !window.proj4) {
                if (!isFinite(x) || !isFinite(y)) return null;
                if (y >= -90 && y <= 90 && x >= -180 && x <= 180) return [y, x];
                return null;
            }
            var r = proj4(srcProj, WGS84, [x, y]);
            var lat = r[1], lng = r[0];
            if (!isFinite(lat) || !isFinite(lng)) return null;
            if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
            return [lat, lng];
        } catch (_) { return null; }
    }

    // ── Geometry helpers ────────────────────────────────────────────────────

    function centroid(coords) {
        var latSum = 0, lngSum = 0;
        coords.forEach(function (c) { latSum += c[0]; lngSum += c[1]; });
        return [latSum / coords.length, lngSum / coords.length];
    }

    // Ray casting — point in polygon (WGS84 koordinate, dovoljno tačno za male površine)
    function pointInPoly(lat, lng, coords) {
        var inside = false, n = coords.length, j = n - 1;
        for (var i = 0; i < n; j = i++) {
            var yi = coords[i][0], xi = coords[i][1];
            var yj = coords[j][0], xj = coords[j][1];
            if (((yi > lat) !== (yj > lat)) && (lng < (xj - xi) * (lat - yi) / (yj - yi) + xi)) {
                inside = !inside;
            }
        }
        return inside;
    }

    // Ekstrakcija road polylines (LineString) iz GeoJSON za backend planning
    function geojsonToPolylines(geojson, srcProj) {
        var result = [];
        (geojson.features || []).forEach(function (f) {
            var g = f.geometry;
            if (!g || g.type !== 'LineString') return;
            var pts = [];
            g.coordinates.forEach(function (c) {
                var ll = toLatLng(c[0], c[1], srcProj);
                if (ll) pts.push(ll);
            });
            if (pts.length >= 2) result.push(pts);
        });
        return result;
    }

    // Izgradnja parcelnog indeksa: { 'broj_parcele' → [[lat,lng],...] }
    // Spaja Polygon feature-e s njihovim Point/text labelama.
    function buildParcelIndex(geojson, srcProj) {
        var polygons = []; // { coords: [[lat,lng],...], centroid: [lat,lng] }
        var textPts  = []; // { pos: [lat,lng], text: string }

        (geojson.features || []).forEach(function (f) {
            var g = f.geometry;
            if (!g) return;

            if (g.type === 'Polygon') {
                var ring = g.coordinates[0] || [];
                var coords = ring.map(function (c) { return toLatLng(c[0], c[1], srcProj); }).filter(Boolean);
                if (coords.length >= 3) polygons.push({ coords: coords, centroid: centroid(coords) });

            } else if (g.type === 'Point' && f.properties && f.properties.text) {
                var pos = toLatLng(g.coordinates[0], g.coordinates[1], srcProj);
                if (pos) textPts.push({ pos: pos, text: (f.properties.text + '').trim() });
            }
        });

        var index = {};
        textPts.forEach(function (t) {
            // Pokušaj point-in-polygon
            var found = null;
            for (var i = 0; i < polygons.length; i++) {
                if (pointInPoly(t.pos[0], t.pos[1], polygons[i].coords)) {
                    found = polygons[i];
                    break;
                }
            }
            // Fallback: najbliži centroid (tekst je izvan poligona u nekim DWG-ovima)
            if (!found && polygons.length) {
                var minD = Infinity;
                polygons.forEach(function (p) {
                    var d = Math.pow(p.centroid[0] - t.pos[0], 2) + Math.pow(p.centroid[1] - t.pos[1], 2);
                    if (d < minD) { minD = d; found = p; }
                });
            }
            if (found && !index[t.text]) index[t.text] = found.coords;
        });

        return index;
    }

    // ── PlannerLab.roads namespace ──────────────────────────────────────────

    // Jedan zajednički canvas renderer za sve DXF linije — jedan <canvas> element,
    // bez SVG DOM-a po featuri. Mnogo brže od L.polyline default (SVG) renderera.
    var _canvasRenderer = null;
    function getRenderer() {
        if (!_canvasRenderer) _canvasRenderer = L.canvas({ padding: 0.5 });
        return _canvasRenderer;
    }

    PlannerLab.roads = {
        polylines:       [],  // [[lat,lng],...] za backend planning
        _parcelIndex:    {},  // { 'broj' → [[lat,lng],...] }
        _excludedNums:   [],  // lista izuzetih parcelnih brojeva
        _excludedLayers: [],  // L.polygon highlight slojevi (crveni) na mapi
        _dxfLayers:      [],  // L.polyline objekti za učitane DXF dionice
        _drawLayers:     [],  // L.polyline za ručno nacrtane linije
        _drawing:        false,
        _currentPts:     [],
        _tempLine:       null,
        _mouseLine:      null,

        // ── DXF upload ──────────────────────────────────────────────────────

        uploadDxf: async function (file) {
            if (!/\.(dxf|dwg)$/i.test(file.name)) {
                PlannerLab.ui.toast('Odaberi DXF ili DWG fajl.', 'err');
                return;
            }

            var label = document.getElementById('pl-road-upload-label');
            if (label) label.textContent = 'Učitavam...';

            try {
                var fd   = new FormData();
                fd.append('file', file);
                var csrf = document.querySelector('meta[name="csrf-token"]');

                var res = await fetch('/mapa/dxf-layer', {
                    method:  'POST',
                    body:    fd,
                    headers: {
                        'X-CSRF-TOKEN':     csrf ? csrf.content : '',
                        'Accept':           'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                var data = await res.json();
                if (!res.ok || data.error) {
                    PlannerLab.ui.toast(data.error || 'Greška pri učitavanju DXF-a.', 'err');
                    return;
                }
                if (!data.features || !data.features.length) {
                    PlannerLab.ui.toast('DXF ne sadrži entitete.', 'err');
                    return;
                }

                var srcProj = detectProj(data.features);

                // Road polylines (LineString) → šalju se backendu za planiranje
                var newPts = geojsonToPolylines(data, srcProj);
                newPts.forEach(function (pts) { PlannerLab.roads.polylines.push(pts); });

                // Parcelni indeks (Polygon + Point/text) → za izuzimanje po broju
                var newParcels = buildParcelIndex(data, srcProj);
                var parcelCount = Object.keys(newParcels).length;
                Object.assign(PlannerLab.roads._parcelIndex, newParcels);

                // Rendering: L.polyline sa zajedničkim canvas rendererom — jedan <canvas>,
                // bez SVG DOM elemenata po featuri → mapa ne usporava
                var renderer = getRenderer();
                newPts.forEach(function (pts) {
                    var line = L.polyline(pts, {
                        color: '#2563eb', weight: 2, opacity: 0.75,
                        renderer: renderer,
                    }).addTo(PlannerLab.map);
                    PlannerLab.roads._dxfLayers.push(line);
                });

                var msg = newPts.length + ' dionica';
                if (parcelCount) msg += ', ' + parcelCount + ' parcela';
                PlannerLab.ui.toast(msg + ' učitano iz ' + file.name, 'ok');
                PlannerLab.roads._updateCounter();

                // Prikaži sekciju za izuzimanje parcela ako ima poligona
                var secParcels = document.getElementById('pl-sec-parcels');
                if (secParcels && parcelCount) secParcels.style.display = '';

                // Zoom
                if (newPts.length) {
                    var allPts = [].concat.apply([], newPts);
                    var lls = allPts.map(function (p) { return L.latLng(p[0], p[1]); });
                    if (lls.length) PlannerLab.map.fitBounds(L.latLngBounds(lls).pad(0.05));
                }

            } catch (e) {
                PlannerLab.ui.toast('Mrežna greška: ' + e.message, 'err');
            } finally {
                if (label) label.textContent = 'Učitaj DXF/DWG';
                var inp = document.getElementById('pl-road-file');
                if (inp) inp.value = '';
            }
        },

        // ── Parcelno izuzimanje ──────────────────────────────────────────────

        excludeParcel: function (num) {
            var self = PlannerLab.roads;
            num = (num + '').trim();
            if (!num) return;
            if (self._excludedNums.indexOf(num) !== -1) {
                PlannerLab.ui.toast('Parcela ' + num + ' već je izuzeta.', 'err');
                return;
            }
            var poly = self._parcelIndex[num];
            if (!poly) {
                PlannerLab.ui.toast('Parcela ' + num + ' nije pronađena u učitanom DWG-u.', 'err');
                return;
            }
            self._excludedNums.push(num);

            // Crveni highlight na mapi
            var layer = L.polygon(poly, {
                color:       '#ef4444',
                weight:      2,
                opacity:     1,
                fillColor:   '#ef4444',
                fillOpacity: 0.2,
            }).addTo(PlannerLab.map);
            layer.bindTooltip('Izuzeta parcela: ' + num, { sticky: true });
            self._excludedLayers.push({ num: num, layer: layer });

            self._renderExcludedList();
            PlannerLab.ui.toast('Parcela ' + num + ' izuzeta iz rutiranja.', 'ok');
        },

        unexcludeParcel: function (num) {
            var self = PlannerLab.roads;
            var idx = self._excludedNums.indexOf(num);
            if (idx === -1) return;
            self._excludedNums.splice(idx, 1);

            // Ukloni highlight
            for (var i = self._excludedLayers.length - 1; i >= 0; i--) {
                if (self._excludedLayers[i].num === num) {
                    PlannerLab.map.removeLayer(self._excludedLayers[i].layer);
                    self._excludedLayers.splice(i, 1);
                }
            }
            self._renderExcludedList();
        },

        getExcludedPolygons: function () {
            var self = PlannerLab.roads;
            return self._excludedNums
                .map(function (n) { return self._parcelIndex[n]; })
                .filter(Boolean);
        },

        _renderExcludedList: function () {
            var self = PlannerLab.roads;
            var el   = document.getElementById('pl-excluded-list');
            if (!el) return;
            if (!self._excludedNums.length) { el.innerHTML = ''; return; }
            el.innerHTML = self._excludedNums.map(function (n) {
                var safe = n.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                return '<span class="pl-excl-tag">' + safe +
                    ' <button data-num="' + safe + '" title="Ukloni">×</button></span>';
            }).join('');
            el.querySelectorAll('button[data-num]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    PlannerLab.roads.unexcludeParcel(this.dataset.num);
                });
            });
        },

        // ── Ručno crtanje ────────────────────────────────────────────────────

        toggleDraw: function () {
            var self = PlannerLab.roads;
            self._drawing = !self._drawing;
            var btn = document.getElementById('btn-draw-road');
            if (btn) btn.classList.toggle('pl-btn-active', self._drawing);
            PlannerLab.map.getContainer().style.cursor = self._drawing ? 'crosshair' : '';
            if (!self._drawing) {
                if (self._currentPts.length >= 2) self.finishLine();
                self._cancelDraw();
            }
        },

        finishLine: function () {
            var self = PlannerLab.roads;
            if (self._currentPts.length >= 2) {
                self._addDrawLine(self._currentPts.slice());
                PlannerLab.ui.toast('Dionica dodana (' + self._currentPts.length + ' tačaka).', 'ok');
            }
            self._cancelDraw();
        },

        _cancelDraw: function () {
            var self = PlannerLab.roads;
            self._currentPts = [];
            if (self._tempLine)  { PlannerLab.map.removeLayer(self._tempLine);  self._tempLine  = null; }
            if (self._mouseLine) { PlannerLab.map.removeLayer(self._mouseLine); self._mouseLine = null; }
        },

        _addDrawLine: function (pts) {
            var self = PlannerLab.roads;
            self.polylines.push(pts);
            var line = L.polyline(pts, { color: '#2563eb', weight: 2.5, opacity: 0.85 }).addTo(PlannerLab.map);
            self._drawLayers.push(line);
            self._updateCounter();
        },

        // ── Clear ─────────────────────────────────────────────────────────────

        clear: function () {
            var self = PlannerLab.roads;
            self._cancelDraw();

            self._dxfLayers.forEach(function (l) { PlannerLab.map.removeLayer(l); });
            self._dxfLayers = [];

            self._drawLayers.forEach(function (l) { PlannerLab.map.removeLayer(l); });
            self._drawLayers = [];

            // Ukloni i highlight izuzetih parcela (indeks ostaje — parcele i dalje poznate)
            self._excludedLayers.forEach(function (e) { PlannerLab.map.removeLayer(e.layer); });
            self._excludedLayers = [];

            self.polylines     = [];
            self._parcelIndex  = {};
            self._excludedNums = [];
            self._drawing      = false;
            PlannerLab.map.getContainer().style.cursor = '';
            var btn = document.getElementById('btn-draw-road');
            if (btn) btn.classList.remove('pl-btn-active');
            var secParcels = document.getElementById('pl-sec-parcels');
            if (secParcels) secParcels.style.display = 'none';
            self._updateCounter();
            self._renderExcludedList();
        },

        _updateCounter: function () {
            var n   = PlannerLab.roads.polylines.length;
            var el  = document.getElementById('pl-road-counter');
            var cls = document.getElementById('btn-clear-roads');
            if (el) {
                el.textContent   = n + (n === 1 ? ' dionica' : ' dionica');
                el.style.display = n ? 'block' : 'none';
            }
            if (cls) cls.style.display = n ? '' : 'none';
        },
    };

    // ── Map event handlers ───────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        var t = setInterval(function () {
            if (!PlannerLab.map) return;
            clearInterval(t);


            PlannerLab.map.on('click', function (e) {
                var self = PlannerLab.roads;
                if (!self._drawing) return;
                self._currentPts.push([e.latlng.lat, e.latlng.lng]);
                if (self._currentPts.length >= 2) {
                    if (self._tempLine) self._tempLine.setLatLngs(self._currentPts);
                    else self._tempLine = L.polyline(self._currentPts, {
                        color: '#2563eb', weight: 2.5, opacity: 0.85,
                    }).addTo(PlannerLab.map);
                }
            });

            PlannerLab.map.on('mousemove', function (e) {
                var self = PlannerLab.roads;
                if (!self._drawing || !self._currentPts.length) return;
                var last = self._currentPts[self._currentPts.length - 1];
                var pts  = [last, [e.latlng.lat, e.latlng.lng]];
                if (self._mouseLine) self._mouseLine.setLatLngs(pts);
                else self._mouseLine = L.polyline(pts, {
                    color: '#2563eb', weight: 2, opacity: 0.5, dashArray: '5 5',
                }).addTo(PlannerLab.map);
            });

            PlannerLab.map.on('dblclick', function () {
                var self = PlannerLab.roads;
                if (!self._drawing) return;
                if (self._currentPts.length > 0) self._currentPts.pop();
                if (self._currentPts.length >= 2) self.finishLine();
                else self._cancelDraw();
            });

            // Parcel exclude input — Enter ili klik "Izuzmi"
            var parcelInput = document.getElementById('pl-parcel-input');
            var parcelBtn   = document.getElementById('btn-exclude-parcel');

            function doExclude() {
                if (!parcelInput) return;
                var nums = parcelInput.value.split(/[,;\s]+/).map(function (s) { return s.trim(); }).filter(Boolean);
                nums.forEach(function (n) { PlannerLab.roads.excludeParcel(n); });
                parcelInput.value = '';
            }

            if (parcelInput) parcelInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doExclude(); } });
            if (parcelBtn)   parcelBtn.addEventListener('click', doExclude);

            var fileInp = document.getElementById('pl-road-file');
            if (fileInp) {
                fileInp.addEventListener('change', function (e) {
                    var f = e.target.files[0];
                    if (f) PlannerLab.roads.uploadDxf(f);
                });
            }
        }, 100);
    });

})();
