    /* ── Map workspace ──────────────────────────────────────────── */
    #map-workspace { min-height: 0; }
    .map-shell {
        background:
            linear-gradient(135deg, rgba(8,145,178,.07), transparent 34%),
            linear-gradient(315deg, rgba(16,185,129,.09), transparent 36%),
            #f8fafc;
    }
    /* ── Top bar ─────────────────────────────────────────────────── */
    .map-topbar { background: #fff; border-bottom: 1px solid #e2e8f0; }
    .metric-pill {
        display: inline-flex; align-items: baseline; gap: 5px;
        padding: 3px 10px; border-radius: 20px; font-size: 11px; border: 1px solid; white-space: nowrap;
    }
    .metric-pill b { font: 700 14px/1 system-ui, sans-serif; }
    .metric-pill.amber   { background: #fffbeb; border-color: #fde68a; color: #92400e; }
    .metric-pill.violet  { background: #f5f3ff; border-color: #ddd6fe; color: #5b21b6; }
    .metric-pill.emerald { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
    /* ── CAD Toolbar ─────────────────────────────────────────────── */
    .map-toolbar {
        display: flex; flex-wrap: wrap; align-items: center; gap: 2px;
        padding: 5px 8px; background: #1e293b; border-bottom: 2px solid #334155;
    }
    .tc {
        display: inline-flex; align-items: center; height: 26px; padding: 0 9px;
        border-radius: 5px; border: 1px solid transparent; cursor: pointer;
        white-space: nowrap; font: 600 11px/1 ui-sans-serif, system-ui, sans-serif;
        letter-spacing: .02em; transition: background .1s, border-color .1s, color .1s;
    }
    .tc-sep { width: 1px; height: 18px; background: #475569; margin: 0 4px; align-self: center; flex-shrink: 0; }
    .tc-ghost   { background: rgba(255,255,255,.06); color: #94a3b8; border-color: #334155; }
    .tc-ghost:hover  { background: rgba(255,255,255,.12); color: #cbd5e1; }
    .tc-white   { background: #e2e8f0; color: #0f172a; border-color: #cbd5e1; }
    .tc-white:hover  { background: #f1f5f9; }
    .tc-cyan    { background: rgba(6,182,212,.20);  color: #67e8f9; border-color: rgba(6,182,212,.45); }
    .tc-cyan:hover   { background: rgba(6,182,212,.32); color: #a5f3fc; }
    .tc-emerald { background: rgba(52,211,153,.18); color: #6ee7b7; border-color: rgba(52,211,153,.4); }
    .tc-emerald:hover{ background: rgba(52,211,153,.30); color: #a7f3d0; }
    .tc-violet  { background: rgba(167,139,250,.18);color: #c4b5fd; border-color: rgba(167,139,250,.4); }
    .tc-violet:hover { background: rgba(167,139,250,.30); color: #ddd6fe; }
    .tc-amber   { background: rgba(251,191,36,.18); color: #fde68a; border-color: rgba(251,191,36,.4); }
    .tc-amber:hover  { background: rgba(251,191,36,.30); color: #fef3c7; }
    .tc-slate   { background: rgba(148,163,184,.14);color: #cbd5e1; border-color: rgba(148,163,184,.35); }
    .tc-slate:hover  { background: rgba(148,163,184,.26); }
    .tc-red     { background: rgba(248,113,113,.18);color: #fca5a5; border-color: rgba(248,113,113,.4); }
    .tc-red:hover    { background: rgba(248,113,113,.30); color: #fecaca; }
    .tc-purple  { background: rgba(196,181,253,.16);color: #ddd6fe; border-color: rgba(196,181,253,.38); }
    .tc-purple:hover { background: rgba(196,181,253,.28); }
    .tc-rose    { background: rgba(251,113,133,.18);color: #fda4af; border-color: rgba(251,113,133,.4); }
    .tc-rose:hover   { background: rgba(251,113,133,.30); color: #fecdd3; }
    .tc-orange  { background: rgba(251,146,60,.18); color: #fdba74; border-color: rgba(251,146,60,.4); }
    .tc-orange:hover { background: rgba(251,146,60,.30); color: #fed7aa; }
    .tc-blue    { background: rgba(96,165,250,.18); color: #93c5fd; border-color: rgba(96,165,250,.4); }
    .tc-blue:hover   { background: rgba(96,165,250,.30); color: #bfdbfe; }
    .tc-sky     { background: rgba(56,189,248,.18); color: #7dd3fc; border-color: rgba(56,189,248,.4); }
    .tc-sky:hover    { background: rgba(56,189,248,.30); color: #bae6fd; }
    .tc-indigo  { background: rgba(129,140,248,.18);color: #a5b4fc; border-color: rgba(129,140,248,.4); }
    .tc-indigo:hover { background: rgba(129,140,248,.30); color: #c7d2fe; }
    .tc-save    { background: #059669; color: #fff; border-color: #047857; }
    .tc-save:hover   { background: #047857; }
    .tc-confirm { background: #d97706; color: #fff; border-color: #b45309; }
    .tc-confirm:hover{ background: #b45309; }
    .tc-danger  { background: rgba(239,68,68,.22); color: #fca5a5; border-color: rgba(239,68,68,.45); }
    .tc-danger:hover { background: rgba(239,68,68,.35); color: #fecaca; }
    .map-toolbar .ring-2 { outline: 2px solid #fff; outline-offset: 1px; }
    /* ── Sidebar cards ───────────────────────────────────────────── */
    .sidebar-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(15,23,42,.06), 0 1px 2px rgba(15,23,42,.04); }
    .sidebar-hd {
        display: flex; align-items: center; gap: 8px; padding: 10px 13px;
        cursor: pointer;
        background: linear-gradient(180deg, #f8fafc 0%, #f3f6fa 100%);
        border-bottom: 1px solid #e8edf3;
        font: 700 11.5px/1.4 ui-sans-serif, system-ui, sans-serif; color: #0f172a;
        user-select: none; list-style: none; letter-spacing: .01em;
    }
    .sidebar-hd::-webkit-details-marker { display: none; }
    .sidebar-hd .chev { margin-left: auto; color: #94a3b8; transition: transform .2s; flex-shrink: 0; }
    details[open] > .sidebar-hd .chev { transform: rotate(90deg); }
    .sdot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; box-shadow: 0 0 0 2px rgba(0,0,0,.06); }
    .sdot-sky    { background: #0ea5e9; }
    .sdot-indigo { background: #6366f1; }
    .sdot-amber  { background: #f59e0b; }
    .sdot-violet { background: #8b5cf6; }
    .sdot-slate  { background: #94a3b8; }
    .sidebar-bd { padding: 11px 13px; max-height: 320px; overflow-y: auto; }
    #bulk-plan-form .sidebar-bd { max-height: none; overflow-y: visible; }
    .ctx-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(15,23,42,.06), 0 1px 2px rgba(15,23,42,.04); }
    .ctx-panel-hd { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 13px; border-bottom: 1px solid #e8edf3; background: linear-gradient(180deg,#f8fafc 0%,#f3f6fa 100%); }
    /* Sidebar inputs */
    .sb-inp, .sb-sel {
        width: 100%; border: 1px solid #dde3ea; border-radius: 7px; padding: 7px 10px;
        font-size: 12px; color: #1e293b; background: #f8fafc; outline: none; transition: border-color .15s, background .15s, box-shadow .15s;
    }
    .sb-inp:focus, .sb-sel:focus { border-color: #6366f1; background: #fff; box-shadow: 0 0 0 3px rgba(99,102,241,.10); }
    .sb-inp::placeholder { color: #b0bac5; }
    /* Sidebar buttons */
    .sb-btn {
        display: block; width: 100%; padding: 8px 12px; border-radius: 8px;
        font: 600 12px/1 ui-sans-serif, system-ui, sans-serif;
        cursor: pointer; border: 1px solid transparent; transition: filter .12s, transform .1s, box-shadow .12s; text-align: center;
    }
    .sb-btn:active { transform: scale(.97); }
    .sb-btn-primary       { background: linear-gradient(180deg, #253447 0%, #0f172a 100%); color: #fff; box-shadow: 0 1px 3px rgba(15,23,42,.25); }
    .sb-btn-primary:hover { filter: brightness(1.12); }
    .sb-btn-emerald       { background: linear-gradient(180deg, #059669 0%, #047857 100%); color: #fff; border-color: #047857; box-shadow: 0 1px 3px rgba(5,150,105,.25); }
    .sb-btn-emerald:hover { filter: brightness(1.08); }
    .sb-btn-cyan          { background: linear-gradient(180deg, #0891b2 0%, #0e7490 100%); color: #fff; border-color: #0e7490; box-shadow: 0 1px 3px rgba(8,145,178,.25); }
    .sb-btn-cyan:hover    { filter: brightness(1.08); }
    .sb-btn-violet        { background: linear-gradient(180deg, #7c3aed 0%, #6d28d9 100%); color: #fff; border-color: #6d28d9; box-shadow: 0 1px 3px rgba(124,58,237,.25); }
    .sb-btn-violet:hover  { filter: brightness(1.08); }
    .sb-btn-blue          { background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%); color: #fff; border-color: #1d4ed8; box-shadow: 0 1px 3px rgba(37,99,235,.25); }
    .sb-btn-blue:hover    { filter: brightness(1.08); }
    .sb-btn-sky           { background: linear-gradient(180deg, #0284c7 0%, #0369a1 100%); color: #fff; border-color: #0369a1; box-shadow: 0 1px 3px rgba(2,132,199,.25); }
    .sb-btn-sky:hover     { filter: brightness(1.08); }
    .sb-btn-amber         { background: linear-gradient(180deg, #d97706 0%, #b45309 100%); color: #fff; border-color: #b45309; box-shadow: 0 1px 3px rgba(217,119,6,.25); }
    .sb-btn-amber:hover   { filter: brightness(1.08); }
    .sb-btn-outline       { background: #fff; color: #475569; border-color: #dde3ea; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
    .sb-btn-outline:hover { background: #f4f7fa; border-color: #c8d0da; }
    .sb-btn-amber-outline       { background: #fff; color: #92400e; border-color: #fde68a; }
    .sb-btn-amber-outline:hover { background: #fffbeb; }
    /* Misc sidebar helpers */
    .sb-kicker { font: 800 9.5px/1 system-ui, sans-serif; color: #7c8ea4; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 5px; }
    .sb-info   { border-radius: 8px; padding: 7px 9px; font-size: 11px; line-height: 1.5; }
    /* Step buttons (workflow guide) */
    .step-btn {
        display: flex; align-items: center; gap: 7px;
        width: 100%; padding: 7px 10px; border-radius: 9px; border: 1.5px solid;
        font: 700 11px/1 system-ui, sans-serif; cursor: pointer; text-align: left;
        transition: filter .12s, box-shadow .12s, transform .1s;
    }
    .step-btn:hover { filter: brightness(.95); box-shadow: 0 2px 8px rgba(0,0,0,.10); transform: translateY(-1px); }
    .step-btn:active { transform: translateY(0); }
    .step-btn b {
        display: inline-flex; align-items: center; justify-content: center;
        width: 18px; height: 18px; border-radius: 50%;
        font: 800 9px/1 system-ui, sans-serif; flex-shrink: 0; color: #fff;
    }
    .step-cyan   { background: #ecfeff; border-color: #a5f3fc; color: #164e63; }
    .step-cyan b { background: #0891b2; }
    .step-amber   { background: #fffbeb; border-color: #fde68a; color: #78350f; }
    .step-amber b { background: #d97706; }
    .step-violet   { background: #f5f3ff; border-color: #ddd6fe; color: #4c1d95; }
    .step-violet b { background: #7c3aed; }
    .step-emerald   { background: #ecfdf5; border-color: #a7f3d0; color: #064e3b; }
    .step-emerald b { background: #059669; }

    /* Refined planner: quiet surfaces, one clear primary action */
    #map-workspace > aside {
        gap: 10px !important;
        padding: 1px 2px 10px;
        grid-auto-rows: max-content;
        align-content: start;
        scroll-padding-top: 2px;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }
    #map-workspace > aside > details.sidebar-card > .sidebar-bd,
    #map-workspace > aside > details.sidebar-card > form > .sidebar-bd {
        max-height: none;
        overflow: visible;
    }
    #map-workspace > aside > details.sidebar-card {
        align-self: start;
        min-height: max-content;
    }
    #map-workspace .sidebar-card {
        border: 1px solid #dce5ee;
        border-radius: 15px;
        box-shadow: 0 5px 16px rgba(15,23,42,.055);
    }
    #map-workspace .sidebar-hd {
        min-height: 43px;
        padding: 11px 14px;
        background: #fff;
        border-bottom-color: #e9eff5;
        font-size: 12px;
        font-weight: 750;
        color: #243448;
    }
    #map-workspace details:not([open]) > .sidebar-hd {
        border-bottom: 0;
    }
    #map-workspace .sidebar-hd:hover {
        background: #f8fafc;
    }
    #map-workspace .sidebar-hd .sdot {
        width: 7px;
        height: 7px;
        box-shadow: 0 0 0 4px rgba(148,163,184,.10);
    }
    #map-workspace .sidebar-hd .chev {
        width: 13px;
        height: 13px;
        color: #94a3b8;
    }
    #map-workspace .sidebar-bd {
        padding: 14px;
        background: #fff;
    }
    #bulk-plan-form .sidebar-bd {
        gap: 16px !important;
    }
    #map-workspace .sb-inp,
    #map-workspace .sb-sel {
        min-height: 39px;
        padding: 8px 11px;
        border-color: #d5e0ea;
        border-radius: 10px;
        background: #fff;
        font-size: 12.5px;
    }
    #map-workspace .sb-inp:hover,
    #map-workspace .sb-sel:hover { border-color: #adc5d9; }
    #map-workspace .sb-inp:focus,
    #map-workspace .sb-sel:focus {
        border-color: #308dcc;
        box-shadow: 0 0 0 3px rgba(48,141,204,.12);
    }
    #map-workspace .sb-kicker {
        margin-bottom: 7px;
        color: #7890a7;
        font-size: 9px;
        letter-spacing: .095em;
    }
    #bulk-plan-form .sidebar-bd > div:has(.step-btn) > .grid {
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 6px !important;
    }
    #map-workspace .step-btn {
        min-height: 56px;
        flex-direction: column;
        justify-content: center;
        gap: 5px;
        padding: 7px 3px;
        border-width: 1px;
        border-radius: 11px;
        text-align: center;
        font-size: 10px;
        background: #f8fafc;
        border-color: #dfe7ef;
        color: #405469;
    }
    #map-workspace .step-btn b {
        width: 20px;
        height: 20px;
        font-size: 9px;
    }
    #map-workspace .step-btn:hover {
        filter: none;
        border-color: #a9c5dc;
        background: #f2f8fc;
        box-shadow: 0 5px 14px rgba(15,23,42,.07);
    }
    #bulk-plan-form .sidebar-bd > .rounded-lg.border-amber-100 {
        border-color: #e2e8f0 !important;
        background: #f8fafc !important;
    }
    #bulk-plan-form .sidebar-bd > details.border-amber-100 {
        border-color: #e2e8f0 !important;
        border-radius: 11px !important;
    }
    #bulk-plan-form .sidebar-bd > details.border-amber-100 > summary {
        background: #f8fafc !important;
        color: #405469 !important;
    }
    #bulk-plan-form label:has(#road-routing-toggle),
    #bulk-plan-form label:has(#gis-routing-toggle) {
        border-color: #dce5ee !important;
        background: #f8fafc !important;
        color: #334155 !important;
    }
    #map-workspace .sb-info {
        border-radius: 9px;
        background: #f0f7fa !important;
        color: #406273 !important;
    }
    #map-workspace .sb-btn {
        min-height: 36px;
        border-radius: 9px;
        font-weight: 700;
    }
    #map-workspace .sb-btn-primary {
        background: linear-gradient(135deg,#167db7,#075985);
        box-shadow: 0 5px 14px rgba(7,89,133,.22);
    }
    #map-workspace .sb-btn-emerald {
        background: linear-gradient(135deg,#15926d,#08745a);
        box-shadow: 0 5px 14px rgba(8,116,90,.20);
    }
    #map-workspace .sb-btn-outline {
        background: #fff;
        border-color: #d5e0ea;
    }

    /* Professional GIS workspace chrome */
    #map-workspace {
        gap: 12px !important;
    }
    #map-workspace .map-shell {
        border-color: #cbd9e5 !important;
        border-radius: 16px !important;
        box-shadow: 0 18px 46px rgba(15,23,42,.13), 0 2px 8px rgba(15,23,42,.07) !important;
        background: #fff;
    }
    #map-workspace .map-topbar {
        min-height: 58px;
        padding: 10px 16px !important;
        background: linear-gradient(110deg,#fff 0%,#f7fbff 66%,#f5fbf1 100%);
        border-bottom: 1px solid #dce6ef;
    }
    #map-workspace .metric-pill {
        min-height: 28px;
        padding: 5px 10px;
        border-radius: 999px;
        box-shadow: none;
    }
    #map-workspace .map-toolbar {
        flex: 0 0 auto;
        flex-wrap: wrap;
        align-content: flex-start;
        gap: 4px;
        overflow: visible;
        padding: 7px 9px;
        background: linear-gradient(180deg,#152438,#0f1c2d);
        border-bottom: 1px solid #07111e;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.055);
    }
    #map-workspace .map-toolbar .tc {
        min-height: 27px;
        padding: 4px 7px;
        border: 1px solid #34465b;
        border-radius: 7px;
        background: #1c2b3e;
        color: #d7e3ef;
        box-shadow: none;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0;
    }
    #map-workspace .map-toolbar > .ml-auto {
        display: flex;
        flex: 1 1 430px;
        flex-wrap: wrap;
        justify-content: flex-end;
        min-width: 0;
        width: auto;
    }
    #map-workspace .map-toolbar .tc:hover {
        background: #26394f;
        border-color: #526b84;
        color: #fff;
        transform: translateY(-1px);
    }
    #map-workspace .map-toolbar .tc-cyan { color: #67e8f9; border-color: rgba(34,211,238,.35); }
    #map-workspace .map-toolbar .tc-emerald,
    #map-workspace .map-toolbar .tc-confirm,
    #map-workspace .map-toolbar .tc-save { color: #6ee7b7; border-color: rgba(52,211,153,.35); }
    #map-workspace .map-toolbar .tc-violet,
    #map-workspace .map-toolbar .tc-indigo { color: #c4b5fd; border-color: rgba(167,139,250,.34); }
    #map-workspace .map-toolbar .tc-amber,
    #map-workspace .map-toolbar .tc-orange { color: #fcd34d; border-color: rgba(251,191,36,.36); }
    #map-workspace .map-toolbar .tc-blue,
    #map-workspace .map-toolbar .tc-sky { color: #93c5fd; border-color: rgba(96,165,250,.36); }
    #map-workspace .map-toolbar .tc-red,
    #map-workspace .map-toolbar .tc-rose,
    #map-workspace .map-toolbar .tc-danger { color: #fda4af; border-color: rgba(251,113,133,.34); }
    #map-workspace .map-toolbar .tc-slate,
    #map-workspace .map-toolbar .tc-ghost,
    #map-workspace .map-toolbar .tc-white { color: #cbd5e1; }
    #map-workspace .map-toolbar .tc:disabled {
        opacity: .34;
        background: #142234;
        border-color: #2b3a4c;
        transform: none;
    }
    #map-workspace .map-toolbar .ring-2 {
        outline: 2px solid #38bdf8 !important;
        outline-offset: 1px;
        background: #263d55 !important;
        color: #fff !important;
    }
    #map-workspace .map-toolbar .tc-sep {
        width: 1px;
        height: 22px;
        margin: 2px 2px;
        background: #3a4b5e;
        opacity: .7;
    }
    #map-workspace .cad-status {
        gap: 6px !important;
        min-height: 54px;
        padding: 7px 10px !important;
        background: linear-gradient(180deg,#101a2b,#0b1423);
        border-top: 1px solid #25364b;
        color: #b9c7d7;
    }
    #map-workspace .cad-status > * {
        border-color: #314259 !important;
        border-radius: 8px !important;
        background: rgba(255,255,255,.025) !important;
    }
    #network-map {
        background: #dce4e9;
    }
    #map-search-overlay {
        border-radius: 10px !important;
        box-shadow: 0 10px 28px rgba(15,23,42,.16) !important;
    }
    #survey-panel {
        width: min(430px, calc(100vw - 28px)) !important;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }
    #dxf-layer-panel { width: min(320px, calc(100vw - 28px)) !important; }
    .floating-tool-panel{display:none;position:fixed;top:90px;right:350px;z-index:9999;max-height:calc(100vh - 110px);overflow-y:auto;border:1px solid rgba(15,23,42,.15);border-radius:14px;background:#fff;box-shadow:0 16px 42px rgba(15,23,42,.2);font-family:inherit}.floating-tool-panel-wide{width:430px}.floating-tool-panel-compact{top:120px;width:320px;overflow:hidden}.floating-tool-header{display:flex;align-items:center;justify-content:space-between;padding:11px 14px;border-bottom:1px solid #e8eef5;font-size:13px;font-weight:800}.floating-tool-header-green{background:linear-gradient(135deg,#ecfdf5,#f0f9ff);color:#065f46}.floating-tool-header-indigo{background:linear-gradient(135deg,#eef2ff,#f0f9ff);color:#3730a3}.floating-tool-close{border:0;background:transparent;color:#94a3b8;padding:0 2px;font-size:20px;line-height:1;cursor:pointer}.floating-tool-close:hover{color:#334155}

    @media (max-width: 1279px) {
        #map-workspace .map-shell { min-height: 860px; }
        #map-workspace > aside { overflow: visible !important; max-height: none !important; }
    }
    @media (min-width: 768px) and (max-width: 1279px) {
        #map-workspace .map-toolbar {
            flex-wrap: wrap;
            overflow: visible;
        }
        #map-workspace .map-toolbar > .ml-auto {
            flex: 1 1 100%;
            flex-wrap: wrap;
            justify-content: flex-start;
            width: 100%;
            margin-left: 0 !important;
        }
        #map-workspace .toolbar-group-label,
        #map-workspace .map-toolbar > .tc-sep { display: none; }
    }
    .mobile-map-tools-toggle { display: none; }
    @media (max-width: 767px) {
        #map-workspace .map-topbar { align-items: flex-start; }
        .mobile-map-tools-toggle {
            display: flex;
            min-height: 42px;
            width: 100%;
            align-items: center;
            justify-content: space-between;
            padding: 8px 13px;
            border: 0;
            border-bottom: 1px solid #25364b;
            background: linear-gradient(180deg,#17273b,#101d2e);
            color: #dbe7f2;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
        }
        .mobile-map-tools-toggle span:last-child { color: #7dd3fc; font-weight: 700; }
        #map-workspace .map-toolbar { display: none; }
        #map-workspace .map-toolbar.mobile-open { display: flex; max-height: none; overflow: visible; align-content: flex-start; }
        #map-workspace .map-toolbar.mobile-open > .ml-auto { flex: 1 1 100%; justify-content: flex-start; width: 100%; margin-left: 0 !important; }
        #map-workspace .map-toolbar.mobile-open .tc { min-height: 31px; padding: 6px 8px; font-size: 10px; }
        #map-workspace .map-toolbar.mobile-open .toolbar-group-label { display: none; }
        #map-workspace .map-toolbar.mobile-open .tc-sep { display: none; }
        #map-workspace #map-search-overlay {
            right: 10px;
            left: 54px !important;
            width: auto !important;
        }
        #map-workspace #map-stats-bar {
            top: 52px !important;
            right: 10px !important;
            left: 64px;
        }
        #map-workspace .map-shell:has(.map-toolbar.mobile-open) { min-height: 1120px; }
        #map-workspace .cad-status { font-size: 10px; }
        #survey-panel,
        #dxf-layer-panel {
            top: 64px !important;
            right: 10px !important;
            left: 10px !important;
            width: auto !important;
            max-height: calc(100dvh - 78px) !important;
            border-radius: 14px !important;
        }
    }
    #map-workspace.map-readonly { grid-template-columns: minmax(0, 1fr); }
    #map-workspace.map-readonly > aside { display: none; }
    #map-workspace.map-readonly .map-toolbar .tc:not(#survey-panel-btn),
    #map-workspace.map-readonly .map-toolbar .tc-sep { display: none; }
    #map-workspace.map-no-field #survey-panel-btn { display: none; }
    .layer-row { display: flex; align-items: center; gap: 8px; padding: 5px 8px; border-radius: 7px; font-size: 11px; transition: background .12s; }
    .layer-row:hover { background: #f0f4f8; }
    .layer-row input[type=checkbox] { accent-color: #6366f1; flex-shrink: 0; }
    .layer-lock-btn { padding: 2px 8px; border-radius: 5px; border: 1px solid #dde3ea; background: #f8fafc; font: 500 11px/1.5 system-ui, sans-serif; color: #64748b; cursor: pointer; flex-shrink: 0; transition: background .1s; }
    .layer-lock-btn:hover { background: #edf0f4; }
    .layer-opacity-slider { width: 44px; height: 3px; flex-shrink: 0; accent-color: #94a3b8; cursor: pointer; }
