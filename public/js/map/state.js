// ── GLOBAL STATE ─────────────────────────────────────────────────────────────
const data = window.ftthMapConfig.data;
const appConfig = window.ftthMapConfig.endpoints;
const defaultCenter = [44.4493, 18.6498];
const map = L.map('network-map', { zoomSnap: 0.25 }).setView(defaultCenter, 17);
window.ftthNetworkMap = map;
// The map's container height depends on sibling panels (branch list, status bar,
// accordions) that change size as the user works — without this, Leaflet keeps
// stale pixel dimensions and every click/snap/pixel calc drifts off the real map.
new ResizeObserver(() => map.invalidateSize()).observe(document.getElementById('network-map'));
let mode = 'pan';
let mapViewMode = 'cad';
let activeBranch = [];
let activeBranchSnapTargets = [];
let quickBranchWorkflow = false;
let activeBranchMarkers = [];
let activeBranchLine = null;
let previewBranchLine = null;
let traceBranchStart = null;
let traceBranchStartSnap = null;
let traceBranchPreviewLine = null;
let snapIndicator = null;
let routeEdit = null;
let selectedAttributeRoute = null;
let routeStackPick = { signature: '', index: -1 };
let highlightedPickedRouteId = null;
let routeSelectionMarkers = [];
const selectionRegistry = []; // { triggerLayer, allLayers, url, title, origStyle }
let currentSelection = [];    // trenutno selektovani elementi
let connectOdf = null;
let connectCabinet = null;
let connectHouseIds = new Set();
let joinRoutes = [];
let validationHighlightLayers = [];
let branches = [];
let branchLines = [];
let branchMeta = [];
let branchLabels = [];
let branchLabelGroups = [];
let trenchLines = [];
let housePoints = [];
let draftHouseMeta = [];
let houseMarkers = [];
let houseMarkerByKey = {};
let houseMarkerById = {};
let suggestionLayers = [];
let draftOdfCount = 0;
let draftCabinetCount = 0;
let draftElements = [];
let draftOdfs = [];
let draftCabinets = [];
let draftAppendixItems = [];
let suggestedCabinets = [];
let expandedMap = false;
let activeDraftOdfIndex = null;
let activeOdfSelection = null;
let cadContext = null;
let autosaveTimer = null;
let autosaveReady = false;
let restoringDraft = false;
let selectedDraftElement = null;
let preflightIssues = [];
let keepCurrentDraftOnProjectChange = false;
let savedRoutePoints = [];
const snapPixelTolerance = 30;
let orthoEnabled = false;
let osmRoutingEnabled = false;
let osmRoutingLoading = false;
let gisRoutingEnabled = false;
let currentAutoPlan = null;
let currentGisPlan = null;
const undoStack = [];
const redoStack = [];
const routeEditUndoStack = [];
const routeEditRedoStack = [];
const mapHistory = {
    stack: [],
    histRedoStack: [],
    busy: false,
    maxSize: 30,
    push(cmd) {
        if (this.busy) return;
        this.stack.push(cmd);
        if (this.stack.length > this.maxSize) this.stack.shift();
        this.histRedoStack = [];
        updateMapHistoryUI();
    },
    async undo() {
        if (this.busy || !this.stack.length) return;
        const cmd = this.stack.pop();
        this.busy = true;
        try {
            await cmd.undo();
            this.histRedoStack.push(cmd);
            document.getElementById('cad-command').textContent = `Undo: ${cmd.label}`;
        } catch (e) {
            document.getElementById('cad-command').textContent = `Undo nije uspio: ${e.message}`;
        } finally {
            this.busy = false;
        }
        updateMapHistoryUI();
    },
    async redo() {
        if (this.busy || !this.histRedoStack.length) return;
        const cmd = this.histRedoStack.pop();
        this.busy = true;
        try {
            await cmd.redo();
            this.stack.push(cmd);
            document.getElementById('cad-command').textContent = `Redo: ${cmd.label}`;
        } catch (e) {
            document.getElementById('cad-command').textContent = `Redo nije uspio: ${e.message}`;
        } finally {
            this.busy = false;
        }
        updateMapHistoryUI();
    },
};
const odfMarkerById = {};
const cabinetMarkerById = {};
const routeLayerById = {};
const routeHitLayerById = {};
const routeLabelsById = {};
let activeTraceHouseId = null;
let colorByFibers = false;
let showCableSpecs = true;
let rulerStart = null;
let rulerLine = null;
let rulerStartMarker = null;
let rulerEndMarker = null;
let rulerLabelMarker = null;
const layerRegistry = {
    odf: [],
    odo: [],
    houses: [],
    trench: [],
    backbone: [],
    distribution: [],
    drop: [],
    gis: [],
    dxf: [],
    preview: [],
    measure: [],
    trace: [],
};
const layerLocks = {};
const layerOpacity = {};
const draftsByProject = {};
const deleteUrls = {
    odf: id => `${appConfig.odfsBase}/${id}`,
    cabinet: id => `${appConfig.cabinetsBase}/${id}`,
    house: id => `${appConfig.housesBase}/${id}`,
    route: id => `${appConfig.routesBase}/${id}`,
};
const positionUrls = {
    odf: id => `${appConfig.odfsBase}/${id}/pozicija`,
    cabinet: id => `${appConfig.cabinetsBase}/${id}/pozicija`,
    house: id => `${appConfig.housesBase}/${id}/pozicija`,
};

// Additional globals needed by functions but initialized later in init.js
const cabinetPalette = [
    '#2563eb', '#dc2626', '#16a34a', '#f59e0b', '#7c3aed', '#0891b2', '#db2777',
    '#65a30d', '#ea580c', '#0f766e', '#9333ea', '#be123c', '#4f46e5', '#ca8a04',
];
const savedHouseKeys = new Set();
const savedHouseColorByKey = {};
const houseDataByKey = {};
let savedHouseCount = 0;
let splitPreview = null;
// Tile layer references — populated in init.js so applyMapViewMode can toggle them
let imagery = null;
let osm = null;
let cartodbDark = null;
