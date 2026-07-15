// AutoBooks Pro — Frontend JavaScript
document.documentElement.classList.add('js');

document.addEventListener('DOMContentLoaded', function() {
    restoreSidebarState();
    initResponsiveSidebar();
    initCurrencyInputs();
    initRegistrationInputs();
    initTableShells();
    initScrollMemory();
    enhanceSelects();
    initExclusiveChoices();
    initLazyTables();
    initSmartBackLinks();
    initBreadcrumbs();
    initFloatingTooltips();

    // Auto-dismiss alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.3s, transform 0.3s';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });

    // Confirm dialogs. Delegated so lazy-loaded rows behave the same way.
    document.addEventListener('click', function(e) {
        const el = e.target.closest('[data-confirm]');
        if (!el) return;
        if (!confirm(el.dataset.confirm)) {
            e.preventDefault();
        }
    });

    document.addEventListener('submit', function(e) {
        const form = e.target.closest('form[data-confirm-submit]');
        if (!form || form.dataset.confirmed === '1') return;
        if (!confirm(form.dataset.confirmSubmit)) {
            e.preventDefault();
            return;
        }
        form.dataset.confirmed = '1';
    });

    document.addEventListener('click', async function(e) {
        const shareButton = e.target.closest('[data-share-url]');
        if (!shareButton) return;
        const url = shareButton.dataset.shareUrl || '';
        const title = shareButton.dataset.shareTitle || 'Attachment';
        if (navigator.share) {
            try {
                await navigator.share({ title, url });
                return;
            } catch (error) {
                if (error && error.name === 'AbortError') return;
            }
        }
        if (navigator.clipboard && url) {
            await navigator.clipboard.writeText(url);
            showToast('Share link copied.');
        } else if (url) {
            window.open(url, '_blank', 'noopener');
        }
    });

    // Dynamic form fields based on transaction type
    const txnTypeSelect = document.getElementById('transaction_type');
    if (txnTypeSelect) {
        txnTypeSelect.addEventListener('change', function() {
            const type = this.value;
            // Hide all optional sections
            document.querySelectorAll('.txn-section').forEach((section) => {
                section.style.display = 'none';
                setConditionalControls(section, false);
            });
            
            // Show relevant sections
            const sectionMap = {
                'CAR_PURCHASE': ['car-section', 'partner-funding-section'],
                'CAR_TOKEN_RECEIVED': ['car-select-section', 'buyer-identity-section', 'token-section'],
                'CAR_SALE': ['car-select-section', 'buyer-identity-section', 'buyer-section'],
                'CAR_EXPENSE': ['car-select-section', 'category-section'],
                'RTO_EXPENSE': ['rto-section'],
                'RTO_RECOVERY': ['rto-section'],
                'GENERAL_EXPENSE': ['category-section'],
                'JOURNAL_VOUCHER': ['split-bill-section'],
                'PARTNER_INVEST': ['partner-section'],
                'PARTNER_WITHDRAW': ['partner-section'],
                'SALARY_PAYMENT': ['employee-section', 'salary-section'],
                'EMPLOYEE_ADVANCE': ['employee-section'],
                'LOAN_GIVEN': ['counterparty-section'],
                'LOAN_RECEIVED': ['party-select-section'],
                'LOAN_TAKEN': ['counterparty-section'],
                'LOAN_REPAID': ['party-select-section'],
                'CONTRA_TRANSFER': ['contra-section'],
            };

            const sections = sectionMap[type] || [];
            sections.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.style.display = 'block';
                    setConditionalControls(el, true);
                }
            });
            if (typeof syncPreselectedExpenseCarState === 'function') {
                syncPreselectedExpenseCarState(type);
            }

            // Update payment account label
            const pLabel = document.getElementById('payment-account-label');
            const paymentAccountGroup = document.getElementById('payment-account-group');
            if (paymentAccountGroup) paymentAccountGroup.style.display = '';
            if (typeof filterPrimaryPaymentAccounts === 'function') {
                filterPrimaryPaymentAccounts(type);
            }
            if (pLabel) {
                const inTypes = ['PARTNER_INVEST', 'LOAN_TAKEN', 'LOAN_RECEIVED', 'CAR_TOKEN_RECEIVED'];
                if (type === 'JOURNAL_VOUCHER') {
                    pLabel.textContent = 'Cash / Bank account';
                } else if (type === 'CAR_SALE') {
                    pLabel.textContent = 'Receiving Account';
                } else {
                    pLabel.textContent = inTypes.includes(type) ? 'Receiving Account' : 'Payment Account';
                }
            }
            if (typeof syncSaleAmountUi === 'function') {
                syncSaleAmountUi();
            }
        });

        if (txnTypeSelect.dataset.preselectedType) {
            txnTypeSelect.value = txnTypeSelect.dataset.preselectedType;
        }
        txnTypeSelect.dispatchEvent(new Event('change'));
    }

});

function restoreSidebarState() {
    const nav = document.querySelector('.sidebar-nav');
    if (!nav) return;

    const collapsed = localStorage.getItem('autobooks.sidebar.collapsed') === '1';
    if (window.innerWidth > 768) {
        document.body.classList.toggle('sidebar-collapsed', collapsed);
    }

    const key = 'autobooks.sidebar.scrollTop';
    const saved = sessionStorage.getItem(key);
    if (saved !== null) {
        nav.scrollTop = parseInt(saved, 10) || 0;
    } else {
        document.querySelector('.sidebar .nav-link.active')?.scrollIntoView({ block: 'center' });
    }

    nav.addEventListener('scroll', () => {
        sessionStorage.setItem(key, String(nav.scrollTop));
    }, { passive: true });

    document.querySelectorAll('.sidebar .nav-link').forEach((link) => {
        link.addEventListener('click', () => {
            sessionStorage.setItem(key, String(nav.scrollTop));
        });
    });
}

function initResponsiveSidebar() {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const toggle = document.getElementById('sidebar-toggle');
    if (!sidebar || !backdrop || !toggle) return;

    const closeSidebar = () => {
        sidebar.classList.remove('open');
        document.body.classList.remove('sidebar-open');
    };

    const openSidebar = () => {
        sidebar.classList.add('open');
        document.body.classList.add('sidebar-open');
    };

    toggle.addEventListener('click', () => {
        if (window.innerWidth > 768) {
            const nextState = !document.body.classList.contains('sidebar-collapsed');
            document.body.classList.toggle('sidebar-collapsed', nextState);
            localStorage.setItem('autobooks.sidebar.collapsed', nextState ? '1' : '0');
            return;
        }
        if (sidebar.classList.contains('open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    backdrop.addEventListener('click', closeSidebar);

    document.querySelectorAll('.sidebar .nav-link').forEach((link) => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                closeSidebar();
            }
        });
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 768) {
            closeSidebar();
            document.body.classList.toggle('sidebar-collapsed', localStorage.getItem('autobooks.sidebar.collapsed') === '1');
        }
    }, { passive: true });
}

function initCurrencyInputs(scope = document) {
    const inputs = scope.querySelectorAll('input.currency-input:not([data-currency-ready])');
    if (!inputs.length) return;

    const normalize = (value) => String(value || '').replace(/[^0-9.\-]/g, '');
    const format = (value) => {
        const normalized = normalize(value);
        if (!normalized) return '';
        const parsed = parseFloat(normalized);
        return Number.isFinite(parsed) ? formatINR(parsed).replace(/^₹/, '') : normalized;
    };

    inputs.forEach((input) => {
        input.dataset.currencyReady = '1';
        if (input.value) {
            input.value = format(input.value);
        }

        input.addEventListener('focus', () => {
            input.value = normalize(input.value);
        });

        input.addEventListener('blur', () => {
            input.value = format(input.value);
            if (input.classList.contains('amount-input') && typeof updateSplitTotals === 'function') {
                updateSplitTotals();
            }
        });
    });

    document.querySelectorAll('form').forEach((form) => {
        if (form.dataset.currencySubmitReady === '1') return;
        form.dataset.currencySubmitReady = '1';
        form.addEventListener('submit', () => {
            form.querySelectorAll('input.currency-input').forEach((input) => {
                input.value = normalize(input.value);
            });
        });
    });
}

function initRegistrationInputs(scope = document) {
    scope.querySelectorAll('input.registration-input:not([data-registration-ready])').forEach((input) => {
        input.dataset.registrationReady = '1';
        input.addEventListener('input', () => {
            input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        });
    });
}

function setConditionalControls(container, active, options = {}) {
    if (!container) return;
    const clear = options.clear === true;

    container.querySelectorAll('input, select, textarea, button').forEach((control) => {
        if (control.dataset.keepEnabled === '1') return;

        if (!active && clear) {
            if (control.matches('input[type="checkbox"], input[type="radio"]')) {
                control.checked = false;
            } else if (control.matches('select')) {
                const emptyOption = Array.from(control.options).find((option) => option.value === '');
                control.value = emptyOption ? '' : (control.options[0]?.value || '');
            } else if (!control.matches('button')) {
                control.value = '';
            }
        }

        control.disabled = !active;
        if (control.matches('select')) refreshCustomSelect(control);
    });
}

function initExclusiveChoices(scope = document) {
    scope.querySelectorAll('[data-exclusive-choice]:not([data-exclusive-ready])').forEach((choice) => {
        choice.dataset.exclusiveReady = '1';
        const modeInput = choice.querySelector('[data-exclusive-mode]');
        const buttons = Array.from(choice.querySelectorAll('[data-exclusive-option]'));
        const panels = Array.from(choice.querySelectorAll('[data-exclusive-panel]'));
        if (!modeInput || !buttons.length || !panels.length) return;

        const availableModes = buttons.filter((button) => !button.disabled).map((button) => button.dataset.exclusiveOption);
        const applyMode = (requestedMode, focusPanel = false) => {
            const mode = availableModes.includes(requestedMode) ? requestedMode : availableModes[0];
            if (!mode) return;

            modeInput.value = mode;
            choice.dataset.activeMode = mode;
            buttons.forEach((button) => {
                const active = button.dataset.exclusiveOption === mode;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            panels.forEach((panel) => {
                const active = panel.dataset.exclusivePanel === mode;
                panel.hidden = !active;
                setConditionalControls(panel, active, { clear: !active });
            });

            if (focusPanel) {
                const panel = panels.find((candidate) => candidate.dataset.exclusivePanel === mode);
                const firstControl = panel?.querySelector('select, input:not([type="hidden"]), textarea');
                const customTrigger = firstControl?.closest('.custom-select')?.querySelector('.custom-select-trigger');
                (customTrigger || firstControl)?.focus();
            }
        };

        buttons.forEach((button) => {
            button.addEventListener('click', () => applyMode(button.dataset.exclusiveOption, true));
        });
        applyMode(modeInput.value || choice.dataset.defaultMode || availableModes[0]);
    });
}

function initLazyTables() {
    document.querySelectorAll('[data-lazy-list]').forEach((container) => {
        const tbody = container.querySelector('tbody');
        const sentinel = container.querySelector('[data-lazy-sentinel]');
        if (!tbody || !sentinel || !container.dataset.nextUrl) return;

        let loading = false;
        const status = container.querySelector('[data-lazy-status]');

        const loadNext = async () => {
            if (loading || !container.dataset.nextUrl) return;
            loading = true;
            container.classList.add('is-loading-next');
            if (status) status.textContent = 'Preparing more rows...';

            try {
                const response = await fetch(container.dataset.nextUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) throw new Error('Could not load next rows');
                const payload = await response.json();

                if (payload.html) {
                    const template = document.createElement('template');
                    template.innerHTML = payload.html.trim();
                    tbody.append(...template.content.childNodes);
                }

                container.dataset.nextUrl = payload.next_url || '';
                sentinel.hidden = !payload.next_url;
                tryRestoreContainerScroll(container);
                if (status) {
                    status.textContent = payload.next_url ? 'More rows will load as you scroll.' : 'All rows loaded.';
                }
            } catch (error) {
                if (status) status.textContent = 'Could not load more rows. Use refresh and try again.';
            } finally {
                loading = false;
                container.classList.remove('is-loading-next');
            }
        };

        const observer = new IntersectionObserver((entries) => {
            if (entries.some(entry => entry.isIntersecting)) loadNext();
        }, {
            root: container,
            rootMargin: '240px 0px 240px 0px',
        });

        observer.observe(sentinel);
        tryRestoreContainerScroll(container);
    });
}

function initTableShells(scope = document) {
    scope.querySelectorAll('table').forEach((table) => {
        if (!table.querySelector('thead')) return;
        if (table.dataset.staticTable === '1') return;

        let wrapper = table.closest('.table-container');
        if (!wrapper) {
            wrapper = document.createElement('div');
            wrapper.className = 'table-container table-container-inline';
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        }

        classifyTableShell(table, wrapper);
    });
}

function classifyTableShell(table, wrapper) {
    const columnCount = table.querySelectorAll('thead tr:first-child th').length;
    wrapper.classList.remove('table-columns-compact', 'table-columns-medium', 'table-columns-wide');

    if (columnCount <= 4) {
        wrapper.classList.add('table-columns-compact');
    } else if (columnCount <= 7) {
        wrapper.classList.add('table-columns-medium');
    } else {
        wrapper.classList.add('table-columns-wide');
    }
}

function initScrollMemory() {
    const pageKey = getPageScrollStorageKey();
    const savedState = readScrollMemory(pageKey);
    const containers = getTrackedScrollContainers();

    containers.forEach((container, index) => {
        if (!container.dataset.scrollMemoryId) {
            container.dataset.scrollMemoryId = `scroll-container-${index}`;
        }
        const savedContainer = savedState?.containers?.[container.dataset.scrollMemoryId];
        if (savedContainer) {
            container.dataset.restoreScrollTop = String(savedContainer.top || 0);
            container.dataset.restoreScrollLeft = String(savedContainer.left || 0);
        }
    });

    const saveState = () => {
        const payload = {
            containers: {},
            savedAt: Date.now(),
        };

        getTrackedScrollContainers().forEach((container, index) => {
            if (!container.dataset.scrollMemoryId) {
                container.dataset.scrollMemoryId = `scroll-container-${index}`;
            }
            payload.containers[container.dataset.scrollMemoryId] = {
                top: container.scrollTop,
                left: container.scrollLeft,
            };
        });

        sessionStorage.setItem(pageKey, JSON.stringify(payload));
    };

    window.addEventListener('pagehide', saveState);
    window.addEventListener('beforeunload', saveState);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') saveState();
    });

    document.addEventListener('click', (event) => {
        if (event.target.closest('a[href]')) {
            saveState();
        }
    });

    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', saveState);
    });

    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            getTrackedScrollContainers().forEach(tryRestoreContainerScroll);
        });
    });
}

function getTrackedScrollContainers() {
    return Array.from(document.querySelectorAll('[data-scroll-memory], [data-lazy-list]'))
        .filter((container) => !container.classList.contains('sidebar-nav'));
}

function getPageScrollStorageKey() {
    return `autobooks.scroll.${location.pathname}${location.search}`;
}

function readScrollMemory(pageKey) {
    try {
        const raw = sessionStorage.getItem(pageKey);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return null;
        return parsed;
    } catch (error) {
        return null;
    }
}

function tryRestoreContainerScroll(container) {
    const topValue = container.dataset.restoreScrollTop;
    const leftValue = container.dataset.restoreScrollLeft;
    const hasTop = topValue !== undefined && topValue !== '';
    const hasLeft = leftValue !== undefined && leftValue !== '';
    const targetTop = hasTop ? Number(topValue) : 0;
    const targetLeft = hasLeft ? Number(leftValue) : 0;
    if (!hasTop && !hasLeft) return;

    const maxTop = Math.max(container.scrollHeight - container.clientHeight, 0);
    const maxLeft = Math.max(container.scrollWidth - container.clientWidth, 0);

    if (hasLeft) {
        container.scrollLeft = Math.min(targetLeft, maxLeft);
    }

    if (hasTop && (maxTop >= targetTop || !container.dataset.nextUrl)) {
        container.scrollTop = Math.min(targetTop, maxTop);
        delete container.dataset.restoreScrollTop;
        delete container.dataset.restoreScrollLeft;
        return;
    }

    if (container.dataset.nextUrl && !container.dataset.restoreLoading) {
        container.dataset.restoreLoading = '1';
        const status = container.querySelector('[data-lazy-status]');
        if (status) status.textContent = 'Returning you to the last position...';
        container.scrollTop = maxTop;
        setTimeout(() => {
            delete container.dataset.restoreLoading;
            tryRestoreContainerScroll(container);
        }, 180);
    }
}

function initSmartBackLinks() {
    document.querySelectorAll('a[data-smart-back]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (!shouldUseHistoryBack()) return;
            event.preventDefault();
            history.back();
        });
    });
}

function shouldUseHistoryBack() {
    if (history.length <= 1 || !document.referrer) return false;
    try {
        const referrer = new URL(document.referrer);
        return referrer.origin === location.origin;
    } catch (error) {
        return false;
    }
}

const customSelectInstances = new WeakMap();
let activeCustomSelect = null;

function getVisibleViewport() {
    const viewport = window.visualViewport;
    const left = viewport?.offsetLeft || 0;
    const top = viewport?.offsetTop || 0;
    const width = viewport?.width || window.innerWidth;
    const height = viewport?.height || window.innerHeight;
    return { left, top, right: left + width, bottom: top + height, width, height };
}

function positionFloatingPopover(trigger, popover, options = {}) {
    if (!trigger || !popover || popover.hidden) return false;

    const viewport = getVisibleViewport();
    const rect = trigger.getBoundingClientRect();
    const gutter = Math.max(6, Number(options.gutter ?? 10));
    const gap = Math.max(0, Number(options.gap ?? 6));
    if (rect.bottom < viewport.top || rect.top > viewport.bottom || rect.right < viewport.left || rect.left > viewport.right) {
        return false;
    }

    const availableWidth = Math.max(0, viewport.width - gutter * 2);
    const requestedWidth = Number(options.width || rect.width);
    const minWidth = Math.min(Number(options.minWidth ?? 220), availableWidth);
    const maxWidth = Math.min(Number(options.maxWidth ?? 620), availableWidth);
    const width = Math.min(maxWidth, Math.max(minWidth, requestedWidth));
    const left = Math.max(viewport.left + gutter, Math.min(rect.left, viewport.right - width - gutter));

    const roomBelow = Math.max(0, viewport.bottom - rect.bottom - gap - gutter);
    const roomAbove = Math.max(0, rect.top - viewport.top - gap - gutter);
    const preferredMaxHeight = Math.max(1, Number(options.maxHeight ?? 420));
    const preferredMinHeight = Math.max(1, Number(options.minHeight ?? 160));
    const idealHeight = Math.min(preferredMaxHeight, Math.max(preferredMinHeight, popover.scrollHeight || preferredMinHeight));
    const openAbove = roomBelow < Math.min(preferredMinHeight, idealHeight) && roomAbove > roomBelow;
    const availableHeight = openAbove ? roomAbove : roomBelow;
    const maxHeight = Math.max(1, Math.min(preferredMaxHeight, availableHeight));

    popover.style.position = 'fixed';
    popover.style.width = `${width}px`;
    popover.style.maxHeight = `${maxHeight}px`;
    popover.style.left = `${left}px`;
    popover.style.right = 'auto';
    popover.style.bottom = 'auto';
    popover.style.top = `${viewport.top + gutter}px`;
    popover.dataset.placement = openAbove ? 'top' : 'bottom';

    const panelHeight = Math.min(popover.getBoundingClientRect().height, maxHeight);
    const desiredTop = openAbove ? rect.top - gap - panelHeight : rect.bottom + gap;
    const top = Math.max(viewport.top + gutter, Math.min(desiredTop, viewport.bottom - panelHeight - gutter));
    popover.style.top = `${top}px`;
    return true;
}

window.positionFloatingPopover = positionFloatingPopover;

function enhanceSelects(scope = document) {
    const candidates = [];
    if (scope instanceof Element && scope.matches('select')) candidates.push(scope);
    candidates.push(...scope.querySelectorAll('select'));

    candidates.forEach((select) => {
        if (
            select.dataset.selectEnhanced === '1' ||
            select.matches('[multiple], [data-native-select], .native-transaction-select') ||
            Number(select.getAttribute('size') || 0) > 1
        ) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'custom-select';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.classList.add('custom-select-native');
        select.dataset.selectEnhanced = '1';
        select.tabIndex = -1;

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'custom-select-trigger';
        trigger.innerHTML = '<span class="custom-select-value"></span><i class="ri-arrow-down-s-line" aria-hidden="true"></i>';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        wrapper.appendChild(trigger);

        const popover = document.createElement('div');
        popover.className = 'custom-select-popover';
        popover.hidden = true;
        document.body.appendChild(popover);

        const instance = { select, wrapper, trigger, popover, search: null, list: null, observer: null };
        customSelectInstances.set(select, instance);

        trigger.addEventListener('click', () => {
            if (select.disabled) return;
            activeCustomSelect === instance ? closeCustomSelect(instance) : openCustomSelect(instance);
        });
        trigger.addEventListener('keydown', (event) => {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                openCustomSelect(instance);
                focusCustomSelectOption(instance, event.key === 'ArrowUp' ? -1 : 1);
            }
        });
        select.addEventListener('change', () => refreshCustomSelect(select));
        select.addEventListener('invalid', () => trigger.focus());

        instance.observer = new MutationObserver(() => refreshCustomSelect(select));
        instance.observer.observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'hidden', 'label'] });
        refreshCustomSelect(select);
    });
}

function refreshCustomSelect(select) {
    const instance = customSelectInstances.get(select);
    if (!instance) return;
    const selected = select.options[select.selectedIndex];
    const value = instance.trigger.querySelector('.custom-select-value');
    value.textContent = selected ? selected.textContent.trim() : (select.dataset.placeholder || 'Select');
    instance.trigger.disabled = select.disabled;
    instance.trigger.classList.toggle('is-placeholder', !selected || selected.value === '');
    instance.wrapper.classList.toggle('is-disabled', select.disabled);
    if (activeCustomSelect === instance) buildCustomSelectMenu(instance);
}

function openCustomSelect(instance) {
    if (activeCustomSelect && activeCustomSelect !== instance) closeCustomSelect(activeCustomSelect);
    activeCustomSelect = instance;
    buildCustomSelectMenu(instance);
    instance.popover.hidden = false;
    instance.wrapper.classList.add('is-open');
    instance.trigger.setAttribute('aria-expanded', 'true');
    positionCustomSelect(instance);
    requestAnimationFrame(() => {
        positionCustomSelect(instance);
        if (instance.search) instance.search.focus();
    });
}

function closeCustomSelect(instance, restoreFocus = false) {
    if (!instance) return;
    instance.popover.hidden = true;
    instance.wrapper.classList.remove('is-open');
    instance.trigger.setAttribute('aria-expanded', 'false');
    if (activeCustomSelect === instance) activeCustomSelect = null;
    if (restoreFocus) instance.trigger.focus();
}

function buildCustomSelectMenu(instance) {
    const { select, popover } = instance;
    popover.replaceChildren();
    const available = Array.from(select.options).filter((option) => !option.hidden);
    const searchableCount = available.filter((option) => option.value !== '' && !option.disabled).length;
    const searchable = select.dataset.searchable === 'true' || (select.dataset.searchable !== 'false' && searchableCount >= 8);

    instance.search = null;
    if (searchable) {
        const searchWrap = document.createElement('div');
        searchWrap.className = 'custom-select-search-wrap';
        searchWrap.innerHTML = '<i class="ri-search-line" aria-hidden="true"></i>';
        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'custom-select-search';
        search.placeholder = select.dataset.searchPlaceholder || 'Search options';
        search.setAttribute('aria-label', 'Search options');
        searchWrap.appendChild(search);
        popover.appendChild(searchWrap);
        instance.search = search;
        search.addEventListener('input', () => filterCustomSelect(instance, search.value));
        search.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                focusCustomSelectOption(instance, 1);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closeCustomSelect(instance, true);
            }
        });
    }

    const list = document.createElement('div');
    list.className = 'custom-select-list';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', select.getAttribute('aria-label') || select.name || 'Options');
    instance.list = list;

    let lastGroup = null;
    available.forEach((option) => {
        const group = option.parentElement instanceof HTMLOptGroupElement ? option.parentElement.label : '';
        if (group && group !== lastGroup) {
            const heading = document.createElement('div');
            heading.className = 'custom-select-group';
            heading.textContent = group;
            list.appendChild(heading);
            lastGroup = group;
        }

        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'custom-select-option';
        item.dataset.value = option.value;
        item.dataset.searchText = option.textContent.toLowerCase();
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
        item.disabled = option.disabled;
        item.innerHTML = `<span>${escapeSelectText(option.textContent.trim())}</span>${option.selected ? '<i class="ri-check-line" aria-hidden="true"></i>' : ''}`;
        if (option.selected) item.classList.add('is-selected');
        item.addEventListener('click', () => {
            if (option.disabled) return;
            select.value = option.value;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            closeCustomSelect(instance, true);
        });
        item.addEventListener('keydown', (event) => handleCustomSelectOptionKeydown(event, instance));
        list.appendChild(item);
    });
    popover.appendChild(list);
}

function filterCustomSelect(instance, query) {
    const needle = query.trim().toLowerCase();
    let visibleCount = 0;
    instance.list.querySelectorAll('.custom-select-option').forEach((item) => {
        const visible = !needle || item.dataset.searchText.includes(needle);
        item.hidden = !visible;
        if (visible) visibleCount += 1;
    });
    instance.list.querySelectorAll('.custom-select-group').forEach((group) => {
        let next = group.nextElementSibling;
        let visible = false;
        while (next && !next.classList.contains('custom-select-group')) {
            if (next.classList.contains('custom-select-option') && !next.hidden) visible = true;
            next = next.nextElementSibling;
        }
        group.hidden = !visible;
    });
    let empty = instance.list.querySelector('.custom-select-empty');
    if (!visibleCount && !empty) {
        empty = document.createElement('div');
        empty.className = 'custom-select-empty';
        empty.textContent = 'No matching options';
        instance.list.appendChild(empty);
    } else if (visibleCount && empty) {
        empty.remove();
    }
}

function handleCustomSelectOptionKeydown(event, instance) {
    if (event.key === 'Escape') {
        event.preventDefault();
        closeCustomSelect(instance, true);
        return;
    }
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const options = visibleCustomSelectOptions(instance);
    let index = options.indexOf(document.activeElement);
    if (event.key === 'Home') index = 0;
    else if (event.key === 'End') index = options.length - 1;
    else index = (index + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
    options[index]?.focus();
}

function visibleCustomSelectOptions(instance) {
    return Array.from(instance.list?.querySelectorAll('.custom-select-option:not([hidden]):not(:disabled)') || []);
}

function focusCustomSelectOption(instance, direction = 1) {
    const options = visibleCustomSelectOptions(instance);
    const selectedIndex = options.findIndex((item) => item.classList.contains('is-selected'));
    const index = selectedIndex >= 0 ? selectedIndex : (direction < 0 ? options.length - 1 : 0);
    options[index]?.focus();
}

function positionCustomSelect(instance) {
    if (instance.popover.hidden) return;
    const positioned = positionFloatingPopover(instance.trigger, instance.popover, {
        minWidth: 220,
        maxWidth: 620,
        minHeight: 160,
        maxHeight: 420,
    });
    if (!positioned) closeCustomSelect(instance);
}

function escapeSelectText(value) {
    const span = document.createElement('span');
    span.textContent = value;
    return span.innerHTML;
}

document.addEventListener('pointerdown', (event) => {
    if (!activeCustomSelect) return;
    if (activeCustomSelect.wrapper.contains(event.target) || activeCustomSelect.popover.contains(event.target)) return;
    closeCustomSelect(activeCustomSelect);
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && activeCustomSelect) closeCustomSelect(activeCustomSelect, true);
});
window.addEventListener('resize', () => activeCustomSelect && positionCustomSelect(activeCustomSelect));
window.addEventListener('scroll', () => activeCustomSelect && positionCustomSelect(activeCustomSelect), true);
window.visualViewport?.addEventListener('resize', () => activeCustomSelect && positionCustomSelect(activeCustomSelect));
window.visualViewport?.addEventListener('scroll', () => activeCustomSelect && positionCustomSelect(activeCustomSelect));

window.enhanceSelects = enhanceSelects;
window.enhanceSearchableSelects = enhanceSelects;
window.refreshCustomSelect = refreshCustomSelect;
window.initExclusiveChoices = initExclusiveChoices;
window.setConditionalControls = setConditionalControls;

// Modal helpers
function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    if (activeCustomSelect) closeCustomSelect(activeCustomSelect);
    modal.classList.add('active');
    document.body.classList.add('modal-open');
    enhanceSelects(modal);
    modal.querySelectorAll('select').forEach((select) => refreshCustomSelect(select));
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    if (activeCustomSelect && modal.contains(activeCustomSelect.wrapper)) closeCustomSelect(activeCustomSelect);
    modal.classList.remove('active');
    if (!document.querySelector('.modal-overlay.active')) document.body.classList.remove('modal-open');
}

// Toast notification
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type} app-toast`;
    const icon = document.createElement('i');
    icon.className = `ri-${type === 'success' ? 'check' : 'error-warning'}-line`;
    const copy = document.createElement('span');
    copy.textContent = message;
    toast.append(icon, copy);
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

let floatingTooltip = null;
let floatingTooltipTarget = null;

function initFloatingTooltips() {
    document.addEventListener('pointerover', (event) => {
        const target = event.target.closest('.narration-tooltip[data-full-text]');
        if (target) showFloatingTooltip(target);
    });
    document.addEventListener('pointerout', (event) => {
        if (!floatingTooltipTarget || event.target.closest('.narration-tooltip') !== floatingTooltipTarget) return;
        if (floatingTooltipTarget.contains(event.relatedTarget)) return;
        hideFloatingTooltip();
    });
    document.addEventListener('focusin', (event) => {
        const target = event.target.closest('.narration-tooltip[data-full-text]');
        if (target) showFloatingTooltip(target);
    });
    document.addEventListener('focusout', (event) => {
        if (event.target.closest('.narration-tooltip') === floatingTooltipTarget) hideFloatingTooltip();
    });
    window.addEventListener('resize', repositionFloatingTooltip);
    window.addEventListener('scroll', repositionFloatingTooltip, true);
    window.visualViewport?.addEventListener('resize', repositionFloatingTooltip);
    window.visualViewport?.addEventListener('scroll', repositionFloatingTooltip);
}

function showFloatingTooltip(target) {
    const text = (target.dataset.fullText || '').trim();
    if (!text) return;
    if (!floatingTooltip) {
        floatingTooltip = document.createElement('div');
        floatingTooltip.className = 'floating-tooltip';
        floatingTooltip.setAttribute('role', 'tooltip');
        floatingTooltip.hidden = true;
        document.body.appendChild(floatingTooltip);
    }
    floatingTooltipTarget = target;
    floatingTooltip.textContent = text;
    floatingTooltip.hidden = false;
    target.setAttribute('aria-describedby', 'active-floating-tooltip');
    floatingTooltip.id = 'active-floating-tooltip';
    repositionFloatingTooltip();
}

function repositionFloatingTooltip() {
    if (!floatingTooltipTarget || !floatingTooltip || floatingTooltip.hidden) return;
    const viewport = getVisibleViewport();
    const positioned = positionFloatingPopover(floatingTooltipTarget, floatingTooltip, {
        width: Math.min(360, viewport.width - 20),
        minWidth: Math.min(220, viewport.width - 20),
        maxWidth: 360,
        minHeight: 48,
        maxHeight: 240,
        gap: 8,
    });
    if (!positioned) hideFloatingTooltip();
}

function hideFloatingTooltip() {
    if (floatingTooltipTarget) floatingTooltipTarget.removeAttribute('aria-describedby');
    if (floatingTooltip) floatingTooltip.hidden = true;
    floatingTooltipTarget = null;
}

// Format number as Indian currency
function formatINR(num) {
    num = parseFloat(num);
    if (isNaN(num)) return '₹0';
    const sign = num < 0 ? '-' : '';
    num = Math.round(Math.abs(num));
    let whole = num.toString();
    if (whole.length > 3) {
        const last3 = whole.slice(-3);
        const rest = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
        whole = rest + ',' + last3;
    }
    return sign + '₹' + whole;
}

// Print page
function printPage() {
    window.print();
}

// Dynamically initialize breadcrumb trails
function initBreadcrumbs() {
    const pageHeader = document.querySelector('.page-header');
    if (!pageHeader) return;

    if (pageHeader.querySelector('.breadcrumb-trail')) return;

    const h1 = pageHeader.querySelector('h1');
    if (!h1) return;

    const path = window.location.pathname;
    const parts = path.split('/');
    const filename = parts.pop();
    const folder = parts.pop();

    let parentName = '';
    let parentUrl = '#';

    const folderMap = {
        'cars': 'Cars',
        'partners': 'Partners',
        'employees': 'Employees',
        'parties': 'Debtors & Creditors',
        'reports': 'Reports',
        'settings': 'Settings',
        'transactions': 'Transactions'
    };

    const isMainList = filename === 'list.php' || filename === 'index.php' || filename === '';

    if (folderMap[folder] && !isMainList) {
        parentName = folderMap[folder];
        parentUrl = 'list.php';
        if (folder === 'settings' || folder === 'reports') {
            parentUrl = '#';
        }
    }

    const currentTitle = h1.textContent.trim();
    const currentIconClass = h1.querySelector('i')?.className || '';

    const breadcrumb = document.createElement('div');
    breadcrumb.className = 'breadcrumb-trail';

    const homeLink = document.createElement('a');
    homeLink.href = '../dashboard.php';
    homeLink.innerHTML = '<i class="ri-home-4-line"></i> Home';
    breadcrumb.appendChild(homeLink);

    if (parentName) {
        const sep1 = document.createElement('span');
        sep1.className = 'breadcrumb-separator';
        sep1.textContent = '/';
        breadcrumb.appendChild(sep1);

        const parentLink = document.createElement('a');
        parentLink.href = parentUrl;
        parentLink.textContent = parentName;
        if (parentUrl === '#') {
            parentLink.style.pointerEvents = 'none';
            parentLink.style.color = 'var(--text-muted)';
        }
        breadcrumb.appendChild(parentLink);
    }

    const sep2 = document.createElement('span');
    sep2.className = 'breadcrumb-separator';
    sep2.textContent = '/';
    breadcrumb.appendChild(sep2);

    const currentSpan = document.createElement('span');
    currentSpan.className = 'breadcrumb-current';
    if (currentIconClass) {
        currentSpan.innerHTML = `<i class="${currentIconClass}"></i> ${currentTitle}`;
    } else {
        currentSpan.textContent = currentTitle;
    }
    breadcrumb.appendChild(currentSpan);

    pageHeader.insertBefore(breadcrumb, pageHeader.firstChild);
}

window.initBreadcrumbs = initBreadcrumbs;
