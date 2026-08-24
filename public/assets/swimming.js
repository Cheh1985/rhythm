(() => {
    'use strict';
    const form = document.querySelector('[data-swimming-form]');
    if (!form || !window.RhythmOffline) return;
    const userId = String(form.dataset.userId || '');
    const swimId = form.dataset.swimmingId || '';
    const sessionKey = swimId ? 'swimming:' + swimId : 'swimming:new';
    const base = (document.querySelector('meta[name="app-url"]')?.content || '').replace(/\/$/, '');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const state = form.querySelector('[data-swim-save-state]');
    const list = form.querySelector('[data-interval-list]');
    const total = form.querySelector('[data-interval-total]');
    let saveTimer = 0;
    const rows = () => [...list.querySelectorAll('[data-interval-row]')];
    const renumber = () => rows().forEach((row, index) => { row.querySelector('.interval-number').textContent = String(index + 1); });
    function updateTotal() { const meters = rows().reduce((sum, row) => sum + Number(row.querySelector('[name="interval_repeat_count[]"]').value || 0) * Number(row.querySelector('[name="interval_distance_m[]"]').value || 0), 0); total.textContent = 'В блоках: ' + meters + ' м'; }
    function addRow(values = {}) {
        const source = rows()[0]; if (!source) return;
        const row = source.cloneNode(true); for (const input of row.querySelectorAll('input')) input.value = '';
        row.querySelector('[name="interval_repeat_count[]"]').value = values.repeat_count || 1;
        for (const [name, value] of Object.entries(values)) { const input = row.querySelector('[name="interval_' + name + '[]"]'); if (input) input.value = value ?? ''; }
        list.append(row); renumber(); updateTotal();
    }
    function payload() {
        const result = {};
        for (const element of form.elements) { if (!element.name || element.name.startsWith('interval_') || element.name === '_csrf') continue; result[element.name] = element.value; }
        for (const key of ['duration_minutes','pool_length_m','total_distance_m','intensity','wellbeing','arms_fatigue','back_fatigue','legs_fatigue','version','schedule_id']) { if (result[key] !== '' && result[key] != null) result[key] = Number(result[key]); else if (key === 'schedule_id') result[key] = null; }
        result.intervals = rows().map((row) => ({repeat_count:Number(row.querySelector('[name="interval_repeat_count[]"]').value),distance_m:Number(row.querySelector('[name="interval_distance_m[]"]').value),style:row.querySelector('[name="interval_style[]"]').value,intensity:row.querySelector('[name="interval_intensity[]"]').value===''?null:Number(row.querySelector('[name="interval_intensity[]"]').value),rest_seconds:row.querySelector('[name="interval_rest_seconds[]"]').value===''?null:Number(row.querySelector('[name="interval_rest_seconds[]"]').value),note:row.querySelector('[name="interval_note[]"]').value}));
        return result;
    }
    async function saveDraft() { await RhythmOffline.saveSession(userId, sessionKey, null, payload()); state.textContent = navigator.onLine ? 'Черновик сохранён на устройстве' : 'Офлайн · черновик сохранён'; }
    function scheduleDraft() { clearTimeout(saveTimer); saveTimer = setTimeout(() => saveDraft().catch(() => {}), 250); updateTotal(); }
    async function sync() {
        if (!navigator.onLine) return;
        for (const action of await RhythmOffline.listActions(userId, sessionKey)) {
            action.status = 'syncing'; await RhythmOffline.putAction(action);
            let response;
            try { response = await fetch(base + action.path, {method:action.method,credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(action.body)}); }
            catch (_) { action.status='pending'; await RhythmOffline.putAction(action); state.textContent='Офлайн · запись ждёт синхронизации'; return; }
            const json = await response.json().catch(() => ({}));
            if (response.ok) { await RhythmOffline.removeAction(action.key); state.textContent='Синхронизировано'; if (json.data?.redirect) location.assign(base + json.data.redirect); continue; }
            action.status=response.status===409?'conflict':'error'; action.error=json.error||'Не удалось синхронизировать.'; await RhythmOffline.putAction(action); state.textContent=(response.status===409?'Конфликт: ':'Ошибка: ')+action.error; return;
        }
    }
    async function restore() {
        const saved = await RhythmOffline.getSession(userId, sessionKey).catch(() => null); if (!saved?.draft || form.dataset.mode === 'edit') return;
        const draft=saved.draft; for (const [name,value] of Object.entries(draft)) { if(name==='intervals') continue; const input=form.elements.namedItem(name); if(input&&value!=null) input.value=String(value); }
        if (Array.isArray(draft.intervals)&&draft.intervals.length) { while(rows().length>1) rows().at(-1).remove(); const fill=(row,values)=>{for(const [name,value] of Object.entries(values)){const input=row.querySelector('[name="interval_'+name+'[]"]');if(input)input.value=value??'';}}; fill(rows()[0],draft.intervals[0]); draft.intervals.slice(1).forEach(addRow); renumber(); updateTotal(); state.textContent='Локальный черновик восстановлен'; }
    }
    form.addEventListener('input', scheduleDraft);
    form.addEventListener('click', (event) => { if(event.target.closest('[data-add-interval]')) addRow(); const remove=event.target.closest('[data-remove-interval]'); if(remove&&rows().length>1){remove.closest('[data-interval-row]').remove();renumber();scheduleDraft();} });
    form.addEventListener('submit', async (event) => { event.preventDefault(); if(!form.reportValidity())return; const method=swimId?'PATCH':'POST'; const path=swimId?'/api/swimming/'+swimId:'/api/swimming'; const existing=await RhythmOffline.listActions(userId,sessionKey); const action=RhythmOffline.createAction({userId,sessionId:sessionKey,type:swimId?'swimming.update':'swimming.create',path,method,body:payload(),dependsOn:existing.length?[existing.at(-1).id]:[]}); await RhythmOffline.enqueue(action,{key:'user:'+userId+':session:'+sessionKey,userId,sessionId:sessionKey,snapshot:null,draft:payload(),updatedAt:Date.now()}); state.textContent=navigator.onLine?'Синхронизация…':'Офлайн · запись в очереди'; await sync(); });
    window.addEventListener('online',()=>sync().catch(()=>{})); updateTotal(); restore().then(sync).catch(()=>{});
})();
