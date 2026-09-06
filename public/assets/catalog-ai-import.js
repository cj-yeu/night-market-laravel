(() => { 'use strict';
document.querySelectorAll('[data-ai-busy]').forEach(form => {
    form.addEventListener('submit', event => {
        if (form.dataset.submitting) { event.preventDefault(); return; }
        form.dataset.submitting='1'; form.setAttribute('aria-busy','true');
        form.querySelectorAll('button[type="submit"],button:not([type])').forEach(button=>button.disabled=true);
        const status=form.querySelector('[data-busy-status]'); if(status) status.textContent=form.dataset.aiBusy;
    });
    form.addEventListener('input',()=>{const link=form.querySelector('[data-review-link]');if(link){link.setAttribute('aria-disabled','true');link.title='Save draft edits before reviewing';}});
    form.querySelector('[data-review-link]')?.addEventListener('click',e=>{if(e.currentTarget.getAttribute('aria-disabled')==='true'){e.preventDefault();alert('Save your draft edits before opening Review Import.');}});
    const update=()=>{const count=form.querySelector('[data-selection-count]');if(count)count.textContent=`${form.querySelectorAll('[name="source_ids[]"]:checked').length} sources · ${form.querySelectorAll('[data-select-stall]:checked').length} stalls · ${form.querySelectorAll('[data-select-food]:checked').length} foods selected`;};
    form.addEventListener('change',update);update();
});
document.querySelectorAll('[data-import-context]').forEach(form=>{
    const market=form.querySelector('[data-market]'),stall=form.querySelector('[data-stall]'),options=[...stall.options];
    const sync=()=>document.querySelectorAll('form[data-paste-source]').forEach(paste=>['module','market_id','stall_id','name','city'].forEach(name=>{paste.elements[name].value=form.elements[name].value;}));
    const update=(fill)=>{const selected=market.selectedOptions[0];if(fill&&market.value){form.querySelector('[data-market-name]').value=selected.dataset.name;form.querySelector('[data-market-city]').value=selected.dataset.city;}const value=stall.value;stall.replaceChildren(...options.filter(o=>!o.value||o.dataset.market===market.value));stall.value=[...stall.options].some(o=>o.value===value)?value:'';sync();};
    market.addEventListener('change',()=>update(true));form.addEventListener('input',sync);form.addEventListener('change',sync);update(false);
});
document.querySelectorAll('[data-existing-stall]').forEach(select=>{
    const foods=[...select.closest('[data-stall-card]').querySelectorAll('[data-existing-food]')].map(input=>({input,options:[...input.options]}));
    const update=()=>foods.forEach(({input,options})=>{const value=input.value;input.replaceChildren(...options.filter(o=>!o.value||o.dataset.stall===select.value));input.value=[...input.options].some(o=>o.value===value)?value:'';});
    select.addEventListener('change',update);update();
});
const cards=[...document.querySelectorAll('[data-source-card]')],filter=document.querySelector('[data-source-filter]'),sort=document.querySelector('[data-source-sort]'),list=document.querySelector('[data-source-list]');
const arrange=()=>{if(!list)return;cards.forEach(c=>c.hidden=filter.value!=='all'&&c.dataset.type!==filter.value);[...cards].sort((a,b)=>sort.value==='title'?a.dataset.title.localeCompare(b.dataset.title):sort.value==='newest'?b.dataset.date.localeCompare(a.dataset.date):Number(a.dataset.order)-Number(b.dataset.order)).forEach(c=>list.append(c));};filter?.addEventListener('change',arrange);sort?.addEventListener('change',arrange);document.querySelector('[data-source-reset]')?.addEventListener('click',()=>{filter.value='all';sort.value='relevance';arrange();});
document.querySelectorAll('[data-image-input]').forEach(input=>input.addEventListener('change',()=>{const image=input.closest('.ai-food').querySelector('[data-upload-preview]');if(input.files[0]){if(image.dataset.previewUrl)URL.revokeObjectURL(image.dataset.previewUrl);image.dataset.previewUrl=URL.createObjectURL(input.files[0]);image.src=image.dataset.previewUrl;}}));
document.querySelectorAll('[data-candidate-preview]').forEach(button=>button.addEventListener('click',()=>{
    const image=button.parentElement.querySelector('img');image.hidden=false;image.src=button.dataset.candidatePreview;
    image.addEventListener('error',()=>{image.hidden=true;button.textContent='Preview unavailable — open original image';},{once:true});
    button.disabled=true;
}));
window.addEventListener('pageshow',()=>document.querySelectorAll('[data-ai-busy]').forEach(form=>{delete form.dataset.submitting;form.removeAttribute('aria-busy');form.querySelectorAll('button').forEach(b=>b.disabled=false);}));
})();
