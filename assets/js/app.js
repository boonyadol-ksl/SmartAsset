// ─── Toast Notifications ─────────────────────────────────────────────────────
function showToast(message, type = 'success', duration = 3500) {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }

    const icons = {
        success: '<i class="fas fa-check-circle"></i>',
        error:   '<i class="fas fa-times-circle"></i>',
        info:    '<i class="fas fa-info-circle"></i>',
        warning: '<i class="fas fa-exclamation-circle"></i>',
    };

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `${icons[type] || ''}<span>${message}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'toastOut 0.3s ease forwards';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// ─── Confirm Modal ────────────────────────────────────────────────────────────
function confirmAction(message, onConfirm, title = 'ยืนยันการดำเนินการ') {
    const existing = document.getElementById('globalConfirmModal');
    if (existing) existing.remove();

    const modal = document.createElement('div');
    modal.id = 'globalConfirmModal';
    modal.className = 'modal-backdrop';
    modal.innerHTML = `
        <div class="modal-box p-6 max-w-sm">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-exclamation-triangle text-amber-600"></i>
                </div>
                <div class="flex-1">
                    <h3 class="font-bold text-gray-900 text-base">${title}</h3>
                    <p class="text-gray-500 text-sm mt-1">${message}</p>
                </div>
            </div>
            <div class="flex gap-2 mt-5 justify-end">
                <button onclick="document.getElementById('globalConfirmModal').remove()" class="btn btn-secondary btn-sm">ยกเลิก</button>
                <button id="confirmBtn" class="btn btn-danger btn-sm">ยืนยัน</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    document.getElementById('confirmBtn').onclick = () => {
        modal.remove();
        onConfirm();
    };
}

// ─── Toggle Sidebar ───────────────────────────────────────────────────────────
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.toggle('-translate-x-full');
    overlay.classList.toggle('hidden');
}

// ─── API Helper ───────────────────────────────────────────────────────────────
async function apiPost(url, data = {}) {
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data),
        });
        return await res.json();
    } catch (e) {
        return { success: false, message: 'Network error: ' + e.message };
    }
}

async function apiGet(url) {
    try {
        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        return await res.json();
    } catch (e) {
        return { success: false, message: 'Network error: ' + e.message };
    }
}

// ─── Format Number ────────────────────────────────────────────────────────────
function formatNumber(n) {
    return new Intl.NumberFormat('th-TH').format(n || 0);
}

function formatCurrency(n) {
    return new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);
}

// ─── Export Table to CSV ──────────────────────────────────────────────────────
function exportTableCSV(tableId, filename = 'export.csv') {
    const table = document.getElementById(tableId);
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('tr'));
    const csv = rows.map(row =>
        Array.from(row.querySelectorAll('th,td'))
            .map(cell => `"${cell.textContent.trim().replace(/"/g, '""')}"`)
            .join(',')
    ).join('\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
}

// ─── Print ────────────────────────────────────────────────────────────────────
function printPage() { window.print(); }

// ─── Search debounce ──────────────────────────────────────────────────────────
function debounce(fn, delay = 350) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

