// AutoBooks Pro — Frontend JavaScript

document.addEventListener('DOMContentLoaded', function() {
    enhanceSearchableSelects();

    // Auto-dismiss alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.3s, transform 0.3s';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });

    // Confirm dialogs
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    // Amount input formatting
    document.querySelectorAll('.amount-input').forEach(input => {
        input.addEventListener('blur', function() {
            let val = parseFloat(this.value.replace(/[^0-9.]/g, ''));
            if (!isNaN(val)) {
                this.value = val.toFixed(2);
            }
        });
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
                'GENERAL_EXPENSE': ['category-section'],
                'JOURNAL_VOUCHER': ['split-bill-section'],
                'PARTNER_INVEST': ['partner-section'],
                'PARTNER_WITHDRAW': ['partner-section'],
                'PARTNER_SETTLEMENT': ['partner-section', 'partner-settlement-section'],
                'SALARY_PAYMENT': ['employee-section', 'salary-section'],
                'EMPLOYEE_ADVANCE': ['employee-section'],
                'LOAN_GIVEN': ['party-name-section'],
                'LOAN_RECEIVED': ['party-select-section'],
                'LOAN_TAKEN': ['party-name-section'],
                'LOAN_REPAID': ['party-select-section'],
                'CONTRA_TRANSFER': ['contra-section'],
                'GST_PAYMENT': [],
            };

            const sections = sectionMap[type] || [];
            sections.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'block';
            });

            // Update payment account label
            const pLabel = document.getElementById('payment-account-label');
            if (pLabel) {
                const inTypes = ['PARTNER_INVEST', 'LOAN_TAKEN', 'LOAN_RECEIVED'];
                if (type === 'JOURNAL_VOUCHER') {
                    pLabel.textContent = 'Cash / Bank / GST account';
                } else {
                    pLabel.textContent = inTypes.includes(type) ? 'Receiving Account' : 'Payment Account';
                }
            }
        });

        if (txnTypeSelect.dataset.preselectedType) {
            txnTypeSelect.value = txnTypeSelect.dataset.preselectedType;
            txnTypeSelect.dispatchEvent(new Event('change'));
        }
    }
});

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
    if (isNaN(num)) return '₹0.00';
    const sign = num < 0 ? '-' : '';
    num = Math.abs(num);
    const dec = num.toFixed(2).split('.')[1];
    let whole = Math.floor(num).toString();
    if (whole.length > 3) {
        const last3 = whole.slice(-3);
        const rest = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
        whole = rest + ',' + last3;
    }
    return sign + '₹' + whole + '.' + dec;
}

// Print page
function printPage() {
    window.print();
}
