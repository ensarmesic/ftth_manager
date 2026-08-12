@extends('ftth.layout')

@section('title', 'Mapa mreže')
@section('subtitle', 'Satelitski projektantski prikaz za ODF, FTTH ormariće, kuće i trase.')
@section('wide', '1')

@section('content')
<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">

<style>
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
        gap: 4px;
        padding: 7px 9px;
        background: linear-gradient(180deg,#152438,#0f1c2d);
        border-bottom: 1px solid #07111e;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.055);
    }
    #map-workspace .map-toolbar .tc {
        min-height: 27px;
        padding: 5px 8px;
        border: 1px solid #34465b;
        border-radius: 7px;
        background: #1c2b3e;
        color: #d7e3ef;
        box-shadow: none;
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: 0;
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

    @media (max-width: 1279px) {
        #map-workspace .map-shell { min-height: 860px; }
        #map-workspace > aside { overflow: visible !important; max-height: none !important; }
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
        #map-workspace .map-toolbar.mobile-open { display: flex; max-height: 52vh; overflow-y: auto; align-content: flex-start; }
        #map-workspace .map-toolbar.mobile-open .tc { min-height: 34px; padding: 7px 10px; font-size: 11px; }
        #map-workspace .map-toolbar.mobile-open .tc-sep { display: none; }
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
    .layer-row { display: flex; align-items: center; gap: 8px; padding: 5px 8px; border-radius: 7px; font-size: 11px; transition: background .12s; }
    .layer-row:hover { background: #f0f4f8; }
    .layer-row input[type=checkbox] { accent-color: #6366f1; flex-shrink: 0; }
    .layer-lock-btn { padding: 2px 8px; border-radius: 5px; border: 1px solid #dde3ea; background: #f8fafc; font: 500 11px/1.5 system-ui, sans-serif; color: #64748b; cursor: pointer; flex-shrink: 0; transition: background .1s; }
    .layer-lock-btn:hover { background: #edf0f4; }
    .layer-opacity-slider { width: 44px; height: 3px; flex-shrink: 0; accent-color: #94a3b8; cursor: pointer; }
    /* ── Snap indicator ─────────────────────────────────────────── */
    @keyframes snap-pulse {
        0%   { transform: translate(-50%,-50%) scale(1);    opacity: .85; }
        55%  { transform: translate(-50%,-50%) scale(1.6);  opacity: .15; }
        100% { transform: translate(-50%,-50%) scale(1);    opacity: .85; }
    }
    .snap-wrap { position: relative; width: 0; height: 0; pointer-events: none; }
    .snap-ring {
        width: 34px; height: 34px; border-radius: 50%;
        border: 2.5px solid var(--sc); position: absolute;
        top: 0; left: 0; transform: translate(-50%,-50%);
        animation: snap-pulse .7s ease-in-out infinite;
    }
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
    .leaflet-snap-active { cursor: crosshair !important; }
    .leaflet-snap-active .leaflet-grab { cursor: crosshair !important; }
    /* ── Map element styles (unchanged) ─────────────────────────── */
    .ftth-label { border: 0; background: transparent; }
    .ftth-tag { position: absolute; left: 1px; top: 1px; transform: translate(-50%, -50%); color: #fff; font: 800 9px/1 system-ui, sans-serif; display: grid; place-items: center; }
    .ftth-tag.odf { width: 18px; height: 18px; border: 2px solid #fff; border-radius: 2px; background: #0f5fa8; box-shadow: 0 0 0 1px #0f172a; }
    .ftth-tag.cabinet { width: 14px; height: 14px; border: 2px solid #fff; border-radius: 2px; background: #16a34a; box-shadow: 0 0 0 1px #0f172a; font-size: 0; }
    .ftth-tag.cabinet.ftth-cabinet-tag { width: auto; height: auto; min-width: 0; min-height: 0; padding: 0; border: 0; border-radius: 0; background: transparent !important; box-shadow: none; display: flex; align-items: center; gap: 4px; transform: translate(-8px, -50%); }
    .ftth-cabinet-symbol { display: flex; align-items: center; justify-content: center; width: 22px; height: 14px; flex: 0 0 auto; border: 2px solid #fff; border-radius: 2px; box-shadow: 0 0 0 1px #0f172a; font: 900 6.5px/1 system-ui, sans-serif; color: #fff; letter-spacing: .3px; }
    .ftth-cabinet-text { display: inline-flex; align-items: center; gap: 2px; padding: 1px 3px; background: rgba(255,255,255,.86); color: #0f172a; text-shadow: 0 1px 0 #fff; border: 1px solid rgba(15,23,42,.28); white-space: nowrap; }
    .ftth-cabinet-title { display: block; font-size: 7px; font-weight: 900; line-height: 1; letter-spacing: 0; opacity: .75; }
    .ftth-cabinet-code { display: block; font-size: 9px; font-weight: 900; line-height: 1.05; letter-spacing: 0; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .ftth-tag.house { width: 14px; height: 14px; border: 2px solid #fff; border-radius: 999px; background: #16a34a; box-shadow: 0 0 0 1px #0f172a; font-size: 8px; font-weight: 900; }
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
    /* ── Project picker modal ────────────────────────────────────── */
    #project-picker-overlay {
        position: fixed; inset: 0; z-index: 99999;
        background:
            radial-gradient(circle at 20% 15%, rgba(14,165,233,.18), transparent 30%),
            radial-gradient(circle at 80% 80%, rgba(129,195,66,.14), transparent 28%),
            rgba(3,17,28,.80);
        backdrop-filter: blur(10px) saturate(120%);
        display: flex; align-items: center; justify-content: center;
        padding: 16px;
    }
    #project-picker-overlay.hidden { display: none; }
    #project-picker-card {
        background: rgba(255,255,255,.98); border: 1px solid rgba(255,255,255,.8); border-radius: 20px; width: 100%; max-width: 480px;
        box-shadow: 0 35px 90px rgba(2,12,22,.48), 0 0 0 1px rgba(125,211,252,.12); overflow: hidden;
        animation: pp-enter .3s cubic-bezier(.2,.8,.2,1) both;
    }
    @keyframes pp-enter { from { opacity: 0; transform: translateY(12px) scale(.975); } to { opacity: 1; transform: none; } }
    #project-picker-card .pp-hd {
        padding: 22px 22px 17px; border-bottom: 1px solid #e8eef5;
        background: radial-gradient(circle at 88% 0%, rgba(129,195,66,.18), transparent 34%), linear-gradient(135deg,#edf8ff,#f7fbff 58%,#f3faef);
    }
    #project-picker-card .pp-title { font: 700 16px/1.3 ui-sans-serif,system-ui,sans-serif; color: #1e293b; }
    #project-picker-card .pp-sub   { font-size: 12px; color: #64748b; margin-top: 2px; }
    #project-picker-card .pp-search-wrap { padding: 10px 20px; border-bottom: 1px solid #e8eef5; background: #fff; }
    #project-picker-card .pp-search { width: 100%; border: 1px solid #d8e3ee; border-radius: 8px; padding: 9px 11px; font-size: 13px; color: #1e293b; outline: none; }
    #project-picker-card .pp-search:focus { border-color: #308dcc; box-shadow: 0 0 0 3px rgba(48,141,204,.14); }
    #project-picker-card .pp-list  { max-height: 320px; overflow-y: auto; }
    .pp-row {
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; padding: 11px 20px; border-bottom: 1px solid #f8fafc;
        transition: background .12s;
    }
    .pp-row:hover { background: linear-gradient(90deg,#f0f8ff,#f7fcf4); }
    .pp-row-name  { font: 600 13px/1.4 ui-sans-serif,system-ui,sans-serif; color: #1e293b; }
    .pp-row-meta  { font-size: 11px; color: #94a3b8; }
    .pp-btn {
        flex-shrink: 0; padding: 5px 14px; border-radius: 6px; border: none; cursor: pointer;
        font: 600 11px/1 ui-sans-serif,system-ui,sans-serif;
        background: linear-gradient(135deg,#167db7,#075985); color: #fff; transition: background .12s, transform .12s, box-shadow .12s;
        box-shadow: 0 4px 12px rgba(7,89,133,.20);
    }
    .pp-btn:hover { background: linear-gradient(135deg,#2097d1,#096c9d); transform: translateY(-1px); box-shadow: 0 7px 16px rgba(7,89,133,.28); }
    #project-picker-card .pp-new {
        padding: 14px 20px; border-top: 1px solid #e2e8f0; background: #fafafa;
    }
    #project-picker-card .pp-new-title { font: 600 11px/1 ui-sans-serif,system-ui,sans-serif; color: #64748b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: .04em; }
    .pp-new-row { display: flex; gap: 8px; }
    .pp-new-inp { flex: 1; border: 1px solid #e2e8f0; border-radius: 6px; padding: 7px 10px; font-size: 13px; color: #1e293b; outline: none; }
    .pp-new-inp:focus { border-color: #6366f1; }
    .pp-new-submit { padding: 7px 16px; border-radius: 7px; border: none; cursor: pointer; font: 700 12px/1 ui-sans-serif,system-ui,sans-serif; background: linear-gradient(135deg,#65a845,#3f7f2c); color: #fff; box-shadow: 0 5px 14px rgba(63,127,44,.22); }
    .pp-new-submit:hover { background: linear-gradient(135deg,#76bd50,#468c30); }
    .pp-new-submit:disabled { cursor: wait; opacity: .6; }
    #pp-new-status { font-size: 11px; color: #64748b; margin-top: 6px; }
    .pp-empty { padding: 28px 20px; text-align: center; color: #94a3b8; font-size: 13px; }
    /* ── Custom checkbox ────────────────────────────────────────── */
    .cb-custom {
        -webkit-appearance: none; appearance: none;
        width: 16px; height: 16px; min-width: 16px;
        border: 2px solid #fbbf24; border-radius: 4px;
        background: #fff; cursor: pointer; position: relative;
        transition: background .15s, border-color .15s;
    }
    .cb-custom:checked { background: #f59e0b; border-color: #d97706; }
    .cb-custom:checked::after {
        content: ''; display: block; position: absolute;
        left: 3px; top: 0px; width: 6px; height: 4px;
        border: 2px solid #fff; border-top: none; border-right: none;
        transform: rotate(-45deg);
    }
    .cb-custom:focus-visible { outline: 2px solid rgba(245,158,11,.5); outline-offset: 2px; }
    .snapshot-overlay{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(3,17,28,.78);backdrop-filter:blur(9px)}.snapshot-overlay.hidden{display:none}.snapshot-card{width:100%;max-width:620px;overflow:hidden;border:1px solid rgba(255,255,255,.8);border-radius:18px;background:#fff;box-shadow:0 32px 90px rgba(2,12,22,.46);animation:pp-enter .22s ease-out}.snapshot-card>header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:20px 22px 16px;border-bottom:1px solid #e5edf5;background:linear-gradient(135deg,#edf8ff,#f5fbf1)}.snapshot-card h2{margin:0;color:#172033;font-size:16px}.snapshot-card header p{margin:3px 0 0;color:#64748b;font-size:11px}.snapshot-card header button{border:0;background:transparent;color:#94a3b8;font-size:24px;cursor:pointer}.snapshot-create{display:flex;gap:8px;padding:14px 20px;border-bottom:1px solid #edf2f7}.snapshot-create input{min-width:0;flex:1;border:1px solid #cbd5e1;border-radius:8px;padding:9px 11px;font-size:12px}.snapshot-create button,.snapshot-restore{border:0;border-radius:7px;background:#075985;color:#fff;padding:8px 13px;font-size:11px;font-weight:800;cursor:pointer}.snapshot-list{max-height:390px;overflow:auto}.snapshot-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 20px;border-bottom:1px solid #f1f5f9}.snapshot-row strong{display:block;color:#1e293b;font-size:12px}.snapshot-row small{color:#94a3b8;font-size:10px}.snapshot-restore{background:#fff;color:#b45309;border:1px solid #fbbf24}.snapshot-empty{padding:34px;text-align:center;color:#94a3b8;font-size:12px}#snapshot-status{display:none;margin:10px 20px 0;border-radius:7px;padding:8px 10px;font-size:11px;font-weight:700}
</style>

@include('ftth.map._project-picker')
@include('ftth.map._snapshot-modal')

<section id="map-workspace" class="grid flex-1 min-h-0 gap-2 xl:grid-cols-[minmax(0,1fr)_316px]">

    <!-- MAP COLUMN -->
    <div class="map-shell flex min-h-0 flex-col overflow-hidden rounded-xl border border-slate-200/80 shadow-xl">

        <!-- Top bar -->
        <div class="map-topbar flex shrink-0 flex-wrap items-center justify-between gap-3 px-4 py-2.5">
            <div class="flex items-center gap-3">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-white" style="background:linear-gradient(135deg,#308dcc,#004f7d)">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M12 1.586l-4 4v12.828l4-4V1.586zM3.707 3.293A1 1 0 002 4v10a1 1 0 00.293.707L6 18.414V5.586L3.707 3.293zM17.707 5.293L14 1.586v12.828l2.293 2.293A1 1 0 0018 16V6a1 1 0 00-.293-.707z" clip-rule="evenodd"/></svg>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-900 leading-tight">Radna karta</div>
                    <div class="text-xs text-slate-500 leading-tight">Satelit · ODF · Ormarići · Trase</div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <div class="metric-pill amber"><b id="draw-length">0 m</b><span>Trasa</span></div>
                <div class="metric-pill violet"><b id="house-count">0</b><span>Kuće</span></div>
                <div class="metric-pill emerald"><b id="cabinet-count">0</b><span>FTTH</span></div>
            </div>
        </div>

        <!-- CAD Toolbar -->
        <button type="button" id="mobile-map-tools-toggle" class="mobile-map-tools-toggle" aria-controls="map-cad-toolbar" aria-expanded="false">
            <span>Alati za crtanje i uređivanje</span>
            <span>Prikaži</span>
        </button>
        <div id="map-cad-toolbar" class="map-toolbar">
            <button type="button" id="mode-pan" class="tc tc-white">⊕ Pan</button>
            <button type="button" id="mode-select" class="tc tc-white tool-btn" title="Selektuj i briši više elemenata (drag pravougaonik)">⬚ Selekt</button>
            <div class="tc-sep"></div>
            <button type="button" id="mode-odf" class="tc tc-cyan">ODF</button>
            <button type="button" id="mode-cabinet" class="tc tc-emerald">FTTH</button>
            <button type="button" id="mode-house" class="tc tc-violet">Kuće</button>
            <button type="button" id="mode-manhole" class="tc tc-slate">Šaht</button>
            <button type="button" id="mode-boring-fi-130" class="tc tc-red">Raketa FI130</button>
            <div class="tc-sep"></div>
            <button type="button" id="mode-draw" class="tc tc-amber">Trasa</button>
            <button type="button" id="mode-trench-draw" class="tc tc-slate">Rov</button>
            <button type="button" id="mode-trace-branch" class="tc tc-amber">Krak po liniji</button>
            <button type="button" id="mode-ruler" class="tc tc-rose">Mjerač</button>
            <div class="tc-sep"></div>
            <button type="button" id="mode-connect" class="tc tc-blue">ODF↔ODO</button>
            <button type="button" id="mode-connect-houses" class="tc tc-violet">ODO↔Kuće</button>
            <button type="button" id="mode-branch-source" class="tc tc-orange">Krak iz ODO</button>
            <button type="button" id="mode-trace" class="tc tc-sky">Trace</button>
            <button type="button" id="mode-join" class="tc tc-rose">Join trase</button>
            <button type="button" id="mode-split" class="tc tc-orange">✂ Split</button>
            <div class="tc-sep"></div>
            <button type="button" id="btn-map-undo" class="tc tc-ghost" title="Undo (Ctrl+Z)" disabled>↩ Undo</button>
            <button type="button" id="btn-map-redo" class="tc tc-ghost" title="Redo (Ctrl+Y)" disabled>↪ Redo</button>
            <div class="tc-sep"></div>
            <div id="house-connect-actions" class="hidden items-center gap-1">
                <button type="button" id="finish-house-connect" class="tc tc-confirm">✓ Završi</button>
                <button type="button" id="cancel-house-connect" class="tc tc-ghost">✕ Otkaži</button>
            </div>
            <button type="button" id="finish-branch" class="tc tc-confirm">✓ Završi krak</button>
            <button type="button" id="undo-draw" class="tc tc-ghost">↩ Tačka</button>
            <button type="button" id="undo-branch" class="tc tc-ghost">↩ Krak</button>
            <button type="button" id="undo-element" class="tc tc-ghost">↩ Elem.</button>
            <button type="button" id="undo-house" class="tc tc-ghost">↩ Kuća</button>
            <button type="button" id="cancel-draw" class="tc tc-danger">✕ Crtanje</button>
            <button type="button" id="clear-draw" class="tc tc-danger">✕ Trase</button>
            <div id="route-edit-actions" class="hidden items-center gap-1">
                <button type="button" id="add-route-vertex" class="tc tc-blue">+ Tačka</button>
                <button type="button" id="undo-route-edit" class="tc tc-ghost" title="Ctrl+Z" disabled>↩ Undo</button>
                <button type="button" id="redo-route-edit" class="tc tc-ghost" title="Ctrl+Y" disabled>↷ Redo</button>
                <button type="button" id="save-route-edit" class="tc tc-save">✓ Sačuvaj trasu</button>
                <button type="button" id="cancel-route-edit" class="tc tc-ghost">✕ Otkaži</button>
            </div>
            <button type="button" id="quick-save-draft" class="tc tc-save">💾 Nacrt</button>
            <span id="map-save-indicator" class="map-save-indicator" data-state="idle"><i></i><span>Spremno</span></span>
            <div class="tc-sep"></div>
            <div class="flex items-center gap-1 ml-auto flex-wrap">
                <button type="button" id="toggle-color-by-fibers" class="tc tc-ghost" title="Boja trase po broju vlakana">Boja F</button>
                <button type="button" id="toggle-parallel-routes" class="tc tc-ghost" title="Uključi ili isključi paralelni prikaz mikrocijevi" aria-pressed="true">Paralelno</button>
                <button type="button" id="toggle-cable-specs" class="tc tc-ghost" title="Specifikacije kabela">Specs</button>
                <button type="button" id="project-snapshot-btn" class="tc tc-ghost" title="Sačuvaj ili vrati sigurnosnu kopiju projekta">Backup</button>
                <button type="button" id="btn-coord-jump" class="tc tc-ghost" title="Skok na koordinate">Goto</button>
                <button type="button" id="dxf-layer-btn" class="tc tc-indigo" title="Učitaj DXF" aria-controls="dxf-layer-panel" aria-expanded="false">DXF</button>
                <button type="button" id="survey-panel-btn" class="tc tc-indigo" title="Uvoz geodetskih tačaka (TXT)" aria-controls="survey-panel" aria-expanded="false">Tačke</button>
                <button type="button" id="toggle-map-view" class="tc tc-ghost">GIS</button>
                <button type="button" id="expand-map" class="tc tc-ghost" title="Proširena mapa">⛶</button>
                <div class="tc-sep"></div>
                <select id="map-project-filter" title="Filtriraj po projektu" style="height:26px;border:1px solid #334155;background:#1e293b;color:#e2e8f0;border-radius:5px;font-size:11px;padding:0 6px;font-family:inherit;cursor:pointer;max-width:150px">
                    <option value="">Svi projekti</option>
                    @foreach($projects as $project)
                    <option value="{{ $project->id }}" {{ $activeProjectId == $project->id ? 'selected' : '' }}>{{ $project->name }}</option>
                    @endforeach
                </select>
                <button type="button" id="btn-map-print" class="tc tc-ghost" title="Štampaj/izvoz mape">
                    <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v3a2 2 0 002 2h1v2a2 2 0 002 2h6a2 2 0 002-2v-2h1a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm8 0H7v3h6V4zm0 8H7v4h6v-4z" clip-rule="evenodd"/></svg>
                    Print
                </button>
            </div>
        </div>
        <p class="shrink-0 border-b border-slate-800 bg-slate-900 px-4 py-1 text-[10px] text-slate-500">Desni klik: obriši / premjesti · ESC prekid · ENTER završi · CTRL+Z undo · O ortho · G GIS graf · Selekcija: K kopiraj, P pomjeri, V rotiraj, Z zrcali, S skaliraj, N niz</p>

        <div id="map-container" class="min-h-0 flex-1 w-full relative">
            <div id="network-map" class="w-full h-full"></div>
            <div id="select-rubber-band" style="display:none;position:absolute;border:2px solid #3b82f6;background:rgba(59,130,246,0.08);pointer-events:none;z-index:2000;box-sizing:border-box;"></div>
            <div id="cabinet-assign-panel" style="display:none;position:absolute;bottom:54px;left:50%;transform:translateX(-50%);z-index:2002;background:#1e293b;border:1px solid #7c3aed;border-radius:10px;padding:10px 12px;min-width:260px;max-width:340px;box-shadow:0 8px 28px rgba(0,0,0,.4);">
                <div style="font:700 11px/1 system-ui,sans-serif;color:#ddd6fe;margin-bottom:8px;letter-spacing:.04em;text-transform:uppercase;">Dodijeli ODO</div>
                <div id="cabinet-assign-list" style="max-height:210px;overflow-y:auto;display:grid;gap:3px;"></div>
                <div id="cabinet-assign-status" style="display:none;font:600 11px/1.4 system-ui;color:#a78bfa;margin-top:8px;"></div>
                <button id="cabinet-assign-cancel" type="button" style="margin-top:8px;width:100%;padding:5px;border:1px solid #475569;border-radius:5px;background:transparent;color:#94a3b8;cursor:pointer;font:600 11px/1 system-ui,sans-serif;">Otkaži</button>
            </div>
            <div id="select-actions" style="display:none;position:absolute;bottom:10px;left:50%;transform:translateX(-50%);z-index:2001;background:#1e293b;border:1px solid #3b82f6;border-radius:6px;padding:6px 12px;align-items:center;gap:8px;font-size:12px;color:#e2e8f0;white-space:nowrap;">
                <span id="select-count">0 selektovano</span>
                <button id="select-assign-btn" type="button" class="tc tc-violet" style="display:none;padding:2px 10px;font-size:11px;">⤵ Dodijeli ODO</button>
                <span style="width:1px;height:16px;background:#334155;"></span>
                <button id="xf-copy-btn" type="button" class="tc tc-ghost" title="Kopiraj [K]" style="padding:2px 8px;font-size:11px;">Kopiraj</button>
                <button id="xf-move-btn" type="button" class="tc tc-ghost" title="Pomjeri [P]" style="padding:2px 8px;font-size:11px;">Pomjeri</button>
                <button id="xf-rotate-btn" type="button" class="tc tc-ghost" title="Rotiraj [V]" style="padding:2px 8px;font-size:11px;">Rotiraj</button>
                <button id="xf-mirror-btn" type="button" class="tc tc-ghost" title="Zrcali [Z]" style="padding:2px 8px;font-size:11px;">Zrcali</button>
                <button id="xf-scale-btn" type="button" class="tc tc-ghost" title="Skaliraj [S]" style="padding:2px 8px;font-size:11px;">Skaliraj</button>
                <button id="xf-array-btn" type="button" class="tc tc-ghost" title="Niz [N]" style="padding:2px 8px;font-size:11px;">Niz</button>
                <button id="xf-align-btn" type="button" class="tc tc-ghost" title="Poravnaj" style="padding:2px 8px;font-size:11px;">Poravnaj</button>
                <label style="display:flex;align-items:center;gap:4px;font-size:10px;color:#94a3b8;">
                    <input type="checkbox" id="xf-keep-original" checked style="accent-color:#3b82f6;">Original
                </label>
                <span style="width:1px;height:16px;background:#334155;"></span>
                <button id="select-delete-btn" class="tc tc-danger" style="padding:2px 10px;font-size:11px;">Obriši selektovano</button>
                <button id="select-cancel-btn" class="tc tc-ghost" style="padding:2px 8px;font-size:11px;">✕</button>
            </div>
            <div id="xf-value-panel" style="display:none;position:absolute;bottom:54px;left:50%;transform:translateX(-50%);z-index:2002;background:#1e293b;border:1px solid #3b82f6;border-radius:10px;padding:10px 12px;min-width:220px;box-shadow:0 8px 28px rgba(0,0,0,.4);">
                <div id="xf-value-label" style="font:700 11px/1 system-ui,sans-serif;color:#bfdbfe;margin-bottom:8px;letter-spacing:.04em;text-transform:uppercase;">Ugao</div>
                <input id="xf-value-input" type="number" step="any" style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #475569;background:#0f172a;color:#e2e8f0;font:600 12px system-ui;">
                <input id="xf-value-input-2" type="number" step="any" style="display:none;width:100%;margin-top:6px;padding:6px 8px;border-radius:6px;border:1px solid #475569;background:#0f172a;color:#e2e8f0;font:600 12px system-ui;">
                <div style="display:flex;gap:6px;margin-top:8px;">
                    <button id="xf-value-confirm" type="button" style="flex:1;padding:5px;border:none;border-radius:5px;background:#2563eb;color:#fff;cursor:pointer;font:600 11px/1 system-ui,sans-serif;">Primijeni</button>
                    <button id="xf-value-cancel" type="button" style="padding:5px 10px;border:1px solid #475569;border-radius:5px;background:transparent;color:#94a3b8;cursor:pointer;font:600 11px/1 system-ui,sans-serif;">Otkaži</button>
                </div>
            </div>

            {{-- Pretraga markera --}}
            <div id="map-search-overlay" style="position:absolute;top:10px;left:10px;z-index:1500;width:250px">
                <div style="position:relative">
                    <svg style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none" width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                    <input id="map-search-input" placeholder="Traži ODF, FTTH, kuću..." autocomplete="off"
                        style="width:100%;box-sizing:border-box;padding:7px 10px 7px 28px;border-radius:9px;border:1.5px solid #e2e8f0;background:rgba(255,255,255,.95);font-size:12px;box-shadow:0 2px 8px rgba(0,0,0,.14);outline:none;font-family:inherit;color:#1e293b">
                </div>
                <div id="map-search-results" style="display:none;margin-top:3px;border-radius:9px;border:1px solid #e2e8f0;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.14);overflow:hidden;max-height:220px;overflow-y:auto"></div>
            </div>

            {{-- Statistike na mapi --}}
            <div id="map-stats-bar" style="position:absolute;top:10px;right:10px;z-index:1500;display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;pointer-events:none"></div>

            {{-- Legenda --}}
            <div style="position:absolute;bottom:46px;right:10px;z-index:1500">
                <button id="map-legend-btn" type="button" style="padding:4px 10px;border-radius:7px;background:rgba(255,255,255,.93);border:1px solid #e2e8f0;font-size:11px;font-weight:600;color:#475569;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.1);display:flex;align-items:center;gap:5px;font-family:inherit;white-space:nowrap">
                    <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path d="M9 4.804A7.968 7.968 0 005.5 4c-1.255 0-2.443.29-3.5.804v10A7.969 7.969 0 015.5 14c1.669 0 3.218.51 4.5 1.385A7.962 7.962 0 0114.5 14c1.255 0 2.443.29 3.5.804v-10A7.968 7.968 0 0014.5 4c-1.255 0-2.443.29-3.5.804V12a1 1 0 11-2 0V4.804z"/></svg>
                    Legenda
                </button>
                <div id="map-legend-panel" style="display:none;position:absolute;bottom:calc(100% + 6px);right:0;width:200px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.14);padding:10px 12px;font-size:11px">
                    <div style="font-weight:700;color:#1e293b;margin-bottom:8px;font-size:12px">Legenda mape</div>
                    <div style="display:grid;gap:5px">
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:14px;border-radius:3px;background:#0891b2"></span><span style="color:#334155">ODF čvorište</span></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:14px;border-radius:3px;background:#059669"></span><span style="color:#334155">FTTH ormarić (ODO)</span></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:14px;border-radius:3px;background:#7c3aed"></span><span style="color:#334155">Kuća / pretplatnik</span></div>
                        <div style="border-top:1px solid #f1f5f9;margin:3px 0"></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:3px;background:#0f172a;border-radius:2px"></span><span style="color:#334155">Backbone kabel</span></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:3px;background:#d97706;border-radius:2px"></span><span style="color:#334155">Distribution kabel</span></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:3px;background:#6d28d9;border-radius:2px"></span><span style="color:#334155">Drop kabel</span></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:3px;background:#64748b;border-radius:2px"></span><span style="color:#334155">Trench / rov</span></div>
                        <div style="border-top:1px solid #f1f5f9;margin:3px 0"></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:10px;height:10px;border-radius:50%;background:#64748b;margin-left:6px"></span><span style="color:#334155">Šaht</span></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:10px;height:10px;background:#ef4444;transform:rotate(45deg);margin-left:6px"></span><span style="color:#334155">Raketa FI 130</span></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="cad-status grid gap-2 px-3 py-2 md:grid-cols-[1fr_auto_auto_auto]">
            <div id="cad-command">Command: PAN</div>
            <div id="cad-metrics" class="cad-chip rounded px-2 py-1">Points: 0 | Distance: 0m | Snap: - | ORTHO: OFF</div>
            <div id="cad-coordinates" class="cad-chip rounded px-2 py-1">LAT -, LNG -</div>
            <div class="cad-chip rounded px-2 py-1">ESC prekid | ENTER zavrsi | CTRL+Z undo | O ORTHO | R OSM ulice</div>
        </div>
    </div>

    <!-- RIGHT SIDEBAR -->
    <aside class="grid min-h-0 content-start gap-2 xl:max-h-full xl:overflow-y-auto xl:pb-2">

        <!-- Novi projekat -->
        <details class="sidebar-card" @if(!$activeProjectId) open @endif>
            <summary class="sidebar-hd">
                <span class="sdot sdot-sky"></span>
                Novi projekat
                <svg class="chev w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            </summary>
            <form id="quick-project-form" class="sidebar-bd grid gap-2">
                <input name="name" class="sb-inp" placeholder="Naziv projekta" required>
                <input type="hidden" name="quick_create" value="1">
                <button class="sb-btn sb-btn-primary">Kreiraj i odaberi</button>
                <div id="quick-project-status" class="text-xs font-semibold text-emerald-700"></div>
            </form>
        </details>

        <!-- Element editor (contextual) -->
        <div id="element-editor" class="ctx-panel hidden" style="border-color:#6ee7b7;border-width:2px">
            <div class="ctx-panel-hd">
                <div>
                    <div class="text-sm font-semibold text-slate-900">Odabrani element</div>
                    <div id="element-editor-type" class="text-xs text-slate-500"></div>
                </div>
                <button type="button" id="close-element-editor" class="sb-btn sb-btn-outline" style="width:auto;padding:3px 9px;font-size:11px">Zatvori</button>
            </div>
            <div class="sidebar-bd grid gap-2">
                <label class="grid gap-1"><span class="sb-kicker">Naziv</span><input id="element-editor-name" class="sb-inp" placeholder="Naziv elementa"></label>
                <label class="grid gap-1"><span class="sb-kicker">Adresa / napomena</span><input id="element-editor-address" class="sb-inp" placeholder="Lokacija ili kratka napomena"></label>
                <div id="element-editor-odf-fields" class="hidden grid grid-cols-2 gap-2">
                    <label class="grid gap-1"><span class="sb-kicker">Vlakana</span><select id="element-editor-fiber-capacity" class="sb-sel"><option>72</option><option selected>144</option><option>288</option><option>576</option></select></label>
                    <label class="grid gap-1"><span class="sb-kicker">Portova</span><input id="element-editor-port-count" type="number" min="1" max="1152" class="sb-inp" value="48"></label>
                </div>
                <div id="element-editor-cabinet-fields" class="hidden grid gap-2">
                    <div class="grid grid-cols-2 gap-2">
                        <label class="grid gap-1"><span class="sb-kicker">Splittera</span><input id="element-editor-splitter-count" type="number" min="1" max="3" class="sb-inp" value="3"></label>
                        <label class="grid gap-1"><span class="sb-kicker">Portova / splitter</span><input id="element-editor-ports-per-splitter" type="number" min="1" max="4" class="sb-inp" value="4"></label>
                    </div>
                    <label class="grid gap-1"><span class="sb-kicker">Povezani ODF</span><select id="element-editor-odf" class="sb-sel"></select></label>
                </div>
                <div id="element-editor-capacity" class="hidden rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600"></div>
                <button type="button" id="save-element-name" class="sb-btn sb-btn-emerald">Sačuvaj podatke</button>
                <div id="element-editor-status" class="text-xs font-semibold text-emerald-700"></div>
            </div>
        </div>

        <!-- Plan projekta -->
        <details class="sidebar-card" @if($activeProjectId) open @endif>
        <summary class="sidebar-hd">
            <span class="sdot sdot-indigo"></span>
            Plan projekta
            <span id="bulk-plan-summary" class="text-[10px] font-normal text-slate-400 truncate" style="max-width:110px">0 ODF</span>
            <svg class="chev w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
        </summary>
        <form method="POST" action="{{ route('map.plan.store') }}" id="bulk-plan-form">
            @csrf
            <div class="sidebar-bd grid gap-3">
                <select id="active-project-id" name="project_id" class="sb-sel" required>
                    <option value="">Svi projekti</option>
                    @foreach($projects as $project)
                        <option value="{{ $project->id }}" @selected($activeProjectId === $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
                <div>
                    <div class="sb-kicker">Tok rada</div>
                    <div class="grid grid-cols-2 gap-1.5">
                        <button type="button" data-guide-mode="odf" class="step-btn step-cyan"><b>1</b> ODF</button>
                        <button type="button" data-guide-mode="draw" class="step-btn step-amber"><b>2</b> Trasa</button>
                        <button type="button" data-guide-mode="house" class="step-btn step-violet"><b>3</b> Kuće</button>
                        <button type="button" id="guide-suggest" class="step-btn step-emerald"><b>4</b> FTTH</button>
                    </div>
                </div>
                <div>
                    <div class="sb-kicker">Aktivni ODF</div>
                    <select id="active-odf-index" class="sb-sel mb-2"><option value="">Prvo postavi ODF</option></select>
                    <div id="odf-link-status" class="sb-info bg-cyan-50 text-cyan-800">Postavi ODF, zatim postavljaj FTTH ormariće.</div>
                </div>
                <div class="rounded-lg border border-amber-100 bg-amber-50 px-3 py-2.5">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-xs font-semibold text-amber-900">Obracun rova</div>
                        <span id="route-trench-status" class="rounded bg-amber-200 px-2 py-1 text-[10px] font-bold text-amber-900 whitespace-nowrap">tip trase odlucuje</span>
                    </div>
                    <div class="mt-1 text-xs text-amber-700 leading-5">Najpre Glavni rov, zatim mikrocijevi/krakovi.</div>
                </div>
                <details class="rounded-lg border border-amber-100 overflow-hidden">
                    <summary class="list-none flex items-center justify-between cursor-pointer px-3 py-2 text-xs font-semibold text-amber-900 bg-amber-50">
                        Postavke trase
                        <svg class="w-3.5 h-3.5 text-amber-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </summary>
                    <div class="p-3 grid gap-2 bg-white border-t border-amber-100">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-amber-900">Aktivni krak</span>
                            <span id="route-branch-count" class="text-xs font-semibold text-amber-600">0 krakova</span>
                        </div>
                        <div class="sb-kicker">Pocetak trase
                            <select id="route-start-source" class="sb-sel mt-1 font-normal">
                                <option value="">Automatski / bez veze</option>
                                @foreach($odfsForSelect as $odf)
                                    <option value="odf:{{ $odf->id }}">ODF: {{ $odf->name }} - {{ $odf->project->name }}</option>
                                @endforeach
                                @foreach($cabinetsForSelect as $cabinet)
                                    <option value="cabinet:{{ $cabinet->id }}">ODO: {{ $cabinet->name }} - {{ $cabinet->project->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="sb-kicker">Tip
                                <select id="route-draw-type" class="sb-sel mt-1 font-normal">
                                    <option value="trench">Glavni rov</option>
                                    <option value="feeder">Primarni</option>
                                    <option value="distribution">Sekundarni</option>
                                    <option value="drop">Drop</option>
                                </select>
                            </div>
                            <div class="sb-kicker">Mikrocijevi
                                <input id="route-draw-microducts" type="number" min="1" value="1" class="sb-inp mt-1">
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div class="sb-kicker">Polaganje
                                <select id="route-draw-installation" class="sb-sel mt-1 font-normal"><option value="underground">Podzemna</option><option value="aerial">Zracna</option></select>
                            </div>
                            <div class="sb-kicker">Mikrocijev
                                <select id="route-draw-microduct-type" class="sb-sel mt-1 font-normal"><option value="14/10">14/10</option><option value="10/8">10/8</option></select>
                            </div>
                            <div class="sb-kicker">Niti
                                <select id="route-draw-fiber-count" class="sb-sel mt-1 font-normal"><option value="4">4F</option><option value="12" selected>12F</option><option value="24">24F</option><option value="48">48F</option></select>
                            </div>
                        </div>
                        <div class="sb-kicker">Oznaka
                            <input id="route-draw-name" class="sb-inp mt-1" placeholder="npr. P-01 ili S-01">
                        </div>
                        <div id="route-odf-status" class="sb-info bg-amber-50 text-amber-900">Krak nije vezan na ODF.</div>
                        <div id="route-branch-list" class="grid max-h-28 gap-1 overflow-y-auto text-xs text-amber-900"></div>
                    </div>
                </details>
                <label class="flex items-center gap-2.5 cursor-pointer select-none rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 transition-colors hover:bg-amber-100">
                    <input type="checkbox" id="osm-routing-toggle" class="cb-custom">
                    <span class="text-xs font-semibold text-amber-900">Prati ulice (OSM)</span>
                    <span class="ml-auto text-[10px] font-mono font-semibold text-amber-400 bg-amber-100 border border-amber-200 rounded px-1">[R]</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer select-none rounded-lg border border-sky-200 bg-sky-50 px-3 py-2.5 transition-colors hover:bg-sky-100">
                    <input type="checkbox" id="gis-routing-toggle" class="cb-custom">
                    <span class="text-xs font-semibold text-sky-900">Interni GIS graf</span>
                    <span class="ml-auto text-[10px] font-mono font-semibold text-sky-500 bg-sky-100 border border-sky-200 rounded px-1">BETA</span>
                </label>
                <input id="bulk-plan-json" type="hidden" name="plan">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" id="save-draft" class="sb-btn sb-btn-outline">Radna verzija</button>
                    <button class="sb-btn sb-btn-emerald">Sačuvaj na mapi</button>
                </div>
                <div id="preflight-panel" class="hidden overflow-hidden rounded-lg border border-amber-200 bg-amber-50">
                    <div class="flex items-center justify-between border-b border-amber-200 px-3 py-2">
                        <b class="text-xs text-amber-950">Plan treba doradu</b>
                        <span id="preflight-count" class="rounded-full bg-amber-200 px-2 py-0.5 text-[10px] font-black text-amber-900"></span>
                    </div>
                    <div id="preflight-list" class="grid max-h-40 divide-y divide-amber-100 overflow-y-auto"></div>
                </div>
                <div id="export-actions" style="display:none" class="grid grid-cols-3 gap-1.5">
                    <a id="export-geojson" href="#" class="sb-btn sb-btn-outline" style="font-size:10px;padding:5px 8px;text-align:center">GeoJSON</a>
                    <a id="export-dxf" href="#" class="sb-btn sb-btn-outline" style="font-size:10px;padding:5px 8px;text-align:center">DXF</a>
                    <a id="print-project" href="#" class="sb-btn sb-btn-outline" style="font-size:10px;padding:5px 8px;text-align:center">Print</a>
                </div>
                <div id="bulk-plan-status" class="text-xs font-semibold text-emerald-700"></div>
            </div>
        </form>
        </details>

        <!-- Provjera projekta -->
        <details class="sidebar-card">
            <summary class="sidebar-hd">
                <span class="sdot sdot-amber"></span>
                Provjera projekta
                <svg class="chev w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            </summary>
            <div class="sidebar-bd grid gap-2">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" id="run-project-check" class="sb-btn sb-btn-outline">Provjeri</button>
                    <button type="button" id="fill-missing-drops" class="sb-btn sb-btn-amber-outline">Popuni dropove</button>
                </div>
                <button type="button" id="repair-drop-routes" class="sb-btn sb-btn-outline">Audit i popravi drop trase</button>
                <div id="project-check-summary" class="text-xs text-slate-500">Odaberi projekat i pokreni provjeru.</div>
                <div id="project-check-panel" class="grid max-h-60 gap-1 overflow-y-auto text-xs"></div>
            </div>
        </details>

        <!-- Trasa atributi (contextual) -->
        <div id="route-attribute-panel" class="ctx-panel hidden" style="border-color:#93c5fd;border-width:2px">
            <div class="ctx-panel-hd">
                <div>
                    <div class="text-sm font-semibold text-slate-900">Trasa</div>
                    <div id="route-attribute-status" class="text-xs text-slate-500">Odabrana trasa</div>
                </div>
                <button type="button" id="close-route-attribute-panel" class="sb-btn sb-btn-outline" style="width:auto;padding:3px 9px;font-size:11px">Zatvori</button>
            </div>
            <div class="sidebar-bd grid gap-2">
                <input id="route-attr-name" class="sb-inp" placeholder="Naziv trase">
                <div class="grid grid-cols-2 gap-2">
                    <select id="route-attr-type" class="sb-sel">
                        <option value="trench">Glavni rov</option>
                        <option value="backbone">Backbone</option>
                        <option value="feeder">Primarni</option>
                        <option value="distribution">Sekundarni</option>
                        <option value="drop">Drop</option>
                    </select>
                    <select id="route-attr-microduct" class="sb-sel">
                        <option value="">Bez mikrocijevi</option>
                        <option value="14/10">14/10</option>
                        <option value="10/8">10/8</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <select id="route-attr-fibers" class="sb-sel">
                        <option value="4">4F</option>
                        <option value="12">12F</option>
                        <option value="24">24F</option>
                        <option value="48">48F</option>
                    </select>
                    <select id="route-attr-installation" class="sb-sel">
                        <option value="underground">Podzemno</option>
                        <option value="aerial">Nadzemno</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <label class="grid gap-1"><span class="sb-kicker">Broj mikrocijevi</span><input id="route-attr-microduct-count" type="number" min="0" max="48" class="sb-inp" value="1"></label>
                    <label class="grid gap-1"><span class="sb-kicker">Napomena</span><input id="route-attr-note" class="sb-inp" placeholder="Napomena"></label>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" id="edit-route-geometry" class="sb-btn sb-btn-outline">Uredi geometriju</button>
                    <button type="button" id="save-route-attributes" class="sb-btn sb-btn-blue">Sačuvaj podatke trase</button>
                </div>
            </div>
        </div>

        <!-- Auto FTTH -->
        <details class="sidebar-card">
            <summary class="sidebar-hd">
                <span class="sdot sdot-violet"></span>
                Auto raspored FTTH
                <svg class="chev w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            </summary>
            <div class="sidebar-bd grid gap-3">
                <div class="grid grid-cols-4 gap-2">
                    <div class="sb-kicker">Min<input id="planner-min" type="number" min="1" max="12" value="8" class="sb-inp mt-1"></div>
                    <div class="sb-kicker">Max<input id="planner-max" type="number" min="1" max="12" value="12" class="sb-inp mt-1"></div>
                    <div class="sb-kicker">Max m<input id="planner-max-drop" type="number" min="20" value="90" class="sb-inp mt-1"></div>
                    <div class="flex items-end"><button type="button" id="clear-suggestions" class="sb-btn sb-btn-outline">Ocisti</button></div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" id="suggest-cabinets" class="sb-btn sb-btn-violet">Predlozi FTTH</button>
                    <button type="button" id="preview-gis-plan" class="sb-btn sb-btn-blue">GIS plan</button>
                </div>
                <div id="suggestion-output" class="max-h-52 overflow-auto rounded-lg border border-slate-100 bg-slate-50 p-3 text-xs text-slate-600 leading-5">Nacrtaj trasu i oznaci kuce.</div>
                <button type="button" id="save-gis-plan" class="hidden sb-btn sb-btn-blue">Snimi GIS mrezu</button>
                <button type="button" id="save-suggestions" class="hidden sb-btn sb-btn-violet">Potvrdi raspored</button>
            </div>
        </details>


        <!-- Layer Manager -->
        <details class="sidebar-card">
            <summary class="sidebar-hd">
                <span class="sdot sdot-slate"></span>
                Slojevi mape
                <svg class="chev w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            </summary>
            <div class="sidebar-bd grid gap-0.5 py-2">
                @foreach(['odf' => ['ODF','#0891b2'], 'odo' => ['ODO','#059669'], 'houses' => ['Kuce','#7c3aed'], 'trench' => ['Glavni rov','#64748b'], 'backbone' => ['Backbone','#0f172a'], 'distribution' => ['Distribution','#d97706'], 'drop' => ['Drop','#6d28d9'], 'gis' => ['GIS ceste','#0284c7'], 'dxf' => ['DXF','#9333ea'], 'preview' => ['Preview','#0369a1'], 'measure' => ['Mjerenje','#dc2626'], 'trace' => ['Fiber tracing','#0d9488']] as $layer => [$label, $color])
                <div class="layer-row">
                    <label class="flex flex-1 items-center gap-2 cursor-pointer select-none min-w-0">
                        <input type="checkbox" data-layer-toggle="{{ $layer }}" checked>
                        <span class="inline-block w-2 h-2 rounded-full shrink-0" style="background:{{ $color }}"></span>
                        <span class="text-slate-700 truncate">{{ $label }}</span>
                        <span data-layer-count="{{ $layer }}" class="text-[10px] text-slate-400 ml-auto shrink-0">0</span>
                    </label>
                    <input type="range" data-layer-opacity="{{ $layer }}" min="10" max="100" value="100" title="Providnost sloja" class="layer-opacity-slider">
                    <button type="button" data-layer-lock="{{ $layer }}" class="layer-lock-btn">&#x1F513;</button>
                </div>
                @endforeach
            </div>
        </details>

        <!-- Fiber tracing (contextual) -->
        <section id="map-trace-panel" class="ctx-panel hidden">
            <div class="ctx-panel-hd">
                <div class="text-sm font-semibold text-slate-900">Fiber tracing</div>
                <button type="button" id="clear-map-trace" class="sb-btn sb-btn-outline" style="width:auto;padding:3px 9px;font-size:11px">Ocisti</button>
            </div>
            <div class="sidebar-bd">
                <div id="map-trace-output" class="grid gap-2 text-xs text-slate-700"></div>
            </div>
        </section>

        <!-- Napredne forme -->
        <details class="sidebar-card">
            <summary class="sidebar-hd">
                <span class="sdot sdot-slate"></span>
                Napredno uređivanje
                <svg class="chev w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            </summary>
            <div class="sidebar-bd grid gap-3">
                <form method="POST" action="{{ route('odfs.store') }}" id="odf-form" class="grid gap-2 rounded-lg border border-cyan-100 bg-cyan-50/50 p-3">
                    @csrf
                    <div class="text-xs font-bold text-cyan-900 mb-1">Sačuvaj ODF</div>
                    <select name="project_id" class="sb-sel" required><option value="">Projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
                    <input name="name" class="sb-inp" placeholder="Naziv ODF-a" required>
                    <input name="address" class="sb-inp" placeholder="Adresa" required>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="fiber_capacity" value="144" min="1" class="sb-inp" placeholder="Vlakna">
                        <input type="number" name="port_count" value="48" min="1" class="sb-inp" placeholder="Portovi">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input id="odf-lat" name="latitude" class="sb-inp" placeholder="Lat" required>
                        <input id="odf-lng" name="longitude" class="sb-inp" placeholder="Lng" required>
                    </div>
                    <button class="sb-btn sb-btn-cyan">Sačuvaj ODF</button>
                </form>
                <form method="POST" action="{{ route('cabinets.store') }}" id="cabinet-form" class="grid gap-2 rounded-lg border border-emerald-100 bg-emerald-50/50 p-3">
                    @csrf
                    <div class="text-xs font-bold text-emerald-900 mb-1">Sačuvaj FTTH ormarić</div>
                    <select name="project_id" class="sb-sel" required><option value="">Projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
                    <select name="odf_id" class="sb-sel"><option value="">Povezani ODF</option>@foreach($odfsForSelect as $odf)<option value="{{ $odf->id }}">{{ $odf->name }} - {{ $odf->project->name }}</option>@endforeach</select>
                    <select name="parent_cabinet_id" class="sb-sel"><option value="">Napaja se iz ODF-a direktno</option>@foreach($cabinetsForSelect as $parentCabinet)<option value="{{ $parentCabinet->id }}">Iz ODO: {{ $parentCabinet->name }} - {{ $parentCabinet->project->name }}</option>@endforeach</select>
                    <input name="name" class="sb-inp" placeholder="Naziv, npr. FTTH 1-2-3" required>
                    <input name="address" class="sb-inp" placeholder="Adresa" required>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="splitter_count" value="3" min="1" max="3" class="sb-inp" placeholder="Splitteri">
                        <input type="number" name="ports_per_splitter" value="4" min="1" max="4" class="sb-inp" placeholder="Portovi">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input id="cabinet-lat" name="latitude" class="sb-inp" placeholder="Lat" required>
                        <input id="cabinet-lng" name="longitude" class="sb-inp" placeholder="Lng" required>
                    </div>
                    <button class="sb-btn sb-btn-emerald">Sačuvaj FTTH</button>
                </form>
                <form method="POST" action="{{ route('routes.store') }}" id="route-form" class="hidden">
                    @csrf
                    <input id="route-duct" name="duct_length_m" value="0">
                    <input id="route-fiber" name="fiber_length_m" value="0">
                    <input id="route-path" name="path" value="[]">
                </form>
                <form method="POST" action="{{ route('houses.store') }}" id="house-form" class="hidden">
                    @csrf
                    <input id="house-lat" name="latitude">
                    <input id="house-lng" name="longitude">
                </form>
            </div>
        </details>
    </aside>
</section>

<script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
@include('ftth.map._configuration')
<script src="{{ asset('js/map/state.js') }}?v={{ filemtime(public_path('js/map/state.js')) }}"></script>
<script src="{{ asset('js/map/utils.js') }}?v={{ filemtime(public_path('js/map/utils.js')) }}"></script>
<script src="{{ asset('js/map/layers.js') }}?v={{ filemtime(public_path('js/map/layers.js')) }}"></script>
<script src="{{ asset('js/map/markers.js') }}?v={{ filemtime(public_path('js/map/markers.js')) }}"></script>
<script src="{{ asset('js/map/context.js') }}?v={{ filemtime(public_path('js/map/context.js')) }}"></script>
<script src="{{ asset('js/map/transform.js') }}?v={{ filemtime(public_path('js/map/transform.js')) }}"></script>
<script src="{{ asset('js/map/routes.js') }}?v={{ filemtime(public_path('js/map/routes.js')) }}"></script>
<script src="{{ asset('js/map/connect.js') }}?v={{ filemtime(public_path('js/map/connect.js')) }}"></script>
<script src="{{ asset('js/map/edit.js') }}?v={{ filemtime(public_path('js/map/edit.js')) }}"></script>
<script src="{{ asset('js/map/draw.js') }}?v={{ filemtime(public_path('js/map/draw.js')) }}"></script>
<script src="{{ asset('js/map/autoplan.js') }}?v={{ filemtime(public_path('js/map/autoplan.js')) }}"></script>
<script src="{{ asset('js/map/draft.js') }}?v={{ filemtime(public_path('js/map/draft.js')) }}"></script>
<script src="{{ asset('js/map/toolbar.js') }}?v={{ filemtime(public_path('js/map/toolbar.js')) }}"></script>
<script src="{{ asset('js/map/init.js') }}?v={{ filemtime(public_path('js/map/init.js')) }}"></script>

@include('ftth.map._dxf-panel')
@include('ftth.map._survey-panel')

<script src="{{ asset('vendor/proj4/proj4.js') }}"></script>
<script src="{{ asset('js/ftth-dxf-layer.js') }}?v={{ filemtime(public_path('js/ftth-dxf-layer.js')) }}"></script>
{{-- survey.js mora doci NAKON #survey-panel HTML-a da bi dugmad postojala pri bindanju --}}
<script src="{{ asset('js/map/survey.js') }}?v={{ filemtime(public_path('js/map/survey.js')) }}"></script>
@endsection
