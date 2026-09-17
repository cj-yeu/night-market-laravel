(() => { 'use strict';
document.querySelectorAll('[data-ai-busy]').forEach(form => {
    form.addEventListener('submit', event => {
        if (form.dataset.submitting) { event.preventDefault(); return; }
        const recoveryAction=event.submitter?.formAction?.includes('/remove-invalid');
        if(form.hasAttribute('data-review-editor') && event.submitter?.value!=='save' && !recoveryAction && !form.querySelector('[name="confirm"]').checked){event.preventDefault();const box=form.querySelector('[name="confirm"]');box.setCustomValidity('Confirm the reviewed records before importing.');box.reportValidity();box.addEventListener('change',()=>box.setCustomValidity(''),{once:true});return;}
        if(event.submitter?.name){const action=document.createElement('input');action.type='hidden';action.name=event.submitter.name;action.value=event.submitter.value;action.dataset.submitAction='1';form.append(action);}
        form.dataset.submitting='1'; form.setAttribute('aria-busy','true');
        form.querySelectorAll('button[type="submit"],button:not([type])').forEach(button=>button.disabled=true);
        const status=form.querySelector('[data-busy-status]'); if(status) status.textContent=form.dataset.aiBusy;
    });
    form.addEventListener('input',()=>{const link=form.querySelector('[data-review-link]');if(link){link.setAttribute('aria-disabled','true');link.title='Save draft edits before reviewing';}});
    form.querySelector('[data-review-link]')?.addEventListener('click',e=>{if(e.currentTarget.getAttribute('aria-disabled')==='true'){e.preventDefault();alert('Save your draft edits before opening Review Import.');}});
    const update=()=>{const count=form.querySelector('[data-selection-count]');if(count){if(!form.hasAttribute('data-review-editor')){count.textContent=`${form.querySelectorAll('[name="source_ids[]"]:checked').length} sources selected for analysis`;return;}const stalls=[...form.querySelectorAll('[data-stall-card]')].filter(card=>card.querySelector('[data-select-stall]').checked);const foods=stalls.reduce((sum,card)=>sum+card.querySelectorAll('[data-select-food]:checked').length,0);count.textContent=`${form.dataset.sourceCount} sources in review · ${stalls.length} stalls · ${foods} foods selected`;}};
    form.addEventListener('change',update);update();
});
document.querySelectorAll('[data-import-context]').forEach(form=>{
    const market=form.querySelector('[data-market]'),stall=form.querySelector('[data-stall]'),options=[...stall.options];
    const sync=()=>document.querySelectorAll('form[data-paste-source]').forEach(paste=>['import_mode','module','market_id','stall_id','name','city'].forEach(name=>{paste.elements[name].value=form.elements[name].value;}));
    const mode=()=>{const existing=form.elements.import_mode.value==='existing_market';market.disabled=!existing;stall.disabled=!existing;market.required=existing;if(form.elements.import_mode.value==='new_market'){market.value='';stall.value='';}sync();};
    const update=(fill)=>{const selected=market.selectedOptions[0];if(fill&&market.value){form.querySelector('[data-market-name]').value=selected.dataset.name;form.querySelector('[data-market-city]').value=selected.dataset.city;}const value=stall.value;stall.replaceChildren(...options.filter(o=>!o.value||o.dataset.market===market.value));stall.value=[...stall.options].some(o=>o.value===value)?value:'';sync();};
    form.querySelectorAll('[name="import_mode"]').forEach(input=>input.addEventListener('change',mode));
    const invalidateResults=()=>document.querySelectorAll('form:has([name="search_id"])').forEach(results=>{results.dataset.targetChanged='1';results.querySelectorAll('button[type="submit"]').forEach(button=>button.disabled=true);const status=results.querySelector('[data-busy-status]');if(status)status.textContent='Your search preferences changed. Search Sources again before analysing these results.';});
    form.addEventListener('input',invalidateResults);form.addEventListener('change',invalidateResults);
    market.addEventListener('change',()=>update(true));form.addEventListener('input',sync);form.addEventListener('change',sync);update(false);mode();
});
document.querySelectorAll('[data-existing-stall]').forEach(select=>{
    const foods=[...select.closest('[data-stall-card]').querySelectorAll('[data-existing-food]')].map(input=>({input,options:[...input.options]}));
    const update=()=>foods.forEach(({input,options})=>{const value=input.value;input.replaceChildren(...options.filter(o=>!o.value||o.dataset.stall===select.value));input.value=[...input.options].some(o=>o.value===value)?value:'';});
    select.addEventListener('change',update);update();
});
document.querySelectorAll('[data-review-editor]').forEach(form=>{
    const market=form.querySelector('[name="market[matched_night_market_id]"]');
    const update=()=>{
        const stalls=[...form.querySelectorAll('[data-stall-card]')].filter(card=>card.querySelector('[data-select-stall]').checked);
        const foods=stalls.reduce((count,card)=>count+card.querySelectorAll('[data-select-food]:checked').length,0);
        const newMarket=market&&!market.value&&form.querySelector('[name="market[selected]"]:checked')?.value==='1';
        const parts=[newMarket?'1 Night Market':null,stalls.length?`${stalls.length} Stall${stalls.length===1?'':'s'}`:null,foods?`${foods} Food${foods===1?'':'s'}`:null].filter(Boolean);
        form.querySelector('[data-import-label]').textContent=parts.length?`Create ${parts.join(', ')}`:'Select records to import';
        if(market?.value)form.querySelector('[data-import-label]').textContent=stalls.length||foods?`Add ${parts.join(', ')} to Existing Market`:'Select records to import';
        const missing=form.querySelector('[data-market-missing-alert]');
        if(missing){
            const address=form.querySelector('[name="market[address]"]')?.value.trim();
            const schedule=[...form.querySelectorAll('[name^="operating_days"][name$="[selected]"]:checked')].some(input=>input.value==='1');
            const fields=[!address?'Address not yet provided':null,!schedule?'Operating schedule not yet provided':null].filter(Boolean);
            missing.hidden=fields.length===0;
            if(fields.length)missing.querySelector('[data-market-missing-text]').textContent=fields.join(' · ');
        }
    };
    form.addEventListener('change',update);form.addEventListener('input',update);update();
});
const cards=[...document.querySelectorAll('[data-source-card]')],filter=document.querySelector('[data-source-filter]'),sort=document.querySelector('[data-source-sort]'),list=document.querySelector('[data-source-list]');
cards.forEach(card=>{const input=card.querySelector('[name="source_ids[]"]');const update=()=>{card.classList.toggle('is-selected',input.checked);card.querySelector('[data-source-selection]').textContent=input.checked?'Selected':'Not selected';};input.addEventListener('change',update);card.addEventListener('click',event=>{if(event.target.closest('a,button,input,label,summary,details'))return;input.checked=!input.checked;input.dispatchEvent(new Event('change',{bubbles:true}));});update();});
const arrange=()=>{if(!list)return;cards.forEach(c=>c.hidden=filter.value!=='all'&&c.dataset.type!==filter.value);[...cards].sort((a,b)=>sort.value==='title'?a.dataset.title.localeCompare(b.dataset.title):sort.value==='newest'?b.dataset.date.localeCompare(a.dataset.date):Number(a.dataset.order)-Number(b.dataset.order)).forEach(c=>list.append(c));};filter?.addEventListener('change',arrange);sort?.addEventListener('change',arrange);document.querySelector('[data-source-reset]')?.addEventListener('click',()=>{filter.value='all';sort.value='relevance';arrange();});
document.querySelectorAll('[data-image-input]').forEach(input=>input.addEventListener('change',()=>{const image=input.closest('.ai-food').querySelector('[data-upload-preview]');if(input.files[0]){if(image.dataset.previewUrl)URL.revokeObjectURL(image.dataset.previewUrl);image.dataset.previewUrl=URL.createObjectURL(input.files[0]);image.src=image.dataset.previewUrl;}}));
document.querySelectorAll('[data-candidate-preview]').forEach(button=>button.addEventListener('click',()=>{
    const image=button.parentElement.querySelector('img');image.hidden=false;image.src=button.dataset.candidatePreview;
    image.addEventListener('error',()=>{image.hidden=true;button.textContent='Preview unavailable — open original image';},{once:true});
    button.disabled=true;
}));
window.addEventListener('pageshow',()=>document.querySelectorAll('[data-ai-busy]').forEach(form=>{delete form.dataset.submitting;form.removeAttribute('aria-busy');form.querySelectorAll('[data-submit-action]').forEach(input=>input.remove());form.querySelectorAll('button').forEach(b=>b.disabled=!!form.dataset.targetChanged);}));
})();
