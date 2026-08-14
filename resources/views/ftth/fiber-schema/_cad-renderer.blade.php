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
