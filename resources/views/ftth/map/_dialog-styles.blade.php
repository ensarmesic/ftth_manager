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
    .snapshot-actions{display:flex;align-items:center;gap:6px}.snapshot-download{border:1px solid #bae6fd;border-radius:7px;background:#f0f9ff;color:#0369a1;padding:8px 10px;font-size:10px;font-weight:800;text-decoration:none}
