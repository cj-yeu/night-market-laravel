(() => {
    'use strict';
    const start = () => {
        const form = document.getElementById('night-out-form');
        if (!form) return;
        const field = name => form.querySelector(`[name="${name}"]`);
        const boxes = () => [...form.querySelectorAll('[name="interests[]"], [name="categories[]"]')];
        const label = input => input.labels?.[0]?.textContent.replace('✓', '').trim() || input.value;
        const selection = name => [...form.querySelectorAll(`[name="${name}[]"]:checked`)].map(input => input.value);
        const dateLabel = value => value ? new Intl.DateTimeFormat('en-MY', {dateStyle: 'full', timeZone: 'Asia/Kuala_Lumpur'}).format(new Date(`${value}T12:00:00+08:00`)) : 'Choose a date';
        const jsonPost = async (url, body) => {
            const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': field('_token').value}, body: JSON.stringify(body)});
            if (!response.ok) throw new Error(response.status === 429 ? 'Please wait a minute before asking again.' : 'This request could not be completed. Review your preferences or refresh the page.');
            return response.json();
        };
        let stale = false;
        const invalidate = () => {
            if (stale || !document.querySelector('[data-snapshot]')) return;
            stale = true;
            document.getElementById('planner-stale').hidden = false;
            document.querySelectorAll('[data-snapshot-save] button[type="submit"]').forEach(button => button.disabled = true);
            const tokens = new Set([...document.querySelectorAll('[data-snapshot]')].map(panel => panel.dataset.snapshot));
            tokens.forEach(snapshot_id => jsonPost(document.getElementById('planner-results').dataset.invalidateUrl, {snapshot_id}).catch(() => {
                document.getElementById('planner-stale').textContent = 'Your preferences changed. Generate again before saving. The old recommendation could not be invalidated on the server; do not reuse it.';
            }));
        };
        const update = () => {
            const date = field('visit_date').value;
            document.getElementById('date-readable').textContent = dateLabel(date);
            form.querySelectorAll('[data-date]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.date === 'custom' ? ![form.dataset.today, form.dataset.tomorrow].includes(date) : form.dataset[button.dataset.date] === date)));
            form.querySelectorAll('[data-budget]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.budget === 'custom' ? !['20', '30', '50'].includes(field('budget_max').value) : button.dataset.budget === field('budget_max').value)));
            const selected = boxes().filter(input => input.checked);
            const chips = document.getElementById('food-selected');
            chips.replaceChildren();
            if (!selected.length) chips.textContent = 'Any food';
            selected.forEach(input => {
                const chip = document.createElement('button');
                chip.type = 'button'; chip.className = 'btn btn-sm btn-outline-market';
                chip.textContent = `${label(input)} ×`; chip.setAttribute('aria-label', `Remove ${label(input)}`);
                chip.addEventListener('click', () => { input.checked = false; input.dispatchEvent(new Event('change', {bubbles:true})); document.getElementById('clear-food').focus(); });
                chips.append(chip);
            });
            const summary = document.getElementById('live-preferences');
            summary.replaceChildren();
            Object.entries({Date:dateLabel(date), City:field('city').selectedOptions[0]?.textContent || 'Any city', 'Food budget':field('budget_max').value ? `Up to RM${Number(field('budget_max').value).toFixed(2)}` : 'Not set', Halal:field('halal_preference').selectedOptions[0]?.textContent, Interests:selected.map(label).join(', ') || 'Any food'}).forEach(([name, value]) => {
                const dt=document.createElement('dt'), dd=document.createElement('dd');dt.textContent=name;dd.textContent=value;summary.append(dt,dd);
            });
        };
        form.querySelectorAll('[data-date]').forEach(button => button.addEventListener('click', () => {
            if (button.dataset.date === 'custom') { field('visit_date').focus(); field('visit_date').showPicker?.(); return; }
            field('visit_date').value = form.dataset[button.dataset.date]; field('visit_date').dispatchEvent(new Event('change', {bubbles:true}));
        }));
        form.querySelectorAll('[data-budget]').forEach(button => button.addEventListener('click', () => {
            if (button.dataset.budget === 'custom') { field('budget_max').focus(); return; }
            field('budget_max').value = button.dataset.budget; field('budget_max').dispatchEvent(new Event('change', {bubbles:true}));
        }));
        document.getElementById('clear-food').addEventListener('click', () => {boxes().forEach(input => input.checked = false); invalidate(); update();});
        document.getElementById('category-search').addEventListener('input', event => {
            let count = 0;
            form.querySelectorAll('[data-category-choice]').forEach(choice => {choice.hidden = !choice.textContent.toLowerCase().includes(event.target.value.trim().toLowerCase()); if (!choice.hidden) count++;});
            document.getElementById('category-empty').hidden = count !== 0;
        });
        form.addEventListener('change', event => {
            if (event.target.name && event.target.name !== '_token') {
                if (event.target.name === 'template' && event.target.value === 'budget' && !field('budget_max').value) field('budget_max').value = '30';
                invalidate(); update();
            }
        });
        form.addEventListener('input', event => {if (event.target.name) {invalidate(); update();}});
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]'); button.disabled = true; form.setAttribute('aria-busy','true');
            document.getElementById('generate-status').textContent = 'Finding eligible foods and checking your plan…';
        });
        // Parsed preferences are suggestions, never automatic assignments.
        let parsed = null;
        const parseButton = document.getElementById('parse-night');
        parseButton.addEventListener('click', async () => {
            const text = document.getElementById('ideal-night').value.trim();
            const status = document.getElementById('parse-status');
            if (!text) {status.textContent='Enter a short request first, or use the preference controls.'; return;}
            parseButton.disabled = true; status.textContent='Interpreting your request…'; document.getElementById('parsed-preferences').hidden = true;
            try {
                const data = await jsonPost(form.dataset.parseUrl, {text});
                parsed = data.preferences; status.textContent = data.notice;
                if (!parsed) return;
                const list = document.getElementById('parsed-fields'); list.replaceChildren();
                Object.entries(parsed).forEach(([name, value]) => {
                    if (value === null || (Array.isArray(value) && !value.length)) return;
                    const current = name === 'interests' ? selection('interests') : field(name).value;
                    const conflict = JSON.stringify(current) !== JSON.stringify(Array.isArray(value) ? value : String(value));
                    const row=document.createElement('label'), input=document.createElement('input'), span=document.createElement('span');
                    input.type='checkbox'; input.dataset.parsed=name; // Deliberately unchecked, including conflicts.
                    const display = name === 'interests' ? value.map(key => label(document.getElementById(`interest-${key}`))).join(', ') : name === 'halal_preference' ? [...field(name).options].find(option => option.value===value)?.textContent : name === 'visit_date' ? dateLabel(value) : String(value);
                    span.textContent = `${name.replaceAll('_',' ')}: ${display}${conflict ? ' — differs from current choice; select to replace it' : ' — matches current choice'}${name === 'interests' ? '. Applying replaces all current food interests and individual category selections.' : ''}`;
                    row.append(input,span);list.append(row);
                });
                document.getElementById('parsed-preferences').hidden=false;
            } catch (error) {status.textContent=error.message;} finally {parseButton.disabled=false;}
        });
        document.getElementById('apply-parsed').addEventListener('click', () => {
            if (!parsed) return;
            document.querySelectorAll('[data-parsed]:checked').forEach(input => {
                const name=input.dataset.parsed, value=parsed[name];
                if (name==='interests') {
                    // Explicitly applying a new food preference replaces both group
                    // and individual selections; tell the user in the confirmation.
                    boxes().forEach(box => box.checked = box.name==='interests[]' && value.includes(box.value));
                } else {
                    if (name === 'city' && field('night_market_id').value && field('night_market_id').selectedOptions[0]?.dataset.parent !== value) {
                        if (!window.confirm('This city conflicts with your selected night market. Clear the target market and apply this city?')) return;
                    }
                    field(name).value=value;field(name).dispatchEvent(new Event('change',{bubbles:true}));
                }
            });
            invalidate();update();document.getElementById('parse-status').textContent='Selected suggestions applied. Review your preferences, then generate when ready.';
            document.getElementById('parsed-preferences').hidden=true;
        });
        document.querySelectorAll('[data-plan-result]').forEach(panel => {
            const save = panel.querySelector('[data-snapshot-save]');
            const catalog = new Map([...panel.querySelectorAll('template[data-food]')].map(template => [template.dataset.food, template]));
            const rows = () => [...panel.querySelectorAll('[data-food-row]')];
            const selectedIds = () => rows().map(row => row.querySelector('[data-selected-food]').value);
            const cost = ids => {
                let low=0, high=0, unknown=false, lowerUnknown=false;
                ids.forEach(id => {const item=catalog.get(id)?.dataset; if (!item) {unknown=true;return;} if(item.max==='') unknown=true;else high+=Math.round(Number(item.max)*100);if(item.min==='') lowerUnknown=true;else low+=Math.round(Number(item.min)*100);});
                return {low, high, unknown, lowerUnknown};
            };
            const format = amount => `RM${(amount/100).toFixed(2)}`;
            const refresh = () => {
                const ids=selectedIds(), total=cost(ids), budget=panel.dataset.budget;
                const over = budget!=='' && (total.unknown || total.high>Math.round(Number(budget)*100));
                panel.querySelector('[data-total]').textContent=total.unknown ? 'Total unavailable — some prices are unknown' : total.lowerUnknown ? `Up to ${format(total.high)}` : total.low===total.high ? format(total.high) : `${format(total.low)}–${format(total.high)}`;
                panel.querySelector('[data-count]').textContent=String(ids.length);
                save.querySelector('button[type="submit"]').disabled=stale || !ids.length || over;
                panel.querySelector('[data-save-notice]').textContent=!ids.length ? 'No food stops selected. Generate again to start a new combination.' : over ? 'This combination exceeds the food budget or contains unknown prices. Remove or replace a food.' : 'Costs are checked again when saving. Replacements keep the same market and preferences.';
            };
            rows().forEach(row => {
                row.querySelector('[data-remove]').addEventListener('click', () => {row.remove();refresh();panel.querySelector('[data-save-notice]').setAttribute('tabindex','-1');panel.querySelector('[data-save-notice]').focus();});
                row.querySelector('[data-replace]').addEventListener('click', event => {
                    const replacementPanel=row.querySelector('[data-replace-panel]'), select=row.querySelector('[data-replacement]');
                    replacementPanel.hidden=!replacementPanel.hidden;event.target.setAttribute('aria-expanded',String(!replacementPanel.hidden));
                    let available=0;
                    [...select.options].forEach(option => {
                        if (!option.value) return;
                        const hypothetical=selectedIds().filter(id => id!==row.querySelector('[data-selected-food]').value).concat(option.value);
                        const price=cost(hypothetical);
                        option.disabled=selectedIds().includes(option.value) || (panel.dataset.budget!=='' && (price.unknown || price.high>Math.round(Number(panel.dataset.budget)*100)));
                        if(!option.disabled)available++;
                    });
                    row.querySelector('[data-replace-message]').textContent=available ? 'Only unselected foods that fit the remaining budget are available.' : 'No eligible replacement fits the remaining budget.';
                    if(!replacementPanel.hidden)select.focus();
                });
                row.querySelector('[data-confirm-replace]').addEventListener('click', () => {
                    const select=row.querySelector('[data-replacement]'), id=select.value, template=catalog.get(id);
                    if(!template || select.selectedOptions[0].disabled)return;
                    row.querySelector('[data-selected-food]').value=id;
                    row.querySelector('[data-food-name]').textContent=template.dataset.name;
                    row.querySelector('[data-food-stall]').textContent=template.dataset.stall;
                    row.querySelector('[data-food-halal]').textContent=template.dataset.halal;
                    row.querySelector('[data-food-category]').textContent=template.dataset.category;
                    row.querySelector('[data-food-image]').replaceChildren(template.content.cloneNode(true));
                    const price=cost([id]);
                    row.querySelector('[data-food-price]').textContent=price.unknown ? 'Price not available' : price.lowerUnknown ? `Up to ${format(price.high)}` : `${format(price.low)}–${format(price.high)}`;
                    row.querySelector('[data-food-reason]').textContent='Your replacement from the same eligible market and food preferences.';
                    row.querySelector('[data-remove]').setAttribute('aria-label',`Remove ${template.dataset.name}`);
                    row.querySelector('[data-replace-panel]').hidden=true;row.querySelector('[data-replace]').setAttribute('aria-expanded','false');row.querySelector('[data-replace]').focus();refresh();
                });
            });
            save.addEventListener('submit', () => {save.querySelector('button[type="submit"]').disabled=true;save.setAttribute('aria-busy','true');});
            refresh();
        });
        update();
        window.addEventListener('pageshow', event => {if (event.persisted) {update();invalidate();form.removeAttribute('aria-busy');form.querySelector('button[type="submit"]').disabled=false;document.getElementById('generate-status').textContent='';}});
    };
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
