function cadFiberRenderer(shell) {
    const data=JSON.parse(shell.dataset.cadFiber || '{"odfs":[],"cabinets":[],"branches":[]}');
    const stage=shell.querySelector('.cad-fiber-stage');
    const colorMode=shell.dataset.colorCode==='true';
    let scale=1, panX=0, panY=0, dragging=false, start=null;
    const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const odfX=2800, odfY=620, odfGap=1050, odfW=240, odfH=280, cabinetW=118, cabinetH=64, cabinetGap=245, childBranchGap=105, branchGap=245, fiberPitch=7;
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
        if(data.odfs.length===1&&reserveFrom<=reserveTo){
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
            labels.push(`<rect x="${centerX-odfW/2-9}" y="${centerY-dynH/2-9}" width="${odfW+18}" height="${dynH+18}" rx="14" fill="${color}" opacity=".055"/>`);
            parts.push(`<g><rect x="${centerX-odfW/2}" y="${centerY-dynH/2}" width="${odfW}" height="${dynH}" rx="12" fill="#fff" stroke="${color}" stroke-width="3" filter="url(#sh)"/><rect x="${centerX-odfW/2}" y="${centerY-dynH/2}" width="${odfW}" height="58" rx="12" fill="${color}"/><rect x="${centerX-odfW/2}" y="${centerY-dynH/2+46}" width="${odfW}" height="12" fill="${color}"/><text x="${centerX}" y="${centerY-dynH/2+22}" text-anchor="middle" fill="#dbeafe" style="font:800 9px Arial;letter-spacing:1px">OPTICAL DISTRIBUTION FRAME</text><text x="${centerX}" y="${centerY-dynH/2+45}" text-anchor="middle" fill="#fff" style="font:900 18px Arial">${esc(odf.name)}</text><rect x="${centerX-88}" y="${centerY-34}" width="176" height="68" rx="8" fill="#eff6ff" stroke="#bfdbfe"/><text x="${centerX}" y="${centerY-9}" text-anchor="middle" class="cad-odf-meta">${odf.ports} PORTOVA · ${odf.fibers}F</text><text x="${centerX}" y="${centerY+13}" text-anchor="middle" class="cad-odf-sub">PATCH PANEL · LC/APC</text><text x="${centerX}" y="${centerY+63}" text-anchor="middle" class="cad-meta">S/R REFERENTNA TAČKA · OLT STRANA</text></g>`);
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
        stage.innerHTML=`<svg width="${width}" height="${height}"><defs><filter id="sh" x="-12%" y="-12%" width="124%" height="124%"><feDropShadow dx="0" dy="2" stdDeviation="4" flood-color="#0f172a" flood-opacity=".13"/></filter></defs><style>.cad-title{font:800 11px Arial;fill:#0f172a}.cad-odf-title{font:900 21px Arial;fill:#1e3a8a}.cad-odf-meta{font:800 13px Arial;fill:#1d4ed8}.cad-odf-sub{font:700 10px Arial;fill:#3b82f6;letter-spacing:.4px}.cad-branch{font:800 11px Arial;fill:#be123c}.cad-meta{font:700 9px Arial;fill:#334155}.cad-port{font:800 8px Arial;fill:#6d28d9}.cad-free{font:800 9px Arial;fill:#15803d}.cad-over{font:700 9px Arial;fill:#dc2626}.cad-label-bg{fill:#fff;stroke:#fecaca;stroke-width:1}.cad-cabinet-node{cursor:pointer}</style><g data-cad-content>${parts.join('')}${labels.join('')}</g></svg>`;
        bindCabinetInteraction();
        refreshMinimap();
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
        const fibers=Math.max(1,Number(branch.fibers)||12), branchRange=branchFiberRange(cabinets), length=Math.max(0,Number(branch.length_m)||0);
        const stackBusHeight=(lineCount-1)*fiberPitch;
        const busTop=y-stackBusHeight/2-15;
        const busBottom=fromCabinet ? y : y+stackBusHeight/2+24;
        parts.push(fromCabinet
            ? `<path d="M${sourceX} ${portStartY} L${sourceX} ${y} L${odfEdge} ${y}" fill="none" stroke="${color}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" opacity=".9"/>`
            : `<path d="M${sourceX} ${portStartY} L${odfEdge} ${portStartY} L${odfEdge} ${busTop}" fill="none" stroke="${color}" stroke-width="6" opacity=".9"/><line x1="${odfEdge}" y1="${busTop-9}" x2="${odfEdge}" y2="${busBottom}" stroke="${color}" stroke-width="3"/>`);
        if(cabinets.length) parts.push(`<circle cx="${odfEdge}" cy="${y}" r="6" fill="${color}"/><text x="${odfEdge+side*12}" y="${y-12}" text-anchor="${side>0?'start':'end'}" class="cad-free">${branchRange}</text>`);
        const labelX=startX+side*(firstCabinetDistance*.5), labelY=y-stackBusHeight/2-82, labelFiber=branchRange!=='?' ? 'F'+branchRange : '';
        const sourceKind=fromCabinet?'ODO':'ODF', cableMeta=`${fibers}F${length?' · '+Math.round(length)+' m':''}${labelFiber?' · '+labelFiber:''}`;
        labels.push(`<g><rect x="${labelX-122}" y="${labelY-4}" width="244" height="58" rx="8" fill="#fff" stroke="${color}" stroke-width="1.5" filter="url(#sh)"/><rect x="${labelX-122}" y="${labelY-4}" width="70" height="58" rx="8" fill="${color}"/><rect x="${labelX-52}" y="${labelY-4}" width="8" height="58" fill="${color}" opacity=".14"/><text x="${labelX-87}" y="${labelY+14}" text-anchor="middle" fill="#dbeafe" style="font:800 8px Arial;letter-spacing:.7px">IZVOR ${sourceKind}</text><text x="${labelX-87}" y="${labelY+33}" text-anchor="middle" fill="#fff" style="font:900 10px Arial">${esc(fitText(odfName,10))}</text><text x="${labelX+35}" y="${labelY+17}" text-anchor="middle" class="cad-branch">${esc(fitText(branch.name,24))}</text><text x="${labelX+35}" y="${labelY+37}" text-anchor="middle" class="cad-meta">KABEL ${esc(cableMeta)}</text></g>`);
        let branchBottomY=y+stackBusHeight/2+34;
        cabinets.forEach((cabinet,index)=>{
            const x=startX+side*(firstCabinetDistance+index*cabinetGap);
            const tapY=y+(index-lineCount/2+.5)*fiberPitch;
            const boxY=tapY+34, titleY=boxY+cabinetH+28, metaY=titleY+16;
            positions[`cab-${cabinet.id}`]={x,y:tapY, boxY, bottomY:metaY+18};
            branchBottomY=Math.max(branchBottomY, metaY+18);
            parts.push(`<line x1="${odfEdge}" y1="${tapY}" x2="${x}" y2="${tapY}" stroke="${colorMode?'#475569':color}" stroke-width="${colorMode?4:2.5}" opacity=".82"/>${colorMode?coloredFiberLines(cabinet,x+(odfEdge<x?-74:74),tapY,x):''}`);
            parts.push(`<circle cx="${x}" cy="${tapY}" r="5.5" fill="${color}"/><circle cx="${x}" cy="${tapY}" r="2.5" fill="#fff"/><text x="${x-side*14}" y="${tapY-9}" text-anchor="${side>0?'end':'start'}" class="cad-port">${fiberRangeText(cabinet)}</text>`);
            parts.push(`<g class="cad-cabinet-node" data-cad-cabinet="${cabinet.id}" tabindex="0"><line x1="${x}" y1="${tapY}" x2="${x}" y2="${boxY}" stroke="${color}" stroke-width="2.5"/><circle cx="${x}" cy="${boxY}" r="4" fill="#fff" stroke="${color}" stroke-width="2"/><rect x="${x-cabinetW/2}" y="${boxY}" width="${cabinetW}" height="${cabinetH}" rx="8" fill="#fff" stroke="${color}" stroke-width="2" filter="url(#sh)"/><rect x="${x-cabinetW/2}" y="${boxY}" width="${cabinetW}" height="24" rx="8" fill="${color}"/><rect x="${x-cabinetW/2}" y="${boxY+16}" width="${cabinetW}" height="8" fill="${color}"/><text x="${x}" y="${boxY+17}" text-anchor="middle" fill="#fff" style="font:900 10px Arial">${esc(fitText(cabinetDisplayName(cabinet),18))}</text><text x="${x}" y="${boxY+42}" text-anchor="middle" class="cad-meta">VLAKNA ${cabinetFiberLabel(cabinet)}</text><text x="${x}" y="${boxY+56}" text-anchor="middle" class="cad-meta">KORISNICI ${cabinet.used}/${cabinet.capacity}</text></g>`);
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
    const clampScale=value=>Math.max(.12,Math.min(5,value));
    function showCabinetDetails(cabinet){
        if(!cabinet)return;
        const panel=shell.querySelector('[data-cad-details]'); if(!panel)return;
        const branch=data.branches.find(item=>Number(item.id)===Number(cabinet.branch_id));
        const odf=data.odfs.find(item=>Number(item.id)===Number(cabinet.odf_id));
        const fiber=cabinet.fiber_from ? (cabinet.fiber_from===cabinet.fiber_to?`F${cabinet.fiber_from}`:`F${cabinet.fiber_from}–F${cabinet.fiber_to}`) : 'Nije dodijeljeno';
        const value=(label,content)=>`<div><small>${label}</small><b>${esc(content??'—')}</b></div>`;
        panel.innerHTML=`<div class="cad-detail-head"><div><small>ODO TEHNIČKI DETALJI</small><strong>${esc(cabinetDisplayName(cabinet))}</strong></div><button type="button" class="cad-detail-close" aria-label="Zatvori">×</button></div><div class="cad-detail-grid">${value('Evidentirani naziv',cabinet.name)}${value('Adresa',cabinet.address||'Nije unesena')}${value('ODF',odf?.name||'Nije povezan')}${value('Krak',branch?.name||'Nije povezan')}${value('Vlakna',fiber)}${value('Tuba',cabinet.tube?`T${cabinet.tube}`:'—')}${value('Korisnici',`${cabinet.used}/${cabinet.capacity}`)}${value('Splitteri',`${cabinet.splitters}/${cabinet.planned_splitters}`)}${value('Optička putanja',cabinet.route_km!==null?`${cabinet.route_km} km`:'Nije unesena')}${value('Splitter odnos',cabinet.splitter_ratio)}${value('ODN gubitak',cabinet.loss_db!==null?`${cabinet.loss_db} dB`:'Nije izračunat')}${value('Headroom',cabinet.headroom_db!==null?`${cabinet.headroom_db} dB`:'Nije izračunat')}</div><div class="cad-detail-status ${esc(cabinet.budget_status)}">STATUS: ${esc(String(cabinet.budget_status||'incomplete').toUpperCase())}</div>`;
        panel.classList.remove('hidden');
        panel.querySelector('.cad-detail-close').onclick=()=>panel.classList.add('hidden');
    }
    function bindCabinetInteraction(){stage.querySelectorAll('[data-cad-cabinet]').forEach(node=>{const open=()=>showCabinetDetails(data.cabinets.find(item=>Number(item.id)===Number(node.dataset.cadCabinet)));node.onclick=event=>{event.stopPropagation();open()};node.onkeydown=event=>{if(['Enter',' '].includes(event.key)){event.preventDefault();open()}}})}
    function refreshMinimap(){
        const minimap=shell.querySelector('[data-cad-minimap]'),content=stage.querySelector('[data-cad-content]'),box=contentBounds();if(!minimap||!content||!box)return;
        minimap.innerHTML=`<svg viewBox="${box.x} ${box.y} ${box.width} ${box.height}" preserveAspectRatio="none"><g opacity=".62">${content.innerHTML}</g><rect class="cad-minimap-viewport" data-cad-minimap-viewport/></svg>`;
        minimap.onclick=event=>{const rect=minimap.getBoundingClientRect(),worldX=box.x+(event.clientX-rect.left)/rect.width*box.width,worldY=box.y+(event.clientY-rect.top)/rect.height*box.height;panX=shell.clientWidth/2-worldX*scale;panY=shell.clientHeight/2-worldY*scale;apply()};
        updateMinimapViewport();
    }
    function updateMinimapViewport(){const viewport=shell.querySelector('[data-cad-minimap-viewport]');if(!viewport)return;viewport.setAttribute('x',-panX/scale);viewport.setAttribute('y',-panY/scale);viewport.setAttribute('width',shell.clientWidth/scale);viewport.setAttribute('height',shell.clientHeight/scale)}
    function contentBounds(){
        const svg=stage.querySelector('svg'), content=svg?.querySelector('[data-cad-content]');
        if(!svg)return null;
        try { const box=content.getBBox(); if(box.width&&box.height)return box; } catch(error) {}
        return {x:0,y:0,width:svg.width.baseVal.value,height:svg.height.baseVal.value};
    }
    function apply(){
        stage.style.transform=`translate(${panX}px,${panY}px) scale(${scale})`;
        const indicator=shell.querySelector('[data-cad-zoom]');
        if(indicator)indicator.textContent=`${Math.round(scale*100)}%`;
        updateMinimapViewport();
    }
    function zoomAt(clientX,clientY,nextScale){
        const rect=shell.getBoundingClientRect(), localX=clientX-rect.left, localY=clientY-rect.top;
        const worldX=(localX-panX)/scale, worldY=(localY-panY)/scale;
        scale=clampScale(nextScale); panX=localX-worldX*scale; panY=localY-worldY*scale; apply();
    }
    function zoomCenter(factor){const rect=shell.getBoundingClientRect();zoomAt(rect.left+rect.width/2,rect.top+rect.height/2,scale*factor)}
    function fit(){
        if(shell.offsetParent===null&&!document.fullscreenElement)return;
        const box=contentBounds(); if(!box)return;
        const padding=Math.max(52,Math.min(110,shell.clientWidth*.045));
        scale=clampScale(Math.min(1.35,(shell.clientWidth-padding*2)/box.width,(shell.clientHeight-padding*2)/box.height));
        panX=(shell.clientWidth-box.width*scale)/2-box.x*scale;
        panY=(shell.clientHeight-box.height*scale)/2-box.y*scale;
        apply();
    }
    function actualSize(){
        const box=contentBounds(); if(!box)return;
        scale=1; panX=(shell.clientWidth-box.width)/2-box.x; panY=(shell.clientHeight-box.height)/2-box.y; apply();
    }
    shell.addEventListener('wheel',e=>{e.preventDefault();zoomAt(e.clientX,e.clientY,scale*(e.deltaY<0?1.16:1/1.16))},{passive:false});
    shell.addEventListener('pointerdown',e=>{if(e.target.closest('.cad-fiber-controls, .cad-color-legend, .cad-document-key, .cad-fiber-minimap, .cad-detail-panel, .cad-cabinet-node'))return;shell.focus({preventScroll:true});dragging=true;shell.classList.add('is-dragging');start={x:e.clientX-panX,y:e.clientY-panY};shell.setPointerCapture(e.pointerId)});
    shell.addEventListener('pointermove',e=>{if(dragging){panX=e.clientX-start.x;panY=e.clientY-start.y;apply()}});
    const stopDragging=()=>{dragging=false;shell.classList.remove('is-dragging')};
    shell.addEventListener('pointerup',stopDragging); shell.addEventListener('pointercancel',stopDragging);
    shell.addEventListener('dblclick',e=>{if(!e.target.closest('.cad-fiber-controls, .cad-color-legend'))zoomAt(e.clientX,e.clientY,scale*(e.shiftKey?1/1.35:1.35))});
    shell.addEventListener('keydown',e=>{
        if(['+','='].includes(e.key)){e.preventDefault();zoomCenter(1.2)}
        else if(e.key==='-'){e.preventDefault();zoomCenter(1/1.2)}
        else if(e.key==='0'){e.preventDefault();actualSize()}
        else if(e.key.toLowerCase()==='f'){e.preventDefault();fit()}
        else if(['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key)){e.preventDefault();panX+=(e.key==='ArrowLeft'?55:e.key==='ArrowRight'?-55:0);panY+=(e.key==='ArrowUp'?55:e.key==='ArrowDown'?-55:0);apply()}
    });
    shell.querySelector('[data-cad-action="zoom-in"]').onclick=()=>zoomCenter(1.2);
    shell.querySelector('[data-cad-action="zoom-out"]').onclick=()=>zoomCenter(1/1.2);
    shell.querySelector('[data-cad-action="actual-size"]').onclick=actualSize;
    shell.querySelector('[data-cad-action="fit"]').onclick=fit;
    shell.querySelector('[data-cad-action="fullscreen"]').onclick=()=>document.fullscreenElement?document.exitFullscreen():shell.requestFullscreen();
    document.addEventListener('fullscreenchange',()=>{if(document.fullscreenElement===shell)setTimeout(fit,80)});
    shell._cadFit=fit;
    render(); setTimeout(fit,0);
}
