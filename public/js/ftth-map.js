(function () {
    window.MapEditor = window.MapEditor || {};
    if (window.MapEditor.initialized) return;
    window.MapEditor.initialized = true;
    const MapEditor = {
        map: null,
        activeTool: "select",
        selectedRoute: null,
        selectedElement: null,
        pendingRoute: null,
        pendingDelete: null,
        suggestions: [],
        moving: null,
        dirty: false,
        routeLayers: new Map(),
        snapTargets: [],
        snapOptions: { odf: true, odo: true, house: true, route: true },
        ortho: false,
        autoPlanning: {
            loading: false,
            previewActive: false,
            currentPreview: null,
        },
        defaultDetails: null,
        defaultHeading: null,
        layers: {},
        drawing: { points: [], polyline: null, tempLine: null, nodes: [] },
        measure: { points: [], polyline: null, tempLine: null, nodes: [] },
        editing: {
            route: null,
            originalPoints: [],
            points: [],
            line: null,
            outline: null,
            nodes: [],
        },
    };
    const colors = {
        feeder: "#308DCC",
        main: "#308DCC",
        distribution: "#65A845",
        secondary: "#81C342",
        drop: "#7BA9DA",
        temporary: "#64748b",
    };
    const cabinetPalette = [
        "#0ea5e9",
        "#f97316",
        "#8b5cf6",
        "#ec4899",
        "#14b8a6",
        "#eab308",
        "#ef4444",
        "#6366f1",
    ];
    const data = () =>
        window.ftthData || { odfs: [], cabinets: [], houses: [], routes: [] };
    const config = () => window.ftthMapConfig || window.ftthConfig || {};
    const isEditor = () => (config().mode || "editor") === "editor";
    const status = () => document.getElementById("map-status");
    const details = () => document.getElementById("detail-body");
    const heading = () => document.getElementById("detail-heading");

    function initMap() {
        MapEditor.map = L.map("dashboard-map", {
            zoomControl: true,
            doubleClickZoom: false,
            zoomSnap: 0.25,
            zoomDelta: 0.5,
            maxZoom: 24,
        }).setView([44.437, 18.882], 15);
        const satellite = L.tileLayer(
            "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",
            { maxNativeZoom: 18, maxZoom: 24, attribution: "Tiles &copy; Esri" },
        ).addTo(MapEditor.map);
        const streets = L.tileLayer(
            "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
            { maxNativeZoom: 19, maxZoom: 24, attribution: "&copy; OpenStreetMap" },
        );
        initLayers();
        L.control
            .layers(
                { Satelit: satellite, Ulice: streets },
                {
                    ODF: MapEditor.layers.odfs,
                    "ODO ormarici": MapEditor.layers.cabinets,
                    Kuce: MapEditor.layers.houses,
                    Trase: MapEditor.layers.routes,
                    Cvorovi: MapEditor.layers.routeNodes,
                    Mjerenje: MapEditor.layers.measure,
                    "Auto ODO": MapEditor.layers.autoPlanMarkers,
                },
            )
            .addTo(MapEditor.map);
        L.control
            .scale({ imperial: false, position: "bottomright" })
            .addTo(MapEditor.map);
        MapEditor.map
            .on("click", onMapClick)
            .on("mousemove", onMouseMove)
            .on("dblclick", onDoubleClick);
        document.addEventListener("keydown", onKeyDown);
        window.addEventListener("beforeunload", (event) => {
            if (!MapEditor.dirty) return;
            event.preventDefault();
            event.returnValue = "";
        });
    }
    function initLayers() {
        [
            "odfs",
            "cabinets",
            "houses",
            "routeOutlines",
            "routes",
            "routeNodes",
            "drawing",
            "measure",
            "snap",
            "suggestions",
            "autoPlanMarkers",
            "autoPlanLines",
            "autoPlanHighlights",
        ].forEach((name) => {
            MapEditor.layers[name] = L.layerGroup().addTo(MapEditor.map);
        });
    }
    function initToolbar() {
        document.querySelectorAll(".tool[data-tool]").forEach((button) =>
            button.addEventListener("click", (event) => {
                event.preventDefault();
                if (!isEditor() && button.dataset.tool !== "select") return;
                setActiveTool(button.dataset.tool);
            }),
        );
        document
            .querySelector('[data-action="save-edit"]')
            ?.addEventListener("click", saveEditedRoute);
        document
            .querySelector('[data-action="cancel-edit"]')
            ?.addEventListener("click", cancelEditedRoute);
        document
            .querySelector('[data-action="save-position"]')
            ?.addEventListener("click", saveMarkerPosition);
        document
            .querySelector('[data-action="cancel-position"]')
            ?.addEventListener("click", cancelMarkerPosition);
        document
            .querySelector('[data-action="finish-route"]')
            ?.addEventListener("click", finishDrawingRoute);
        document
            .querySelector('[data-action="undo-route-point"]')
            ?.addEventListener("click", removeLastRoutePoint);
        document
            .querySelector('[data-action="cancel-route"]')
            ?.addEventListener("click", () => setActiveTool("select"));
        document
            .querySelectorAll('[data-action="close-route-modal"]')
            .forEach((button) =>
                button.addEventListener("click", closeRouteModal),
            );
        document
            .getElementById("route-form")
            ?.addEventListener("submit", saveRouteForm);
        document
            .querySelector('[data-action="confirm-delete"]')
            ?.addEventListener("click", performPendingDelete);
        document
            .querySelector('[data-action="cancel-delete"]')
            ?.addEventListener("click", closeDeleteModal);
        document
            .querySelectorAll('[data-action="suggest-cabinets"]')
            .forEach((button) => button.addEventListener("click", (event) => {
                event.preventDefault();
                previewAutoOdoPlan();
            }));
        document
            .querySelectorAll('[data-action="close-suggestions"]')
            .forEach((button) => button.addEventListener("click", closeSuggestions));
        document
            .querySelector('[data-action="save-suggestions"]')
            ?.addEventListener("click", confirmAutoOdoPlan);
        document
            .querySelectorAll('[data-action="discard-auto-plan"], [data-action="close-suggestions"]')
            .forEach((button) => button.addEventListener("click", discardAutoPlan));
        document
            .querySelectorAll("[data-snap-option]")
            .forEach((input) => input.addEventListener("change", () => {
                MapEditor.snapOptions[input.dataset.snapOption] = input.checked;
                setStatus(toolInstruction(MapEditor.activeTool));
            }));
        document.querySelectorAll("[data-quick-tool]").forEach((button) =>
            button.addEventListener("click", (event) => {
                event.preventDefault();
                setActiveTool(button.dataset.quickTool);
            }),
        );
    }
    function setActiveTool(tool) {
        if (!isEditor() && tool !== "select") tool = "select";
        if (
            MapEditor.dirty &&
            tool !== MapEditor.activeTool &&
            !confirm("Imas nespremljene izmjene. Odbaciti ih?")
        )
            return;
        if (tool !== "draw_route") cancelDrawingRoute();
        if (tool !== "measure") cancelMeasure();
        if (tool !== "edit_route") cancelEditedRoute();
        MapEditor.activeTool = tool;
        document
            .querySelectorAll(".tool")
            .forEach((button) =>
                button.classList.toggle("active", button.dataset.tool === tool),
            );
        const container = document.getElementById("dashboard-map");
        [...container.classList]
            .filter((name) => name.startsWith("tool-"))
            .forEach((name) => container.classList.remove(name));
        container.classList.add(`tool-${tool}`);
        document
            .getElementById("map-draw-actions")
            ?.classList.toggle("hidden", tool !== "draw_route");
        refreshDrawActions();
        setStatus(toolInstruction(tool));
    }
    function toolInstruction(tool) {
        return (
            {
                select: "Odaberi element na mapi.",
                add_odf: "Klikni na mapu za novu ODF lokaciju.",
                add_odo: "Klikni na mapu za novi ODO ormaric.",
                add_customer: "Klikni na mapu za novu kucu ili prikljucak.",
                draw_route:
                    "Klikni tacke trase, zatim klikni Zavrsi trasu. ESC prekida. Backspace uklanja zadnju tacku.",
                edit_route: "Klikni trasu za uredjivanje geometrije.",
                delete_node:
                    "Uredi trasu, zatim klikni lomnu tacku koju zelis obrisati.",
                measure:
                    "Klikni tacke za mjerenje. Double click zavrsava. ESC brise mjerenje.",
                delete_element: "Klikni trasu koju zelis obrisati.",
            }[tool] || "Odaberi alat."
        );
    }
    function setStatus(message, points = null, length = null, snap = null) {
        if (!status()) return;
        const extras = [];
        if (points !== null) extras.push(`Tacke: ${points}`);
        if (length !== null) extras.push(`Duzina: ${length} m`);
        if (snap !== null) extras.push(`Snap: ${snap || "nema"}`);
        extras.push(`ORTHO: ${MapEditor.ortho ? "ON" : "OFF"}`);
        status().textContent = `Command: ${MapEditor.activeTool.toUpperCase()} | ${message}${extras.length ? ` | ${extras.join(" | ")}` : ""}`;
    }
    function renderAll() {
        renderOdfs();
        renderCabinets();
        renderHouses();
        renderRoutes();
        fitMapToData();
    }
    function markerIcon(type, label, color = "") {
        return L.divIcon({
            className: "ftth-marker",
            html: `<div class="marker-box ${type}-box"${color ? ` style="background:${color}"` : ""}>${label}</div>`,
            iconSize: [24, 24],
            iconAnchor: [12, 12],
        });
    }
    function renderOdfs() {
        data()
            .odfs.filter(validPoint)
            .forEach((item) =>
                addAssetMarker(item, "odf", MapEditor.layers.odfs),
            );
    }
    function renderCabinets() {
        data()
            .cabinets.filter(validPoint)
            .forEach((item) =>
                addAssetMarker(item, "odo", MapEditor.layers.cabinets),
            );
    }
    function renderHouses() {
        data()
            .houses.filter(validPoint)
            .forEach((item) =>
                addAssetMarker(item, "house", MapEditor.layers.houses),
            );
    }
    function addAssetMarker(item, type, layer) {
        const labels = { odf: "ODF", odo: "ODO", house: "K" };
        const color =
            type === "odo"
                ? cabinetColor(item.id)
                : type === "house"
                  ? cabinetColor(item.cabinet_id)
                  : "";
        const marker = L.marker([item.lat, item.lng], {
            icon: markerIcon(type === "house" ? "house" : type, labels[type], color),
        }).addTo(layer);
        item.marker = marker;
        marker
            .bindTooltip(item.name || item.label || labels[type])
            .on("click", () => {
                item.elementType = type;
                item.marker = marker;
                showDetails(item);
            });
        MapEditor.snapTargets.push({
            latlng: marker.getLatLng(),
            label: item.name || item.label || labels[type],
            type,
        });
    }
    function renderRoutes() {
        data()
            .routes.filter((route) => normalizePoints(route.path).length > 1)
            .forEach(drawStyledRoute);
    }
    function drawStyledRoute(route) {
        const coords = normalizePoints(route.path),
            color = route.type === "drop" ? cabinetColor(route.cabinet_id) : routeColor(route.type),
            weight = route.type === "feeder" ? 3 : route.type === "drop" ? 1.6 : 2.4;
        const outline = L.polyline(coords, {
            color: "#fff",
            weight: weight + 2,
            opacity: 0.72,
            lineCap: "round",
            lineJoin: "round",
            interactive: false,
        }).addTo(MapEditor.layers.routeOutlines);
        const line = L.polyline(coords, {
            color,
            weight,
            lineCap: "round",
            lineJoin: "round",
        }).addTo(MapEditor.layers.routes);
        const nodes = coords.map((point, index) =>
            addViewNode(route, point, index, color),
        );
        line.bindTooltip(
            `${route.name} - ${route.length || calculateLength(coords)} m`,
        );
        line.on("mouseover", () => line.setStyle({ weight: weight + 2 })).on(
            "mouseout",
            () => line.setStyle({ weight: MapEditor.selectedRoute?.id === route.id ? weight + 2 : weight }),
        );
        line.on("click", (event) => {
            L.DomEvent.stopPropagation(event);
            handleRouteClick(route, event.latlng);
        });
        MapEditor.routeLayers.set(route.id, { outline, line, nodes });
        coords.forEach((point, index) =>
            MapEditor.snapTargets.push({
                latlng: L.latLng(point),
                label: `${route.name} T${index + 1}`,
                type: "route",
            }),
        );
        return line;
    }
    function addViewNode(route, point, index, color) {
        return L.circleMarker(point, {
            radius: route.type === "drop" ? 2 : 3,
            color,
            weight: 1.5,
            fillColor: "#fff",
            fillOpacity: 1,
        })
            .addTo(MapEditor.layers.routeNodes)
            .on("click", (event) => {
                if (
                    MapEditor.activeTool === "delete_node" &&
                    MapEditor.editing.route?.id === route.id
                ) {
                    L.DomEvent.stopPropagation(event);
                    deleteRouteNode(index);
                }
            });
    }
    function handleRouteClick(route, latlng) {
        if (MapEditor.activeTool === "delete_element")
            return confirmDeleteRoute(route);
        if (MapEditor.activeTool === "edit_route") {
            if (MapEditor.editing.route?.id === route.id)
                return insertNodeOnSegment(latlng);
            return startEditingRoute(route);
        }
        route.elementType = "route";
        showDetails(route);
    }
    function onMapClick(event) {
        const snapped = applySnap(event.latlng);
        if (MapEditor.activeTool === "draw_route")
            return addDrawingPoint(snapped.latlng);
        if (MapEditor.activeTool === "measure")
            return addMeasurePoint(snapped.latlng);
        if (MapEditor.activeTool === "add_odf") return addOdf(snapped.latlng);
        if (MapEditor.activeTool === "add_odo") return addOdo(snapped.latlng);
        if (MapEditor.activeTool === "add_customer")
            return addHouse(snapped.latlng);
        if (MapEditor.activeTool === "select") deselectCurrent(true);
    }
    function onMouseMove(event) {
        const snapped = applySnap(event.latlng);
        showSnapIndicator(snapped);
        if (
            MapEditor.activeTool === "draw_route" &&
            MapEditor.drawing.points.length
        ) {
            updateTempLine(
                MapEditor.drawing,
                snapped.latlng,
                MapEditor.layers.drawing,
            );
            setStatus(
                "Klikni Zavrsi trasu kada zavrsis.",
                MapEditor.drawing.points.length,
                calculateLength([
                    ...MapEditor.drawing.points,
                    [snapped.latlng.lat, snapped.latlng.lng],
                ]),
                snapped.label,
            );
        }
        if (
            MapEditor.activeTool === "measure" &&
            MapEditor.measure.points.length
        ) {
            updateTempLine(
                MapEditor.measure,
                snapped.latlng,
                MapEditor.layers.measure,
            );
            setStatus(
                "Mjerenje aktivno. ESC brise.",
                MapEditor.measure.points.length,
                calculateLength([
                    ...MapEditor.measure.points,
                    [snapped.latlng.lat, snapped.latlng.lng],
                ]),
                snapped.label,
            );
        }
        if (
            ["draw_route", "measure"].includes(MapEditor.activeTool) &&
            !MapEditor.drawing.points.length &&
            !MapEditor.measure.points.length
        )
            setStatus(toolInstruction(MapEditor.activeTool), null, null, snapped.label);
    }
    function onDoubleClick(event) {
        event.originalEvent?.preventDefault();
        if (MapEditor.activeTool === "draw_route") finishDrawingRoute();
        if (MapEditor.activeTool === "measure") finishMeasure();
    }
    function addDrawingPoint(latlng) {
        MapEditor.drawing.points.push([latlng.lat, latlng.lng]);
        MapEditor.dirty = true;
        redrawTemporary(
            MapEditor.drawing,
            MapEditor.layers.drawing,
            colors.temporary,
        );
        setStatus(
            "Klikni Zavrsi trasu kada zavrsis.",
            MapEditor.drawing.points.length,
            calculateLength(MapEditor.drawing.points),
        );
        refreshDrawActions();
    }
    function updateTempLine(state, latlng, layer) {
        if (state.tempLine) layer.removeLayer(state.tempLine);
        state.tempLine = L.polyline(
            [state.points.at(-1), [latlng.lat, latlng.lng]],
            { color: colors.temporary, weight: 2, dashArray: "6,6" },
        ).addTo(layer);
    }
    function redrawTemporary(state, layer, color) {
        if (state.polyline) layer.removeLayer(state.polyline);
        state.nodes.forEach((node) => layer.removeLayer(node));
        state.nodes = state.points.map((point) =>
            L.circleMarker(point, {
                radius: 5,
                color,
                weight: 2,
                fillColor: "#fff",
                fillOpacity: 1,
            }).addTo(layer),
        );
        state.polyline = L.polyline(state.points, {
            color,
            weight: 3,
            dashArray: "6,6",
        }).addTo(layer);
    }
    async function finishDrawingRoute() {
        if (MapEditor.drawing.points.length < 2)
            return showToast("Trasa mora imati najmanje dvije tacke.", "error");
        openRouteModal({ path: [...MapEditor.drawing.points] });
    }
    function cancelDrawingRoute() {
        clearState(MapEditor.drawing, MapEditor.layers.drawing);
        MapEditor.dirty = Boolean(MapEditor.editing.route);
        refreshDrawActions();
    }
    function removeLastRoutePoint() {
        MapEditor.drawing.points.pop();
        redrawTemporary(
            MapEditor.drawing,
            MapEditor.layers.drawing,
            colors.temporary,
        );
        setStatus(
            "Klikni Zavrsi trasu kada zavrsis.",
            MapEditor.drawing.points.length,
            calculateLength(MapEditor.drawing.points),
        );
        refreshDrawActions();
    }
    function refreshDrawActions() {
        const finish = document.querySelector('[data-action="finish-route"]');
        const undo = document.querySelector('[data-action="undo-route-point"]');
        if (finish) finish.disabled = MapEditor.drawing.points.length < 2;
        if (undo) undo.disabled = MapEditor.drawing.points.length < 1;
    }
    function addMeasurePoint(latlng) {
        MapEditor.measure.points.push([latlng.lat, latlng.lng]);
        redrawTemporary(MapEditor.measure, MapEditor.layers.measure, "#facc15");
        setStatus(
            "Mjerenje aktivno. ESC brise.",
            MapEditor.measure.points.length,
            calculateLength(MapEditor.measure.points),
        );
    }
    function finishMeasure() {
        setStatus(
            "Mjerenje zavrseno. ESC brise.",
            MapEditor.measure.points.length,
            calculateLength(MapEditor.measure.points),
        );
    }
    function cancelMeasure() {
        clearState(MapEditor.measure, MapEditor.layers.measure);
    }
    function clearState(state, layer) {
        layer.clearLayers();
        state.points = [];
        state.nodes = [];
        state.polyline = null;
        state.tempLine = null;
    }
    function startEditingRoute(route) {
        cancelEditedRoute();
        const current = normalizePoints(route.path);
        if (current.length < 2) return;
        MapEditor.editing.route = route;
        MapEditor.editing.originalPoints = current.map((x) => [...x]);
        MapEditor.editing.points = current.map((x) => [...x]);
        MapEditor.routeLayers.get(route.id)?.line.setStyle({ weight: 7 });
        renderEditGeometry();
        document.getElementById("map-edit-actions")?.classList.remove("hidden");
        setStatus(
            "Pomjeri cvorove ili klikni segment za dodavanje nove tacke.",
            current.length,
            calculateLength(current),
        );
    }
    function renderEditGeometry() {
        const edit = MapEditor.editing;
        MapEditor.layers.drawing.clearLayers();
        edit.outline = L.polyline(edit.points, {
            color: "#fff",
            weight: 9,
        }).addTo(MapEditor.layers.drawing);
        edit.line = L.polyline(edit.points, {
            color: routeColor(edit.route.type),
            weight: 5,
        }).addTo(MapEditor.layers.drawing);
        edit.nodes = edit.points.map((point, index) =>
            L.marker(point, {
                draggable: true,
                icon: L.divIcon({
                    className: "route-node",
                    iconSize: [14, 14],
                    iconAnchor: [7, 7],
                }),
            })
                .addTo(MapEditor.layers.drawing)
                .on("drag", (event) => {
                    edit.points[index] = [event.latlng.lat, event.latlng.lng];
                    edit.outline.setLatLngs(edit.points);
                    edit.line.setLatLngs(edit.points);
                    MapEditor.dirty = true;
                    setStatus(
                        "Nespremljene izmjene.",
                        edit.points.length,
                        calculateLength(edit.points),
                    );
                })
                .on("click", () => {
                    if (MapEditor.activeTool === "delete_node")
                        deleteRouteNode(index);
                }),
        );
    }
    function insertNodeOnSegment(latlng) {
        const points = MapEditor.editing.points;
        let bestIndex = 1,
            bestDistance = Infinity;
        for (let index = 1; index < points.length; index++) {
            const distance = segmentDistance(
                latlng,
                points[index - 1],
                points[index],
            );
            if (distance < bestDistance) {
                bestDistance = distance;
                bestIndex = index;
            }
        }
        points.splice(bestIndex, 0, [latlng.lat, latlng.lng]);
        MapEditor.dirty = true;
        renderEditGeometry();
    }
    function deleteRouteNode(index) {
        if (MapEditor.editing.points.length <= 2)
            return showToast(
                "Trasa mora zadrzati najmanje dvije tacke.",
                "error",
            );
        MapEditor.editing.points.splice(index, 1);
        MapEditor.dirty = true;
        renderEditGeometry();
    }
    async function saveEditedRoute() {
        const edit = MapEditor.editing;
        if (!edit.route) return;
        const response = await request(
            config().routeGeometryUrl.replace("__ID__", edit.route.id),
            "PATCH",
            { path: edit.points },
        );
        if (!response?.route) return;
        edit.route.path = response.route.path;
        edit.route.length = response.route.length;
        rerenderRoutes();
        refreshDashboardUi();
        cancelEditedRoute();
        edit.route.elementType = "route";
        showDetails(edit.route);
        showToast("Izmjene trase su sacuvane.", "success");
    }
    function cancelEditedRoute() {
        MapEditor.layers.drawing?.clearLayers();
        MapEditor.routeLayers
            .get(MapEditor.editing.route?.id)
            ?.line.setStyle({ weight: 4 });
        MapEditor.editing = {
            route: null,
            originalPoints: [],
            points: [],
            line: null,
            outline: null,
            nodes: [],
        };
        MapEditor.dirty = false;
        document.getElementById("map-edit-actions")?.classList.add("hidden");
    }
    function rerenderRoutes() {
        MapEditor.layers.routeOutlines.clearLayers();
        MapEditor.layers.routes.clearLayers();
        MapEditor.layers.routeNodes.clearLayers();
        MapEditor.routeLayers.clear();
        renderRoutes();
        rebuildSnapTargets();
    }
    function rebuildSnapTargets() {
        MapEditor.snapTargets = [];
        [...data().odfs, ...data().cabinets, ...data().houses].forEach((item) => {
            if (!item.marker) return;
            MapEditor.snapTargets.push({
                latlng: item.marker.getLatLng(),
                label: item.name || item.label,
            });
        });
        data().routes.forEach((route) =>
            normalizePoints(route.path).forEach((point, index) =>
                MapEditor.snapTargets.push({
                    latlng: L.latLng(point),
                    label: `${route.name} T${index + 1}`,
                }),
            ),
        );
    }
    function openDeleteModal(type, name, callback) {
        MapEditor.pendingDelete = callback;
        document.getElementById("delete-confirm-message").textContent =
            `Da li sigurno zelis obrisati ${type} "${name}"?`;
        document.getElementById("delete-confirm-modal").classList.remove("hidden");
    }
    function closeDeleteModal() {
        MapEditor.pendingDelete = null;
        document.getElementById("delete-confirm-modal").classList.add("hidden");
    }
    async function performPendingDelete() {
        const callback = MapEditor.pendingDelete;
        if (!callback) return;
        if (!(await callback())) return;
        closeDeleteModal();
        rebuildSnapTargets();
        refreshDashboardUi();
        deselectCurrent(true);
        showToast("Element je obrisan.", "success");
    }
    async function confirmDeleteRoute(route) {
        openDeleteModal("trasu", route.name, async () => {
        const response = await request(
            config().routeDeleteUrl.replace("__ID__", route.id),
            "DELETE",
        );
        if (!response) return false;
        data().routes = data().routes.filter((item) => item.id !== route.id);
        window.ftthData.routes = data().routes;
        rerenderRoutes();
        return true;
        });
    }
    async function confirmDeleteOdf(odf) {
        openDeleteModal("ODF", odf.name, async () => {
        const response = await request(config().deleteUrls.odf.replace("__ID__", odf.id), "DELETE");
        if (!response) return false;
        if (odf.marker) MapEditor.layers.odfs.removeLayer(odf.marker);
        data().odfs = data().odfs.filter((item) => item.id !== odf.id);
        window.ftthData.odfs = data().odfs;
        MapEditor.snapTargets = MapEditor.snapTargets.filter(
            (target) => target.label !== odf.name,
        );
        return true;
        });
    }
    async function confirmDeleteCabinet(cabinet) {
        openDeleteModal("ODO", cabinet.name, async () => {
        const response = await request(config().deleteUrls.odo.replace("__ID__", cabinet.id), "DELETE");
        if (!response) return false;
        if (cabinet.marker)
            MapEditor.layers.cabinets.removeLayer(cabinet.marker);
        data().cabinets = data().cabinets.filter(
            (item) => item.id !== cabinet.id,
        );
        window.ftthData.cabinets = data().cabinets;
        window.ftthData.routes = data().routes.filter(
            (route) => route.cabinet_id !== cabinet.id || route.type !== "drop",
        );
        data().houses.forEach((house) => {
            if (house.cabinet_id !== cabinet.id) return;
            house.cabinet_id = null;
            house.cabinet = "Nije povezano";
            house.marker?.setIcon(markerIcon("house", "K", cabinetColor(null)));
        });
        rerenderRoutes();
        MapEditor.snapTargets = MapEditor.snapTargets.filter(
            (target) => target.label !== cabinet.name,
        );
        return true;
        });
    }
    async function legacyConfirmDeleteHouse(house) {
        if (!confirm(`Obrisati kuću "${house.name || house.label}"?`)) return;
        const url = config().positionUrls.house.replace("__ID__", house.id);
        const deleteUrl = url.replace("/pozicija", "");
        const response = await request(deleteUrl, "DELETE");
        if (!response) return;
        if (house.marker) MapEditor.layers.houses.removeLayer(house.marker);
        data().houses = data().houses.filter((item) => item.id !== house.id);
        window.ftthData.houses = data().houses;
        MapEditor.snapTargets = MapEditor.snapTargets.filter(
            (target) => target.label !== (house.name || house.label),
        );
        refreshRoutesTable();
        recalculateStats();
        deselectCurrent();
        showToast("Kuća je obrisana.", "success");
    }
    async function confirmDeleteHouse(house) {
        openDeleteModal("kucu", house.name || house.label, async () => {
            const response = await request(
                config().deleteUrls.house.replace("__ID__", house.id),
                "DELETE",
            );
            if (!response) return false;
            if (house.marker) MapEditor.layers.houses.removeLayer(house.marker);
            window.ftthData.houses = data().houses.filter(
                (item) => item.id !== house.id,
            );
            return true;
        });
    }
    function confirmDeleteElement(item) {
        if (item.elementType === "route") return confirmDeleteRoute(item);
        if (item.elementType === "odf") return confirmDeleteOdf(item);
        if (item.elementType === "odo") return confirmDeleteCabinet(item);
        if (item.elementType === "house") return confirmDeleteHouse(item);
    }
    function applySnap(latlng) {
        if (!["draw_route", "measure"].includes(MapEditor.activeTool))
            return { latlng, label: "" };
        const target = findSnapTarget(latlng);
        const snapped = target
            ? { latlng: target.latlng, label: target.label }
            : { latlng, label: "" };
        if (MapEditor.ortho && MapEditor.activeTool === "draw_route" && MapEditor.drawing.points.length) {
            snapped.latlng = applyOrtho(snapped.latlng, MapEditor.drawing.points.at(-1));
        }
        return snapped;
    }
    function applyOrtho(latlng, previousPoint) {
        const previous = L.latLng(previousPoint);
        const latDelta = Math.abs(latlng.lat - previous.lat);
        const lngDelta = Math.abs(latlng.lng - previous.lng);
        return L.latLng(latDelta > lngDelta ? latlng.lat : previous.lat, latDelta > lngDelta ? previous.lng : latlng.lng);
    }
    function findSnapTarget(latlng) {
        const point = MapEditor.map.latLngToContainerPoint(latlng);
        return MapEditor.snapTargets.find(
            (target) =>
                MapEditor.snapOptions[target.type === "odo" ? "odo" : target.type] !== false &&
                point.distanceTo(
                    MapEditor.map.latLngToContainerPoint(target.latlng),
                ) <= 12,
        );
    }
    function showSnapIndicator(result) {
        MapEditor.layers.snap.clearLayers();
        if (result.label)
            L.circleMarker(result.latlng, {
                radius: 8,
                color: "#308DCC",
                weight: 2,
                fillOpacity: 0,
            }).addTo(MapEditor.layers.snap);
    }
    function calculateLength(points) {
        let total = 0;
        for (let i = 1; i < points.length; i++)
            total += MapEditor.map.distance(points[i - 1], points[i]);
        return Math.round(total);
    }
    function suggestCabinets() {
        return previewAutoOdoPlan();
    }
    async function previewAutoOdoPlan() {
        if (MapEditor.autoPlanning.loading) return;
        if (!config().autoOdoPreviewUrl) return showToast("Auto ODO preview endpoint nije podesen.", "error");
        if (!prepareForAutoPlanning()) return;
        MapEditor.autoPlanning.loading = true;
        const buttons = document.querySelectorAll('[data-action="suggest-cabinets"]');
        buttons.forEach((button) => {
            button.disabled = true;
            button.dataset.originalText = button.textContent;
            button.textContent = "Planiram...";
        });
        try {
            clearAutoPlanPreview();
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 20000);
            const plan = await request(config().autoOdoPreviewUrl, "POST", {
                max_branch_distance_m: Number(document.getElementById("planner-branch-distance")?.value || 60),
                max_houses_per_odo: Number(document.getElementById("planner-max")?.value || 12),
                max_house_to_odo_m: Number(document.getElementById("planner-max-drop")?.value || 120),
                max_gap_m: Number(document.getElementById("planner-max-gap")?.value || 100),
                preferred_fill_min: Number(document.getElementById("planner-min")?.value || 8),
                create_drop_routes: false,
            }, { signal: controller.signal });
            clearTimeout(timeout);
            MapEditor.autoPlanning.currentPreview = plan;
            MapEditor.autoPlanning.previewActive = true;
            renderAutoPlanPreview(plan);
            showAutoPlanPanel(plan);
        } catch (error) {
            showToast(error.name === "AbortError" ? "Auto ODO request je istekao." : error.message, "error");
        } finally {
            MapEditor.autoPlanning.loading = false;
            buttons.forEach((button) => {
                button.disabled = false;
                button.textContent = button.dataset.originalText || "Predlozi ODO";
            });
        }
    }
    function prepareForAutoPlanning() {
        if (MapEditor.drawing.points.length && !confirm("Aktivno je crtanje trase. Prekinuti crtanje i pokrenuti Auto ODO?")) return false;
        if (MapEditor.editing.route && !confirm("Aktivno je editovanje trase. Prekinuti editovanje i pokrenuti Auto ODO?")) return false;
        if (MapEditor.measure.points.length && !confirm("Aktivno je mjerenje. Prekinuti mjerenje i pokrenuti Auto ODO?")) return false;
        cancelDrawingRoute();
        cancelMeasure();
        cancelEditedRoute();
        clearAutoPlanPreview();
        return true;
    }
    function clearAutoPlanPreview() {
        ["autoPlanMarkers", "autoPlanLines", "autoPlanHighlights"].forEach((name) => MapEditor.layers[name]?.clearLayers());
        MapEditor.autoPlanning.previewActive = false;
        MapEditor.autoPlanning.currentPreview = null;
    }
    function renderAutoPlanPreview(plan) {
        (plan.cabinets || []).forEach((cabinet, index) => {
            const color = cabinetPalette[index % cabinetPalette.length];
            const point = [cabinet.proposed_latitude, cabinet.proposed_longitude];
            L.marker(point, { icon: markerIcon("suggest", "ODO", color) })
                .bindTooltip(`${cabinet.name}: ${cabinet.house_count}/12 | score ${cabinet.score}`)
                .addTo(MapEditor.layers.autoPlanMarkers);
            (cabinet.houses || []).forEach((house) => {
                L.circleMarker([house.latitude, house.longitude], {
                    radius: 7,
                    color,
                    weight: 2,
                    fillColor: color,
                    fillOpacity: 0.35,
                }).bindTooltip(`${house.label} | krak ${house.branch_index} | ${house.chainage_m ?? "-"} m`).addTo(MapEditor.layers.autoPlanHighlights);
                L.polyline([point, [house.latitude, house.longitude]], {
                    color,
                    weight: 1.4,
                    opacity: 0.8,
                    dashArray: "5,5",
                }).addTo(MapEditor.layers.autoPlanLines);
            });
        });
    }
    function showAutoPlanPanel(plan) {
        const summary = plan.summary || {};
        const warningHtml = [...(plan.warnings || []), ...(plan.cabinets || []).flatMap((cabinet) => (cabinet.warnings || []).map((message) => ({ message })))]
            .map((warning) => `<div class="border-t py-1 text-amber-700">${escapeHtml(warning.message || warning)}</div>`)
            .join("");
        const cabinetsHtml = (plan.cabinets || []).map((cabinet) =>
            `<div class="border-t py-2"><b>${escapeHtml(cabinet.name)}</b><br>Krak ${cabinet.branch_index}: ${cabinet.house_count}/12 kuca, ${cabinet.splitter_count} splittera, max ${cabinet.max_distance_m} m, score ${cabinet.score}</div>`,
        ).join("");
        document.getElementById("suggestion-summary").innerHTML =
            `<p class="mb-2"><b>Analiza plana</b>: ${summary.proposed_odo_count || 0} ODO, score ${summary.score || 0}, nedodijeljeno ${summary.unassigned_house_count || 0}.</p>${cabinetsHtml}${warningHtml}`;
        document.getElementById("suggestion-modal")?.classList.remove("hidden");
    }
    async function confirmAutoOdoPlan() {
        const plan = MapEditor.autoPlanning.currentPreview || (MapEditor.suggestions.length ? null : null);
        if (!plan) return saveSuggestions();
        if (!config().autoOdoConfirmUrl) return showToast("Auto ODO confirm endpoint nije podesen.", "error");
        const result = await request(config().autoOdoConfirmUrl, "POST", { plan, create_drop_routes: false });
        showToast(result.message || "Auto ODO plan je snimljen.");
        discardAutoPlan();
        window.location.reload();
    }
    function discardAutoPlan() {
        clearAutoPlanPreview();
        document.getElementById("suggestion-modal")?.classList.add("hidden");
    }
    function escapeHtml(value) {
        return String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]));
    }
    function legacySuggestCabinets() {
        clearSuggestions();
        const houses = data().houses.filter(
            (house) => validPoint(house) && !house.cabinet_id,
        );
        const routes = data().routes.filter(
            (route) => route.type !== "drop" && normalizePoints(route.path).length > 1,
        );
        if (!houses.length)
            return showToast("Nema novih kuca bez dodijeljenog ODO-a.", "error");
        if (!routes.length)
            return showToast("Prvo nacrtaj glavnu ili distribucionu trasu.", "error");
        if (!data().odfs.length)
            return showToast("Prvo dodaj ODF lokaciju.", "error");
        const plan = optimizedHouseClusters(houses, routes);
        const groups = plan.groups;
        groups.forEach((group) => {
            const center = groupCenter(group);
            const anchor = nearestRouteAnchor(L.latLng(center.lat, center.lng), routes);
            const point = anchor.latlng;
            const odf = nearestItem(point, data().odfs);
            const groupColor = cabinetPalette[MapEditor.suggestions.length % cabinetPalette.length];
            const suggestion = {
                name: `ODO-P-${String(MapEditor.suggestions.length + 1).padStart(2, "0")}`,
                latitude: Number(point.lat.toFixed(7)),
                longitude: Number(point.lng.toFixed(7)),
                splitter_count: Math.max(1, Math.ceil(group.length / 4)),
                odf_id: odf?.id || null,
                houses: group.map((house) => ({
                    latitude: Number(house.lat),
                    longitude: Number(house.lng),
                    path: dropPathAlongRoute(anchor, house).map((latlng) => [
                        Number(latlng.lat ?? latlng[0]),
                        Number(latlng.lng ?? latlng[1]),
                    ]),
                })),
            };
            suggestion.max_drop_m = Math.round(Math.max(
                ...group.map((house) => distanceBetween(point, house)),
            ));
            const marker = L.marker(point, { icon: markerIcon("suggest", "ODO?", groupColor) })
                .bindTooltip(`${suggestion.name}: ${group.length}/12 kuca`)
                .addTo(MapEditor.layers.suggestions);
            group.forEach((house) =>
                house.marker?.setIcon(markerIcon("house", "K", groupColor)),
            );
            suggestion.houses.forEach((house) =>
                L.polyline(house.path, {
                    color: groupColor,
                    weight: 1.25,
                    opacity: 0.72,
                    dashArray: "4,4",
                }).addTo(MapEditor.layers.suggestions),
            );
            suggestion.marker = marker;
            MapEditor.suggestions.push(suggestion);
        });
        document.getElementById("suggestion-summary").innerHTML =
            `<p class="mb-2">Pripremljeno: <b>${MapEditor.suggestions.length}</b> ODO ormarica. Cilj je 11-12 kuca po ODO-u, prihvatljivo 8-12. Svaka boja predstavlja jedan ODO i njegovu grupu kuca. Procjena modela: <b>${Math.round(plan.score)}</b>.</p>` +
            MapEditor.suggestions.map((item) =>
                `<div class="border-t py-2"><b>${item.name}</b>: ${item.houses.length}/12 kuca, ${item.splitter_count} splittera, max odvojak ${item.max_drop_m} m</div>`,
            ).join("");
        document.getElementById("suggestion-modal").classList.remove("hidden");
    }
    function optimizedHouseClusters(houses, routes) {
        const minimum = Math.ceil(houses.length / 12);
        const maximum = Math.min(houses.length, minimum + 1);
        let best = null;
        for (let count = minimum; count <= maximum; count++) {
            const groups = balancedHouseClusters(houses, 12, count, routes);
            const score = groups.reduce((total, group) => {
                const anchor = nearestRouteAnchor(L.latLng(groupCenter(group)), routes);
                const splitters = Math.ceil(group.length / 4);
                const dropMeters = group.reduce(
                    (sum, house) => sum + calculateLength(dropPathAlongRoute(anchor, house)),
                    0,
                );
                return total + 700 + splitters * 120 + occupancyPenalty(group.length) + dropMeters;
            }, 0);
            if (!best || score < best.score) best = { groups, score };
        }
        return best;
    }
    function occupancyPenalty(size) {
        if (size < 8) return 5000 + (8 - size) * 1000;
        if (size === 8) return 450;
        if (size === 9) return 300;
        if (size === 10) return 150;
        return 0;
    }
    function balancedHouseClusters(houses, capacity, clusterCount, routes) {
        let centers = initialClusterCenters(houses, clusterCount);
        let groups = [];
        for (let iteration = 0; iteration < 12; iteration++) {
            groups = Array.from({ length: clusterCount }, () => []);
            [...houses]
                .sort((a, b) => nearestRouteCenterDistance(b, centers, routes) - nearestRouteCenterDistance(a, centers, routes))
                .forEach((house) => {
                    const target = centers
                        .map((center, index) => ({
                            index,
                            distance: routeAwareDistance(center, house, routes),
                            size: groups[index].length,
                        }))
                        .filter((candidate) => candidate.size < capacity)
                        .sort((a, b) => a.distance - b.distance || a.size - b.size)[0];
                    groups[target.index].push(house);
                });
            centers = groups.map((group, index) => group.length ? groupCenter(group) : centers[index]);
        }
        return groups.filter((group) => group.length);
    }
    function initialClusterCenters(houses, count) {
        const center = groupCenter(houses);
        const centers = [[...houses].sort((a, b) => distanceBetween(b, center) - distanceBetween(a, center))[0]];
        while (centers.length < count) {
            centers.push([...houses].sort(
                (a, b) => nearestCenterDistance(b, centers) - nearestCenterDistance(a, centers),
            )[0]);
        }
        return centers;
    }
    function nearestCenterDistance(house, centers) {
        return Math.min(...centers.map((center) => distanceBetween(house, center)));
    }
    function nearestRouteCenterDistance(house, centers, routes) {
        return Math.min(...centers.map((center) => routeAwareDistance(center, house, routes)));
    }
    function routeAwareDistance(center, house, routes) {
        const anchor = nearestRouteAnchor(L.latLng(center.lat, center.lng), routes);
        return calculateLength(dropPathAlongRoute(anchor, house));
    }
    function groupCenter(group) {
        const center = group.reduce(
            (sum, house) => ({ lat: sum.lat + Number(house.lat), lng: sum.lng + Number(house.lng) }),
            { lat: 0, lng: 0 },
        );
        return { lat: center.lat / group.length, lng: center.lng / group.length };
    }
    function nearestRouteAnchor(point, routes) {
        let nearest = null;
        let nearestDistance = Infinity;
        routes.forEach((route) => {
            const path = normalizePoints(route.path);
            for (let index = 1; index < path.length; index++) {
                const projected = projectPointToSegment(point, path[index - 1], path[index]);
                const distance = MapEditor.map.distance(point, projected);
                if (distance < nearestDistance) {
                    nearest = { route, path, segmentIndex: index, latlng: projected };
                    nearestDistance = distance;
                }
            }
        });
        return nearest || { route: null, path: [], segmentIndex: 0, latlng: point };
    }
    function dropPathAlongRoute(cabinetAnchor, house) {
        const houseAnchor = nearestRouteAnchor(
            L.latLng(house.lat, house.lng),
            cabinetAnchor.route ? [cabinetAnchor.route] : data().routes,
        );
        if (!cabinetAnchor.route || houseAnchor.route !== cabinetAnchor.route)
            return [cabinetAnchor.latlng, [house.lat, house.lng]];
        const points = [cabinetAnchor.latlng];
        if (cabinetAnchor.segmentIndex <= houseAnchor.segmentIndex) {
            for (let index = cabinetAnchor.segmentIndex; index < houseAnchor.segmentIndex; index++)
                points.push(cabinetAnchor.path[index]);
        } else {
            for (let index = cabinetAnchor.segmentIndex - 1; index >= houseAnchor.segmentIndex; index--)
                points.push(cabinetAnchor.path[index]);
        }
        points.push(houseAnchor.latlng, [house.lat, house.lng]);
        return points;
    }
    function projectPointToSegment(point, start, end) {
        const projected = L.GeometryUtil?.closest
            ? L.GeometryUtil.closest(MapEditor.map, [start, end], point)
            : null;
        if (projected) return projected;
        const p = MapEditor.map.latLngToLayerPoint(point);
        const a = MapEditor.map.latLngToLayerPoint(start);
        const b = MapEditor.map.latLngToLayerPoint(end);
        const dx = b.x - a.x;
        const dy = b.y - a.y;
        const ratio = Math.max(0, Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / (dx * dx + dy * dy || 1)));
        return MapEditor.map.layerPointToLatLng([a.x + ratio * dx, a.y + ratio * dy]);
    }
    function nearestItem(point, items) {
        return items.filter(validPoint).sort(
            (a, b) => MapEditor.map.distance(point, [a.lat, a.lng]) - MapEditor.map.distance(point, [b.lat, b.lng]),
        )[0];
    }
    function distanceBetween(a, b) {
        return MapEditor.map.distance([a.lat, a.lng], [b.lat, b.lng]);
    }
    function clearSuggestions() {
        MapEditor.layers.suggestions.clearLayers();
        MapEditor.suggestions = [];
        data().houses.forEach((house) =>
            house.marker?.setIcon(markerIcon("house", "K", cabinetColor(house.cabinet_id))),
        );
    }
    function closeSuggestions() {
        document.getElementById("suggestion-modal").classList.add("hidden");
        clearSuggestions();
    }
    async function saveSuggestions() {
        if (!MapEditor.suggestions.length) return;
        const response = await request(config().suggestionUrl, "POST", {
            project_id: config().projectId,
            cabinets: MapEditor.suggestions.map(({ marker, max_drop_m, ...item }) => item),
        });
        if (!response) return;
        showToast(response.message || "ODO prijedlog je sacuvan.", "success");
        window.setTimeout(() => window.location.reload(), 500);
    }
    function segmentDistance(point, a, b) {
        const p = MapEditor.map.latLngToLayerPoint(point),
            x = MapEditor.map.latLngToLayerPoint(a),
            y = MapEditor.map.latLngToLayerPoint(b),
            dx = y.x - x.x,
            dy = y.y - x.y,
            t = Math.max(
                0,
                Math.min(
                    1,
                    ((p.x - x.x) * dx + (p.y - x.y) * dy) /
                        (dx * dx + dy * dy || 1),
                ),
            );
        return p.distanceTo(L.point(x.x + t * dx, x.y + t * dy));
    }
    function normalizePoints(points) {
        return (points || []).map((point) =>
            Array.isArray(point)
                ? [Number(point[0]), Number(point[1])]
                : [Number(point.lat), Number(point.lng)],
        );
    }
    function routeColor(type) {
        return colors[type] || colors.distribution;
    }
    function routeWeight(route) {
        return route.type === "feeder" ? 3 : route.type === "drop" ? 1.6 : 2.4;
    }
    function cabinetColor(cabinetId) {
        if (!cabinetId) return "#64748b";
        return cabinetPalette[(Number(cabinetId) - 1) % cabinetPalette.length];
    }
    function validPoint(item) {
        return (
            Number.isFinite(Number(item.lat)) &&
            Number.isFinite(Number(item.lng))
        );
    }
    function fitMapToData() {
        const points = [...data().odfs, ...data().cabinets, ...data().houses]
            .filter(validPoint)
            .map((item) => [item.lat, item.lng]);
        if (points.length)
            MapEditor.map.fitBounds(points, { padding: [35, 35], maxZoom: 16 });
    }
    function deselectCurrent(restorePanel = false) {
        if (MapEditor.selectedElement?.marker) {
            const markerElement =
                MapEditor.selectedElement.marker.getElement?.();
            markerElement?.classList.remove("selected");
        }
        if (MapEditor.selectedRoute) {
            const layer = MapEditor.routeLayers.get(MapEditor.selectedRoute.id);
            if (layer) {
                const normalWeight = routeWeight(MapEditor.selectedRoute);
                layer.line.setStyle({ weight: normalWeight });
                layer.nodes.forEach((node) => node.setRadius(MapEditor.selectedRoute.type === "drop" ? 2 : 3));
            }
            MapEditor.selectedRoute = null;
        }
        MapEditor.selectedElement = null;
        if (restorePanel && MapEditor.defaultHeading !== null) {
            heading().innerHTML = MapEditor.defaultHeading;
            details().innerHTML = MapEditor.defaultDetails;
        }
    }
    function showDetails(item) {
        deselectCurrent();
        MapEditor.selectedElement = item;
        heading().innerHTML = `<div class="flex items-center gap-3"><b>${item.elementType.toUpperCase()}</b><b>${item.name || item.label}</b></div>`;
        if (item.marker) {
            const markerElement = item.marker.getElement?.();
            markerElement?.classList.add("selected");
        }
        if (item.elementType === "route") {
            const layer = MapEditor.routeLayers.get(item.id);
            if (layer) {
                const normalWeight = routeWeight(item);
                layer.line.setStyle({ weight: normalWeight + 2 });
                layer.nodes.forEach((node) => node.setRadius(item.type === "drop" ? 3.5 : 4.5));
                MapEditor.selectedRoute = item;
            }
        }
        const routeActions = `<button data-panel-action="edit-data">Uredi podatke</button><button data-panel-action="edit-geometry">Uredi geometriju</button><button data-panel-action="delete-element">Obrisi</button>`;
        const markerActions = `<button data-panel-action="move-marker">Pomjeri</button><button data-panel-action="delete-element">Obrisi</button>`;
        details().innerHTML = `<dl class="grid grid-cols-[90px_1fr] gap-y-2 px-3 tiny"><dt>Tip:</dt><dd>${item.elementType}</dd><dt>Naziv:</dt><dd>${item.name || item.label}</dd>${item.elementType === "route" ? `<dt>Duzina:</dt><dd>${item.length} m</dd><dt>Tacke:</dt><dd>${normalizePoints(item.path).length}</dd><dt>Mikrocijev:</dt><dd>${item.microduct}</dd><dt>Kabal:</dt><dd>${item.fibers} niti</dd><dt>Napomena:</dt><dd>${item.note || "-"}</dd>` : ""}</dl><div class="property-actions">${item.elementType === "route" ? routeActions : markerActions}</div>`;
        details()
            .querySelector('[data-panel-action="edit-data"]')
            ?.addEventListener("click", () => openRouteModal(item));
        details()
            .querySelector('[data-panel-action="edit-geometry"]')
            ?.addEventListener("click", () => {
                setActiveTool("edit_route");
                startEditingRoute(item);
            });
        details()
            .querySelector('[data-panel-action="delete-element"]')
            ?.addEventListener("click", () => {
                if (item.elementType === "route") confirmDeleteRoute(item);
                else if (item.elementType === "odf") confirmDeleteOdf(item);
                else if (item.elementType === "odo") confirmDeleteCabinet(item);
                else if (item.elementType === "house") confirmDeleteHouse(item);
            });
        details()
            .querySelector('[data-panel-action="move-marker"]')
            ?.addEventListener("click", () => startMoveMarker(item));
    }
    function openRouteModal(route) {
        MapEditor.pendingRoute = route;
        const form = document.getElementById("route-form"),
            points = normalizePoints(route.path);
        form.elements.route_id.value = route.id || "";
        form.elements.name.value =
            route.name || `Trasa ${data().routes.length + 1}`;
        form.elements.route_type.value = route.type || "distribution";
        form.elements.odf_id.value = route.odf_id || "";
        form.elements.cabinet_id.value = route.cabinet_id || "";
        form.elements.microduct_type.value = route.microduct || "14/10";
        form.elements.fiber_count.value = route.fibers || 12;
        form.elements.note.value = route.note || "";
        document.getElementById("route-modal-points").textContent =
            `Tacke: ${points.length}`;
        document.getElementById("route-modal-length").textContent =
            `Duzina: ${calculateLength(points)} m`;
        document.getElementById("route-modal").classList.remove("hidden");
    }
    function closeRouteModal() {
        document.getElementById("route-modal").classList.add("hidden");
        MapEditor.pendingRoute = null;
    }
    async function saveRouteForm(event) {
        event.preventDefault();
        const form = event.currentTarget,
            values = Object.fromEntries(new FormData(form)),
            route = MapEditor.pendingRoute,
            points = normalizePoints(route.path);
        if (!validateRouteForm(form, values, points)) return;
        const edit = Boolean(values.route_id),
            url = edit
                ? config().routeUpdateUrl.replace("__ID__", values.route_id)
                : config().routeUrl;
        const payload = {
            project_id: config().projectId,
            name: values.name,
            route_type: values.route_type,
            odf_id: values.odf_id || null,
            cabinet_id: values.cabinet_id || null,
            microduct_type: values.microduct_type,
            fiber_count: Number(values.fiber_count),
            note: values.note || null,
        };
        if (!edit)
            Object.assign(payload, {
                installation_type: "underground",
                duct_length_m: calculateLength(points),
                fiber_length_m: calculateLength(points),
                microduct_count: 1,
                status: "planned",
                path: JSON.stringify(points),
            });
        const response = await request(url, edit ? "PATCH" : "POST", payload);
        if (!response?.route) return;
        if (edit) Object.assign(route, response.route);
        else {
            data().routes.push(response.route);
            appendRouteRow(response.route);
            updateRouteStats(response.route);
        }
        closeRouteModal();
        cancelDrawingRoute();
        rerenderRoutes();
        refreshDashboardUi();
        showToast("Trasa je sacuvana.", "success");
    }
    function startMoveMarker(item) {
        if (!item.marker) return;
        MapEditor.moving = {
            item,
            marker: item.marker,
            original: item.marker.getLatLng(),
        };
        item.marker.dragging.enable();
        MapEditor.dirty = true;
        document.getElementById("map-move-actions").classList.remove("hidden");
        setStatus("Pomjeri marker, zatim sacuvaj poziciju ili ponisti.");
    }
    async function saveMarkerPosition() {
        const move = MapEditor.moving;
        if (!move) return;
        const latlng = move.marker.getLatLng(),
            url = config().positionUrls[move.item.elementType]?.replace(
                "__ID__",
                move.item.id,
            );
        const response = await request(url, "PATCH", {
            latitude: latlng.lat,
            longitude: latlng.lng,
        });
        if (!response) return;
        move.item.lat = response.latitude;
        move.item.lng = response.longitude;
        rebuildSnapTargets();
        refreshDashboardUi();
        finishMarkerMove();
        showToast("Pozicija je sacuvana.", "success");
    }
    function cancelMarkerPosition() {
        const move = MapEditor.moving;
        if (!move) return;
        move.marker.setLatLng(move.original);
        finishMarkerMove();
    }
    function finishMarkerMove() {
        MapEditor.moving?.marker.dragging.disable();
        MapEditor.moving = null;
        MapEditor.dirty = false;
        document.getElementById("map-move-actions").classList.add("hidden");
    }
    async function request(url, method, payload = null, options = {}) {
        try {
            if (!config().projectId && method === "POST")
                throw new Error("Prvo kreiraj projekat.");
            const response = await fetch(url, {
                method,
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": config().csrf,
                },
                body: payload ? JSON.stringify(payload) : null,
                ...options,
            });
            const json = await response.json();
            if (!response.ok)
                throw new Error(
                    Object.values(json.errors || {})[0]?.[0] ||
                        json.message ||
                        "Zahtjev nije uspio.",
                );
            return json;
        } catch (error) {
            showToast(error.message, "error");
            if (options.signal) throw error;
            return null;
        }
    }
    async function addOdf(latlng) {
        const name = prompt(
            "Naziv ODF-a",
            `ODF-${String(data().odfs.length + 1).padStart(2, "0")}`,
        );
        if (!name) return;
        const response = await request(config().odfUrl, "POST", {
            project_id: config().projectId,
            name,
            address: "Sa mape",
            fiber_capacity: 144,
            port_count: 48,
            latitude: latlng.lat,
            longitude: latlng.lng,
        });
        if (response?.odf) {
            data().odfs.push(response.odf);
            addAssetMarker(response.odf, "odf", MapEditor.layers.odfs);
            showToast("ODF je dodat.", "success");
        }
    }
    async function addOdo(latlng) {
        const name = prompt(
            "Naziv ODO ormarica",
            `ODO-${String(data().cabinets.length + 1).padStart(2, "0")}`,
        );
        if (!name) return;
        const response = await request(config().cabinetUrl, "POST", {
            project_id: config().projectId,
            odf_id: data().odfs[0]?.id || null,
            name,
            address: "Sa mape",
            splitter_count: 3,
            ports_per_splitter: 4,
            latitude: latlng.lat,
            longitude: latlng.lng,
        });
        if (response?.cabinet) {
            data().cabinets.push(response.cabinet);
            addAssetMarker(response.cabinet, "odo", MapEditor.layers.cabinets);
            showToast("ODO je dodat.", "success");
        }
    }
    async function addHouse(latlng) {
        const name = prompt(
            "Naziv kuce",
            `K-${String(data().houses.length + 1).padStart(3, "0")}`,
        );
        if (!name) return;
        const response = await request(config().houseUrl, "POST", {
            project_id: config().projectId,
            cabinet_id: data().cabinets[0]?.id || null,
            label: name,
            address: "Sa mape",
            latitude: latlng.lat,
            longitude: latlng.lng,
            status: "planned",
        });
        if (response?.house) {
            data().houses.push(response.house);
            addAssetMarker(response.house, "house", MapEditor.layers.houses);
            showToast("Kuca je dodata.", "success");
        }
    }
    function appendRouteRow(route) {
        const body = document.getElementById("dashboard-routes-body");
        if (!body) return;
        body.querySelector("[data-empty-row]")?.remove();
        const row = document.createElement("tr");
        row.className = "border-t";
        row.innerHTML = `<td>${body.children.length + 1}</td><td>${route.from}</td><td>${route.to}</td><td>${route.type}</td><td>${route.length} m</td><td>${route.microduct}</td><td>${route.fibers} niti</td>`;
        body.prepend(row);
        while (body.children.length > 5) body.lastElementChild.remove();
    }
    function updateRouteStats(route) {
        document.querySelectorAll("[data-stat]").forEach((element) => {
            let next = Number(element.dataset.value || 0),
                key = element.dataset.stat;
            if (
                key === "routes_m" ||
                (key === "microduct_14_10" && route.microduct === "14/10") ||
                (key === "microduct_10_8" && route.microduct === "10/8") ||
                (key === "fiber_4" && route.fibers === 4) ||
                (key === "fiber_12" && route.fibers === 12)
            )
                next += route.length;
            element.dataset.value = next;
            element.textContent = `${(next / 1000).toFixed(2)} km`;
        });
    }
    function refreshRoutesTable() {
        const body = document.getElementById("dashboard-routes-body");
        if (!body) return;
        body.innerHTML = "";
        if (data().routes.length === 0) {
            body.innerHTML = `<tr data-empty-row class="border-t"><td colspan="7" class="px-4 py-6 text-center text-slate-400">Nema trasa.</td></tr>`;
            return;
        }
        data()
            .routes.slice(0, 5)
            .forEach((route, index) => {
                const row = document.createElement("tr");
                row.className = "border-t";
                const odfName = route.odf?.name || route.from || "-";
                const cabinetName = route.cabinet?.name || route.to || "-";
                row.innerHTML = `<td>${index + 1}</td><td>${odfName}</td><td>${cabinetName}</td><td>${route.route_type || route.type}</td><td>${route.duct_length_m || route.length || 0} m</td><td>${route.microduct_type || route.microduct}</td><td>${route.fiber_count || route.fibers} niti</td>`;
                body.appendChild(row);
            });
    }
    function recalculateStats() {
        const stats = {};
        stats.routes_m = 0;
        stats.microduct_14_10 = 0;
        stats.microduct_10_8 = 0;
        stats.fiber_4 = 0;
        stats.fiber_12 = 0;
        data().routes.forEach((route) => {
            const length = route.duct_length_m || route.length || 0;
            stats.routes_m += length;
            const microduct = route.microduct_type || route.microduct;
            const fibers = route.fiber_count || route.fibers;
            if (microduct === "14/10") stats.microduct_14_10 += length;
            if (microduct === "10/8") stats.microduct_10_8 += length;
            if (fibers === 4) stats.fiber_4 += length;
            if (fibers === 12) stats.fiber_12 += length;
        });
        document.querySelectorAll("[data-stat]").forEach((element) => {
            const key = element.dataset.stat;
            if (stats[key] !== undefined) {
                element.dataset.value = stats[key];
                element.textContent = `${(stats[key] / 1000).toFixed(2)} km`;
            }
        });
        const counts = {
            cabinets: data().cabinets.length,
            houses: data().houses.length,
        };
        document.querySelectorAll("[data-count-stat]").forEach((element) => {
            if (counts[element.dataset.countStat] !== undefined)
                element.textContent = counts[element.dataset.countStat];
        });
    }
    function refreshDashboardUi() {
        refreshRoutesTable();
        recalculateStats();
    }
    function validateRouteForm(form, values, points) {
        form.querySelectorAll(".field-error").forEach((element) => element.remove());
        form.querySelectorAll(".invalid").forEach((element) => element.classList.remove("invalid"));
        const errors = {
            name: !values.name?.trim() ? "Naziv trase je obavezan." : "",
            route_type: !values.route_type ? "Tip trase je obavezan." : "",
            microduct_type: !values.microduct_type ? "Mikrocijev je obavezna." : "",
            fiber_count: !values.fiber_count ? "Kabal je obavezan." : "",
        };
        Object.entries(errors).forEach(([name, message]) => {
            if (!message) return;
            const field = form.elements[name];
            field.classList.add("invalid");
            field.insertAdjacentHTML("afterend", `<span class="field-error">${message}</span>`);
        });
        if (points.length < 2) showToast("Trasa mora imati najmanje dvije tacke.", "error");
        else if (Object.values(errors).some(Boolean)) showToast("Popuni obavezna polja trase.", "error");
        return points.length >= 2 && !Object.values(errors).some(Boolean);
    }
    function onKeyDown(event) {
        const tag = event.target?.tagName?.toLowerCase();
        if (["input", "select", "textarea"].includes(tag) || event.target?.closest?.(".route-modal")) return;
        if (event.key === "Escape") {
            cancelDrawingRoute();
            cancelMeasure();
            cancelEditedRoute();
            cancelMarkerPosition();
            closeRouteModal();
            closeDeleteModal();
            setActiveTool("select");
        }
        if (event.key === "Enter" && MapEditor.activeTool === "draw_route") {
            event.preventDefault();
            finishDrawingRoute();
        }
        if (
            event.key === "Backspace" &&
            MapEditor.activeTool === "draw_route"
        ) {
            event.preventDefault();
            removeLastRoutePoint();
        }
        if (event.key === "Delete") {
            if (MapEditor.selectedRoute) confirmDeleteRoute(MapEditor.selectedRoute);
            else if (MapEditor.selectedElement) confirmDeleteElement(MapEditor.selectedElement);
        }
        const key = event.key.toLowerCase();
        const shortcuts = { s: "select", l: "draw_route", m: "measure", e: "edit_route" };
        if (shortcuts[key]) {
            event.preventDefault();
            setActiveTool(shortcuts[key]);
        }
        if (key === "o") {
            event.preventDefault();
            MapEditor.ortho = !MapEditor.ortho;
            setStatus(toolInstruction(MapEditor.activeTool));
        }
    }
    function showToast(message, type = "") {
        const toast = document.getElementById("map-toast");
        if (!toast) return;
        toast.textContent = message;
        toast.className = `map-toast ${type}`;
        clearTimeout(showToast.timer);
        showToast.timer = setTimeout(() => toast.classList.add("hidden"), 3200);
    }
    document.addEventListener("DOMContentLoaded", () => {
        if (!document.getElementById("dashboard-map")) return;
        initMap();
        initToolbar();
        MapEditor.defaultHeading = heading().innerHTML;
        MapEditor.defaultDetails = details().innerHTML;
        renderAll();
        setActiveTool("select");
    });
})();
