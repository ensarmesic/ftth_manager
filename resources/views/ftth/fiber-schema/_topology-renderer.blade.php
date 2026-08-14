function topologyRenderer(shell) {
    const data = JSON.parse(shell.dataset.topologyGraph || '{"odfs":[],"cabinets":[]}');
    const stage = shell.querySelector('.topology-graph-stage');
    const minimap = shell.querySelector('.topology-minimap');
    const expanded = new Set();
    const customPositions = {...(data.layout || {})};
    shell.dataset.layout = JSON.stringify(customPositions);
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
        nodes.forEach(node => { if(customPositions[node.id]) { node.x=Number(customPositions[node.id].x); node.y=Number(customPositions[node.id].y); } });
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
            return `<g class="topology-node" data-node-id="${node.id}" data-node-x="${node.x}" data-node-y="${node.y}" data-node-type="${node.type}" data-cabinet-id="${node.cabinet?.id||''}" transform="translate(${node.x},${node.y})"><rect width="${nodeW}" height="${nodeH}" rx="6" fill="${colors[0]}" stroke="${colors[1]}"/><text x="${nodeW/2}" y="18" text-anchor="middle">${esc(node.label)}</text><text x="${nodeW/2}" y="33" text-anchor="middle" style="font-size:9px;fill:#64748b">${esc(node.meta)}</text></g>`;
        }).join('');
        stage.innerHTML=`<svg width="${maxX}" height="${maxY}">${edgeSvg}${nodeSvg}</svg>`;
        minimap.innerHTML=`<svg viewBox="0 0 ${maxX} ${maxY}">${edges.map(edge=>{const a=byId[edge.from],b=byId[edge.to];return a&&b?`<line x1="${a.x}" y1="${a.y}" x2="${b.x}" y2="${b.y}" stroke="#94a3b8" stroke-width="5"/>`:''}).join('')}${nodes.map(n=>`<rect x="${n.x}" y="${n.y}" width="35" height="18" fill="${n.type==='odf'?'#2563eb':n.type==='house'?'#f97316':'#65a845'}"/>`).join('')}</svg>`;
        stage.querySelectorAll('.topology-node').forEach(node => {
            let dragStart=null;
            node.addEventListener('pointerdown',event=>{event.stopPropagation();dragStart={x:event.clientX,y:event.clientY};node.setPointerCapture(event.pointerId);});
            node.addEventListener('pointerup',event=>{if(!dragStart)return;const dx=(event.clientX-dragStart.x)/scale,dy=(event.clientY-dragStart.y)/scale;if(Math.hypot(dx,dy)>5){customPositions[node.dataset.nodeId]={x:Number(node.dataset.nodeX)+dx,y:Number(node.dataset.nodeY)+dy};shell.dataset.layout=JSON.stringify(customPositions);render();return;}if(node.dataset.nodeType==='cabinet'){const id=Number(node.dataset.cabinetId);expanded.has(id)?expanded.delete(id):expanded.add(id);render();}});
        });
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
    shell.querySelector('[data-topology-action="save-layout"]').onclick=async()=>{const projectId=shell.closest('.schema-project').dataset.projectId;try{const result=await fiberRequest(`/projekti/${projectId}/fiber-raspored`,{method:'PUT',body:JSON.stringify({positions:customPositions})});window.ftthToast(result.message,'success');}catch(error){window.ftthToast(error.message,'error');}};
    render(); requestAnimationFrame(fit);
}
document.querySelectorAll('[data-topology-graph]').forEach(topologyRenderer);
document.querySelectorAll('[data-cad-fiber]').forEach(cadFiberRenderer);
document.querySelectorAll('[data-trace-house]').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelectorAll('[data-trace-house]').forEach(item => item.classList.remove('active'));
        document.querySelectorAll('.trace-highlight').forEach(item => item.classList.remove('trace-highlight'));
        button.classList.add('active');
        button.closest('.cabinet-node,.child-cabinet-node')?.classList.add('trace-highlight');
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
