(() => {
    'use strict';
    const initialise = () => {
        const adminSidebar = document.getElementById('adminSidebar');
        if (adminSidebar) {
            const toggles = document.querySelectorAll('[data-bs-toggle="offcanvas"][aria-controls="adminSidebar"]');
            const updateExpanded = () => toggles.forEach(toggle => {
                toggle.setAttribute('aria-expanded', adminSidebar.classList.contains('show') ? 'true' : 'false');
            });
            adminSidebar.addEventListener('shown.bs.offcanvas', updateExpanded);
            adminSidebar.addEventListener('hidden.bs.offcanvas', updateExpanded);
            updateExpanded();
        }
        const selectors = [...document.querySelectorAll('select[data-parent-select], select[data-searchable]')];
        const controls = selectors.map(select => {
            const parent = document.getElementById(select.dataset.parentSelect);
            const market = document.getElementById(select.dataset.marketSelect);
            const options = [...select.children].map(option => option.cloneNode(true));
            let search;
            if (select.dataset.searchable !== undefined && select.options.length > 15) {
                search = document.createElement('input');
                search.type = 'search';
                search.disabled = select.disabled;
                search.className = 'form-control form-control-sm mb-2';
                search.placeholder = 'Type to narrow the options';
                search.setAttribute('aria-label', 'Search ' + (select.labels[0]?.textContent.trim() || 'options'));
                search.setAttribute('aria-controls', select.id);
                select.before(search);
                search.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });
            }
            const hint = document.createElement('div');
            hint.className = 'form-text';
            hint.setAttribute('role', 'status');
            hint.hidden = true;
            select.after(hint);
            const update = (parentChanged = false) => {
                if (parentChanged && search) search.value = '';
                const selected = select.value;
                const term = (search?.value || '').trim().toLocaleLowerCase();
                const matches = option => (!parent?.value || option.dataset.parent === parent.value)
                    && (!market?.value || option.dataset.market === market.value);
                const nodes = options.map(node => {
                    if (node.tagName === 'OPTGROUP') {
                        const group = node.cloneNode(false);
                        [...node.children].filter(option => matches(option) && (!term || option.value === selected || (option.textContent + node.label).toLocaleLowerCase().includes(term)))
                            .forEach(option => group.append(option.cloneNode(true)));
                        return group.children.length ? group : null;
                    }
                    if (!node.value || (matches(node) && (!term || node.value === selected || node.textContent.toLocaleLowerCase().includes(term)))) return node.cloneNode(true);
                    return null;
                }).filter(Boolean);
                select.replaceChildren(...nodes);
                select.value = [...select.options].some(option => option.value === selected) ? selected : '';
                hint.hidden = [...select.options].some(option => option.value);
                hint.textContent = hint.hidden ? '' : 'No matching options. Adjust the parent selection or search.';
                if (selected !== select.value) select.dispatchEvent(new Event('change', {bubbles: true}));
            };
            parent?.addEventListener('change', () => update(true));
            market?.addEventListener('change', () => update(true));
            search?.addEventListener('input', () => update());
            return {select, update, resetOptions: () => select.replaceChildren(...options.map(option => option.cloneNode(true)))};
        });

        // Restore the server-rendered filters when returning through the browser history.
        const filters = [...document.querySelectorAll('form[method="GET" i] input:not([type="hidden"]), form[method="GET" i] select')]
            .map(field => ({field, value: field.value}));
        const restore = () => {
            controls.forEach(({resetOptions}) => resetOptions());
            filters.forEach(({field, value}) => { field.value = value; });
            controls.forEach(({update}) => update());
        };
        restore();
        window.addEventListener('pageshow', event => { if (event.persisted) restore(); });

        const marketSelect = document.querySelector('[data-schedule-select]');
        const dateInput = document.querySelector('[data-schedule-date]');
        const summary = document.querySelector('[data-planner-summary]');
        const scheduleHint = document.querySelector('[data-schedule-hint]');
        if (marketSelect) {
            const updateSchedule = () => {
                const option = marketSelect.selectedOptions[0];
                const date = dateInput?.value;
                const days = (option?.dataset.days || '').split('|').filter(Boolean);
                const label = option?.value ? option.textContent.trim() : 'Choose a Night Market';
                if (summary) summary.textContent = label + (date ? ' · ' + date : ' · Choose a visit date');
                if (!scheduleHint) return;
                const schedule = option?.dataset.schedule || '';
                let message = schedule ? 'Regular schedule: ' + schedule + '. ' : 'Choose a market to view its regular schedule. ';
                if (date && option?.value && days.length) {
                    const weekday = new Intl.DateTimeFormat('en', {weekday:'long', timeZone:'Asia/Kuala_Lumpur'}).format(new Date(date + 'T12:00:00+08:00'));
                    message += days.includes(weekday) ? 'Scheduled on your selected ' + weekday + '. ' : 'Not scheduled on your selected ' + weekday + '. Choose an operating day' + (marketSelect.dataset.fallback === 'true' ? ' or generate recommendations to see an alternative date' : '') + '. ';
                }
                scheduleHint.textContent = message + 'Regular schedules are not live opening updates.';
            };
            marketSelect.addEventListener('change', updateSchedule);
            dateInput?.addEventListener('change', updateSchedule);
            window.addEventListener('pageshow', updateSchedule);
            updateSchedule();
        }
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialise, {once:true});
    else initialise();
})();
