(() => {
    const script = document.currentScript;
    const base = script?.dataset.base || '/';
    const nameFields = new Set(['seller_name', 'dealer_name', 'buyer_name', 'party_name',
        'new_car_partner_name', 'outside_owner_name']);
    const bind = input => {
        if (input.dataset.personSuggestionsBound || input.type === 'hidden') return;
        input.dataset.personSuggestionsBound = '1';
        const panel = document.createElement('div');
        panel.className = 'form-hint person-suggestions';
        panel.setAttribute('aria-live', 'polite');
        input.insertAdjacentElement('afterend', panel);
        let timer, controller;
        input.addEventListener('input', () => {
            clearTimeout(timer); controller?.abort(); panel.replaceChildren();
            const query = input.value.trim();
            if (query.length < 2) return;
            timer = setTimeout(async () => {
                controller = new AbortController();
                try {
                    const response = await fetch(base + 'persons/search.php?q=' + encodeURIComponent(query), {signal: controller.signal});
                    if (!response.ok) return;
                    const rows = await response.json();
                    if (input.value.trim() !== query || !Array.isArray(rows) || !rows.length) return;
                    const message = document.createElement('div');
                    message.textContent = 'Existing people — check before creating another record:';
                    panel.append(message);
                    rows.forEach(row => {
                        const item = document.createElement('div');
                        const link = document.createElement('a');
                        link.href = base + 'persons/view.php?' + new URLSearchParams({kind: row.kind, id: row.id});
                        link.target = '_blank'; link.rel = 'noopener';
                        link.textContent = row.name + ' · ' + [...new Set(row.roles)].join(', ') + (row.phone ? ' · ' + row.phone : '');
                        item.append(link);
                        panel.append(item);
                    });
                    const hint = document.createElement('div');
                    hint.textContent = 'Same name alone does not merge accounts. Open the statement to reuse a record or merge confirmed duplicates.';
                    panel.append(hint);
                } catch (error) { /* Keep the form usable when suggestions are unavailable. */ }
            }, 300);
        });
    };
    const scan = root => root.querySelectorAll('input[name]').forEach(input => {
        const listAdd = input.name === 'name'
            && /\/(parties|partners)\/list\.php$/.test(location.pathname);
        if (nameFields.has(input.name) || listAdd) bind(input);
    });
    scan(document);
    new MutationObserver(changes => {
        if (changes.some(change => [...change.addedNodes].some(node => node.nodeType === 1 && (node.matches('input, form') || node.querySelector('input'))))) scan(document);
    }).observe(document.body, {childList: true, subtree: true});
})();
