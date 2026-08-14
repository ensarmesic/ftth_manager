const fiberCsrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
const fiberEscape = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
const fiberRequest = async (url, options={}) => {
    const response = await fetch(url, { ...options, headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':fiberCsrf,'X-Requested-With':'XMLHttpRequest',...(options.headers||{})} });
    const body = await response.json().catch(()=>({message:'Neispravan odgovor servera.'}));
    const validationMessage=body.errors ? Object.values(body.errors).flat().find(Boolean) : null;
    if(!response.ok) throw new Error(validationMessage || body.message || `HTTP ${response.status}`);
    return body;
};
document.querySelectorAll('.schema-project').forEach(project => {
    const projectId=project.dataset.projectId;
    const search=project.querySelector('[data-fiber-search]'), filter=project.querySelector('[data-fiber-filter]');
    const applyFiberFilter=()=>{const query=(search.value||'').trim().toLocaleLowerCase('bs'),mode=filter.value;project.querySelectorAll('.cabinet-node,.child-cabinet-node,[data-fiber-item],.fiber-plan-line').forEach(item=>{const text=item.textContent.toLocaleLowerCase('bs');const issue=item.matches('.warn,.full,.warning,.error,[data-status="warning"],[data-status="error"]')||Boolean(item.querySelector('.warn,.full'));const free=Boolean(item.querySelector('.port.empty'))||item.dataset.status==='ok';item.classList.toggle('fiber-hidden',Boolean((query&&!text.includes(query))||(mode==='issues'&&!issue)||(mode==='free'&&!free)));});};
    search.addEventListener('input',applyFiberFilter);filter.addEventListener('change',applyFiberFilter);
    project.querySelector('[data-fiber-version]')?.addEventListener('click',async()=>{const label=prompt('Naziv verzije fiber šeme:',`Kontrolna verzija ${new Date().toLocaleString('bs-BA')}`);if(!label)return;try{const result=await fiberRequest(`/projekti/${projectId}/fiber-verzije`,{method:'POST',body:JSON.stringify({label})});window.ftthToast(result.message,'success');}catch(error){window.ftthToast(error.message,'error');}});
    project.querySelector('[data-fiber-lock]')?.addEventListener('click',async()=>{const locked=project.dataset.locked==='1';if(!await window.ftthConfirm(locked?'Otključati odobrenu fiber šemu?':'Zaključati i označiti fiber šemu kao odobrenu?',{title:locked?'Otključavanje šeme':'Odobrenje fiber šeme',detail:locked?'Daljnje splice izmjene ponovo će biti dozvoljene.':'Prije zaključavanja sačuvaj verziju i provjeri power-budget i konflikte.',confirmLabel:locked?'Otključaj':'Zaključaj'}))return;try{await fiberRequest(`/projekti/${projectId}/fiber-zakljucavanje`,{method:'PATCH',body:JSON.stringify({locked:!locked})});location.reload();}catch(error){window.ftthToast(error.message,'error');}});
    project.querySelector('[data-fiber-versions]')?.addEventListener('click',async()=>{const modal=document.getElementById('fiber-version-modal'),list=document.getElementById('fiber-version-list');modal.classList.remove('hidden');list.innerHTML='Učitavam…';try{const data=await fiberRequest(`/projekti/${projectId}/fiber-verzije`);list.innerHTML=data.versions.length?data.versions.map(v=>`<div class="flex items-center gap-2 rounded-lg border p-3"><button type="button" class="min-w-0 flex-1 text-left" data-compare-version="${Number(v.id)}"><b>${fiberEscape(v.label)}</b><small class="block text-slate-500">${fiberEscape(v.user?.name||'Sistem')} · ${fiberEscape(new Date(v.created_at).toLocaleString('bs-BA'))}</small></button><button type="button" class="rounded border px-2 py-1 text-xs font-bold text-amber-700" data-restore-version="${Number(v.id)}">Vrati</button></div>`).join(''):'Nema sačuvanih verzija.';list.querySelectorAll('[data-compare-version]').forEach(btn=>btn.onclick=async()=>{const result=await fiberRequest(`/projekti/${projectId}/fiber-verzije/${btn.dataset.compareVersion}/poredi`);list.innerHTML=`<h3 class="font-bold">Promjene prema: ${fiberEscape(result.version.label)}</h3>${Object.entries(result.changes).map(([key,value])=>`<div class="rounded border p-2"><b>${fiberEscape(key)}</b>: ${fiberEscape(value.before)} → ${fiberEscape(value.after)}</div>`).join('')}`;});list.querySelectorAll('[data-restore-version]').forEach(btn=>btn.onclick=async()=>{if(!await window.ftthConfirm('Vratiti ovu fiber verziju?',{title:'Vraćanje fiber šeme',detail:'Trenutno stanje će prvo biti automatski sačuvano.',confirmLabel:'Vrati verziju'}))return;try{const result=await fiberRequest(`/projekti/${projectId}/fiber-verzije/${btn.dataset.restoreVersion}/vrati`,{method:'POST'});window.ftthToast(result.message,'success');location.reload();}catch(error){window.ftthToast(error.message,'error');}});}catch(error){list.textContent=error.message;}});
    project.querySelectorAll('[data-splice-cabinet]').forEach(button=>button.addEventListener('click',()=>{if(project.dataset.locked==='1')return window.ftthToast('Fiber šema je zaključana.','warning');const form=document.getElementById('fiber-splice-form');form.dataset.projectId=projectId;form.elements.cabinet_id.value=button.dataset.spliceCabinet;form.elements.fiber_number.value=button.dataset.spliceFiber||1;document.getElementById('fiber-splice-modal').classList.remove('hidden');}));
});
document.querySelectorAll('[data-fiber-modal-close]').forEach(button=>button.addEventListener('click',()=>button.closest('.fiber-modal').classList.add('hidden')));
document.querySelectorAll('[data-budget-setup]').forEach(button=>button.addEventListener('click',()=>{
    const form=document.getElementById('budget-setup-form'),settings=JSON.parse(button.dataset.budgetSettings||'{}');
    form.dataset.projectId=button.dataset.projectId;
    Object.entries(settings).forEach(([name,value])=>{if(form.elements[name]&&value!==null)form.elements[name].value=value;});
    document.getElementById('budget-setup-modal').classList.remove('hidden');
}));
document.getElementById('budget-setup-form')?.addEventListener('submit',async event=>{
    event.preventDefault();const form=event.currentTarget,data=Object.fromEntries(new FormData(form));
    for(const key of ['feeder_splitter_ratio','olt_tx_power_dbm','onu_tx_power_dbm','onu_rx_sensitivity_dbm','olt_rx_sensitivity_dbm','engineering_margin_db','connector_count','connector_loss_db','planned_splice_count','splice_allowance_db','additional_passive_loss_db'])data[key]=Number(data[key]);
    try{const result=await fiberRequest(`/projekti/${form.dataset.projectId}/power-budget`,{method:'PATCH',body:JSON.stringify(data)});window.ftthToast(result.message,'success');location.reload();}catch(error){window.ftthToast(error.message,'error');}
});
document.getElementById('fiber-splice-form')?.addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget,data=Object.fromEntries(new FormData(form));for(const key of ['cabinet_id','fiber_number','tray','position','loss_db'])data[key]=Number(data[key]);try{const result=await fiberRequest(`/projekti/${form.dataset.projectId}/fiber-splice`,{method:'POST',body:JSON.stringify(data)});window.ftthToast(result.message,'success');document.getElementById('fiber-splice-modal').classList.add('hidden');}catch(error){window.ftthToast(error.message,'error');}});

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
    const queryParams = new URLSearchParams(location.search);
    const requestedProject = queryParams.get('project');
    applyFilter(requestedProject && [...articles].some(el => el.dataset.projectId === requestedProject) ? requestedProject : initial);
    const requestedCabinet = Number(queryParams.get('cabinet') || 0);
    if (requestedCabinet) {
        const article = [...articles].find(el => el.style.display !== 'none');
        const cabinet = JSON.parse(article?.querySelector('[data-cad-fiber]')?.dataset.cadFiber || '{"cabinets":[]}').cabinets.find(item => Number(item.id) === requestedCabinet);
        if (cabinet) { const input=article.querySelector('[data-fiber-search]'); input.value=cabinet.name; input.dispatchEvent(new Event('input')); article.scrollIntoView({behavior:'smooth'}); }
    }
})();
