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
    .fiber-commandbar{display:flex;flex-wrap:wrap;align-items:center;gap:.45rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;padding:.6rem}.fiber-commandbar input,.fiber-commandbar select{min-height:34px;border:1px solid #cbd5e1;border-radius:.45rem;background:#fff;padding:.35rem .55rem;font-size:.72rem}.fiber-commandbar input{min-width:230px;flex:1}.fiber-commandbar button,.fiber-commandbar a{min-height:34px;border:1px solid #cbd5e1;border-radius:.45rem;background:#fff;padding:.4rem .65rem;color:#334155;font-size:.68rem;font-weight:900;text-decoration:none}.fiber-commandbar .primary{border-color:#075985;background:#075985;color:#fff}.fiber-commandbar .danger{border-color:#fecaca;color:#b91c1c}.fiber-health{background:#ecfdf5;color:#047857}.fiber-health.warn{background:#fffbeb;color:#b45309}.fiber-health.error{background:#fef2f2;color:#b91c1c}.budget-overview{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.6rem;margin-bottom:.75rem;border:1px solid #bae6fd;border-radius:.7rem;background:linear-gradient(135deg,#f0f9ff,#f8fafc);padding:.75rem}.budget-overview strong{display:block;color:#0c4a6e;font-size:.9rem}.budget-overview span{color:#64748b;font-size:.7rem}.budget-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:.7rem}.budget-card{overflow:hidden;border:1px solid #dbe7f3;border-radius:.75rem;background:#fff;box-shadow:0 4px 14px #0f172a0d}.budget-card header{display:flex;align-items:center;justify-content:space-between;gap:.6rem;border-bottom:1px solid #eef2f7;padding:.75rem;font-size:.8rem;font-weight:900}.budget-status{border-radius:999px;padding:.25rem .5rem;background:#dcfce7;color:#166534;font-size:.62rem;white-space:nowrap}.budget-card.warning .budget-status{background:#fef3c7;color:#92400e}.budget-card.error .budget-status{background:#fee2e2;color:#991b1b}.budget-body{padding:.75rem}.budget-verdict{display:flex;align-items:flex-end;justify-content:space-between;gap:.75rem}.budget-verdict b{display:block;color:#0f172a;font-size:1.55rem;line-height:1}.budget-verdict small{color:#64748b;font-size:.65rem}.budget-meter{position:relative;height:12px;margin:.75rem 0 .3rem;overflow:hidden;border-radius:999px;background:linear-gradient(90deg,#fee2e2 0 30%,#dcfce7 30% 82%,#fee2e2 82%)}.budget-meter i{display:block;width:3px!important;height:100%;margin-left:var(--budget-position);background:#0f172a;box-shadow:0 0 0 2px #fff}.budget-scale{display:flex;justify-content:space-between;color:#64748b;font-size:.58rem}.budget-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:.35rem;margin-top:.7rem}.budget-meta span{border-radius:.45rem;background:#f8fafc;padding:.45rem;color:#64748b;font-size:.6rem}.budget-meta b{display:block;color:#0f172a;font-size:.78rem}.budget-details{margin-top:.65rem;border-top:1px solid #eef2f7;padding-top:.55rem}.budget-details summary{cursor:pointer;color:#0369a1;font-size:.68rem;font-weight:900}.budget-formula{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.35rem;margin-top:.55rem;color:#475569;font-size:.63rem}.budget-formula span{border-left:3px solid #bae6fd;background:#f8fafc;padding:.35rem}.fiber-modal{position:fixed;inset:0;z-index:1700;display:grid;place-items:center;background:#0f172abf;padding:16px}.fiber-modal.hidden{display:none}.fiber-modal-card{width:min(620px,100%);max-height:85vh;overflow:auto;border-radius:14px;background:#fff;padding:20px;box-shadow:0 30px 80px #0006}.fiber-modal-card input,.fiber-modal-card select,.fiber-modal-card textarea{width:100%;border:1px solid #cbd5e1;border-radius:7px;padding:8px}.fiber-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}.fiber-modal-actions button{border-radius:7px;padding:8px 13px;font-weight:800}.schema-project.fiber-locked{box-shadow:0 0 0 2px #16a34a,0 12px 30px #1018280f}.fiber-hidden{display:none!important}
    .trace-highlight{outline:3px solid #0ea5e9!important;outline-offset:3px;filter:drop-shadow(0 0 8px #38bdf866)}
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
    .fiber-commandbar .primary{border-color:#047857;background:linear-gradient(135deg,#059669,#047857);box-shadow:0 5px 14px #04785730}.budget-overview{border-color:#a7f3d0;background:linear-gradient(135deg,#ecfdf5,#f8fafc)}.budget-overview strong{color:#065f46}.budget-card{border-color:#d1fae5}.budget-card.estimate{border-color:#bae6fd;background:linear-gradient(180deg,#fff,#f8fdff)}.budget-card.estimate .budget-status{background:#e0f2fe;color:#075985}.budget-card.ok{border-color:#86efac;box-shadow:0 8px 24px #16a34a18}.budget-card.ok header{background:linear-gradient(90deg,#ecfdf5,#fff)}.budget-details summary{color:#047857}.budget-formula span{border-left-color:#6ee7b7}
    .budget-dashboard{position:relative;overflow:hidden;border-radius:18px!important;background:#f4f8f6!important;padding:0!important}.budget-dashboard>.budget-overview{display:none}.budget-hero{position:relative;display:grid;grid-template-columns:minmax(0,1fr) 190px auto;align-items:center;gap:28px;overflow:hidden;background:radial-gradient(circle at 75% 20%,#10b98138,transparent 25%),linear-gradient(125deg,#031d17 0%,#064e3b 58%,#047857 100%);padding:30px 34px;color:#fff}.budget-hero:after{content:"";position:absolute;inset:0;opacity:.16;background-image:linear-gradient(#6ee7b733 1px,transparent 1px),linear-gradient(90deg,#6ee7b733 1px,transparent 1px);background-size:28px 28px;mask-image:linear-gradient(90deg,transparent,#000)}.budget-hero-copy,.signal-orb,.budget-config-button{position:relative;z-index:1}.budget-eyebrow{display:block;margin-bottom:8px;color:#6ee7b7;font-size:10px;font-weight:950;letter-spacing:.2em}.budget-hero h2{margin:0;font-size:clamp(25px,3vw,42px);font-weight:950;letter-spacing:-.045em;line-height:1}.budget-hero h2 em{color:#6ee7b7;font-style:normal}.budget-hero p{margin:10px 0 15px;color:#a7f3d0;font-size:12px}.budget-hero-state{display:inline-flex;align-items:center;gap:8px;border:1px solid #ffffff30;border-radius:999px;background:#ffffff12;padding:6px 10px;font-size:9px;font-weight:950;letter-spacing:.1em}.budget-hero-state i{width:8px;height:8px;border-radius:50%;background:#fbbf24;box-shadow:0 0 12px #fbbf24}.budget-hero-state.confirmed i{background:#34d399;box-shadow:0 0 14px #34d399}.signal-orb{display:grid;place-content:center;width:158px;height:158px;border:1px solid #6ee7b760;border-radius:50%;background:radial-gradient(circle,#065f46 48%,#064e3b 49%);box-shadow:0 0 0 10px #10b98112,0 0 0 20px #10b98108,inset 0 0 35px #10b98145;text-align:center}.signal-orb span{color:#a7f3d0;font-size:8px;font-weight:900;letter-spacing:.12em}.signal-orb strong{font-size:37px;line-height:1}.signal-orb small{color:#6ee7b7;font-size:11px;font-weight:900}.signal-orb.is-good{box-shadow:0 0 0 10px #10b9811f,0 0 35px #34d39955,inset 0 0 35px #10b98155}.budget-config-button{display:flex;align-items:center;gap:8px;border:1px solid #6ee7b7!important;border-radius:12px!important;background:#ecfdf5!important;padding:12px 16px!important;color:#065f46!important;font-size:11px!important;font-weight:950!important;box-shadow:0 10px 28px #001a1255;white-space:nowrap}.budget-config-button span{font-size:16px}.budget-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;border-bottom:1px solid #d1fae5;background:#d1fae5}.budget-kpis article{background:#fff;padding:17px 20px}.budget-kpis span{display:block;color:#64748b;font-size:8px;font-weight:950;letter-spacing:.13em}.budget-kpis strong{display:block;margin:4px 0 2px;color:#064e3b;font-size:20px;font-weight:950}.budget-kpis small{color:#94a3b8;font-size:9px}.budget-dashboard>.budget-grid{padding:20px}.budget-dashboard .budget-card{border:0;border-radius:15px;box-shadow:0 8px 25px #052e2412;transition:transform .2s,box-shadow .2s}.budget-dashboard .budget-card:hover{transform:translateY(-3px);box-shadow:0 15px 36px #052e2422}.budget-dashboard .budget-card header{border-bottom:1px solid #d1fae5;background:linear-gradient(90deg,#f0fdf4,#fff);padding:13px 16px;color:#064e3b}.budget-dashboard .budget-body{padding:16px}.budget-dashboard .budget-meter{height:16px;border:3px solid #fff;box-shadow:0 0 0 1px #dbe7e2;background:linear-gradient(90deg,#fecaca 0 16%,#a7f3d0 16% 82%,#fecaca 82%)}.budget-dashboard .budget-meta span{border:1px solid #e2eee9;background:#f8fbfa;padding:9px}.budget-dashboard .budget-meta b{margin-top:3px;color:#065f46}.budget-dashboard .budget-formula span{border:1px solid #dbece5;border-left:3px solid #10b981;border-radius:7px;background:#f8fbfa;padding:8px}.schema-view-tab[data-schema-view="power-budget"]{color:#047857}.schema-view-tab[data-schema-view="power-budget"].active{border-color:#059669;background:#ecfdf5;color:#065f46}@media(max-width:800px){.budget-hero{grid-template-columns:1fr;padding:24px}.signal-orb{width:130px;height:130px}.budget-kpis{grid-template-columns:repeat(2,1fr)}.budget-config-button{width:max-content}}@media(max-width:480px){.budget-kpis{grid-template-columns:1fr}.budget-dashboard>.budget-grid{padding:12px}.budget-grid{grid-template-columns:1fr}.budget-meta{grid-template-columns:1fr}}
    .optical-path{display:grid;grid-template-columns:auto 1fr auto 1fr auto 1fr auto;align-items:center;margin-bottom:15px;border-radius:10px;background:#062e25;padding:10px;color:#fff}.optical-path span{text-align:center}.optical-path i{display:grid;place-items:center;width:32px;height:32px;margin:auto;border:1px solid #34d39980;border-radius:9px;background:#065f46;color:#6ee7b7;font-size:8px;font-style:normal;font-weight:950}.optical-path b{display:block;margin-top:4px;color:#d1fae5;font-size:8px;white-space:nowrap}.optical-path em{height:2px;background:linear-gradient(90deg,#10b981,#6ee7b7);box-shadow:0 0 7px #34d399}.optical-path em:after{content:"";display:block;float:right;width:5px;height:5px;margin-top:-1.5px;border-radius:50%;background:#a7f3d0;box-shadow:0 0 7px #a7f3d0}@media(max-width:420px){.optical-path b{font-size:7px}.optical-path i{width:27px;height:27px}}
    /* Balanced viewport layout: preserve hierarchy and readable type. */
    .budget-dashboard{max-height:none!important}.budget-hero{grid-template-columns:minmax(0,1fr) 140px auto;gap:22px;padding:22px 28px}.signal-orb{width:128px;height:128px}.budget-dashboard>.budget-grid{grid-template-columns:repeat(auto-fit,minmax(390px,1fr));gap:16px;padding:16px}.budget-kpis article{padding:14px 18px}.budget-kpis span{font-size:10px}.budget-kpis small{font-size:11px}.budget-dashboard .budget-card header{font-size:14px}.budget-status{font-size:11px}.budget-verdict small,.budget-scale{font-size:11px}.budget-dashboard .budget-meta span{font-size:11px}.budget-dashboard .budget-meta b{font-size:13px}.budget-details summary{font-size:12px}.optical-path b{font-size:10px}@media(max-width:1050px){.budget-hero{grid-template-columns:minmax(0,1fr) 128px}.budget-config-button{grid-column:1/-1;width:100%;justify-content:center}.budget-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.budget-dashboard>.budget-grid{grid-template-columns:1fr}}@media(max-width:600px){.budget-hero{grid-template-columns:1fr;padding:20px}.signal-orb{width:120px;height:120px}.budget-kpis{grid-template-columns:1fr}.budget-dashboard>.budget-grid{padding:10px}.optical-path{overflow-x:auto}}
    .budget-guide{display:flex;align-items:center;justify-content:space-between;gap:18px;border-bottom:1px solid #d8e9e2;background:#f8fbfa;padding:11px 18px}.budget-guide>div:first-child{display:flex;align-items:center;gap:10px}.guide-icon{display:grid;place-items:center;width:31px;height:31px;border-radius:9px;background:#d1fae5;color:#047857;font-size:16px;font-weight:950}.budget-guide p{margin:0}.budget-guide b{display:block;color:#064e3b;font-size:11px}.budget-guide small{display:block;color:#64748b;font-size:10px}.budget-legend{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:7px 14px}.budget-legend span{display:flex;align-items:center;gap:5px;color:#475569;font-size:10px;font-weight:750}.budget-legend i{width:7px;height:7px;border-radius:50%}.budget-legend .good{background:#10b981;box-shadow:0 0 0 3px #d1fae5}.budget-legend .warn{background:#f59e0b}.budget-legend .bad{background:#ef4444}.budget-legend .estimate{background:#0ea5e9}.budget-card-title{display:grid;gap:2px}.budget-card-title b{font-size:14px}.budget-card-title small{color:#64748b;font-size:10px;font-weight:700}.budget-status{display:inline-flex;align-items:center;gap:6px}.budget-status i{width:7px;height:7px;border-radius:50%;background:#10b981;box-shadow:0 0 0 3px #d1fae5}.budget-card.warning .budget-status i{background:#f59e0b;box-shadow:0 0 0 3px #fef3c7}.budget-card.error .budget-status i{background:#ef4444;box-shadow:0 0 0 3px #fee2e2}.budget-card.estimate .budget-status i{background:#0ea5e9;box-shadow:0 0 0 3px #e0f2fe}@media(max-width:760px){.budget-guide{align-items:flex-start;flex-direction:column}.budget-legend{justify-content:flex-start}}
    /* Cards must remain self-contained even when the grid has many items. */
    .budget-grid{align-items:start}.budget-card,.budget-card header,.budget-body,.budget-card-title,.budget-verdict>div,.budget-meta span,.budget-formula span{min-width:0}.budget-card{width:100%;max-width:100%}.budget-card header{flex-wrap:wrap}.budget-card-title{overflow:hidden;flex:1 1 180px}.budget-card-title b,.budget-card-title small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.budget-status{flex:0 0 auto}.optical-path{grid-template-columns:minmax(48px,auto) minmax(12px,1fr) minmax(48px,auto) minmax(12px,1fr) minmax(48px,auto) minmax(12px,1fr) minmax(48px,auto);width:100%;box-sizing:border-box}.optical-path span{min-width:0}.optical-path b{max-width:84px;overflow-wrap:anywhere;white-space:normal;line-height:1.2}.budget-verdict{flex-wrap:wrap}.budget-meta{grid-template-columns:repeat(3,minmax(0,1fr))}.budget-meta b,.budget-formula b{overflow-wrap:anywhere}.budget-formula{grid-template-columns:repeat(2,minmax(0,1fr))}.budget-dashboard>.budget-grid{grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr))}@media(max-width:520px){.budget-card header{align-items:flex-start}.budget-status{margin-left:auto}.optical-path{grid-template-columns:repeat(4,minmax(52px,1fr));gap:6px}.optical-path em{display:none}.optical-path b{max-width:none}.budget-meta,.budget-formula{grid-template-columns:1fr}.budget-verdict{gap:10px}}
    .budget-viewbar{display:flex;align-items:center;justify-content:space-between;gap:14px;border-bottom:1px solid #d8e9e2;background:#fff;padding:9px 16px}.budget-viewbar>div:first-child{display:grid}.budget-viewbar b{color:#064e3b;font-size:12px}.budget-viewbar small{color:#64748b;font-size:10px}.budget-view-actions{display:flex;flex-wrap:wrap;gap:6px}.budget-view-actions button{border:1px solid #cbded7;border-radius:8px;background:#f8fbfa;padding:7px 10px;color:#475569;font-size:10px;font-weight:850}.budget-view-actions button:hover,.budget-view-actions button.active{border-color:#10b981;background:#ecfdf5;color:#047857}.budget-grid.fit-view{grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr))!important}.budget-grid.scroll-view{display:grid!important;grid-auto-flow:column;grid-auto-columns:minmax(420px,32vw);grid-template-columns:none!important;max-width:100%;overflow-x:auto;overscroll-behavior-inline:contain;scroll-snap-type:x proximity;padding-bottom:22px!important}.budget-grid.scroll-view .budget-card{scroll-snap-align:start}.budget-grid.scroll-view::-webkit-scrollbar{height:12px}.budget-grid.scroll-view::-webkit-scrollbar-track{border-radius:999px;background:#dce9e4}.budget-grid.scroll-view::-webkit-scrollbar-thumb{border:3px solid #dce9e4;border-radius:999px;background:#059669}.budget-dashboard:fullscreen{width:100vw;height:100vh;max-height:none!important;margin:0;border:0;border-radius:0!important;overflow:auto!important;background:#eef7f3!important}.budget-dashboard:fullscreen .budget-hero{position:sticky;top:0;z-index:5}.budget-dashboard:fullscreen .budget-grid.fit-view{grid-template-columns:repeat(auto-fit,minmax(300px,1fr))!important}.budget-dashboard:fullscreen .budget-viewbar{position:sticky;top:0;z-index:6;box-shadow:0 5px 18px #052e2418}@media(max-width:700px){.budget-viewbar{align-items:flex-start;flex-direction:column}.budget-view-actions{width:100%}.budget-view-actions button{flex:1}.budget-grid.scroll-view{grid-auto-columns:minmax(88vw,420px)}}
    .budget-grid.grid-view{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;overflow:visible}.budget-grid.grid-view .budget-card{min-width:0}.budget-grid.scroll-view{grid-auto-columns:minmax(480px,32vw)}@media(max-width:1500px){.budget-grid.grid-view{grid-template-columns:repeat(3,minmax(0,1fr))!important}}@media(max-width:1100px){.budget-grid.grid-view{grid-template-columns:repeat(2,minmax(0,1fr))!important}}@media(max-width:720px){.budget-grid.grid-view{grid-template-columns:1fr!important}.budget-grid.scroll-view{grid-auto-columns:minmax(88vw,360px)}}
    .budget-grid.grid-view{grid-template-columns:repeat(2,minmax(0,1fr))!important;overflow:visible!important}.budget-dashboard{overflow:visible!important}.budget-dashboard>.budget-grid{max-height:none!important}@media(max-width:900px){.budget-grid.grid-view{grid-template-columns:1fr!important}}
    .budget-grid.vertical-list-view{display:grid!important;grid-auto-flow:row;grid-template-columns:repeat(5,minmax(0,1fr))!important;gap:12px;max-height:820px!important;overflow-x:hidden!important;overflow-y:auto!important;scrollbar-gutter:stable;padding:14px 10px 18px 14px!important}.budget-grid.vertical-list-view::-webkit-scrollbar{width:13px}.budget-grid.vertical-list-view::-webkit-scrollbar-track{border-radius:999px;background:#dce9e4}.budget-grid.vertical-list-view::-webkit-scrollbar-thumb{border:3px solid #dce9e4;border-radius:999px;background:#059669}.budget-grid.vertical-list-view .budget-card{min-width:0}.budget-grid.vertical-list-view .budget-body{padding:11px}.budget-grid.vertical-list-view .optical-path{padding:7px}.budget-grid.vertical-list-view .budget-card header{padding:10px 12px}@media(max-width:1700px){.budget-grid.vertical-list-view{grid-template-columns:repeat(4,minmax(0,1fr))!important}}@media(max-width:1300px){.budget-grid.vertical-list-view{grid-template-columns:repeat(3,minmax(0,1fr))!important}}@media(max-width:950px){.budget-grid.vertical-list-view{grid-template-columns:repeat(2,minmax(0,1fr))!important}}@media(max-width:620px){.budget-grid.vertical-list-view{grid-template-columns:1fr!important;max-height:75vh!important}}
    .budget-grid.vertical-list-view{grid-template-columns:repeat(6,minmax(0,1fr))!important;gap:9px;max-height:690px!important;padding:10px 7px 14px 10px!important}.budget-grid.vertical-list-view .budget-card{border-radius:11px}.budget-grid.vertical-list-view .budget-card header{padding:8px 10px}.budget-grid.vertical-list-view .budget-card-title b{font-size:12px}.budget-grid.vertical-list-view .budget-card-title small{font-size:9px}.budget-grid.vertical-list-view .budget-status{padding:4px 7px;font-size:9px}.budget-grid.vertical-list-view .budget-body{padding:9px}.budget-grid.vertical-list-view .optical-path{margin-bottom:8px;padding:6px}.budget-grid.vertical-list-view .optical-path i{width:25px;height:25px;font-size:7px}.budget-grid.vertical-list-view .optical-path b{font-size:8px}.budget-grid.vertical-list-view .budget-verdict b{font-size:19px}.budget-grid.vertical-list-view .budget-verdict small,.budget-grid.vertical-list-view .budget-scale{font-size:9px}.budget-grid.vertical-list-view .budget-meter{height:11px;margin:7px 0 3px}.budget-grid.vertical-list-view .budget-meta{margin-top:7px}.budget-grid.vertical-list-view .budget-meta span{padding:6px;font-size:9px}.budget-grid.vertical-list-view .budget-meta b{font-size:11px}.budget-grid.vertical-list-view .budget-details{margin-top:7px;padding-top:6px}.budget-grid.vertical-list-view .budget-details summary{font-size:10px}.budget-grid.vertical-list-view .budget-body>p{margin-top:7px!important;padding:6px!important;font-size:9px!important;line-height:1.3}.budget-grid.vertical-list-view .budget-body>.mt-3{margin-top:7px!important;gap:8px!important}.budget-grid.vertical-list-view .budget-body>.mt-3>*{font-size:9px!important}@media(max-width:1800px){.budget-grid.vertical-list-view{grid-template-columns:repeat(5,minmax(0,1fr))!important}}@media(max-width:1450px){.budget-grid.vertical-list-view{grid-template-columns:repeat(4,minmax(0,1fr))!important}}@media(max-width:1100px){.budget-grid.vertical-list-view{grid-template-columns:repeat(3,minmax(0,1fr))!important}}@media(max-width:800px){.budget-grid.vertical-list-view{grid-template-columns:repeat(2,minmax(0,1fr))!important}}@media(max-width:520px){.budget-grid.vertical-list-view{grid-template-columns:1fr!important}}
    /* Keep type readable and reclaim vertical space above the card list. */
    .budget-hero{padding-top:12px;padding-bottom:12px}.budget-hero p{margin-top:5px;margin-bottom:7px}.budget-hero-state{padding-top:4px;padding-bottom:4px}.budget-hero .signal-orb{width:96px;height:96px}.budget-hero .signal-orb strong{font-size:27px}.budget-kpis article{padding-top:7px;padding-bottom:7px}.budget-kpis strong{margin-top:1px;margin-bottom:0}.budget-guide{padding-top:6px;padding-bottom:6px}.budget-viewbar{padding-top:5px;padding-bottom:5px}.budget-grid.vertical-list-view{scroll-padding-bottom:18px}
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
        $fiberPlan = app(\App\Services\FiberPlanService::class)->build($project);
        $fiberAllocations = $fiberPlan['allocations'];
        $fibersPerTube = str_ends_with($project->fiber_layout ?? '6x24', 'x12') ? 12 : 24;
        $tubeCount = (int) str($project->fiber_layout ?? '6x24')->before('x')->value();
        $configuredFiberCapacity = $tubeCount * $fibersPerTube;
        $reservePerTube = min((int) ($project->fiber_reserve_per_tube ?? 0), $fibersPerTube - 1);
        $usedFiberTo = $fiberPlan['usedTo'];
        $odfCapacity = $project->odfs->max('fiber_capacity') ?? 144;
        $reserveFrom = $usedFiberTo + 1;
        $reserveTo = max($odfCapacity, $usedFiberTo + 1);
        $unassignedCabs = $allCabinets->filter(fn ($cabinet) => ! isset($fiberAllocations[$cabinet->id]));
        $fiberWarnings = collect($fiberPlan['issues'])->pluck('message')->all();
    @endphp
    <article class="schema-project {{ $project->fiber_schema_locked ? 'fiber-locked' : '' }}" data-project-id="{{ $project->id }}" data-locked="{{ $project->fiber_schema_locked ? '1' : '0' }}">
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
                <span class="schema-chip fiber-health {{ $fiberPlan['health'] < 60 ? 'error' : ($fiberPlan['health'] < 85 ? 'warn' : '') }}">Health {{ $fiberPlan['health'] }}%</span>
                <span class="schema-chip">{{ $project->fiber_schema_locked ? '🔒 Odobrena' : 'Radna verzija' }}</span>
                @can('project.export')
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
                <a href="{{ route('projects.fiber.csv', $project) }}" class="schema-chip">CSV / Excel</a>
                @endcan
            </div>
        </div>

        <div class="fiber-commandbar">
            <input type="search" data-fiber-search placeholder="Pretraži ODF, ODO, kuću, adresu, krak ili vlakno…">
            <select data-fiber-filter><option value="all">Svi elementi</option><option value="issues">Samo problemi</option><option value="free">Slobodan kapacitet</option></select>
            <a href="{{ route('map.dashboard', ['project' => $project->id]) }}" data-open-map>Otvori mapu</a>
            @can('project.edit')
            <button type="button" data-fiber-version class="primary">Sačuvaj verziju</button>
            @endcan
            <button type="button" data-fiber-versions>Uporedi verzije</button>
            @can('project.edit')
            <button type="button" data-fiber-lock class="{{ $project->fiber_schema_locked ? 'danger' : '' }}">{{ $project->fiber_schema_locked ? 'Otključaj šemu' : 'Zaključaj / odobri' }}</button>
            @endcan
        </div>

        <div class="schema-view-tabs">
            <button type="button" class="schema-view-tab active" data-schema-view="cad-fiber">⌁ CAD šema</button>
            <button type="button" class="schema-view-tab" data-schema-view="color-code">● Color Code</button>
            <button type="button" class="schema-view-tab" data-schema-view="topology">◇ Topologija</button>
            <button type="button" class="schema-view-tab" data-schema-view="rack">▤ Rack</button>
            <button type="button" class="schema-view-tab" data-schema-view="port-plan">P# ODF portovi</button>
            <button type="button" class="schema-view-tab" data-schema-view="splice-plan">⋈ Splice plan</button>
            <button type="button" class="schema-view-tab" data-schema-view="fiber-check">✓ Kontrola</button>
            <button type="button" class="schema-view-tab" data-schema-view="power-budget">dB Power budget</button>
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
                'layout' => $project->fiber_schema_layout ?? [],
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
                    $duplicateFibers = collect($fiberPlan['odfs'])
                        ->flatMap(fn ($odfPlan) => $odfPlan['duplicates'])
                        ->unique()
                        ->values();
                @endphp
                <div class="fiber-check {{ $duplicateFibers->isEmpty() ? 'ok' : 'error' }}"><b>{{ $duplicateFibers->isEmpty() ? '✓' : '!' }}</b><span>{{ $duplicateFibers->isEmpty() ? 'Nema dvostruko dodijeljenih vlakana.' : 'Konflikt na vlaknima: F'.$duplicateFibers->implode(', F') }}</span></div>
                <div class="fiber-check {{ $configuredFiberCapacity === $odfCapacity ? 'ok' : 'warn' }}"><b>{{ $configuredFiberCapacity === $odfCapacity ? '✓' : '!' }}</b><span>Model kabla: {{ $configuredFiberCapacity }}F, {{ $tubeCount }} tuba × {{ $fibersPerTube }} niti. ODF je evidentiran kao {{ $odfCapacity }}F. Sljedeća planirana rezerva: F{{ $reserveFrom }}–F{{ $reserveTo }}.</span></div>
                <div class="fiber-check warn"><b>i</b><span>Profil boja: {{ ($project->fiber_color_standard ?? 'telcordia') === 'din_vde' ? 'DIN/VDE profil' : 'TIA‑598 / Telcordia' }}. Za izvedbeni plan evidentirati proizvođača i tačnu oznaku kabla iz datasheeta.</span></div>
            </div>
        </section></div>
        @php
            $budgetSettings = $project->only(['pon_profile','feeder_splitter_ratio','olt_tx_power_dbm','onu_tx_power_dbm','onu_rx_sensitivity_dbm','olt_rx_sensitivity_dbm','engineering_margin_db','connector_count','connector_loss_db','planned_splice_count','splice_allowance_db','additional_passive_loss_db']);
            $budgetConnections = $fiberPlan['connections'];
            $budgetAverageRx = $budgetConnections->whereNotNull('downstream_rx_dbm')->avg('downstream_rx_dbm');
            $budgetWorstRx = $budgetConnections->whereNotNull('downstream_rx_dbm')->min('downstream_rx_dbm');
            $budgetPassing = $budgetConnections->where('budget_status', 'ok')->count();
            $budgetTotal = $budgetConnections->count();
        @endphp
        <div class="hidden" data-schema-panel="power-budget"><section class="fiber-tool-panel budget-dashboard">
            <div class="budget-hero">
                <div class="budget-hero-copy"><span class="budget-eyebrow">OPTICAL NETWORK CONTROL</span><h2>Power Budget <em>{{ $fiberPlan['profile']['label'] }}</em></h2><p>{{ $fiberPlan['profile']['standard'] }} · {{ $fiberPlan['profile']['downstream_nm'] }} / {{ $fiberPlan['profile']['upstream_nm'] }} nm · projektna margina {{ $fiberPlan['engineeringMargin'] }} dB</p><div class="budget-hero-state {{ $fiberPlan['assumptionsConfirmed'] ? 'confirmed' : 'draft' }}"><i></i>{{ $fiberPlan['assumptionsConfirmed'] ? 'PARAMETRI POTVRĐENI' : 'INŽENJERSKA PROCJENA' }}</div></div>
                <div class="signal-orb {{ $fiberPlan['assumptionsConfirmed'] && $budgetPassing === $budgetTotal ? 'is-good' : '' }}"><span>PROSJEČNI ONU Rx</span><strong>{{ $budgetAverageRx !== null ? number_format($budgetAverageRx, 1) : '—' }}</strong><small>dBm</small></div>
                @can('project.edit')<button type="button" class="budget-config-button" data-budget-setup data-project-id="{{ $project->id }}" data-budget-settings='@json($budgetSettings)'><span>⚙</span>{{ $fiberPlan['assumptionsConfirmed'] ? 'Podesi parametre' : 'Pokreni precizni setup' }}</button>@endcan
            </div>
            <div class="budget-kpis">
                <article><span>NAJSLABIJI SIGNAL</span><strong>{{ $budgetWorstRx !== null ? number_format($budgetWorstRx, 1).' dBm' : 'Nije unesen Tx' }}</strong><small>kritična krajnja tačka</small></article>
                <article><span>PROLAZNOST</span><strong>{{ $fiberPlan['assumptionsConfirmed'] ? $budgetPassing.'/'.$budgetTotal : 'PROCJENA' }}</strong><small>{{ $fiberPlan['assumptionsConfirmed'] ? 'veza unutar klase' : 'čeka potvrdu opreme' }}</small></article>
                <article><span>ODN KLASA</span><strong>{{ $fiberPlan['profile']['min'] }}–{{ $fiberPlan['profile']['max'] }} dB</strong><small>{{ $fiberPlan['profile']['standard'] }}</small></article>
                <article><span>FEEDER SPLITTER</span><strong>{{ (int)($project->feeder_splitter_ratio ?? 1) > 1 ? '1:'.$project->feeder_splitter_ratio : 'NEMA' }}</strong><small>prvi optički stepen</small></article>
            </div>
            <div class="budget-overview {{ $fiberPlan['assumptionsConfirmed'] ? '' : '!border-amber-300 !bg-amber-50' }}"><div><strong>{{ $fiberPlan['assumptionsConfirmed'] ? 'Da li optički signal sigurno prolazi?' : 'Prvo podesi power-budget' }}</strong><span>{{ $fiberPlan['assumptionsConfirmed'] ? 'Parametri projekta su potvrđeni. Rezultat i dalje treba potvrditi terenskim mjerenjem.' : 'Vođeni setup traži samo podatke koji su potrebni za pošten dBm proračun.' }}</span></div><div class="flex items-center gap-3"><div class="text-right"><strong>{{ $fiberPlan['profile']['label'] }}</strong><span>{{ $fiberPlan['profile']['min'] }}–{{ $fiberPlan['profile']['max'] }} dB</span></div>@can('project.edit')<button type="button" class="rounded-lg bg-sky-800 px-3 py-2 text-xs font-black text-white" data-budget-setup data-project-id="{{ $project->id }}" data-budget-settings='@json($budgetSettings)'>{{ $fiberPlan['assumptionsConfirmed'] ? 'Uredi proračun' : 'Pokreni setup' }}</button>@endcan</div></div>
            <div class="budget-guide"><div><span class="guide-icon">ƒ</span><p><b>Kako se računa</b><small>Tx snaga − kompletan ODN gubitak = očekivani Rx signal</small></p></div><div class="budget-legend"><span><i class="good"></i>Sigurna rezerva</span><span><i class="warn"></i>Provjeriti marginu</span><span><i class="bad"></i>Van dozvoljenog</span><span><i class="estimate"></i>Nepotvrđena procjena</span></div></div>
            <div class="budget-grid vertical-list-view">
            @forelse($fiberPlan['connections'] as $connection)
                @php
                    $statusLabel = ! $fiberPlan['assumptionsConfirmed'] ? 'Procjena' : ($connection['below_minimum'] ? 'Premali gubitak' : match($connection['budget_status']) { 'ok' => 'Prolazi', 'warning' => 'Mala rezerva', default => 'Ne prolazi' });
                    $range = max(1, $fiberPlan['profile']['max'] - $fiberPlan['profile']['min']);
                    $marker = min(98, max(2, (($connection['design_loss_db'] - $fiberPlan['profile']['min']) / $range) * 70 + 15));
                @endphp
                <article class="budget-card {{ $connection['budget_status'] }}" data-fiber-item data-status="{{ $connection['budget_status'] }}">
                    <header><span class="budget-card-title"><b>{{ $connection['cabinet'] }}</b><small>{{ $connection['branch'] ?: 'Krak nije dodijeljen' }} · F{{ $connection['fiber_from'] }}–F{{ $connection['fiber_to'] }}</small></span><span class="budget-status"><i></i>{{ $statusLabel }}</span></header>
                    <div class="budget-body">
                        <div class="optical-path" aria-label="Optički put"><span><i>OLT</i><b>{{ $connection['olt_tx_power_dbm'] ?? '—' }} dBm</b></span><em></em><span><i>FIBER</i><b>{{ $connection['route_km'] }} km</b></span><em></em><span><i>SPLIT</i><b>{{ $connection['splitter_ratio'] }}</b></span><em></em><span><i>ONU</i><b>{{ $connection['downstream_rx_dbm'] ?? '—' }} dBm</b></span></div>
                        <div class="budget-verdict"><div><small>Projektni gubitak</small><b>{{ $connection['design_loss_db'] }} dB</b></div><div class="text-right"><small>do maksimuma ostaje</small><b>{{ $connection['headroom_db'] }} dB</b></div></div>
                        <div class="budget-meter" style="--budget-position:{{ $marker }}%"><i></i></div><div class="budget-scale"><span>premalo</span><span>dozvoljeni raspon {{ $fiberPlan['profile']['min'] }}–{{ $fiberPlan['profile']['max'] }} dB</span><span>previše</span></div>
                        <div class="budget-meta"><span>ONU signal ↓<b>{{ $connection['downstream_rx_dbm'] !== null ? $connection['downstream_rx_dbm'].' dBm' : 'Nije unesen Tx' }}</b></span><span>OLT signal ↑<b>{{ $connection['upstream_rx_dbm'] !== null ? $connection['upstream_rx_dbm'].' dBm' : 'Nije unesen Tx' }}</b></span><span>ODN gubitak<b>{{ $connection['loss_db'] }} dB</b></span></div>
                        @if(! $fiberPlan['assumptionsConfirmed'])<p class="mt-2 rounded-lg bg-amber-50 p-2 text-[11px] font-bold text-amber-800">Ovo je informativna procjena iz početnih vrijednosti. Potvrdi stvarne parametre u postavkama projekta prije odluke.</p>@elseif($connection['below_minimum'])<p class="mt-2 rounded-lg bg-red-50 p-2 text-[11px] font-bold text-red-700">Signal može biti prejak. Provjeriti Tx/Rx nivo opreme i potrebu za atenuatorom.</p>@elseif($connection['budget_status']==='error')<p class="mt-2 rounded-lg bg-red-50 p-2 text-[11px] font-bold text-red-700">Plan prelazi dozvoljeni maksimum klase. Smanjiti gubitke ili izabrati odgovarajuću optičku klasu.</p>@elseif($connection['budget_status']==='warning')<p class="mt-2 rounded-lg bg-amber-50 p-2 text-[11px] font-bold text-amber-800">Veza prolazi, ali ostaje manje od 1 dB dodatne rezerve.</p>@else<p class="mt-2 rounded-lg bg-emerald-50 p-2 text-[11px] font-bold text-emerald-700">Veza je unutar klase i ima dovoljnu projektnu rezervu.</p>@endif
                        <details class="budget-details"><summary>Prikaži detaljan obračun</summary><div class="budget-formula"><span>ONU Rx signal<br><b>{{ $connection['olt_tx_power_dbm'] ?? '?' }} dBm − {{ $connection['downstream_loss_db'] }} dB = {{ $connection['downstream_rx_dbm'] ?? '?' }} dBm</b></span><span>OLT Rx signal<br><b>{{ $connection['onu_tx_power_dbm'] ?? '?' }} dBm − {{ $connection['upstream_loss_db'] }} dB = {{ $connection['upstream_rx_dbm'] ?? '?' }} dBm</b></span><span>Downstream {{ $connection['downstream_nm'] }} nm<br><b>{{ $connection['downstream_loss_db'] }} dB</b></span><span>Upstream {{ $connection['upstream_nm'] }} nm<br><b>{{ $connection['upstream_loss_db'] }} dB</b></span><span>Vlakno DS / US<br><b>{{ $connection['fiber_loss_downstream_db'] }} / {{ $connection['fiber_loss_upstream_db'] }} dB</b></span><span>Splitter {{ $connection['splitter_ratio'] }}<br><b>{{ $connection['splitter_loss_db'] }} dB</b></span><span>{{ $connection['connector_count'] }} konektora<br><b>{{ $connection['connector_loss_db'] }} dB</b></span><span>{{ $connection['splice_count'] }} varenja<br><b>{{ $connection['splice_loss_db'] }} dB</b></span><span>WDM / atenuator / ostalo<br><b>{{ $connection['additional_passive_loss_db'] }} dB</b></span><span>Projektna zaštita<br><b>ODN + {{ $connection['engineering_margin_db'] }} dB margine</b></span></div></details>
                        <div class="mt-3 flex flex-wrap gap-3">@can('project.export')<a href="{{ route('projects.fiber.field-sheet', [$project, $connection['cabinet_id']]) }}" target="_blank" class="text-[11px] font-bold text-sky-700">Terenski list</a>@endcan<a href="{{ route('map.dashboard', ['project'=>$project->id, 'cabinet'=>$connection['cabinet_id']]) }}" class="text-[11px] font-bold text-emerald-700">Na mapi</a>@can('project.edit')<button type="button" data-splice-cabinet="{{ $connection['cabinet_id'] }}" data-splice-fiber="{{ $connection['fiber_from'] }}" class="text-[11px] font-bold text-violet-700">Splice zapis</button>@endcan</div>
                    </div>
                </article>
            @empty<div class="fiber-check warn">Nema potpunih ODO veza za proračun.</div>@endforelse
            </div>
        </section></div>
        <div class="hidden" data-schema-panel="topology">
            <div class="topology-graph-shell" data-topology-graph='@json($topologyGraph)'>
                <div class="topology-controls"><button data-topology-action="zoom-in">+</button><button data-topology-action="zoom-out">&minus;</button><button data-topology-action="fit">Fit</button><button data-topology-action="collapse">Sažmi</button>@can('project.edit')<button data-topology-action="save-layout">Sačuvaj raspored</button>@endcan</div>
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
                                                <div class="mt-1 text-[10px] font-bold text-amber-600">{{ $activeSplitters }} x {{ $splitterRatio }} / vlakna nisu dodijeljena</div>
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
                                                            <button type="button" class="port" title="S{{ $splitter }} / P{{ $absolutePort }} -> {{ $house->label }}" data-trace-house="{{ $house->id }}" data-house-label="{{ $house->label }}" data-cabinet-name="{{ $cabinet->name }}" data-odf-name="{{ $odf->name }}" data-fiber-range="nije dodijeljeno" data-splitter="{{ $splitter }}" data-port="{{ $absolutePort }}" data-out="{{ $rackOutCounter }}">
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

@include('ftth.fiber-schema._modals')
<script>
document.querySelectorAll('.budget-dashboard').forEach(dashboard => {
    dashboard.querySelector('[data-budget-fullscreen]')?.addEventListener('click', async () => {
        if (document.fullscreenElement) await document.exitFullscreen();
        else await dashboard.requestFullscreen();
    });
});
document.querySelectorAll('.schema-project').forEach(project => {
    project.querySelectorAll('[data-schema-view]').forEach(button => button.addEventListener('click', () => {
        project.querySelectorAll('[data-schema-view]').forEach(item => item.classList.toggle('active', item === button));
        project.querySelectorAll('[data-schema-panel]').forEach(panel => panel.classList.toggle('hidden', panel.dataset.schemaPanel !== button.dataset.schemaView));
    }));
});
@include('ftth.fiber-schema._cad-renderer')
@include('ftth.fiber-schema._topology-renderer')
@include('ftth.fiber-schema._actions')
</script>
@endsection
