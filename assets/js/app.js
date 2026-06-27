// AutoBooks Pro — Frontend JavaScript
document.documentElement.classList.add('js');

document.addEventListener('DOMContentLoaded', function() {
    restoreSidebarState();
    initResponsiveSidebar();
    initCurrencyInputs();
    initRegistrationInputs();
    initTableShells();
    syncViewportTableHeights();
    initScrollMemory();
    enhanceSearchableSelects();
    initLazyTables();
    initSmartBackLinks();
    initBreadcrumbs();

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
            document.querySelectorAll('.txn-section').forEach(s => s.style.display = 'none');
            
            // Show relevant sections
            const sectionMap = {
                'CAR_PURCHASE': ['car-section', 'partner-funding-section'],
                'CAR_SALE': ['car-select-section', 'buyer-section'],
                'CAR_EXPENSE': ['car-select-section', 'category-section'],
                'RTO_EXPENSE': ['rto-section'],
                'RTO_RECOVERY': ['rto-section'],
                'GENERAL_EXPENSE': ['category-section'],
                'JOURNAL_VOUCHER': ['split-bill-section'],
                'PARTNER_INVEST': ['partner-section'],
                'PARTNER_WITHDRAW': ['partner-section'],
                'SALARY_PAYMENT': ['employee-section', 'salary-section'],
                'EMPLOYEE_ADVANCE': ['employee-section'],
                'LOAN_GIVEN': ['party-name-section'],
                'LOAN_RECEIVED': ['party-select-section'],
                'LOAN_TAKEN': ['party-name-section'],
                'LOAN_REPAID': ['party-select-section'],
                'CONTRA_TRANSFER': ['contra-section'],
            };

            const sections = sectionMap[type] || [];
            sections.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'block';
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
                const inTypes = ['PARTNER_INVEST', 'LOAN_TAKEN', 'LOAN_RECEIVED'];
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
            txnTypeSelect.dispatchEvent(new Event('change'));
        }
    }

    window.addEventListener('resize', syncViewportTableHeights, { passive: true });
    window.addEventListener('orientationchange', function() {
        setTimeout(syncViewportTableHeights, 120);
    });
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
        if (table.closest('.table-container')) return;
        if (!table.querySelector('thead')) return;
        if (table.dataset.staticTable === '1') return;

        const wrapper = document.createElement('div');
        wrapper.className = 'table-container table-container-inline';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    });
}

function syncViewportTableHeights() {
    const viewportHeight = window.innerHeight;
    document.querySelectorAll('.table-container-fill').forEach((container) => {
        const rect = container.getBoundingClientRect();
        const bottomOffset = window.innerWidth <= 768 ? 12 : 18;
        const targetHeight = Math.max(260, Math.floor(viewportHeight - rect.top - bottomOffset));
        container.style.height = `${targetHeight}px`;
    });
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
            windowY: Math.max(window.scrollY, 0),
            windowX: Math.max(window.scrollX, 0),
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
            if (savedState && typeof savedState.windowY === 'number') {
                window.scrollTo(savedState.windowX || 0, savedState.windowY || 0);
            }
        });
    });
}

function getTrackedScrollContainers() {
    return Array.from(document.querySelectorAll('.table-container, [data-scroll-memory]'))
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

function enhanceSearchableSelects(scope = document) {
    scope.querySelectorAll('select.searchable-select:not([data-search-enhanced])').forEach(select => {
        select.dataset.searchEnhanced = '1';
        const wrapper = document.createElement('div');
        wrapper.className = 'searchable-select-wrap';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);

        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'select-search-input';
        search.placeholder = select.dataset.searchPlaceholder || 'Search...';
        search.setAttribute('aria-label', 'Search options');
        wrapper.insertBefore(search, select);

        const allOptions = Array.from(select.querySelectorAll('option')).map(option => ({
            option,
            parent: option.parentElement,
            text: option.textContent.toLowerCase(),
        }));
        const groups = Array.from(select.querySelectorAll('optgroup'));

        search.addEventListener('input', () => {
            const query = search.value.trim().toLowerCase();
            allOptions.forEach(({ option, text }) => {
                option.hidden = !!query && !text.includes(query);
            });
            groups.forEach(group => {
                const visibleOptions = Array.from(group.querySelectorAll('option')).some(option => !option.hidden);
                group.hidden = !!query && !visibleOptions;
            });
        });

        select.addEventListener('change', () => {
            search.value = '';
            search.dispatchEvent(new Event('input'));
        });
    });
}
window.enhanceSearchableSelects = enhanceSearchableSelects;

// Modal helpers
function openModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Toast notification
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.style.position = 'fixed';
    toast.style.top = '80px';
    toast.style.right = '20px';
    toast.style.zIndex = '9999';
    toast.style.minWidth = '300px';
    toast.innerHTML = `<i class="ri-${type === 'success' ? 'check' : 'error-warning'}-line"></i> ${message}`;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
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
