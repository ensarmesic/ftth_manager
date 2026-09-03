    /* ── Snap indicator ─────────────────────────────────────────── */
    @keyframes snap-pulse {
        0%   { transform: translate(-50%,-50%) scale(1);    opacity: .85; }
        55%  { transform: translate(-50%,-50%) scale(1.6);  opacity: .15; }
        100% { transform: translate(-50%,-50%) scale(1);    opacity: .85; }
    }
    .snap-wrap { position: relative; width: 0; height: 0; pointer-events: none; }
    .snap-ring {
        width: 18px; height: 18px; border-radius: 2px;
        border: 2px solid var(--sc); position: absolute;
        top: 0; left: 0; transform: translate(-50%,-50%);
        box-shadow: 0 0 0 1px rgba(255,255,255,.88), 0 0 8px rgba(0,0,0,.35);
    }
    .snap-midpoint .snap-ring {
        width: 20px;
        height: 18px;
        border: 0;
        border-radius: 0;
        background: var(--sc);
        clip-path: polygon(50% 0, 100% 100%, 0 100%, 50% 0, 50% 18%, 17% 88%, 83% 88%, 50% 18%);
        box-shadow: none;
    }
    .snap-node .snap-ring { border-radius: 50%; }
    .snap-nearest .snap-ring { width: 15px; height: 15px; transform: translate(-50%,-50%) rotate(45deg); }
    .snap-odf .snap-ring,
    .snap-cabinet .snap-ring,
    .snap-house .snap-ring { border-radius: 50%; }
    .snap-dot {
        width: 7px; height: 7px; border-radius: 50%;
        background: var(--sc); position: absolute;
        top: 0; left: 0; transform: translate(-50%,-50%);
        border: 1.5px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.18);
    }
    .snap-lbl {
        position: absolute; left: 13px; top: -19px;
        background: #0f172a; color: #fff;
        font: 700 10px/1.4 system-ui, sans-serif;
        padding: 2px 7px; border-radius: 5px;
        white-space: nowrap;
        box-shadow: 0 2px 6px rgba(0,0,0,.3);
    }
    .snap-lbl::after {
        content: ''; position: absolute;
        top: 100%; left: 10px;
        border: 4px solid transparent;
        border-top-color: #0f172a;
    }
    .snap-lbl b { color: var(--sc); font-weight: 900; }
    .leaflet-snap-active { cursor: crosshair !important; }
    .leaflet-snap-active .leaflet-grab { cursor: crosshair !important; }
    #cad-crosshair {
        --cad-x: -100px;
        --cad-y: -100px;
        position: absolute;
        z-index: 1490;
        left: var(--cad-x);
        top: var(--cad-y);
        display: none;
        width: 0;
        height: 0;
        pointer-events: none;
        filter: drop-shadow(0 1px 1px rgba(0,0,0,.75));
    }
    #map-container.cad-crosshair-active #cad-crosshair { display: block; }
    #cad-crosshair i,
    #cad-crosshair b {
        position: absolute;
        display: block;
        background: rgba(239,246,255,.92);
        content: '';
    }
    #cad-crosshair i { left: -18px; top: 0; width: 36px; height: 1px; }
    #cad-crosshair b { left: 0; top: -18px; width: 1px; height: 36px; }
    #cad-crosshair span {
        position: absolute;
        left: -3px;
        top: -3px;
        width: 7px;
        height: 7px;
        border: 1px solid #38bdf8;
        background: transparent;
    }
    #cad-dynamic-input {
        position: absolute;
        left: 22px;
        top: 15px;
        display: none;
        min-width: max-content;
        padding: 4px 7px;
        border: 1px solid rgba(56,189,248,.85);
        border-radius: 3px;
        background: rgba(3,15,30,.94);
        color: #dff6ff;
        box-shadow: 0 5px 14px rgba(0,0,0,.38);
        font: normal 700 10px/1.25 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        letter-spacing: .01em;
        white-space: nowrap;
    }
    #cad-dynamic-input.is-visible { display: block; }
    #cad-dynamic-input strong { color: #38bdf8; font-weight: 850; }
    #map-container.cad-crosshair-active .leaflet-container,
    #map-container.cad-crosshair-active .leaflet-grab { cursor: none !important; }
    #map-container.cad-temporary-pan .leaflet-container,
    #map-container.cad-temporary-pan .leaflet-grab,
    #map-container.cad-temporary-pan .leaflet-dragging .leaflet-grab { cursor: grabbing !important; }
    #map-container.cad-temporary-pan #cad-crosshair i,
    #map-container.cad-temporary-pan #cad-crosshair b,
    #map-container.cad-temporary-pan #cad-crosshair span { display: none; }
    #map-container.cad-grid-visible::after {
        position: absolute;
        z-index: 800;
        inset: 0;
        pointer-events: none;
        background-image:
            linear-gradient(rgba(56,189,248,.12) 1px, transparent 1px),
            linear-gradient(90deg, rgba(56,189,248,.12) 1px, transparent 1px),
            linear-gradient(rgba(56,189,248,.22) 1px, transparent 1px),
            linear-gradient(90deg, rgba(56,189,248,.22) 1px, transparent 1px);
        background-size: 24px 24px, 24px 24px, 120px 120px, 120px 120px;
        content: '';
    }
    #select-rubber-band {
        position: absolute;
        z-index: 2000;
        display: none;
        box-sizing: border-box;
        pointer-events: none;
        border: 1px solid #60a5fa;
        background: rgba(37,99,235,.13);
    }
    #select-rubber-band.is-crossing {
        border-color: #4ade80;
        border-style: dashed;
        background: rgba(22,163,74,.13);
    }
    #select-rubber-band::after {
        position: absolute;
        left: 4px;
        top: 4px;
        padding: 2px 4px;
        border-radius: 2px;
        background: rgba(2,12,27,.88);
        color: #93c5fd;
        content: attr(data-selection-mode);
        font: 800 8px/1 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        letter-spacing: .08em;
    }
    #select-rubber-band.is-crossing::after { color: #86efac; }
    .cad-ucs {
        position: absolute;
        z-index: 1490;
        left: 68px;
        bottom: 12px;
        width: 48px;
        height: 48px;
        pointer-events: none;
        color: #dff6ff;
        filter: drop-shadow(0 1px 2px rgba(0,0,0,.8));
        font: 800 9px/1 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    }
    .cad-ucs i,
    .cad-ucs b { position: absolute; left: 8px; bottom: 9px; display: block; background: #e0f2fe; }
    .cad-ucs i { width: 1px; height: 30px; }
    .cad-ucs b { width: 30px; height: 1px; }
    .cad-ucs i::after,
    .cad-ucs b::after { position: absolute; content: ''; width: 6px; height: 6px; border-color: #e0f2fe; }
    .cad-ucs i::after { left: -3px; top: 0; border-left: 1px solid; border-top: 1px solid; transform: rotate(45deg); }
    .cad-ucs b::after { right: 0; top: -3px; border-right: 1px solid; border-top: 1px solid; transform: rotate(45deg); }
    .cad-ucs span { position: absolute; left: 5px; top: 0; }
    .cad-ucs em { position: absolute; right: 3px; bottom: 6px; font-style: normal; }
    .cad-ucs small { position: absolute; left: 14px; bottom: -2px; color: #38bdf8; font-size: 7px; }
    /* ── Map element styles (unchanged) ─────────────────────────── */
    .ftth-label { border: 0; background: transparent; }
    .ftth-tag { position: absolute; left: 1px; top: 1px; transform: translate(-50%, -50%); color: #fff; font: 800 9px/1 system-ui, sans-serif; display: grid; place-items: center; }
    .ftth-tag.odf {
        width: 28px;
        height: 20px;
        box-sizing: border-box;
        border: 2px solid #fff;
        border-radius: 4px;
        background: linear-gradient(180deg, #1976c9 0%, #0f5fa8 100%);
        box-shadow: 0 0 0 1.25px #0f172a, 0 3px 7px rgb(15 23 42 / .28);
        color: #fff;
        font-size: 8px;
        font-weight: 950;
        line-height: 1;
        letter-spacing: -.15px;
        white-space: nowrap;
        overflow: hidden;
        text-align: center;
    }
    .ftth-tag.cabinet { width: 14px; height: 14px; border: 2px solid #fff; border-radius: 2px; background: #16a34a; box-shadow: 0 0 0 1px #0f172a; font-size: 0; }
    .ftth-tag.cabinet.ftth-cabinet-tag { width: auto; height: auto; min-width: 0; min-height: 0; padding: 0; border: 0; border-radius: 0; background: transparent !important; box-shadow: none; display: flex; align-items: center; gap: 4px; transform: translate(-8px, -50%); }
    .ftth-cabinet-symbol { display: flex; align-items: center; justify-content: center; width: 22px; height: 14px; flex: 0 0 auto; border: 2px solid #fff; border-radius: 2px; box-shadow: 0 0 0 1px #0f172a; font: 900 6.5px/1 system-ui, sans-serif; color: #fff; letter-spacing: .3px; }
    .ftth-cabinet-text { display: inline-flex; align-items: center; gap: 2px; padding: 1px 3px; background: rgba(255,255,255,.86); color: #0f172a; text-shadow: 0 1px 0 #fff; border: 1px solid rgba(15,23,42,.28); white-space: nowrap; }
    .ftth-cabinet-title { display: block; font-size: 7px; font-weight: 900; line-height: 1; letter-spacing: 0; opacity: .75; }
    .ftth-cabinet-code { display: block; font-size: 9px; font-weight: 900; line-height: 1.05; letter-spacing: 0; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .ftth-tag.house { width: 14px; height: 14px; border: 2px solid #fff; border-radius: 999px; background: #16a34a; box-shadow: 0 0 0 1px #0f172a; font-size: 8px; font-weight: 900; }
    .ftth-route-drop {
        filter: drop-shadow(0 0 1px rgba(255,255,255,.98)) drop-shadow(0 0 1px rgba(15,23,42,.45));
    }
    .ftth-house-icon .ftth-tag { left: 50%; top: 50%; }
    .ftth-tag.suggest { width: 12px; height: 12px; border: 2px solid #fff; border-radius: 2px; background: #f59e0b; box-shadow: 0 0 0 1px #0f172a; font-size: 0; }
    .ftth-tag.manhole { width: 15px; height: 15px; border: 2px solid #fff; border-radius: 1px; background: #334155; box-shadow: 0 0 0 1px #0f172a; font-size: 8px; }
    .ftth-tag.boring { width: 10px; height: 10px; border-radius: 2px; background: #dc2626; font-size: 0; transform: translate(-50%, -50%) rotate(45deg); }
    .ftth-tag.splice { width: 12px; height: 12px; border: 2px solid #fff; border-radius: 50%; background: #7c3aed; box-shadow: 0 0 0 1px #0f172a; font-size: 0; }
    .ftth-tag.loop { width: 12px; height: 12px; border: 2px solid #fff; border-radius: 2px; background: #0891b2; box-shadow: 0 0 0 1px #0f172a; font-size: 0; transform: translate(-50%, -50%) rotate(45deg); }
    .boring-grip { border: 0; background: transparent; }
    .boring-grip span {
        display: grid;
        width: 8px;
        height: 8px;
        place-items: center;
        border: 1.5px solid #fff;
        border-radius: 2px;
        background: #f59e0b;
        color: #111827;
        box-shadow: 0 1px 4px rgba(15, 23, 42, .28);
        font-size: 0;
        transform: rotate(45deg);
    }
    .boring-length-label { border: 0; background: transparent; pointer-events: none; }
    .boring-length-label span {
        display: block;
        transform: translate(-50%, -50%);
        white-space: nowrap;
        border: 1px solid #fecaca;
        border-radius: 5px;
        background: rgba(255, 255, 255, .95);
        color: #991b1b;
        box-shadow: 0 6px 16px rgba(15, 23, 42, .16);
        padding: 2px 6px;
        font: 800 11px/1.2 system-ui, sans-serif;
    }
    .cad-popup .leaflet-popup-content-wrapper { border-radius: 6px; padding: 0; }
    .cad-popup .leaflet-popup-content { width: 92px !important; margin: 0; }
    .cad-popup .leaflet-popup-close-button { width: 16px; height: 16px; padding: 0; font-size: 14px; line-height: 14px; }
    .cad-popup .leaflet-popup-tip { width: 10px; height: 10px; }
    .route-label {
        border: 0;
        background: transparent;
        pointer-events: none;
    }
    .route-label span {
        display: block;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
        color: #000;
        font: 900 10px/1 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        letter-spacing: 0;
        padding: 0 2px;
        text-shadow:
            -1px -1px 0 #fff,
            1px -1px 0 #fff,
            -1px 1px 0 #fff,
            1px 1px 0 #fff,
            0 0 3px #fff,
            0 0 5px #fff;
        white-space: nowrap;
    }
    #map-workspace.zoom-high .route-label span {
        font-size: 11px;
        padding: 0 2px;
        background: transparent;
    }
    #map-workspace.zoom-low .route-label span {
        font-size: 10px;
        padding: 0 2px;
    }
    #map-workspace.zoom-far .route-label { display: none; }
    #map-workspace.zoom-far .ftth-tag.odf { display: none; }
    #map-workspace.zoom-far .ftth-cabinet-tag { display: none; }
    #map-workspace.zoom-far .ftth-tag.house { display: none; }
    #map-workspace.zoom-far .ftth-tag.suggest { display: none; }
    #map-workspace.zoom-far .ftth-tag.manhole { display: none; }
    #map-workspace.zoom-far .ftth-tag.boring { display: none; }
    #map-workspace.zoom-far .ftth-tag.splice { display: none; }
    #map-workspace.zoom-far .ftth-tag.loop { display: none; }
    #map-workspace.zoom-far .boring-length-label { display: none; }
    /* Zoom-based scaling: markers grow with the map so they stay readable when zoomed in */
    #map-workspace.z20 .ftth-tag:not(.ftth-cabinet-tag) { transform: translate(-50%,-50%) scale(1.6); }
    #map-workspace.z21 .ftth-tag:not(.ftth-cabinet-tag) { transform: translate(-50%,-50%) scale(2.4); }
    #map-workspace.z22 .ftth-tag:not(.ftth-cabinet-tag) { transform: translate(-50%,-50%) scale(3.2); }
    #map-workspace.z20 .ftth-cabinet-tag { transform: translate(-8px,-50%) scale(1.6); transform-origin: 8px 50%; }
    #map-workspace.z21 .ftth-cabinet-tag { transform: translate(-8px,-50%) scale(2.4); transform-origin: 8px 50%; }
    #map-workspace.z22 .ftth-cabinet-tag { transform: translate(-8px,-50%) scale(3.2); transform-origin: 8px 50%; }
    #map-workspace.z20 .route-label span { font-size: 16px; }
    #map-workspace.z21 .route-label span { font-size: 22px; }
    #map-workspace.z22 .route-label span { font-size: 30px; }
    .cad-map-legend {
        border: 1px solid rgba(15,23,42,.45);
        background: rgba(255,255,255,.78);
        color: #0f172a;
        font: 800 9px/1.2 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        padding: 5px 6px;
    }
    .cad-map-legend b { display: block; margin-bottom: 4px; font-size: 9px; }
    .cad-map-legend div { display: grid; grid-template-columns: 26px 1fr; align-items: center; gap: 5px; margin-top: 3px; white-space: nowrap; }
    .cad-line-sample { height: 0; border-top: 2px solid currentColor; }
    .cad-line-sample.dashed { border-top-style: dashed; }
    .cad-point-sample { width: 10px; height: 10px; border: 2px solid #fff; box-shadow: 0 0 0 1px #0f172a; justify-self: start; }
    .cad-point-sample.circle { border-radius: 999px; }
    @media (max-width: 767px) {
        .cad-map-legend {
            transform: scale(.78);
            transform-origin: bottom right;
            opacity: .9;
        }
        #map-stats-bar {
            max-width: calc(100% - 92px);
            gap: 2px !important;
        }
        #map-stats-bar > * {
            padding: 3px 6px !important;
            font-size: 9px !important;
        }
        .ftth-tag.house { width: 13px; height: 13px; font-size: 7.5px; }
        .ftth-cabinet-text { display: none; }
    }
    .cad-status {
        border-top: 1px solid rgba(15, 23, 42, .12);
        background: #0f172a;
        color: #e2e8f0;
        font: 600 12px/1.2 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    }
    .cad-chip {
        border: 1px solid rgba(148, 163, 184, .35);
        background: rgba(15, 23, 42, .8);
    }
    #cad-command {
        overflow: hidden;
        color: #e0f2fe;
        font-weight: 750;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .cad-command-line {
        display: grid;
        min-width: 260px;
        grid-template-columns: minmax(110px, 1fr) minmax(190px, .75fr);
        align-items: center;
        gap: 8px;
    }
    .cad-command-line label {
        display: flex;
        min-width: 0;
        align-items: center;
        gap: 5px;
        color: #38bdf8;
    }
    #cad-command-input {
        width: 100%;
        min-width: 0;
        border: 1px solid rgba(56,189,248,.35);
        border-radius: 3px;
        outline: none;
        background: rgba(2,12,27,.82);
        color: #e0f2fe;
        padding: 3px 7px;
        font: 700 11px/1.2 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        text-transform: uppercase;
    }
    #cad-command-input:focus {
        border-color: #38bdf8;
        box-shadow: 0 0 0 2px rgba(56,189,248,.13);
    }
    #cad-command-input::placeholder { color: #56708c; text-transform: none; }
    .cad-toggle-strip { display: flex; align-items: stretch; gap: 3px; }
    .cad-toggle-strip button {
        display: flex;
        align-items: center;
        gap: 4px;
        border: 1px solid rgba(112,151,194,.2);
        border-radius: 3px;
        background: rgba(12,31,53,.72);
        color: #7188a1;
        padding: 3px 6px;
        font: 750 9px/1 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        cursor: pointer;
    }
    .cad-toggle-strip button b { color: #526a83; font-size: 8px; }
    .cad-toggle-strip button.is-on { border-color: rgba(56,189,248,.62); background: rgba(7,89,133,.48); color: #dff6ff; }
    .cad-toggle-strip button.is-on b { color: #38bdf8; }
    @media (max-width: 900px) {
        .cad-command-line { grid-template-columns: 1fr; }
    }
    #map-workspace.gis-view .route-label span,
    #map-workspace.gis-view .ftth-cabinet-text {
        background: rgba(15,23,42,.78);
        border-color: rgba(255,255,255,.75);
        color: #fff;
        text-shadow: none;
    }
    #map-container { min-height: 620px; }
    #network-map { width: 100%; height: 100%; }
    @media (min-width: 1280px) {
        #map-container { min-height: 0; }
    }
    /* --- Dark CAD mode --- */
    #map-workspace.cad-dark .route-label span {
        color: #e2e8f0;
        background: transparent;
        text-shadow: 0 0 4px #000, 0 0 2px #000, -1px -1px 0 #000, 1px 1px 0 #000;
    }
    #map-workspace.cad-dark .ftth-cabinet-text {
        background: rgba(0,0,0,.8);
        border-color: rgba(255,255,255,.45);
        color: #e2e8f0;
        text-shadow: none;
    }
    #map-workspace.cad-dark .cad-map-legend {
        background: rgba(15,23,42,.88);
        border-color: rgba(255,255,255,.25);
        color: #cbd5e1;
    }
    /* --- Ruler tool --- */
    .ruler-label { border: 0; background: transparent; pointer-events: none; }
    .ruler-label span {
        display: block;
        transform: translate(-50%, -100%) translateY(-6px);
        white-space: nowrap;
        border: 1px solid #fca5a5;
        border-radius: 4px;
        background: rgba(255,255,255,.95);
        color: #b91c1c;
        font: 800 11px/1.2 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        padding: 2px 6px;
        box-shadow: 0 4px 12px rgba(0,0,0,.15);
    }
    /* --- Fiber color legend chip --- */
    .fiber-legend-chip { display: inline-block; width: 18px; height: 4px; border-radius: 2px; vertical-align: middle; margin-right: 3px; }
