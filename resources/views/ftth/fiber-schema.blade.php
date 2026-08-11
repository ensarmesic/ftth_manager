@extends('ftth.layout')

@section('title', 'FTTH Topologija')
@section('subtitle', 'Tehnicka fiber sema: ODF, magistralni kabl, FTTH ormarici, splitteri i kuce.')

@section('content')
<style>
    .schema-page { display: grid; gap: .75rem; }
    .schema-project { overflow: hidden; border: 1px solid #dbe7f3; border-radius: .75rem; background: #fff; box-shadow: 0 12px 30px rgb(16 24 40 / .06); }
    .schema-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; border-bottom: 1px solid #dfeaf5; background: #f8fbff; padding: .7rem .85rem; }
    .schema-stats { display: flex; flex-wrap: wrap; gap: .35rem; }
    .schema-chip { border-radius: 999px; background: #edf6ff; padding: .2rem .5rem; color: #005f96; font-size: .71rem; font-weight: 800; }
    .schema-shell { display: grid; gap: .65rem; padding: .65rem; max-height: 80vh; overflow-y: auto; }
    @media (min-width: 1180px) { .schema-shell { grid-template-columns: minmax(0, 1fr) 260px; } }
    .schema-board { min-width: 0; overflow: visible; border: 1px solid #dbe7f3; border-radius: .75rem; background:
        linear-gradient(#e8eef6 1px, transparent 1px),
        linear-gradient(90deg, #e8eef6 1px, transparent 1px),
        #f9fbfe; background-size: 28px 28px; padding: .65rem; }
    .schema-legend { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .55rem; }
    .legend-item { display: inline-flex; align-items: center; gap: .3rem; border: 1px solid #dbe7f3; border-radius: 999px; background: rgb(255 255 255 / .9); padding: .22rem .45rem; color: #475467; font-size: .67rem; font-weight: 800; }
    .legend-swatch { width: .72rem; height: .72rem; border-radius: .18rem; background: #2684c2; }
    .legend-swatch.odf { border: 2px solid #2684c2; background: #eaf6ff; }
    .legend-swatch.ftth { border: 2px solid #65a845; background: #f2faeb; }
    .legend-swatch.used { border: 1px solid #91c6eb; background: #eaf6ff; }
    .legend-swatch.free { border: 1px dashed #aab7c4; background: #fff; }
    .board-labels { display: grid; grid-template-columns: minmax(110px, 145px) minmax(22px, 34px) minmax(0, 1fr); gap: .45rem; margin: 0 0 .35rem; color: #667085; font-size: .62rem; font-weight: 950; letter-spacing: 0; text-transform: uppercase; }
    .board-labels span { border-bottom: 1px solid #d8e4f0; padding: 0 0 .22rem; }
    .schema-row { display: grid; grid-template-columns: minmax(110px, 145px) minmax(22px, 34px) minmax(0, 1fr); gap: .45rem; align-items: stretch; }
    .odf-rack { display: grid; grid-template-rows: auto 1fr; min-height: 150px; border: 2px solid #2684c2; border-radius: .45rem; background: #eaf6ff; box-shadow: inset 0 0 0 1px #fff; }
    .odf-rack header { display: flex; align-items: center; justify-content: space-between; gap: .35rem; border-bottom: 1px solid #b8d7ef; padding: .4rem .5rem; color: #004f7d; font-size: .72rem; font-weight: 900; }
    .odf-meta { display: grid; gap: .2rem; padding: .45rem .5rem; color: #334155; font-size: .7rem; line-height: 1.18; }
    .fiber-bus { position: relative; min-height: 100%; }
    .fiber-bus::before { content: ""; position: absolute; left: 50%; top: .75rem; bottom: .75rem; width: 4px; transform: translateX(-50%); border-radius: 999px; background: #2684c2; box-shadow: 0 0 0 2px #dff0ff; }
    .fiber-bus span { position: absolute; left: 50%; top: .35rem; transform: translateX(-50%) rotate(90deg); transform-origin: center; border: 1px solid #b8d7ef; border-radius: 999px; background: #fff; padding: .08rem .28rem; color: #005f96; font-size: .52rem; font-weight: 900; white-space: nowrap; }
    .cabinet-area { display: grid; gap: .42rem; min-width: 0; }
    .cabinet-node { position: relative; display: grid; grid-template-columns: minmax(100px, 145px) minmax(0, 1fr); gap: .42rem; align-items: stretch; min-width: 0; }
    .cabinet-node::before { content: ""; position: absolute; left: -2.4rem; top: 1.45rem; width: 2.4rem; height: 2px; background: #2684c2; }
    .connection-tag { position: absolute; left: -2.35rem; top: .35rem; z-index: 1; border: 1px solid #b8d7ef; border-radius: 999px; background: #fff; padding: .08rem .28rem; color: #005f96; font-size: .5rem; font-weight: 950; }
    .cabinet-box { border: 2px solid #65a845; border-radius: .45rem; background: #f2faeb; padding: .48rem; min-width: 0; }
    .cabinet-box.warn { border-color: #d99a15; background: #fff8e8; }
    .cabinet-box.full { border-color: #dc2626; background: #fff2f2; }
    .cabinet-title { display: flex; justify-content: space-between; gap: .4rem; color: #172033; font-size: .76rem; font-weight: 900; }
    .cabinet-title span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .child-cabinets { grid-column: 1 / -1; display: grid; gap: .35rem; margin-left: 1.1rem; padding-left: .75rem; border-left: 2px dashed #65a845; }
    .child-cabinet-node { position: relative; display: grid; grid-template-columns: minmax(100px, 145px) minmax(0, 1fr); gap: .42rem; align-items: stretch; }
    .child-cabinet-node::before { content: ""; position: absolute; left: -.75rem; top: 1.35rem; width: .75rem; height: 2px; background: #65a845; }
    .child-tag { display: inline-block; width: fit-content; border: 1px solid #b7dfaa; border-radius: 999px; background: #fff; padding: .08rem .28rem; color: #34751f; font-size: .5rem; font-weight: 950; }
    .child-cabinet-node .cabinet-box { border-color: #2f8f5b; background: #f0fff7; }
    .util-bar { height: .28rem; overflow: hidden; border-radius: 999px; background: #dfe7ef; margin-top: .4rem; }
    .util-bar div { height: 100%; border-radius: inherit; background: #65a845; }
    .cabinet-box.warn .util-bar div { background: #d99a15; }
    .cabinet-box.full .util-bar div { background: #dc2626; }
    .splitter-panel { display: grid; gap: .28rem; min-width: 0; }
    .splitter-line { display: grid; grid-template-columns: 70px repeat(4, minmax(0, 1fr)); gap: .22rem; align-items: center; }
    .splitter-label { border: 1px solid #cddbea; border-radius: .35rem; background: #fff; padding: .28rem .35rem; color: #334155; font-size: .66rem; font-weight: 850; text-align: center; }
    .port { position: relative; min-height: 28px; border: 1px solid #d8e4f0; border-left: 4px solid #2684c2; border-radius: .35rem; background: #fff; padding: .22rem .3rem .22rem .42rem; color: #1f2a3a; font-size: clamp(.62rem, .66vw, .72rem); font-weight: 760; text-align: left; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .port::after { content: ""; position: absolute; right: .32rem; top: .42rem; width: .34rem; height: .34rem; border-radius: 999px; background: #16a34a; box-shadow: 0 0 0 2px #dcfce7; }
    .port b { display: inline-block; margin-right: .25rem; color: #7a8797; font-size: .58rem; }
    .port.empty { border-left-color: #cbd5e1; border-style: dashed; color: #9aa6b2; font-weight: 650; }
    .port.empty::after { background: #cbd5e1; box-shadow: none; }
    .port.active, .port:hover { border-color: #2684c2; background: #eaf6ff; color: #004f7d; }
    .trace-panel { border: 1px solid #dbe7f3; border-radius: .75rem; background: #fff; padding: .75rem; align-self: start; }
    @media (min-width: 1180px) { .trace-panel { position: sticky; top: .75rem; } }
    .trace-chain { display: grid; gap: .4rem; margin-top: .6rem; }
    .trace-step { border-left: 3px solid #2684c2; border-radius: .35rem; background: #f8fbff; padding: .45rem .55rem; }
    .trace-step b { display: block; color: #101828; font-size: .82rem; }
    .trace-step span { color: #667085; font-size: .72rem; }
    .topology-wrap { margin: .65rem; overflow: hidden; border: 1px solid #dbe7f3; border-radius: .65rem; background: #fff; padding: .75rem; }
    .topology-title { margin-bottom: .6rem; color: #334155; font-size: .78rem; font-weight: 900; }
    .topology-viewport { width: 100%; overflow: hidden; }
    .topology-canvas { width: max-content; min-width: 100%; transform-origin: top left; }
    .topology-odf { display: grid; justify-items: center; min-width: max-content; padding: .15rem .5rem .6rem; }
    .topology-box { position: relative; z-index: 2; border: 2px solid #60a5fa; border-radius: .3rem; background: #eff6ff; padding: .32rem .5rem; color: #1e3a5f; font-size: .68rem; font-weight: 900; box-shadow: 0 1px 3px rgb(15 23 42 / .12); }
    .topology-box.odo { display: grid; gap: .12rem; min-width: 84px; border-color: #65a845; background: #f2faeb; color: #315f24; text-align: center; }
    .topology-box.odo.full { border-color: #ef4444; background: #fff1f2; color: #991b1b; }
    .topology-box small { color: #64748b; font-size: .48rem; font-weight: 800; }
    .topology-cabinets { position: relative; display: flex; align-items: flex-start; gap: 1rem; padding-top: 1.35rem; }
    .topology-cabinets::before { content: ""; position: absolute; left: 2.5rem; right: 2.5rem; top: .7rem; height: 2px; background: #64748b; }
    .topology-cabinet { position: relative; display: grid; justify-items: center; min-width: 112px; }
    .topology-cabinet::before { content: ""; position: absolute; left: 50%; top: -1.35rem; width: 2px; height: 1.35rem; background: #64748b; }
    .topology-splitters { position: relative; display: flex; align-items: flex-start; gap: .35rem; padding-top: 1.15rem; }
    .topology-splitters::before { content: ""; position: absolute; left: 1.35rem; right: 1.35rem; top: .55rem; height: 2px; background: #65a845; }
    .topology-splitter { position: relative; display: grid; justify-items: center; gap: .3rem; min-width: 34px; }
    .topology-splitter::before { content: ""; position: absolute; left: 50%; top: -1.15rem; width: 2px; height: 1.15rem; background: #65a845; }
    .topology-splitter-label { border: 1px solid #a78bfa; border-radius: .25rem; background: #f5f3ff; padding: .2rem .25rem; color: #6d28d9; font-size: .52rem; font-weight: 900; text-align: center; }
    .topology-houses { position: relative; display: flex; gap: .22rem; padding-top: .8rem; }
    .topology-houses::before { content: ""; position: absolute; left: .45rem; right: .45rem; top: .38rem; height: 1px; background: #a78bfa; }
    .topology-port { position: relative; display: grid; justify-items: center; gap: .12rem; width: 1rem; padding-top: .05rem; }
    .topology-port::before { content: ""; position: absolute; left: 50%; top: -.8rem; width: 1px; height: .62rem; background: #a78bfa; }
    .topology-fiber { display: none; }
    .topology-house { position: relative; width: .72rem; height: .58rem; border-radius: .08rem; background: #fb923c; }
    .topology-house::before { content: ""; position: absolute; left: 50%; top: -.32rem; transform: translateX(-50%); border-left: .42rem solid transparent; border-right: .42rem solid transparent; border-bottom: .38rem solid #f97316; }
    .topology-port.empty { opacity: .42; }
    .topology-port.empty .topology-house { background: #cbd5e1; }
    .topology-port.empty .topology-house::before { border-bottom-color: #94a3b8; }
    .topology-house-name { display: none; }
    .topology-legend { display: flex; flex-wrap: wrap; justify-content: center; gap: 1.1rem; border-top: 1px solid #e2e8f0; padding-top: .6rem; color: #475569; font-size: .62rem; font-weight: 800; }
    .topology-line { display: inline-block; width: 1.3rem; height: 2px; vertical-align: middle; margin-right: .25rem; background: #64748b; }
    .topology-line.distribution { background: #65a845; }
    .topology-line.drop { background: #a78bfa; }
    .schema-view-tabs { display: flex; flex-wrap: wrap; gap: .35rem; padding: .65rem .65rem 0; }
    .schema-view-tab { border: 1px solid #cbd5e1; border-radius: .35rem; background: #fff; padding: .42rem .72rem; color: #475569; font-size: .72rem; font-weight: 900; box-shadow: 0 1px 2px rgb(15 23 42 / .06); transition: background-color .15s ease, border-color .15s ease, color .15s ease; }
    .schema-view-tab:hover { border-color: #93c5fd; background: #f8fbff; color: #1d4ed8; }
    .schema-view-tab.active { border-color: #2563eb; background: #eff6ff; color: #1d4ed8; box-shadow: inset 0 -2px 0 #2563eb; }
    .topology-graph-shell { position: relative; margin: .65rem; height: min(72vh, 720px); min-height: 420px; overflow: hidden; border: 1px solid #cbd5e1; border-radius: .65rem; background:
        linear-gradient(#e8eef6 1px, transparent 1px),
        linear-gradient(90deg, #e8eef6 1px, transparent 1px),
        #f8fafc; background-size: 28px 28px; }
    .topology-graph-stage { position: absolute; inset: 0; transform-origin: 0 0; }
    .topology-graph-stage svg { overflow: visible; }
    .topology-node { cursor: pointer; }
    .topology-node rect { stroke-width: 2; filter: drop-shadow(0 2px 2px rgb(15 23 42 / .15)); }
    .topology-node text { pointer-events: none; font-family: ui-sans-serif, system-ui, sans-serif; font-size: 11px; font-weight: 800; fill: #172033; }
    .topology-edge { fill: none; stroke: #64748b; stroke-width: 2.5; }
    .topology-edge.child { stroke: #65a845; stroke-dasharray: 6 4; }
    .topology-edge.cabinet-branch { stroke: #16a34a; stroke-width: 3.5; }
    .topology-edge.drop { stroke: #a78bfa; stroke-width: 1.4; }
    .topology-minimap { position: absolute; right: .7rem; bottom: .7rem; width: 180px; height: 120px; overflow: hidden; border: 1px solid #94a3b8; border-radius: .4rem; background: rgb(255 255 255 / .94); box-shadow: 0 5px 16px rgb(15 23 42 / .18); }
    .topology-minimap svg { width: 100%; height: 100%; }
    .topology-controls, .cad-fiber-controls { position: absolute; left: .7rem; top: .7rem; z-index: 3; display: flex; flex-wrap: wrap; gap: .3rem; }
    .topology-controls button, .cad-fiber-controls button { min-width: 2rem; border: 1px solid #cbd5e1; border-radius: .3rem; background: rgb(255 255 255 / .96); padding: .34rem .55rem; color: #334155; font-size: .68rem; font-weight: 900; box-shadow: 0 2px 8px rgb(15 23 42 / .12); }
    .topology-controls button:hover, .cad-fiber-controls button:hover { border-color: #93c5fd; background: #eff6ff; color: #1d4ed8; }
    .topology-help { position: absolute; left: .7rem; bottom: .7rem; z-index: 3; border: 1px solid #e2e8f0; border-radius: .35rem; background: rgb(255 255 255 / .92); padding: .34rem .5rem; color: #64748b; font-size: .6rem; font-weight: 800; box-shadow: 0 2px 8px rgb(15 23 42 / .08); }
    .cad-fiber-shell { position: relative; margin: .65rem; height: min(78vh, 820px); min-height: 520px; overflow: hidden; border: 1px solid #cbd5e1; border-radius: .65rem; background:
        linear-gradient(#edf2f7 1px, transparent 1px),
        linear-gradient(90deg, #edf2f7 1px, transparent 1px),
        #fff; background-size: 32px 32px; }
    .cad-fiber-stage { position: absolute; inset: 0; transform-origin: 0 0; }
    .cad-fiber-stage svg { overflow: visible; }
    .cad-fiber-help { position: absolute; left: .7rem; bottom: .7rem; z-index: 3; border: 1px solid #e2e8f0; border-radius: .35rem; background: rgb(255 255 255 / .92); padding: .34rem .5rem; color: #64748b; font-size: .62rem; font-weight: 800; box-shadow: 0 2px 8px rgb(15 23 42 / .08); }
    .fiber-color-board { margin: .65rem; overflow: hidden; border: 1px solid #cbd5e1; border-radius: .75rem; background: #f8fafc; }
    .fiber-color-hero { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.1rem; color: #fff; background: radial-gradient(circle at 85% 20%, rgb(34 211 238 / .28), transparent 30%), linear-gradient(125deg, #07152d, #123c67); }
    .fiber-color-hero h3 { font-size: 1rem; font-weight: 950; letter-spacing: -.015em; }
    .fiber-color-hero p { max-width: 52rem; margin-top: .25rem; color: #bfdbfe; font-size: .72rem; }
    .fiber-standard-badge { flex: 0 0 auto; border: 1px solid rgb(125 211 252 / .5); border-radius: 999px; background: rgb(14 165 233 / .16); padding: .3rem .65rem; color: #e0f2fe; font-size: .66rem; font-weight: 900; }
    .fiber-color-legend { display: grid; grid-template-columns: repeat(12, minmax(68px, 1fr)); gap: .4rem; padding: .8rem; overflow-x: auto; background: #fff; border-bottom: 1px solid #e2e8f0; }
    .fiber-legend-item { min-width: 68px; text-align: center; }
    .fiber-legend-swatch { display: grid; place-items: center; height: 2rem; border: 2px solid; border-radius: .45rem; font-size: .7rem; font-weight: 950; }
    .fiber-legend-item small { display: block; margin-top: .2rem; color: #64748b; font-size: .58rem; font-weight: 800; }
    .fiber-allocation-list { display: grid; gap: 1rem; padding: 1rem; background: linear-gradient(#e8eef6 1px, transparent 1px), linear-gradient(90deg, #e8eef6 1px, transparent 1px), #f8fafc; background-size: 24px 24px; }
    .fiber-schematic { position: relative; display: grid; grid-template-columns: 124px minmax(260px, 1fr) 150px; align-items: center; min-height: 132px; border: 1px solid #d4e1ee; border-radius: .75rem; background: rgb(255 255 255 / .94); padding: 1rem; box-shadow: 0 7px 20px rgb(15 23 42 / .06); }
    .fiber-node { position: relative; z-index: 2; display: grid; place-items: center; min-height: 82px; border: 2px solid; border-radius: .55rem; padding: .55rem; text-align: center; box-shadow: 0 4px 10px rgb(15 23 42 / .1); }
    .fiber-node.odf { border-color: #0284c7; background: linear-gradient(145deg, #e0f2fe, #fff); color: #075985; }
    .fiber-node.odo { border-color: #16a34a; background: linear-gradient(145deg, #dcfce7, #fff); color: #166534; }
    .fiber-node-icon { display: grid; place-items: center; width: 2rem; height: 2rem; margin-bottom: .25rem; border-radius: .4rem; background: currentColor; color: #fff; font-size: .58rem; font-weight: 950; }
    .fiber-node b { max-width: 100%; overflow: hidden; text-overflow: ellipsis; color: #0f172a; font-size: .7rem; white-space: nowrap; }
    .fiber-node small { margin-top: .12rem; font-size: .55rem; font-weight: 800; opacity: .72; }
    .fiber-cable-run { position: relative; z-index: 1; min-width: 0; padding: .2rem 1rem; }
    .fiber-cable-run::before { content: ''; position: absolute; z-index: -2; left: -2px; right: -2px; top: 50%; height: 16px; transform: translateY(-50%); border: 2px solid #334155; border-radius: 999px; background: #0f172a; box-shadow: 0 0 0 4px #e2e8f0; }
    .fiber-cable-run::after { content: ''; position: absolute; right: -8px; top: 50%; width: 0; height: 0; transform: translateY(-50%); border-top: 9px solid transparent; border-bottom: 9px solid transparent; border-left: 12px solid #334155; }
    .fiber-cable-label { position: absolute; left: 50%; top: -1.05rem; transform: translateX(-50%); border: 1px solid #cbd5e1; border-radius: 999px; background: #fff; padding: .15rem .55rem; color: #475569; font-size: .57rem; font-weight: 900; white-space: nowrap; }
    .fiber-chip-list { display: flex; position: relative; justify-content: center; flex-wrap: wrap; gap: .35rem; }
    .fiber-code-chip { display: grid; position: relative; z-index: 2; width: 54px; overflow: hidden; border: 2px solid #fff; border-radius: .45rem; background: #fff; box-shadow: 0 2px 8px rgb(15 23 42 / .28); text-align: center; }
    .fiber-tube-mark { display: block; padding: .12rem; border-bottom: 1px solid rgb(15 23 42 / .12); font-size: .5rem; font-weight: 950; }
    .fiber-core-mark { display: block; padding: .25rem .12rem; font-size: .61rem; font-weight: 950; line-height: 1.1; }
    .fiber-core-mark small { display: block; margin-top: .1rem; font-size: .45rem; }
    .fiber-color-empty { border: 1px dashed #cbd5e1; border-radius: .55rem; padding: 1rem; color: #64748b; text-align: center; font-size: .72rem; font-weight: 800; }
    .fiber-color-note { padding: 0 .8rem .8rem; color: #64748b; font-size: .62rem; }
    .fiber-tool-panel { margin: .65rem; max-height: 72vh; overflow: auto; border: 1px solid #dbe5ef; border-radius: .7rem; background: #f8fafc; padding: .8rem; }
    .fiber-tool-title { margin-bottom: .65rem; color: #0f172a; font-size: .85rem; font-weight: 950; }
    .fiber-plan-grid { display: grid; gap: .55rem; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
    .fiber-plan-card { border: 1px solid #dbe5ef; border-radius: .55rem; background: #fff; padding: .65rem; box-shadow: 0 2px 7px rgb(15 23 42 / .04); }
    .fiber-plan-card header { display:flex; justify-content:space-between; gap:.5rem; margin-bottom:.45rem; font-size:.7rem; font-weight:900; }
    .fiber-plan-line { display:grid; grid-template-columns:45px 52px 1fr; gap:.35rem; align-items:center; border-top:1px solid #eef2f7; padding:.3rem 0; font-size:.62rem; }
    .fiber-dot { display:inline-block; width:.72rem; height:.72rem; margin-right:.25rem; border:1px solid rgb(15 23 42 / .25); border-radius:999px; vertical-align:middle; }
    .fiber-check { display:flex; gap:.55rem; align-items:flex-start; border:1px solid; border-radius:.55rem; background:#fff; padding:.65rem; font-size:.7rem; font-weight:750; }
    .fiber-check.ok { border-color:#86efac;color:#166534 }.fiber-check.warn { border-color:#fcd34d;color:#92400e }.fiber-check.error { border-color:#fca5a5;color:#991b1b }
    .cad-color-legend { position:absolute; right:.7rem; top:.7rem; z-index:3; display:flex; flex-wrap:wrap; max-width:390px; gap:.22rem; border:1px solid #cbd5e1; border-radius:.4rem; background:rgb(255 255 255 / .95); padding:.35rem; box-shadow:0 2px 8px rgb(15 23 42 / .1); }
    .cad-color-legend span { display:flex; align-items:center; gap:.2rem; font-size:.5rem; font-weight:850; color:#334155 }
    @media (max-width: 920px) {
        .schema-row, .board-labels { grid-template-columns: 1fr; }
        .fiber-bus, .cabinet-node::before { display: none; }
        .cabinet-node, .child-cabinet-node { grid-template-columns: 1fr; }
        .child-cabinets { margin-left: 0; }
        .connection-tag { position: static; width: fit-content; margin-bottom: -.2rem; }
    }
    @media (max-width: 620px) {
        .schema-board, .schema-shell, .schema-head { padding: .5rem; }
        .splitter-line { grid-template-columns: 1fr 1fr; }
        .splitter-label { grid-column: 1 / -1; text-align: left; }
        .fiber-color-hero { display: grid; grid-template-columns: 1fr; }
        .fiber-schematic { grid-template-columns: 82px minmax(150px, 1fr) 92px; padding: .65rem; }
        .fiber-node { min-height: 70px; padding: .3rem; }
        .fiber-node-icon { width: 1.6rem; height: 1.6rem; }
        .fiber-node small { display: none; }
        .fiber-cable-run { padding-inline: .45rem; }
        .fiber-cable-label { top: -1.2rem; }
        .fiber-standard-badge { width: fit-content; }
    }
    .fiber-warning-band { margin-bottom: .55rem; display: grid; gap: .28rem; }
    .fiber-warning-item { display: flex; align-items: flex-start; gap: .4rem; border: 1px solid #fca5a5; border-radius: .4rem; background: #fff1f1; padding: .38rem .6rem; color: #991b1b; font-size: .69rem; font-weight: 800; }
    .rack-branch-section { display: grid; gap: .42rem; margin-bottom: .7rem; }
    .rack-branch-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #2684c2; padding: .22rem .1rem .18rem; margin-bottom: .32rem; color: #004f7d; font-size: .73rem; font-weight: 900; letter-spacing: .01em; }
    .rack-fiber-badge { border: 1px solid #b8d7ef; border-radius: 999px; background: #eaf6ff; padding: .1rem .48rem; color: #1d4ed8; font-size: .66rem; font-weight: 900; }
    .rack-unassigned-section { display: grid; gap: .42rem; margin-bottom: .7rem; }
    .rack-unassigned-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #d97706; padding: .22rem .1rem .18rem; margin-bottom: .32rem; color: #92400e; font-size: .73rem; font-weight: 900; letter-spacing: .01em; }
    .rack-unassigned-badge { border: 1px solid #fde68a; border-radius: 999px; background: #fef3c7; padding: .1rem .48rem; color: #92400e; font-size: .66rem; font-weight: 900; }
    /* ── Project filter bar ─────────────────────────────────────────────── */
    .fiber-project-filter { display: flex; flex-wrap: wrap; gap: .4rem; padding: .6rem 0 .25rem; }
    .fpf-btn { border: 1.5px solid #dbe7f3; border-radius: .45rem; background: #fff; padding: .38rem .8rem; color: #475569; font-size: .76rem; font-weight: 800; cursor: pointer; transition: background .12s, border-color .12s, color .12s; white-space: nowrap; }
    .fpf-btn:hover { border-color: #93c5fd; background: #f0f7ff; color: #1d4ed8; }
    .fpf-btn.active { border-color: #2563eb; background: #eff6ff; color: #1d4ed8; box-shadow: inset 0 -2px 0 #2563eb; }
    /* Compact engineering workspace */
    .fiber-project-filter { position: sticky; top: 0; z-index: 8; align-items: center; margin: -.25rem -.15rem .45rem; padding: .4rem .25rem; background: rgb(241 245 249 / .94); backdrop-filter: blur(8px); }
    .fpf-btn { padding: .3rem .65rem; border-radius: 999px; font-size: .68rem; }
    .schema-project { border-radius: .65rem; box-shadow: 0 8px 24px rgb(15 23 42 / .055); }
    .schema-head { min-height: 48px; padding: .48rem .65rem; background: linear-gradient(90deg,#f8fbff,#fff); }
    .schema-head h2 { font-size: .88rem; }
    .schema-stats { align-items: center; gap: .25rem; }
    .schema-chip { padding: .14rem .42rem; font-size: .62rem; }
    .schema-stats a { padding: .16rem .48rem !important; font-size: .63rem !important; }
    .schema-view-tabs { position: relative; gap: .2rem; overflow-x: auto; flex-wrap: nowrap; padding: .42rem .55rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc; scrollbar-width: thin; }
    .schema-view-tab { flex: 0 0 auto; border-color: transparent; border-radius: .4rem; background: transparent; padding: .34rem .58rem; box-shadow: none; font-size: .64rem; }
    .schema-view-tab:hover { border-color: #dbeafe; }
    .schema-view-tab.active { border-color: #bfdbfe; background: #fff; box-shadow: 0 1px 4px rgb(15 23 42 / .08), inset 0 -2px 0 #2563eb; }
    .cad-fiber-shell { margin: .5rem; height: min(72vh, 720px); min-height: 460px; border-radius: .55rem; }
    .cad-fiber-controls { gap: .2rem; left: .5rem; top: .5rem; }
    .cad-fiber-controls button { min-width: 1.8rem; padding: .27rem .45rem; font-size: .61rem; }
    .cad-fiber-help { left: .5rem; bottom: .5rem; max-width: calc(100% - 1rem); padding: .25rem .42rem; font-size: .55rem; }
    .cad-color-legend { right: .5rem; top: .5rem; max-width: 350px; padding: .28rem; gap: .16rem .3rem; }
    .cad-color-legend .fiber-dot { width: .55rem; height: .55rem; }
    .fiber-tool-panel { margin: .5rem; max-height: 70vh; padding: .6rem; border-radius: .55rem; }
    .fiber-tool-title { display:flex; align-items:center; min-height: 30px; margin: -.6rem -.6rem .55rem; padding: .45rem .65rem; border-bottom:1px solid #e2e8f0; background:#fff; font-size:.75rem; }
    .fiber-plan-grid { gap: .4rem; grid-template-columns: repeat(auto-fill,minmax(235px,1fr)); }
    .fiber-plan-card { padding: .5rem; border-radius: .45rem; }
    .fiber-plan-card header { margin-bottom: .3rem; font-size: .65rem; }
    .fiber-plan-line { grid-template-columns: 39px 48px 1fr; padding: .24rem 0; font-size: .57rem; }
    .fiber-check { padding: .5rem .6rem; border-radius: .45rem; font-size: .64rem; }
    @media (max-width: 760px) {
        .schema-head { align-items:flex-start; }
        .schema-stats { width:100%; }
        .cad-fiber-shell { min-height:400px; height:65vh; margin:.35rem; }
        .cad-color-legend { top:auto; right:.4rem; bottom:2.15rem; max-width:calc(100% - .8rem); }
        .cad-color-legend span { font-size:.46rem; }
        .fiber-tool-panel { margin:.35rem; }
    }
</style>

<div class="fiber-project-filter" id="fiber-project-filter">
    @foreach($projects as $p)
        <button type="button" class="fpf-btn" data-filter="{{ $p->id }}">{{ $p->name }}</button>
    @endforeach
</div>

<section class="schema-page" id="schema-page">
@forelse($projects as $project)
    @php
        $cabinets = $project->odfs->flatMap->cabinets;
        $childCabinets = $cabinets->flatMap->childCabinets;
        $allCabinets = $cabinets->merge($childCabinets);
        $totalHouses = $allCabinets->sum('houses_count');
        $totalCapacity = max($allCabinets->sum(fn ($cabinet) => max($cabinet->capacity, 12)), 1);
        $projectUtilization = min(100, round($totalHouses / $totalCapacity * 100));
        $neededSplitters = function ($cabinet): int {
            $houseCount = $cabinet->houses_count ?? ($cabinet->relationLoaded('houses') ? $cabinet->houses->count() : $cabinet->houses()->count());
            return (int) ceil(((int) $houseCount) / max(1, (int) $cabinet->ports_per_splitter));
        };
        $fiberAllocations = [];
        $nextFiber = 1;
        $fibersPerTube = str_ends_with($project->fiber_layout ?? '6x24', 'x12') ? 12 : 24;
        $tubeCount = (int) str($project->fiber_layout ?? '6x24')->before('x')->value();
        $configuredFiberCapacity = $tubeCount * $fibersPerTube;
        $reservePerTube = min((int) ($project->fiber_reserve_per_tube ?? 0), $fibersPerTube - 1);
        $allocateFibers = function (int $count) use (&$nextFiber, $fibersPerTube, $reservePerTube): array {
            $position = (($nextFiber - 1) % $fibersPerTube) + 1;
            $usable = $fibersPerTube - $reservePerTube;
            if ($position > $usable || $position + $count - 1 > $usable) {
                $nextFiber += $fibersPerTube - $position + 1;
            }
            $allocation = ['from' => $nextFiber, 'to' => $nextFiber + $count - 1, 'count' => $count];
            $nextFiber += $count;
            return $allocation;
        };
        $project->odfs
            ->sortBy(fn ($odf) => (string) $odf->name)
            ->each(function ($odf) use ($project, &$fiberAllocations, &$nextFiber, $neededSplitters, $allocateFibers) {
            $project->branches
                ->where('type', 'secondary')
                ->where('odf_id', $odf->id)
                ->sortBy(fn ($branch) => sprintf('%06d|%s', (int) ($branch->sort_order ?? 0), (string) $branch->name))
                ->each(function ($branch) use (&$fiberAllocations, &$nextFiber, $neededSplitters, $allocateFibers) {
                    $branch->cabinets
                        ->sortBy(fn ($cabinet) => sprintf('%06d|%s', (int) ($cabinet->branch_order ?? 0), (string) $cabinet->name))
                        ->each(function ($cabinet) use (&$fiberAllocations, &$nextFiber, $neededSplitters, $allocateFibers) {
                            $fiberCount = $neededSplitters($cabinet);
                            if ($fiberCount > 0) {
                                $fiberAllocations[$cabinet->id] = $allocateFibers($fiberCount);
                            }

                        $cabinet->childCabinets
                            ->whereNull('branch_id')
                            ->sortBy(fn ($child) => sprintf('%06d|%s', (int) ($child->branch_order ?? 0), (string) $child->name))
                            ->each(function ($child) use (&$fiberAllocations, &$nextFiber, $neededSplitters, $allocateFibers) {
                                $childFiberCount = $neededSplitters($child);
                                if ($childFiberCount > 0) {
                                    $fiberAllocations[$child->id] = $allocateFibers($childFiberCount);
                                }
                            });
                        });
                    });
            });
        $usedFiberTo = collect($fiberAllocations)->max('to') ?? 0;
        $odfCapacity = $project->odfs->max('fiber_capacity') ?? 144;
        $reserveFrom = $usedFiberTo + 1;
        $reserveTo = max($odfCapacity, $usedFiberTo + 1);
        $fiberWarnings = [];
        if ($usedFiberTo > $odfCapacity) {
            $overflow = $usedFiberTo - $odfCapacity;
            $fiberWarnings[] = "ODF kapacitet prekoračen: potrebno {$usedFiberTo} vlakana, kapacitet {$odfCapacity}F (prekoračenje za {$overflow} vlakana).";
        }
        $unassignedCabs = $allCabinets->filter(fn($c) => !isset($fiberAllocations[$c->id]));
        if ($unassignedCabs->isNotEmpty()) {
            $names = $unassignedCabs->pluck('name')->implode(', ');
            $fiberWarnings[] = $unassignedCabs->count().' ormarić(a) nije dodjeljeno grani — vlakna nisu alocirana: '.$names.'.';
        }
    @endphp
    <article class="schema-project" data-project-id="{{ $project->id }}">
        <div class="schema-head">
            <div>
                <h2 class="text-base font-black text-slate-950">{{ $project->name }}</h2>
                <p class="text-xs text-slate-500">{{ $project->code }} / {{ $project->location }}</p>
            </div>
            <div class="schema-stats">
                <span class="schema-chip">{{ $project->odfs->count() }} ODF</span>
                <span class="schema-chip">{{ $allCabinets->count() }} FTTH</span>
                <span class="schema-chip">{{ $totalHouses }}/{{ $totalCapacity }}</span>
                <span class="schema-chip">{{ $projectUtilization }}%</span>
                <a href="{{ route('projects.fiber-schema-dxf', $project) }}"
                   style="display:inline-flex;align-items:center;gap:5px;border-radius:999px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:.2rem .6rem;font-size:.71rem;font-weight:800;text-decoration:none">
                    <svg viewBox="0 0 16 16" fill="currentColor" style="width:12px;height:12px"><path d="M7.47 10.78a.75.75 0 001.06 0l3.75-3.75a.75.75 0 00-1.06-1.06L8.75 8.44V1.75a.75.75 0 00-1.5 0v6.69L4.78 5.97a.75.75 0 00-1.06 1.06l3.75 3.75zM3.75 13a.75.75 0 000 1.5h8.5a.75.75 0 000-1.5h-8.5z"/></svg>
                    DXF Fiber Sema
                </a>
                <a href="{{ route('projects.fiber-schema-pdf', $project) }}"
                   style="display:inline-flex;align-items:center;gap:5px;border-radius:999px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:.2rem .6rem;font-size:.71rem;font-weight:800;text-decoration:none">
                    <svg viewBox="0 0 16 16" fill="currentColor" style="width:12px;height:12px"><path d="M7.47 10.78a.75.75 0 001.06 0l3.75-3.75a.75.75 0 00-1.06-1.06L8.75 8.44V1.75a.75.75 0 00-1.5 0v6.69L4.78 5.97a.75.75 0 00-1.06 1.06l3.75 3.75zM3.75 13a.75.75 0 000 1.5h8.5a.75.75 0 000-1.5h-8.5z"/></svg>
                    PDF Fiber Sema
                </a>
            </div>
        </div>

        <div class="schema-view-tabs">
            <button type="button" class="schema-view-tab active" data-schema-view="cad-fiber">⌁ CAD šema</button>
            <button type="button" class="schema-view-tab" data-schema-view="color-code">● Color Code</button>
            <button type="button" class="schema-view-tab" data-schema-view="topology">◇ Topologija</button>
            <button type="button" class="schema-view-tab" data-schema-view="rack">▤ Rack</button>
            <button type="button" class="schema-view-tab" data-schema-view="port-plan">P# ODF portovi</button>
            <button type="button" class="schema-view-tab" data-schema-view="splice-plan">⋈ Splice plan</button>
            <button type="button" class="schema-view-tab" data-schema-view="fiber-check">✓ Kontrola</button>
        </div>
        @php
            $topologyGraph = [
                'odfs' => $project->odfs->map(fn ($odf) => [
                    'id' => $odf->id, 'name' => $odf->name, 'ports' => $odf->port_count, 'fibers' => $odf->fiber_capacity,
                ])->values(),
                'cabinets' => $allCabinets->map(fn ($cabinet) => [
                    'id' => $cabinet->id, 'odf_id' => $cabinet->odf_id, 'parent_id' => $cabinet->parent_cabinet_id, 'branch_id' => $cabinet->branch_id, 'branch_order' => $cabinet->branch_order,
                    'name' => $cabinet->name, 'used' => $cabinet->houses_count, 'capacity' => max($cabinet->capacity, 12), 'splitters' => $neededSplitters($cabinet), 'planned_splitters' => $cabinet->splitter_count,
                    'fiber_from' => $fiberAllocations[$cabinet->id]['from'] ?? null, 'fiber_to' => $fiberAllocations[$cabinet->id]['to'] ?? null, 'fiber_count' => $fiberAllocations[$cabinet->id]['count'] ?? $neededSplitters($cabinet),
                    'houses' => $cabinet->houses->map(fn ($house) => ['id' => $house->id, 'label' => $house->label])->values(),
                ])->values(),
                'branches' => $project->branches->where('type', '!=', 'rov')->map(fn ($branch) => [
                    'id' => $branch->id, 'route_id' => $branch->route_id, 'odf_id' => $branch->odf_id, 'parent_id' => $branch->parent_branch_id,
                    'from_cabinet_id' => $branch->route?->from_type === 'cabinet' ? $branch->route->from_id : null,
                    'name' => $branch->name, 'code' => $branch->code, 'type' => $branch->type, 'order' => $branch->sort_order,
                    'fibers' => $branch->route?->fiber_count ?? 12,
                ])->values(),
                'used_fiber_to' => $usedFiberTo,
                'reserve_from' => $reserveFrom,
                'reserve_to' => $reserveTo,
                'odf_capacity' => $odfCapacity,
                'fibers_per_tube' => $fibersPerTube,
                'fiber_layout' => $project->fiber_layout ?? '6x24',
                'color_standard' => $project->fiber_color_standard ?? 'telcordia',
                'reserve_per_tube' => $reservePerTube,
                'fiber_palette' => array_values(\App\Support\FiberColorCode::paletteFor($project->fiber_color_standard ?? 'telcordia')),
            ];
        @endphp
        <div data-schema-panel="cad-fiber">
            <div class="cad-fiber-shell" data-cad-fiber='@json($topologyGraph)'>
                <div class="cad-fiber-controls"><button data-cad-action="zoom-in">+</button><button data-cad-action="zoom-out">&minus;</button><button data-cad-action="fit">Fit</button></div>
                <div class="cad-fiber-stage"></div>
                <div class="cad-fiber-help">Magistralna optika / raspored iz 144 / ODF u centru / krakovi lijevo-desno</div>
            </div>
        </div>
        <div class="hidden" data-schema-panel="color-code">
            <div class="cad-fiber-shell" data-cad-fiber='@json($topologyGraph)' data-color-code="true">
                <div class="cad-fiber-controls"><button data-cad-action="zoom-in">+</button><button data-cad-action="zoom-out">&minus;</button><button data-cad-action="fit">Fit</button></div>
                <div class="cad-fiber-stage"></div>
                <div class="cad-color-legend">
                    @foreach(\App\Support\FiberColorCode::paletteFor($project->fiber_color_standard ?? 'telcordia') as $position => $color)
                        <span><i class="fiber-dot" style="background:{{ $color['hex'] }}"></i>{{ $position }} {{ $color['name'] }}</span>
                    @endforeach
                </div>
                <div class="cad-fiber-help">COLOR CODE {{ $configuredFiberCapacity }}F · {{ $tubeCount }} tuba × {{ $fibersPerTube }} niti · {{ ($project->fiber_color_standard ?? 'telcordia') === 'din_vde' ? 'DIN/VDE profil' : 'TIA‑598 / Telcordia' }}</div>
            </div>
        </div>
        <div class="hidden" data-schema-panel="port-plan"><section class="fiber-tool-panel">
            <h3 class="fiber-tool-title">ODF port plan · planirana terminacija</h3>
            <div class="fiber-plan-grid">
                @foreach($project->odfs as $odf)
                    <article class="fiber-plan-card"><header><span>{{ $odf->name }}</span><span>{{ $odf->port_count }} portova</span></header>
                    @forelse($allCabinets->filter(fn($cabinet) => $cabinet->odf_id === $odf->id && isset($fiberAllocations[$cabinet->id])) as $cabinet)
                        @foreach(range($fiberAllocations[$cabinet->id]['from'], $fiberAllocations[$cabinet->id]['to']) as $fiberNumber)
                            @php $fc = \App\Support\FiberColorCode::describe($fiberNumber, $fibersPerTube, $project->fiber_color_standard ?? 'telcordia'); @endphp
                            <div class="fiber-plan-line" data-odf-port="{{ $fiberNumber }}"><b>P{{ $fiberNumber }}</b><span><i class="fiber-dot" style="background:{{ $fc['fiber']['hex'] }}"></i>F{{ $fiberNumber }}</span><span>T{{ $fc['tube_number'] }}/V{{ $fc['position'] }} → {{ $cabinet->name }}</span></div>
                        @endforeach
                    @empty <div class="text-xs text-slate-500">Nema planiranih terminacija.</div> @endforelse
                    </article>
                @endforeach
            </div>
        </section></div>
        <div class="hidden" data-schema-panel="splice-plan"><section class="fiber-tool-panel">
            <h3 class="fiber-tool-title">Splice plan · planirana varenja</h3>
            <div class="fiber-plan-grid">
                @forelse($allCabinets->filter(fn($cabinet) => isset($fiberAllocations[$cabinet->id])) as $cabinet)
                    @php $fa = $fiberAllocations[$cabinet->id]; @endphp
                    <article class="fiber-plan-card"><header><span>{{ $cabinet->name }}</span><span>{{ $fa['count'] }} varenja</span></header>
                        @foreach(range($fa['from'], $fa['to']) as $fiberNumber)
                            @php $fc = \App\Support\FiberColorCode::describe($fiberNumber, $fibersPerTube, $project->fiber_color_standard ?? 'telcordia'); @endphp
                            <div class="fiber-plan-line"><b>F{{ $fiberNumber }}</b><span><i class="fiber-dot" style="background:{{ $fc['fiber']['hex'] }}"></i>V{{ $fc['position'] }}</span><span>T{{ $fc['tube_number'] }} → splitter {{ $loop->iteration }} IN</span></div>
                        @endforeach
                    </article>
                @empty <div class="fiber-check warn">Nema vlakana za splice plan.</div> @endforelse
            </div>
        </section></div>
        <div class="hidden" data-schema-panel="fiber-check"><section class="fiber-tool-panel">
            <h3 class="fiber-tool-title">Automatska kontrola fiber plana</h3>
            <div class="grid gap-2">
                <div class="fiber-check {{ $usedFiberTo <= $odfCapacity ? 'ok' : 'error' }}"><b>{{ $usedFiberTo <= $odfCapacity ? '✓' : '!' }}</b><span>Kapacitet: plan koristi {{ $usedFiberTo }} od {{ $odfCapacity }} vlakana.</span></div>
                <div class="fiber-check {{ $unassignedCabs->isEmpty() ? 'ok' : 'warn' }}"><b>{{ $unassignedCabs->isEmpty() ? '✓' : '!' }}</b><span>{{ $unassignedCabs->isEmpty() ? 'Svi ODO ormarići imaju planiranu fiber dodjelu.' : $unassignedCabs->count().' ODO ormarića nema fiber dodjelu: '.$unassignedCabs->pluck('name')->implode(', ') }}</span></div>
                @php
                    $claimedFibers = collect($fiberAllocations)->flatMap(fn($range) => range($range['from'], $range['to']));
                    $duplicateFibers = $claimedFibers->duplicates()->unique()->values();
                @endphp
                <div class="fiber-check {{ $duplicateFibers->isEmpty() ? 'ok' : 'error' }}"><b>{{ $duplicateFibers->isEmpty() ? '✓' : '!' }}</b><span>{{ $duplicateFibers->isEmpty() ? 'Nema dvostruko dodijeljenih vlakana.' : 'Konflikt na vlaknima: F'.$duplicateFibers->implode(', F') }}</span></div>
                <div class="fiber-check {{ $configuredFiberCapacity === $odfCapacity ? 'ok' : 'warn' }}"><b>{{ $configuredFiberCapacity === $odfCapacity ? '✓' : '!' }}</b><span>Model kabla: {{ $configuredFiberCapacity }}F, {{ $tubeCount }} tuba × {{ $fibersPerTube }} niti. ODF je evidentiran kao {{ $odfCapacity }}F. Sljedeća planirana rezerva: F{{ $reserveFrom }}–F{{ $reserveTo }}.</span></div>
                <div class="fiber-check warn"><b>i</b><span>Profil boja: {{ ($project->fiber_color_standard ?? 'telcordia') === 'din_vde' ? 'DIN/VDE profil' : 'TIA‑598 / Telcordia' }}. Za izvedbeni plan evidentirati proizvođača i tačnu oznaku kabla iz datasheeta.</span></div>
            </div>
        </section></div>
        <div class="hidden" data-schema-panel="topology">
            <div class="topology-graph-shell" data-topology-graph='@json($topologyGraph)'>
                <div class="topology-controls"><button data-topology-action="zoom-in">+</button><button data-topology-action="zoom-out">&minus;</button><button data-topology-action="fit">Fit</button><button data-topology-action="collapse">Sazmi</button></div>
                <div class="topology-graph-stage"></div>
                <div class="topology-minimap"></div>
                <div class="topology-help">Povuci za pomjeranje / tockic za zoom / klik ODO za korisnike</div>
            </div>
        </div>

        <div class="hidden" data-schema-panel="rack"><div class="schema-shell">
            <div class="schema-board">
                <div class="schema-legend" aria-label="Legenda fiber seme">
                    <span class="legend-item"><span class="legend-swatch odf"></span>ODF rack</span>
                    <span class="legend-item"><span class="legend-swatch"></span>Magistralni kabl</span>
                    <span class="legend-item"><span class="legend-swatch ftth"></span>FTTH ormaric</span>
                    <span class="legend-item"><span class="legend-swatch used"></span>Zauzet port</span>
                    <span class="legend-item"><span class="legend-swatch free"></span>Slobodan port</span>
                </div>
                <div class="board-labels" aria-hidden="true">
                    <span>ODF / patch panel</span>
                    <span>Feeder</span>
                    <span>FTTH ormarici / splitteri 1:4 / korisnici</span>
                </div>
                @if(!empty($fiberWarnings))
                    <div class="fiber-warning-band">
                        @foreach($fiberWarnings as $fiberWarning)
                            <div class="fiber-warning-item">&#9888; {{ $fiberWarning }}</div>
                        @endforeach
                    </div>
                @endif
                <div class="grid gap-3">
                @forelse($project->odfs as $odf)
                    <section class="schema-row">
                        <aside class="odf-rack">
                            <header><span>ODF</span><span>{{ $odf->port_count }}P</span></header>
                            <div class="odf-meta">
                                <b class="truncate" title="{{ $odf->name }}">{{ $odf->name }}</b>
                                <span class="truncate" title="{{ $odf->address ?: 'Bez adrese' }}">{{ $odf->address ?: 'Bez adrese' }}</span>
                                <span>{{ $odf->fiber_capacity }} vlakana</span>
                                <span>{{ $odf->cabinets->count() }} izlaza</span>
                            </div>
                        </aside>
                        <div class="fiber-bus"><span>SM FO</span></div>
                        <div class="cabinet-area">
                        @php
                            $rackOutCounter = 0;
                            $rackBranches = $project->branches
                                ->where('type', 'secondary')
                                ->where('odf_id', $odf->id)
                                ->sortBy(fn($b) => sprintf('%06d|%s', (int)($b->sort_order ?? 0), (string)$b->name));
                            $rackHasCabinets = $rackBranches->flatMap->cabinets->isNotEmpty();
                            $unassignedOdfCabs = $odf->cabinets->filter(fn($c) => is_null($c->branch_id))
                                ->sortBy(fn($c) => (string)$c->name);
                        @endphp
                        @if(($rackHasCabinets ?? false) || ($unassignedOdfCabs ?? collect())->isNotEmpty())
                            @foreach($rackBranches as $rackBranch)
                                @php
                                    $rackBranchCabs = $rackBranch->cabinets
                                        ->sortBy(fn($c) => sprintf('%06d|%s', (int)($c->branch_order ?? 0), (string)$c->name));
                                    $bfMin = PHP_INT_MAX; $bfMax = 0;
                                    foreach ($rackBranchCabs as $bc) {
                                        if (isset($fiberAllocations[$bc->id])) {
                                            $bfMin = min($bfMin, $fiberAllocations[$bc->id]['from']);
                                            $bfMax = max($bfMax, $fiberAllocations[$bc->id]['to']);
                                        }
                                    }
                                    $bfStr = $bfMax > 0 ? ($bfMin === $bfMax ? "F{$bfMin}" : "F{$bfMin}–F{$bfMax}") : '?';
                                @endphp
                                @if($rackBranchCabs->isNotEmpty())
                                <div class="rack-branch-section">
                                    <div class="rack-branch-header">
                                        <span>{{ $rackBranch->name }}</span>
                                        <span class="rack-fiber-badge">{{ $bfStr }}</span>
                                    </div>
                                    @foreach($rackBranchCabs as $cabinet)
                                        @php
                                            $rackOutCounter++;
                                            $houses = $cabinet->houses->values();
                                            $capacity = max($cabinet->capacity, 12);
                                            $used = $cabinet->houses_count;
                                            $utilization = min(100, round($used / max($capacity, 1) * 100));
                                            $state = $utilization >= 100 ? 'full' : ($utilization >= 80 ? 'warn' : '');
                                            $fiberRange = $fiberAllocations[$cabinet->id] ?? null;
                                            $fiberLabel = $fiberRange ? ($fiberRange['from'] === $fiberRange['to'] ? (string) $fiberRange['from'] : $fiberRange['from'].'-'.$fiberRange['to']) : '?';
                                            $activeSplitters = $neededSplitters($cabinet);
                                            $portsPerSplitter = max(1, (int) $cabinet->ports_per_splitter);
                                            $splitterRatio = '1:' . $portsPerSplitter;
                                        @endphp
                                        <div class="cabinet-node">
                                            <span class="connection-tag">OUT {{ $rackOutCounter }}</span>
                                            <div class="cabinet-box {{ $state }}">
                                                <div class="cabinet-title">
                                                    <span title="{{ $cabinet->name }}">{{ $cabinet->name }}</span>
                                                    <span>{{ $used }}/{{ $capacity }}</span>
                                                </div>
                                                <div class="mt-1 truncate text-[10px] font-semibold text-slate-500" title="{{ $cabinet->address ?: 'Bez adrese' }}">{{ $cabinet->address ?: 'Bez adrese' }}</div>
                                                <div class="util-bar"><div style="width: {{ $utilization }}%"></div></div>
                                                <div class="mt-1 text-[10px] font-bold text-slate-500">{{ $activeSplitters }} x {{ $splitterRatio }} / F {{ $fiberLabel }}</div>
                                            </div>
                                            <div class="splitter-panel">
                                            @for($splitter = 1; $splitter <= max($activeSplitters, 1); $splitter++)
                                                <div class="splitter-line">
                                                    <div class="splitter-label">S{{ $splitter }} {{ $splitterRatio }}</div>
                                                    @for($port = 1; $port <= $portsPerSplitter; $port++)
                                                        @php
                                                            $absolutePort = ($splitter - 1) * $portsPerSplitter + $port;
                                                            $house = $houses->get($absolutePort - 1);
                                                        @endphp
                                                        @if($house)
                                                            <button type="button" class="port" title="S{{ $splitter }} / P{{ $absolutePort }} -> {{ $house->label }}" data-trace-house="{{ $house->id }}" data-house-label="{{ $house->label }}" data-cabinet-name="{{ $cabinet->name }}" data-odf-name="{{ $odf->name }}" data-fiber-range="{{ $fiberLabel }}" data-splitter="{{ $splitter }}" data-port="{{ $absolutePort }}" data-out="{{ $rackOutCounter }}">
                                                                <b>P{{ $absolutePort }}</b>{{ $house->label }}
                                                            </button>
                                                        @else
                                                            <div class="port empty" title="S{{ $splitter }} / P{{ $absolutePort }} slobodan"><b>P{{ $absolutePort }}</b>Slobodno</div>
                                                        @endif
                                                    @endfor
                                                </div>
                                            @endfor
                                            </div>
                                            @if($cabinet->childCabinets->isNotEmpty())
                                                <div class="child-cabinets">
                                                    @foreach($cabinet->childCabinets as $childCabinet)
                                                        @php
                                                            $childHouses = $childCabinet->houses->values();
                                                            $childCapacity = max($childCabinet->capacity, 12);
                                                            $childUsed = $childCabinet->houses_count;
                                                            $childUtilization = min(100, round($childUsed / max($childCapacity, 1) * 100));
                                                            $childState = $childUtilization >= 100 ? 'full' : ($childUtilization >= 80 ? 'warn' : '');
                                                            $childFiberRange = $fiberAllocations[$childCabinet->id] ?? null;
                                                            $childFiberLabel = $childFiberRange ? ($childFiberRange['from'] === $childFiberRange['to'] ? (string) $childFiberRange['from'] : $childFiberRange['from'].'-'.$childFiberRange['to']) : '?';
                                                            $childActiveSplitters = $neededSplitters($childCabinet);
                                                            $childPortsPerSplitter = max(1, (int) $childCabinet->ports_per_splitter);
                                                            $childSplitterRatio = '1:' . $childPortsPerSplitter;
                                                        @endphp
                                                        <div class="child-cabinet-node">
                                                            <div class="cabinet-box {{ $childState }}">
                                                                <span class="child-tag">IZ {{ $cabinet->name }}</span>
                                                                <div class="cabinet-title mt-1">
                                                                    <span title="{{ $childCabinet->name }}">{{ $childCabinet->name }}</span>
                                                                    <span>{{ $childUsed }}/{{ $childCapacity }}</span>
                                                                </div>
                                                                <div class="mt-1 truncate text-[10px] font-semibold text-slate-500" title="{{ $childCabinet->address ?: 'Bez adrese' }}">{{ $childCabinet->address ?: 'Bez adrese' }}</div>
                                                                <div class="util-bar"><div style="width: {{ $childUtilization }}%"></div></div>
                                                                <div class="mt-1 text-[10px] font-bold text-slate-500">{{ $childActiveSplitters }} x {{ $childSplitterRatio }} / F {{ $childFiberLabel }}</div>
                                                            </div>
                                                            <div class="splitter-panel">
                                                            @for($childSplitter = 1; $childSplitter <= max($childActiveSplitters, 1); $childSplitter++)
                                                                <div class="splitter-line">
                                                                    <div class="splitter-label">S{{ $childSplitter }} {{ $childSplitterRatio }}</div>
                                                                    @for($childPort = 1; $childPort <= $childPortsPerSplitter; $childPort++)
                                                                        @php
                                                                            $childAbsolutePort = ($childSplitter - 1) * $childPortsPerSplitter + $childPort;
                                                                            $childHouse = $childHouses->get($childAbsolutePort - 1);
                                                                        @endphp
                                                                        @if($childHouse)
                                                                            <button type="button" class="port" title="S{{ $childSplitter }} / P{{ $childAbsolutePort }} -> {{ $childHouse->label }}" data-trace-house="{{ $childHouse->id }}" data-house-label="{{ $childHouse->label }}" data-cabinet-name="{{ $childCabinet->name }}" data-parent-cabinet="{{ $cabinet->name }}" data-odf-name="{{ $odf->name }}" data-fiber-range="{{ $childFiberLabel }}" data-splitter="{{ $childSplitter }}" data-port="{{ $childAbsolutePort }}" data-out="{{ $rackOutCounter }}">
                                                                                <b>P{{ $childAbsolutePort }}</b>{{ $childHouse->label }}
                                                                            </button>
                                                                        @else
                                                                            <div class="port empty" title="S{{ $childSplitter }} / P{{ $childAbsolutePort }} slobodan"><b>P{{ $childAbsolutePort }}</b>Slobodno</div>
                                                                        @endif
                                                                    @endfor
                                                                </div>
                                                            @endfor
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                @endif
                            @endforeach
                            @if($unassignedOdfCabs->isNotEmpty())
                                <div class="rack-unassigned-section">
                                    <div class="rack-unassigned-header">
                                        <span>&#9888; Neraspoređeni ODO</span>
                                        <span class="rack-unassigned-badge">{{ $unassignedOdfCabs->count() }} bez grane</span>
                                    </div>
                                    @foreach($unassignedOdfCabs as $cabinet)
                                        @php
                                            $rackOutCounter++;
                                            $houses = $cabinet->houses->values();
                                            $capacity = max($cabinet->capacity, 12);
                                            $used = $cabinet->houses_count ?? $cabinet->houses->count();
                                            $utilization = min(100, round($used / max($capacity, 1) * 100));
                                            $state = $utilization >= 100 ? 'full' : ($utilization >= 80 ? 'warn' : '');
                                            $activeSplitters = $neededSplitters($cabinet);
                                            $portsPerSplitter = max(1, (int) $cabinet->ports_per_splitter);
                                            $splitterRatio = '1:' . $portsPerSplitter;
                                        @endphp
                                        <div class="cabinet-node">
                                            <span class="connection-tag">OUT {{ $rackOutCounter }}</span>
                                            <div class="cabinet-box {{ $state }}">
                                                <div class="cabinet-title">
                                                    <span title="{{ $cabinet->name }}">{{ $cabinet->name }}</span>
                                                    <span>{{ $used }}/{{ $capacity }}</span>
                                                </div>
                                                <div class="mt-1 truncate text-[10px] font-semibold text-slate-500" title="{{ $cabinet->address ?: 'Bez adrese' }}">{{ $cabinet->address ?: 'Bez adrese' }}</div>
                                                <div class="util-bar"><div style="width: {{ $utilization }}%"></div></div>
                                                <div class="mt-1 text-[10px] font-bold text-amber-600">{{ $activeSplitters }} x {{ $splitterRatio }} / F ???</div>
                                            </div>
                                            <div class="splitter-panel">
                                            @for($splitter = 1; $splitter <= max($activeSplitters, 1); $splitter++)
                                                <div class="splitter-line">
                                                    <div class="splitter-label">S{{ $splitter }} {{ $splitterRatio }}</div>
                                                    @for($port = 1; $port <= $portsPerSplitter; $port++)
                                                        @php
                                                            $absolutePort = ($splitter - 1) * $portsPerSplitter + $port;
                                                            $house = $houses->get($absolutePort - 1);
                                                        @endphp
                                                        @if($house)
                                                            <button type="button" class="port" title="S{{ $splitter }} / P{{ $absolutePort }} -> {{ $house->label }}" data-trace-house="{{ $house->id }}" data-house-label="{{ $house->label }}" data-cabinet-name="{{ $cabinet->name }}" data-odf-name="{{ $odf->name }}" data-fiber-range="?" data-splitter="{{ $splitter }}" data-port="{{ $absolutePort }}" data-out="{{ $rackOutCounter }}">
                                                                <b>P{{ $absolutePort }}</b>{{ $house->label }}
                                                            </button>
                                                        @else
                                                            <div class="port empty" title="S{{ $splitter }} / P{{ $absolutePort }} slobodan"><b>P{{ $absolutePort }}</b>Slobodno</div>
                                                        @endif
                                                    @endfor
                                                </div>
                                            @endfor
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @else
                            <div class="rounded-md border border-dashed border-slate-300 bg-white p-3 text-sm text-slate-500">ODF jos nema povezane FTTH ormarice u granama.</div>
                        @endif
                        </div>
                    </section>
                @empty
                    <div class="rounded-md border border-slate-200 bg-white p-5 text-sm text-slate-500">Projekat jos nema ODF lokaciju.</div>
                @endforelse
                </div>
            </div>

            <aside class="trace-panel">
                <h3 class="font-bold text-slate-950">Fiber tracing</h3>
                <p class="mt-1 text-sm text-slate-500">Klikni port za prikaz putanje.</p>
                <div data-trace-output class="trace-chain">
                    <div class="rounded-md bg-slate-50 p-3 text-sm text-slate-500">Nema odabrane kuce.</div>
                </div>
                <a href="{{ route('map.dashboard') }}" class="mt-4 block rounded-md border border-blue-200 px-3 py-2 text-center text-sm font-bold text-blue-700">Otvori mapu</a>
            </aside>
        </div></div>
        @if($project->houses->isNotEmpty())
            <details class="mx-3 mb-3 rounded-md border border-amber-200 bg-amber-50 p-3">
                <summary class="cursor-pointer text-sm font-black text-amber-900">Nepovezane kuce ({{ $project->houses->count() }})</summary>
                <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($project->houses as $house)
                        <div class="rounded border border-amber-200 bg-white p-2 text-xs"><b>{{ $house->label }}</b><br>{{ $house->address ?: 'Bez adrese' }} / {{ $house->status }}</div>
                    @endforeach
                </div>
            </details>
        @endif
    </article>
@empty
    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">Nema projekata.</div>
@endforelse
</section>

<script>
document.querySelectorAll('.schema-project').forEach(project => {
    project.querySelectorAll('[data-schema-view]').forEach(button => button.addEventListener('click', () => {
        project.querySelectorAll('[data-schema-view]').forEach(item => item.classList.toggle('active', item === button));
        project.querySelectorAll('[data-schema-panel]').forEach(panel => panel.classList.toggle('hidden', panel.dataset.schemaPanel !== button.dataset.schemaView));
    }));
});
function cadFiberRenderer(shell) {
    const data=JSON.parse(shell.dataset.cadFiber || '{"odfs":[],"cabinets":[],"branches":[]}');
    const stage=shell.querySelector('.cad-fiber-stage');
    const colorMode=shell.dataset.colorCode==='true';
    let scale=1, panX=0, panY=0, dragging=false, start=null;
    const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const odfX=2800, odfY=620, odfGap=1150, odfW=280, odfH=360, cabinetW=58, cabinetH=96, cabinetGap=220, childBranchGap=70, branchGap=230, fiberPitch=8;
    const odfPalette=['#1d4ed8','#047857','#b45309','#7c3aed','#be123c','#0f766e'];
    const fiberPalette=(data.fiber_palette||[]).map(color=>color.hex);
    if(!fiberPalette.length) fiberPalette.push('#2563eb','#f97316','#16a34a','#92400e','#64748b','#f8fafc','#dc2626','#111827','#facc15','#7c3aed','#ec4899','#22d3ee');
    const odfColor=index=>odfPalette[index%odfPalette.length];
    const fitText=(value,max=18)=>String(value??'').length>max ? `${String(value).slice(0,max-3)}...` : String(value??'');
    function branchCabinets(branch) {
        return data.cabinets.filter(c=>Number(c.branch_id)===Number(branch.id)).sort((a,b)=>(a.branch_order||0)-(b.branch_order||0));
    }
    function cabinetFiberLabel(cabinet) {
        const from=Number(cabinet.fiber_from)||0, to=Number(cabinet.fiber_to)||from;
        if(!from) return 'F?';
        return from===to ? `F${from}` : `F${from}-${to}`;
    }
    function fiberRangeText(cabinet) {
        const from=Number(cabinet.fiber_from)||0, to=Number(cabinet.fiber_to)||from;
        if(!from) return '?';
        return from===to ? `${from}` : `${from}-${to}`;
    }
    function coloredFiberLines(cabinet,x1,y1,x2) {
        if(!colorMode || !Number(cabinet.fiber_from)) return '';
        const from=Number(cabinet.fiber_from), to=Number(cabinet.fiber_to)||from;
        const count=Math.max(1,to-from+1), spacing=5;
        return Array.from({length:count},(_,index)=>{
            const number=from+index, perTube=Number(data.fibers_per_tube)||24, position=((number-1)%perTube)+1, color=fiberPalette[(position-1)%12];
            const offset=(index-(count-1)/2)*spacing;
            const dash=position>12 ? ' stroke-dasharray="8 3"' : '';
            return `<line x1="${x1}" y1="${y1+offset}" x2="${x2}" y2="${y1+offset}" stroke="${color}" stroke-width="3.2" stroke-linecap="round"${dash}/>`;
        }).join('');
    }
    function branchFiberRange(cabinets) {
        const ranges=cabinets.filter(c=>Number(c.fiber_from)).map(c=>[Number(c.fiber_from),Number(c.fiber_to)||Number(c.fiber_from)]);
        if(!ranges.length) return '?';
        const from=Math.min(...ranges.map(range=>range[0])), to=Math.max(...ranges.map(range=>range[1]));
        return from===to ? `${from}` : `${from}-${to}`;
    }
    function branchSide(branch, index) {
        return index % 2 === 0 ? 1 : -1;
    }
    function branchPrefix(branch) {
        const label=String(`${branch?.code||''} ${branch?.name||''}`).trim();
        const match=label.match(/(\d+(?:[.-]\d+)*)/);
        return match ? match[1].replace(/[._]/g,'-') : String(branch?.order || branch?.id || '?');
    }
    function cabinetDisplayName(cabinet) {
        const branch=data.branches.find(item=>Number(item.id)===Number(cabinet.branch_id));
        if(!branch) return cabinet.name;
        const cabinets=branchCabinets(branch);
        const index=Math.max(1,cabinets.findIndex(item=>Number(item.id)===Number(cabinet.id))+1);
        return `FTTH ${branchPrefix(branch)}-${index}`;
    }
    function render() {
        const parts=[], labels=[], positions={}, drawnBranches=new Set(), odfPositions={};
        data.odfs.forEach((odf,odfIndex)=>{odfPositions[odf.id]={x:odfX,y:odfY+(odfIndex*odfGap)};});
        const odfYs=Object.values(odfPositions).map(p=>p.y);
        const trunkTop=Math.min(...odfYs,odfY)-360, trunkBottom=Math.max(...odfYs,odfY)+360;
        const primaryBranches=data.branches.filter(branch=>branch.type==='primary').sort((a,b)=>a.order-b.order);
        const primaryCount=Math.max(primaryBranches.length,2);
        const primaryXs=[];
        for(let index=0;index<primaryCount;index++){
            const branch=primaryBranches[index] || null, x=odfX-28+(index*18);
            primaryXs.push(x);
            parts.push(`<line x1="${x}" y1="${trunkTop}" x2="${x}" y2="${trunkBottom}" stroke="${index%2?'#6366f1':'#3b82f6'}" stroke-width="${index%2?2:3}"/>`);
            if(branch){
                const fibers=Math.max(1,Number(branch.fibers)||12);
                labels.push(`<g><rect x="${x-62}" y="${trunkTop-50}" width="124" height="36" rx="4" class="cad-label-bg"/><text x="${x}" y="${trunkTop-35}" text-anchor="middle" class="cad-branch">${esc(branch.name)}</text><text x="${x}" y="${trunkTop-21}" text-anchor="middle" class="cad-meta">OPTIKA ${fibers} niti</text></g>`);
            }
        }
        const reserveFrom=Number(data.reserve_from)||0, reserveTo=Number(data.reserve_to)||144;
        if(reserveFrom<=reserveTo){
            const reserveLeft=Math.min(...primaryXs), reserveRight=Math.max(...primaryXs);
            const reserveX=(reserveLeft+reserveRight)/2, reserveTop=trunkBottom, reserveBottom=trunkBottom+150;
            parts.push(`<line x1="${reserveLeft}" y1="${reserveTop}" x2="${reserveRight}" y2="${reserveTop}" stroke="#16a34a" stroke-width="8" stroke-linecap="round"/><line x1="${reserveX}" y1="${reserveTop}" x2="${reserveX}" y2="${reserveBottom}" stroke="#16a34a" stroke-width="9" stroke-linecap="round"/><circle cx="${reserveLeft}" cy="${reserveTop}" r="6" fill="#16a34a"/><circle cx="${reserveRight}" cy="${reserveTop}" r="6" fill="#16a34a"/><circle cx="${reserveX}" cy="${reserveBottom}" r="7" fill="#16a34a"/>`);
            labels.push(`<g><rect x="${reserveX-92}" y="${reserveBottom+20}" width="184" height="42" rx="6" fill="#ecfdf5" stroke="#16a34a" stroke-width="2"/><text x="${reserveX}" y="${reserveBottom+38}" text-anchor="middle" class="cad-free">REZERVA F${reserveFrom}-${reserveTo}</text><text x="${reserveX}" y="${reserveBottom+54}" text-anchor="middle" class="cad-meta">${reserveTo-reserveFrom+1} slobodnih niti</text></g>`);
        }
        data.odfs.forEach((odf,odfIndex)=>{
            const centerX=odfPositions[odf.id].x, centerY=odfPositions[odf.id].y;
            const color=odfColor(odfIndex);
            const roots=data.branches.filter(b=>b.type==='secondary'&&Number(b.odf_id)===Number(odf.id)&&!b.from_cabinet_id).sort((a,b)=>a.order-b.order);
            const maxSideRoots=Math.max(1,Math.ceil(roots.length/2));
            const dynH=Math.max(odfH, maxSideRoots*branchGap+80);
            labels.push(`<g><rect x="${centerX-odfW/2-10}" y="${centerY-dynH/2-10}" width="${odfW+20}" height="${dynH+20}" rx="14" fill="${color}" opacity=".07"/><rect x="${centerX-odfW/2-8}" y="${centerY-dynH/2-8}" width="${odfW+16}" height="${dynH+16}" rx="12" fill="none" stroke="${color}" stroke-width="3.5"/><rect x="${centerX-odfW/2-8}" y="${centerY-dynH/2-48}" width="${odfW+16}" height="32" rx="8" fill="${color}"/><text x="${centerX}" y="${centerY-dynH/2-26}" text-anchor="middle" fill="#fff" style="font:900 14px Arial;letter-spacing:.3px">${esc(odf.name)}</text></g>`);
            parts.push(`<g><rect x="${centerX-odfW/2}" y="${centerY-dynH/2}" width="${odfW}" height="${dynH}" rx="10" fill="#f8fbff" stroke="${color}" stroke-width="4" filter="url(#sh)"/><rect x="${centerX-odfW/2}" y="${centerY-dynH/2}" width="${odfW}" height="56" rx="10" fill="${color}" opacity=".11"/><rect x="${centerX-odfW/2+10}" y="${centerY-dynH/2+12}" width="${odfW-20}" height="34" rx="5" fill="${color}" opacity=".15"/><text x="${centerX}" y="${centerY-dynH/2+36}" text-anchor="middle" class="cad-odf-title">${esc(odf.name)}</text><text x="${centerX}" y="${centerY+10}" text-anchor="middle" class="cad-odf-meta">ODF / PATCH PANEL</text><text x="${centerX}" y="${centerY+32}" text-anchor="middle" class="cad-odf-meta">${odf.ports}P / ${odf.fibers}F</text><text x="${centerX}" y="${centerY+54}" text-anchor="middle" class="cad-odf-sub">LC/APC</text><line x1="${centerX-odfW/2+20}" y1="${centerY+68}" x2="${centerX+odfW/2-20}" y2="${centerY+68}" stroke="${color}" stroke-width="1.5" opacity=".3"/><text x="${centerX}" y="${centerY+86}" text-anchor="middle" class="cad-meta">izlazi lijevo ←  → desno</text></g>`);
            parts.push(`<rect x="${centerX-odfW/2-54}" y="${centerY-(maxSideRoots*branchGap)/2-120}" width="${odfW+108}" height="${maxSideRoots*branchGap+240}" rx="18" fill="${color}" opacity=".045" stroke="${color}" stroke-width="2" stroke-dasharray="10 8"/>`);
            const sideSlots={1:0,'-1':0};
            const sideCounts={1:roots.filter((item,i)=>branchSide(item,i)===1).length,'-1':roots.filter((item,i)=>branchSide(item,i)===-1).length};
            roots.forEach((branch,index)=>{
                const side=branchSide(branch,index), slot=sideSlots[side]++, maxSide=Math.max(1,sideCounts[side]);
                const y=centerY+(slot-(maxSide-1)/2)*branchGap+(side>0 ? -branchGap*.18 : branchGap*.18);
                const portX=centerX+side*(odfW/2), portY=y, portLabelX=centerX+side*(odfW/2-30);
                parts.push(`<g><rect x="${portX+(side>0?0:-28)}" y="${portY-12}" width="28" height="24" rx="4" fill="${color}" stroke="#fff" stroke-width="2"/><circle cx="${portX}" cy="${portY}" r="5" fill="#fff" stroke="${color}" stroke-width="3"/><text x="${portLabelX}" y="${portY+4}" text-anchor="${side>0?'end':'start'}" fill="${color}" style="font:800 10px Arial">${esc(branch.name)}</text></g>`);
                drawManualBranch(branch,centerX,y,side,`odf-${odf.id}`,parts,labels,positions,drawnBranches,color,odf.name,portY);
            });
        });
        const width=Math.max(5200,...Object.values(positions).map(p=>p.x+620)), height=Math.max(2200,trunkBottom+340,...Object.values(positions).map(p=>p.y+460));
        stage.innerHTML=`<svg width="${width}" height="${height}"><defs><filter id="sh" x="-12%" y="-12%" width="124%" height="124%"><feDropShadow dx="0" dy="2" stdDeviation="4" flood-color="#0f172a" flood-opacity=".13"/></filter></defs><style>.cad-title{font:800 11px Arial;fill:#0f172a}.cad-odf-title{font:900 21px Arial;fill:#1e3a8a}.cad-odf-meta{font:800 13px Arial;fill:#1d4ed8}.cad-odf-sub{font:700 10px Arial;fill:#3b82f6;letter-spacing:.4px}.cad-branch{font:800 11px Arial;fill:#be123c}.cad-meta{font:700 9px Arial;fill:#334155}.cad-port{font:800 8px Arial;fill:#6d28d9}.cad-free{font:800 9px Arial;fill:#15803d}.cad-over{font:700 9px Arial;fill:#dc2626}.cad-label-bg{fill:#fff;stroke:#fecaca;stroke-width:1}</style>${parts.join('')}${labels.join('')}</svg>`;
    }
    function drawManualBranch(branch,startX,y,side,parent,parts,labels,positions,drawnBranches,color='#1d4ed8',odfName='ODF',portY=null) {
        if(drawnBranches.has(String(branch.id))) return y;
        drawnBranches.add(String(branch.id));
        const cabinets=branchCabinets(branch), labelWidth=Math.max(170,String(branch.name||'').length*7+24);
        const fromCabinet=String(parent||'').startsWith('cab-');
        const lineCount=Math.max(1,cabinets.length);
        const portStartY=portY ?? y;
        const sourceX=fromCabinet ? startX : startX+side*(odfW/2);
        const odfEdge=fromCabinet ? startX+side*150 : startX+side*(odfW/2+70);
        const firstCabinetDistance=fromCabinet ? Math.max(410,labelWidth+210) : Math.max(470,labelWidth+240);
        const fibers=Math.max(1,Number(branch.fibers)||12), branchRange=branchFiberRange(cabinets);
        const stackBusHeight=(lineCount-1)*fiberPitch;
        const busTop=y-stackBusHeight/2-15;
        const busBottom=fromCabinet ? y : y+stackBusHeight/2+24;
        parts.push(fromCabinet
            ? `<path d="M${sourceX} ${portStartY} L${sourceX} ${y} L${odfEdge} ${y}" fill="none" stroke="${color}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" opacity=".9"/>`
            : `<path d="M${sourceX} ${portStartY} L${odfEdge} ${portStartY} L${odfEdge} ${busTop}" fill="none" stroke="${color}" stroke-width="6" opacity=".9"/><line x1="${odfEdge}" y1="${busTop-9}" x2="${odfEdge}" y2="${busBottom}" stroke="${color}" stroke-width="3"/>`);
        if(cabinets.length) parts.push(`<circle cx="${odfEdge}" cy="${y}" r="6" fill="${color}"/><text x="${odfEdge+side*12}" y="${y-12}" text-anchor="${side>0?'start':'end'}" class="cad-free">${branchRange}</text>`);
        const labelX=startX+side*(firstCabinetDistance*.5), labelY=y-stackBusHeight/2-82, labelFiber=branchRange!=='?' ? 'F'+branchRange : '';
        labels.push(`<g><rect x="${labelX-112}" y="${labelY-4}" width="224" height="54" rx="8" fill="#fff" stroke="${color}" stroke-width="2" filter="url(#sh)"/><rect x="${labelX-112}" y="${labelY-4}" width="66" height="54" rx="8" fill="${color}"/><rect x="${labelX-46}" y="${labelY-4}" width="9" height="54" fill="${color}" opacity=".18"/><text x="${labelX-79}" y="${labelY+14}" text-anchor="middle" fill="#fff" style="font:700 8px Arial;letter-spacing:.3px">ODF</text><text x="${labelX-79}" y="${labelY+30}" text-anchor="middle" fill="#fff" style="font:900 12px Arial">${esc(fitText(odfName,8))}</text><text x="${labelX+56}" y="${labelY+17}" text-anchor="middle" class="cad-branch">${esc(fitText(branch.name,20))}</text><text x="${labelX+56}" y="${labelY+35}" text-anchor="middle" class="cad-meta">OPTIKA ${fibers}F  ${labelFiber}</text></g>`);
        let branchBottomY=y+stackBusHeight/2+34;
        cabinets.forEach((cabinet,index)=>{
            const x=startX+side*(firstCabinetDistance+index*cabinetGap);
            const tapY=y+(index-lineCount/2+.5)*fiberPitch;
            const boxY=tapY+34, titleY=boxY+cabinetH+28, metaY=titleY+16;
            positions[`cab-${cabinet.id}`]={x,y:tapY, boxY, bottomY:metaY+18};
            branchBottomY=Math.max(branchBottomY, metaY+18);
            parts.push(`<line x1="${odfEdge}" y1="${tapY}" x2="${x}" y2="${tapY}" stroke="${colorMode?'#cbd5e1':color}" stroke-width="${colorMode?8:2}" opacity="${colorMode?'.8':'.65'}"/>${coloredFiberLines(cabinet,odfEdge,tapY,x)}`);
            parts.push(`<circle cx="${x}" cy="${tapY}" r="5.5" fill="${color}"/><circle cx="${x}" cy="${tapY}" r="2.5" fill="#fff"/><text x="${x-side*14}" y="${tapY-9}" text-anchor="${side>0?'end':'start'}" class="cad-port">${fiberRangeText(cabinet)}</text>`);
            parts.push(`<rect x="${x-cabinetW/2}" y="${boxY}" width="${cabinetW}" height="${cabinetH}" rx="7" fill="#fff" stroke="${color}" stroke-width="2" filter="url(#sh)"/><rect x="${x-cabinetW/2}" y="${boxY}" width="${cabinetW}" height="22" rx="7" fill="${color}" opacity=".85"/><rect x="${x-cabinetW/2}" y="${boxY+15}" width="${cabinetW}" height="7" fill="${color}" opacity=".85"/><line x1="${x}" y1="${tapY}" x2="${x}" y2="${boxY}" stroke="${color}" stroke-width="2.5"/><rect x="${x-72}" y="${titleY-14}" width="144" height="36" rx="5" fill="#f8faff" stroke="#e2e8f0" stroke-width="1"/><text x="${x}" y="${titleY+2}" text-anchor="middle" class="cad-title">${esc(fitText(cabinetDisplayName(cabinet),18))}</text><text x="${x}" y="${metaY+2}" text-anchor="middle" class="cad-meta">${cabinetFiberLabel(cabinet)} / ${cabinet.used}/${cabinet.capacity}</text>`);
        });
        let childBranchCursorY=branchBottomY+childBranchGap;
        data.branches.filter(child=>Number(child.from_cabinet_id)&&positions[`cab-${child.from_cabinet_id}`]&&Number(child.odf_id)===Number(branch.odf_id)&&!drawnBranches.has(String(child.id))).forEach((child,index)=>{
            const anchor=positions[`cab-${child.from_cabinet_id}`], childStartY=(anchor.boxY ? anchor.boxY+cabinetH : anchor.y);
            const childY=Math.max(anchor.childCursorY || 0, (anchor.bottomY || childStartY)+childBranchGap, childBranchCursorY);
            const sourceCabinet=data.cabinets.find(cabinet=>Number(cabinet.id)===Number(child.from_cabinet_id));
            const sourceName=sourceCabinet ? cabinetDisplayName(sourceCabinet) : odfName;
            const childBottomY=drawManualBranch(child,anchor.x,childY,side,`cab-${child.from_cabinet_id}`,parts,labels,positions,drawnBranches,color,sourceName,anchor.y);
            childBranchCursorY=childY+childBranchGap;
            anchor.childCursorY=childY+childBranchGap;
            anchor.bottomY=Math.max(anchor.bottomY || childStartY, childBottomY);
            branchBottomY=Math.max(branchBottomY, childBottomY);
        });
        return branchBottomY;
    }
    function apply(){stage.style.transform=`translate(${panX}px,${panY}px) scale(${scale})`}
    function fit(){const svg=stage.querySelector('svg');if(!svg)return;scale=Math.min(.95,(shell.clientWidth-40)/svg.width.baseVal.value,(shell.clientHeight-40)/svg.height.baseVal.value);panX=(shell.clientWidth-svg.width.baseVal.value*scale)/2;panY=(shell.clientHeight-svg.height.baseVal.value*scale)/2;apply()}
    shell.addEventListener('wheel',e=>{e.preventDefault();scale=Math.max(.15,Math.min(3,scale*(e.deltaY<0?1.12:.89)));apply()},{passive:false});
    shell.addEventListener('pointerdown',e=>{if(e.target.closest('.cad-fiber-controls'))return;dragging=true;start={x:e.clientX-panX,y:e.clientY-panY};shell.setPointerCapture(e.pointerId)});
    shell.addEventListener('pointermove',e=>{if(dragging){panX=e.clientX-start.x;panY=e.clientY-start.y;apply()}});
    shell.addEventListener('pointerup',()=>dragging=false);
    shell.querySelector('[data-cad-action="zoom-in"]').onclick=()=>{scale=Math.min(3,scale*1.2);apply()};
    shell.querySelector('[data-cad-action="zoom-out"]').onclick=()=>{scale=Math.max(.15,scale/1.2);apply()};
    shell.querySelector('[data-cad-action="fit"]').onclick=fit;
    render(); setTimeout(fit,0);
}
function topologyRenderer(shell) {
    const data = JSON.parse(shell.dataset.topologyGraph || '{"odfs":[],"cabinets":[]}');
    const stage = shell.querySelector('.topology-graph-stage');
    const minimap = shell.querySelector('.topology-minimap');
    const expanded = new Set();
    let scale = 1, panX = 0, panY = 0, dragging = false, start = null;
    const nodeW = 116, nodeH = 42, laneGap = 210, columnGap = 175;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    function branchPrefix(branch) {
        const label=String(`${branch?.code||''} ${branch?.name||''}`).trim();
        const match=label.match(/(\d+(?:[.-]\d+)*)/);
        return match ? match[1].replace(/[._]/g,'-') : String(branch?.order || branch?.id || '?');
    }
    function cabinetDisplayName(cabinet) {
        const branch=data.branches.find(item=>Number(item.id)===Number(cabinet.branch_id));
        if(!branch) return cabinet.name;
        const cabinets=data.cabinets.filter(c=>Number(c.branch_id)===Number(branch.id)).sort((a,b)=>(a.branch_order||0)-(b.branch_order||0));
        const index=Math.max(1,cabinets.findIndex(item=>Number(item.id)===Number(cabinet.id))+1);
        return `FTTH ${branchPrefix(branch)}-${index}`;
    }
    function graph() {
        const nodes = [], edges = [];
        data.odfs.forEach((odf, odfIndex) => {
            let roots = data.branches.filter(branch => Number(branch.odf_id) === Number(odf.id) && (!branch.parent_id || branch.from_cabinet_id))
                .sort((a,b)=>Number(Boolean(a.from_cabinet_id))-Number(Boolean(b.from_cabinet_id)) || a.order-b.order);
            const unassigned = data.cabinets.filter(c => Number(c.odf_id) === Number(odf.id) && !c.branch_id);
            if (unassigned.length) roots.push({ id:`unassigned-${odf.id}`, name:'Neraspoređeni ODO', code:'?', type:'secondary', synthetic:true });
            const baseX=80+odfIndex*1800;
            const laneState={next:0, anchorBranches:{}};
            roots.forEach(branch=>addBranchLane(branch,baseX+220,`odf-${odf.id}`,nodes,edges,unassigned,laneState));
            nodes.push({ id:`odf-${odf.id}`, type:'odf', x:baseX, y:80+Math.max(0,laneState.next-1)*laneGap/2, label:odf.name, meta:`${odf.ports}P / ${odf.fibers}F` });
        });
        return {nodes, edges};
    }
    function addBranchLane(branch, x, parent, nodes, edges, unassigned=[], laneState={next:0}) {
        laneState.anchorBranches ||= {};
        let y=80+laneState.next*laneGap;
        laneState.next++;
        const anchorNode=branch.from_cabinet_id ? nodes.find(node=>node.id===`cab-${branch.from_cabinet_id}`) : null;
        if (anchorNode) {
            const anchorIndex=laneState.anchorBranches[branch.from_cabinet_id] || 0;
            laneState.anchorBranches[branch.from_cabinet_id]=anchorIndex+1;
            parent=anchorNode.id;
            x=anchorNode.x;
            y=anchorNode.y+95+(anchorIndex*95);
        }
        const branchNodeId=`branch-${branch.id}`;
        nodes.push({ id:branchNodeId, type:'branch', x, y, label:branch.name, meta:branch.code || branch.type });
        edges.push({ from:parent, to:branchNodeId, type:branch.from_cabinet_id ? 'cabinet-branch' : (branch.parent_id ? 'child' : '') });
        const cabinets=(branch.synthetic ? unassigned : data.cabinets.filter(c=>Number(c.branch_id)===Number(branch.id) && (!c.parent_id || Number(c.parent_id)===Number(branch.from_cabinet_id)))).sort((a,b)=>(a.branch_order||0)-(b.branch_order||0));
        let previous=branchNodeId;
        cabinets.forEach((cabinet,index)=>{addCabinet(cabinet,x+(index+1)*columnGap,y,previous,1,nodes,edges);previous=`cab-${cabinet.id}`;});
        data.branches.filter(item=>Number(item.parent_id)===Number(branch.id) && !item.from_cabinet_id).sort((a,b)=>a.order-b.order).forEach(child=>addBranchLane(child,x+columnGap,branchNodeId,nodes,edges,unassigned,laneState));
    }
    function addCabinet(cabinet, x, y, parent, side, nodes, edges) {
        const fiberLabel=cabinet.fiber_from ? (Number(cabinet.fiber_from)===Number(cabinet.fiber_to) ? `F${cabinet.fiber_from}` : `F${cabinet.fiber_from}-${cabinet.fiber_to}`) : 'F?';
        nodes.push({ id:`cab-${cabinet.id}`, type:'cabinet', x, y, label:cabinetDisplayName(cabinet), meta:`${cabinet.used}/${cabinet.capacity} / ${fiberLabel}`, cabinet });
        edges.push({ from:parent, to:`cab-${cabinet.id}`, type:cabinet.parent_id ? 'child' : '' });
        data.cabinets.filter(c => Number(c.parent_id) === Number(cabinet.id) && Number(c.branch_id)===Number(cabinet.branch_id)).sort((a,b)=>(a.branch_order||0)-(b.branch_order||0)).forEach((child, index) => addCabinet(child, x + side * (index + 1) * columnGap, y, `cab-${cabinet.id}`, side, nodes, edges));
        if (expanded.has(cabinet.id)) cabinet.houses.forEach((house, index) => {
            const hx = x + (index % 4) * 82, hy = y + 64 + Math.floor(index / 4) * 48;
            nodes.push({ id:`house-${house.id}`, type:'house', x:hx, y:hy, label:house.label, meta:'' });
            edges.push({ from:`cab-${cabinet.id}`, to:`house-${house.id}`, type:'drop' });
        });
    }
    function render() {
        const {nodes, edges} = graph();
        const byId = Object.fromEntries(nodes.map(node => [node.id, node]));
        const maxX = Math.max(1100, ...nodes.map(n => n.x + nodeW + 80)), maxY = Math.max(520, ...nodes.map(n => n.y + nodeH + 80));
        const edgeSvg = edges.map(edge => {
            const a=byId[edge.from], b=byId[edge.to]; if(!a||!b)return '';
            const ax=a.x+nodeW/2, ay=a.y+nodeH/2, bx=b.x+nodeW/2, by=b.y+nodeH/2, mid=(ax+bx)/2;
            return `<path class="topology-edge ${edge.type}" d="M${ax} ${ay} C${mid} ${ay},${mid} ${by},${bx} ${by}"/>`;
        }).join('');
        const nodeSvg = nodes.map(node => {
            const colors=node.type==='odf'?['#eff6ff','#2563eb']:node.type==='branch'?['#f8fafc','#64748b']:node.type==='house'?['#fff7ed','#f97316']:['#f2faeb','#65a845'];
            return `<g class="topology-node" data-node-type="${node.type}" data-cabinet-id="${node.cabinet?.id||''}" transform="translate(${node.x},${node.y})"><rect width="${nodeW}" height="${nodeH}" rx="6" fill="${colors[0]}" stroke="${colors[1]}"/><text x="${nodeW/2}" y="18" text-anchor="middle">${esc(node.label)}</text><text x="${nodeW/2}" y="33" text-anchor="middle" style="font-size:9px;fill:#64748b">${esc(node.meta)}</text></g>`;
        }).join('');
        stage.innerHTML=`<svg width="${maxX}" height="${maxY}">${edgeSvg}${nodeSvg}</svg>`;
        minimap.innerHTML=`<svg viewBox="0 0 ${maxX} ${maxY}">${edges.map(edge=>{const a=byId[edge.from],b=byId[edge.to];return a&&b?`<line x1="${a.x}" y1="${a.y}" x2="${b.x}" y2="${b.y}" stroke="#94a3b8" stroke-width="5"/>`:''}).join('')}${nodes.map(n=>`<rect x="${n.x}" y="${n.y}" width="35" height="18" fill="${n.type==='odf'?'#2563eb':n.type==='house'?'#f97316':'#65a845'}"/>`).join('')}</svg>`;
        stage.querySelectorAll('[data-node-type="cabinet"]').forEach(node => node.addEventListener('click', event => { event.stopPropagation(); const id=Number(node.dataset.cabinetId); expanded.has(id)?expanded.delete(id):expanded.add(id); render(); }));
        applyTransform();
    }
    function applyTransform(){ stage.style.transform=`translate(${panX}px,${panY}px) scale(${scale})`; }
    function fit(){ const svg=stage.querySelector('svg'); if(!svg)return; scale=Math.min(.95,(shell.clientWidth-40)/svg.width.baseVal.value,(shell.clientHeight-40)/svg.height.baseVal.value); panX=(shell.clientWidth-svg.width.baseVal.value*scale)/2; panY=(shell.clientHeight-svg.height.baseVal.value*scale)/2; applyTransform(); }
    shell.addEventListener('wheel', e=>{e.preventDefault(); scale=Math.max(.2,Math.min(2.5,scale*(e.deltaY<0?1.12:.89)));applyTransform();},{passive:false});
    shell.addEventListener('pointerdown',e=>{if(e.target.closest('.topology-node,.topology-controls'))return;dragging=true;start={x:e.clientX-panX,y:e.clientY-panY};shell.setPointerCapture(e.pointerId)});
    shell.addEventListener('pointermove',e=>{if(!dragging)return;panX=e.clientX-start.x;panY=e.clientY-start.y;applyTransform()});
    shell.addEventListener('pointerup',()=>dragging=false);
    shell.querySelector('[data-topology-action="zoom-in"]').onclick=()=>{scale=Math.min(2.5,scale*1.2);applyTransform()};
    shell.querySelector('[data-topology-action="zoom-out"]').onclick=()=>{scale=Math.max(.2,scale/1.2);applyTransform()};
    shell.querySelector('[data-topology-action="fit"]').onclick=fit;
    shell.querySelector('[data-topology-action="collapse"]').onclick=()=>{expanded.clear();render();fit()};
    render(); requestAnimationFrame(fit);
}
document.querySelectorAll('[data-topology-graph]').forEach(topologyRenderer);
document.querySelectorAll('[data-cad-fiber]').forEach(cadFiberRenderer);
document.querySelectorAll('[data-trace-house]').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelectorAll('[data-trace-house]').forEach(item => item.classList.remove('active'));
        button.classList.add('active');
        const project = button.closest('.schema-project');
        const output = project?.querySelector('[data-trace-output]');
        if (!output) return;
        const parentStep = button.dataset.parentCabinet
            ? `<div class="trace-step"><b>${button.dataset.parentCabinet}</b><span>Roditeljski FTTH ormaric / uzete niti za izvedeni ODO</span></div>`
            : '';
        output.innerHTML = `
            <div class="trace-step"><b>${button.dataset.odfName}</b><span>ODF OUT ${button.dataset.out} / patch panel</span></div>
            <div class="trace-step"><b>Magistralni kabl</b><span>SM FO vlakna ${button.dataset.fiberRange || '?'} prema FTTH ormaricu</span></div>
            ${parentStep}
            <div class="trace-step"><b>${button.dataset.cabinetName}</b><span>FTTH ormaric / splitter blok</span></div>
            <div class="trace-step"><b>Splitter ${button.dataset.splitter} / P${button.dataset.port}</b><span>1:4 izlaz prema korisniku</span></div>
            <div class="trace-step"><b>${button.dataset.houseLabel}</b><span>Kuca / krajnja tacka</span></div>
        `;
        localStorage.setItem('ftthTraceHouseId', button.dataset.traceHouse);
    });
});

// ── Project filter ───────────────────────────────────────────────────────────
(function () {
    const buttons  = document.querySelectorAll('#fiber-project-filter .fpf-btn');
    const articles = document.querySelectorAll('#schema-page .schema-project');

    function applyFilter(projectId) {
        articles.forEach(el => {
            el.style.display = (!projectId || el.dataset.projectId === projectId) ? '' : 'none';
        });
        buttons.forEach(btn => btn.classList.toggle('active', btn.dataset.filter === projectId));

        // Persist selection
        if (projectId) sessionStorage.setItem('fiberProjectFilter', projectId);
        else sessionStorage.removeItem('fiberProjectFilter');
    }

    buttons.forEach(btn => btn.addEventListener('click', () => applyFilter(btn.dataset.filter)));

    // Restore last selection, otherwise default to first project
    const saved = sessionStorage.getItem('fiberProjectFilter');
    const firstId = buttons[0]?.dataset.filter ?? '';
    const initial = (saved && [...articles].some(el => el.dataset.projectId === saved)) ? saved : firstId;
    applyFilter(initial);
})();
</script>
@endsection
