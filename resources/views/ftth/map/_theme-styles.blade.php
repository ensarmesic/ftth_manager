    /* Dark GIS workspace skin. Structure, ids and event hooks intentionally stay unchanged. */
    :root {
        --map-night: #061426;
        --map-night-2: #091a30;
        --map-panel: #0b1c32;
        --map-line: rgba(115, 154, 197, .20);
        --map-muted: #8297af;
        --map-text: #dbeafe;
        --map-blue: #1688df;
    }
    #map-workspace {
        gap: 6px;
        padding: 6px;
        border: 1px solid rgba(49, 91, 139, .22);
        border-radius: 12px;
        background: linear-gradient(145deg, #071528, #040d1a 72%);
        box-shadow: 0 18px 50px rgba(2, 8, 23, .28);
    }
    #map-workspace .map-shell {
        border-color: var(--map-line) !important;
        border-radius: 9px !important;
        background: var(--map-night) !important;
        box-shadow: 0 8px 30px rgba(0, 0, 0, .30) !important;
    }
    #map-workspace .map-topbar {
        min-height: 48px;
        padding: 7px 12px !important;
        border-color: var(--map-line);
        background: linear-gradient(180deg, #0c2039, #08182b);
    }
    #map-workspace .map-topbar .text-slate-900 { color: #f1f5f9 !important; }
    #map-workspace .map-topbar .text-slate-500 { color: var(--map-muted) !important; }
    #map-workspace .metric-pill {
        padding: 5px 10px;
        border-color: rgba(117, 153, 195, .22);
        background: rgba(5, 15, 29, .58);
        box-shadow: inset 0 1px rgba(255,255,255,.035);
    }
    #map-workspace .metric-pill.amber { color: #fbbf24; }
    #map-workspace .metric-pill.violet { color: #c084fc; }
    #map-workspace .metric-pill.emerald { color: #4ade80; }
    #map-workspace .map-toolbar {
        flex-wrap: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: thin;
        scrollbar-color: #294a6c transparent;
        gap: 5px;
        padding: 7px 8px;
        border-color: var(--map-line);
        background: #061326;
        box-shadow: inset 0 -1px rgba(255,255,255,.025);
    }
    #map-workspace .map-toolbar .tc {
        flex-shrink: 0;
        height: 29px;
        padding: 0 10px;
        border-radius: 6px;
        border-color: rgba(107, 143, 184, .20);
        background: #0a1a2e;
        box-shadow: inset 0 1px rgba(255,255,255,.025);
    }
    #map-workspace .map-toolbar .tc:hover {
        border-color: rgba(72, 157, 230, .55);
        background: #102946;
        transform: translateY(-1px);
    }
    #map-workspace .map-toolbar .tc-white,
    #map-workspace .map-toolbar .tc-save,
    #map-workspace .map-toolbar .tc-confirm {
        color: #fff;
        border-color: #147ed0;
        background: linear-gradient(180deg, #188ee3, #0869b4);
    }
    #map-workspace .map-toolbar .tc-sep { background: rgba(115,154,197,.2); }
    #map-workspace .map-toolbar .map-save-indicator,
    #map-workspace .map-toolbar select { flex-shrink: 0; }
    #map-workspace .map-toolbar select {
        height: 29px !important;
        border-color: var(--map-line) !important;
        background: #091a2f !important;
        color: #dbeafe !important;
    }
    #map-workspace .map-toolbar + p {
        border-color: var(--map-line) !important;
        background: #071326 !important;
        color: #5f7792 !important;
    }
    #map-workspace #network-map { background: #071323; }
    #network-map .leaflet-interactive:focus,
    #network-map .leaflet-marker-icon:focus,
    #network-map .leaflet-marker-shadow:focus {
        outline: none;
    }
    .map-vertical-tools {
        position: absolute;
        top: 82px;
        left: 10px;
        z-index: 1501;
        display: grid;
        width: 34px;
        padding: 4px;
        gap: 2px;
        border: 1px solid rgba(91, 137, 187, .34);
        border-radius: 8px;
        background: rgba(5, 18, 34, .96);
        box-shadow: 0 8px 24px rgba(0,0,0,.38), inset 0 1px rgba(255,255,255,.04);
        backdrop-filter: blur(8px);
    }
    .map-vertical-tools button {
        display: grid;
        width: 26px;
        height: 29px;
        place-items: center;
        border: 1px solid transparent;
        border-radius: 5px;
        background: transparent;
        color: #b9cbe0;
        cursor: pointer;
        transition: color .12s, background .12s, border-color .12s;
    }
    .map-vertical-tools button:hover {
        border-color: rgba(64, 148, 220, .42);
        background: #102b49;
        color: #fff;
    }
    .map-vertical-tools button.is-active {
        border-color: #178bdd;
        background: linear-gradient(180deg, #168de2, #0868b3);
        color: #fff;
        box-shadow: 0 3px 10px rgba(14,126,207,.35);
    }
    .map-vertical-tools button[data-map-delete] { color: #fb7185; }
    .map-vertical-tools button[data-map-delete]:hover { border-color: rgba(244,63,94,.42); background: rgba(159,18,57,.28); }
    .map-vertical-tools svg { width: 14px; height: 14px; }
    #map-workspace #map-search-input {
        border-color: rgba(112, 151, 194, .30) !important;
        background: rgba(6, 19, 37, .94) !important;
        color: #e2e8f0 !important;
        box-shadow: 0 7px 20px rgba(0,0,0,.28) !important;
    }
    #map-workspace #map-search-overlay {
        left: 58px !important;
    }
    #map-workspace #map-search-input::placeholder { color: #8396aa; }
    #map-workspace .cad-status {
        border-top: 1px solid var(--map-line);
        background: #061326;
        color: #8096ae;
    }
    #map-workspace .cad-chip {
        border: 1px solid rgba(112,151,194,.14);
        background: rgba(12, 31, 53, .72);
        color: #7890a9;
    }
    #map-workspace > aside {
        gap: 6px !important;
        padding: 0 0 4px !important;
        scrollbar-color: #274565 transparent;
    }
    #map-workspace .sidebar-card,
    #map-workspace .ctx-panel {
        border-color: var(--map-line) !important;
        border-radius: 8px !important;
        background: var(--map-panel) !important;
        box-shadow: 0 5px 18px rgba(0,0,0,.22) !important;
    }
    #map-workspace .sidebar-hd,
    #map-workspace .ctx-panel-hd {
        min-height: 40px;
        padding: 9px 11px;
        border-color: var(--map-line);
        background: linear-gradient(180deg, #0d213b, #0a1a2f) !important;
        color: #dce9f8 !important;
    }
    #map-workspace .sidebar-hd:hover { background: #102945 !important; }
    #map-workspace .sidebar-bd {
        padding: 11px;
        background: #08182b !important;
        color: #a9bdd3;
    }
    #map-workspace .sb-kicker { color: #7890aa; }
    #map-workspace .sb-inp,
    #map-workspace .sb-sel {
        min-height: 35px;
        border-color: rgba(111,149,191,.22);
        background: #0c2037;
        color: #dce8f5;
    }
    #map-workspace .sb-inp::placeholder { color: #60758d; }
    #map-workspace .sb-inp:focus,
    #map-workspace .sb-sel:focus {
        border-color: #258bd5;
        background: #102842;
        box-shadow: 0 0 0 3px rgba(37,139,213,.14);
    }
    #map-workspace .sb-btn-outline { border-color: var(--map-line); background: #0d2138; color: #b8c9db; }
    #map-workspace .sb-btn-primary { background: linear-gradient(180deg, #178bdf, #076bb7); }
    #map-workspace .step-btn {
        border-color: rgba(111,149,191,.20);
        background: #0d2138;
        color: #a9bfd6;
    }
    #map-workspace .step-btn:hover { border-color: #238bd8; background: #112b48; }
    #map-workspace .text-slate-900,
    #map-workspace .text-slate-800,
    #map-workspace .text-slate-700 { color: #dce8f5 !important; }
    #map-workspace .text-slate-600,
    #map-workspace .text-slate-500,
    #map-workspace .text-slate-400 { color: #8398af !important; }
    @media (min-width: 1280px) {
        #map-workspace { grid-template-columns: minmax(0, 1fr) clamp(350px, 18vw, 420px); }
    }

    /* Comfortable sizing on large and ultra-wide planning screens. */
    @media (min-width: 1440px) {
        #map-workspace { gap: 8px; padding: 8px; }
        #map-workspace .map-topbar {
            min-height: 58px;
            padding: 9px 14px !important;
        }
        #map-workspace .map-topbar .text-sm { font-size: 15px !important; }
        #map-workspace .map-topbar .text-xs { font-size: 11px !important; }
        #map-workspace .metric-pill { min-height: 30px; padding: 6px 12px; font-size: 11px; }
        #map-workspace .metric-pill b { font-size: 15px; }
        #map-workspace .map-toolbar {
            align-items: center;
            flex-wrap: nowrap;
            gap: 6px;
            min-height: 48px;
            padding: 7px 10px;
        }
        #map-workspace .map-toolbar .tc {
            height: 34px;
            padding: 0 12px;
            border-radius: 7px;
            font-size: 11.5px;
            letter-spacing: .01em;
        }
        #map-workspace .map-toolbar .tc-sep { height: 25px; margin: 0 2px; }
        #map-workspace .map-toolbar select {
            height: 34px !important;
            max-width: 185px !important;
            padding: 0 9px !important;
            font-size: 11.5px !important;
        }
        #map-workspace .map-toolbar > .ml-auto {
            flex: 0 0 auto;
            flex-wrap: nowrap;
            justify-content: flex-end;
            margin-left: auto !important;
            padding-top: 0;
            border-top: 0;
        }
        #map-workspace .map-toolbar + p {
            min-height: 23px;
            padding: 5px 12px !important;
            font-size: 10px !important;
            line-height: 1.35;
        }
        .map-vertical-tools {
            top: 86px;
            left: 14px;
            width: 46px;
            padding: 5px;
            gap: 4px;
            border-radius: 10px;
        }
        .map-vertical-tools button {
            width: 34px;
            height: 36px;
            border-radius: 7px;
        }
        .map-vertical-tools svg { width: 17px; height: 17px; }
        #map-workspace #map-search-overlay { top: 14px !important; left: 64px !important; width: 290px !important; }
        #map-workspace #map-search-input { min-height: 38px; padding-left: 32px !important; font-size: 12.5px !important; }
        #map-workspace .sidebar-hd {
            min-height: 46px;
            padding: 11px 14px;
            font-size: 12.5px;
        }
        #map-workspace .sidebar-bd { padding: 14px; }
        #map-workspace .sb-inp,
        #map-workspace .sb-sel { min-height: 40px; font-size: 12.5px; }
        #map-workspace .sb-btn { min-height: 38px; font-size: 12px; }
        #map-workspace .step-btn { min-height: 62px; font-size: 10.5px; }
        #map-workspace .cad-status { min-height: 34px; padding: 6px 10px; font-size: 10.5px; }
    }

    @media (min-width: 2200px) {
        #map-workspace { grid-template-columns: minmax(0, 1fr) 430px; }
        #map-workspace .map-toolbar { padding-block: 9px; }
        #map-workspace .map-toolbar .tc { height: 38px; padding-inline: 14px; font-size: 12px; }
        #map-workspace .map-toolbar select { height: 38px !important; font-size: 12px !important; }
        .map-vertical-tools { width: 52px; }
        .map-vertical-tools button { width: 40px; height: 41px; }
        .map-vertical-tools svg { width: 19px; height: 19px; }
    }
