/**
 * Client View JavaScript - Optimized and Modular
 * Handles all client view interactions, API calls, and UI updates
 */

class ClientViewManager {
    constructor() {
        this.apiSingle = '../api/control.php';
        this.apiAutoControl = '/api/auto_control_client.php';
        this.apiLiveStatus = '/api/client_live_status.php';
        this.apiRenew = '/api/renew.php';
        this.apiMacVendor = '/api/mac_vendor.php';
        
        this.liveTimer = null;
        this.clientId = null;
        this.csrfToken = null;
        
        this.init();
    }
    
    init() {
        this.clientId = this.getClientId();
        this.csrfToken = this.getCSRFToken();
        this.setupEventListeners();
        this.startLiveStatusUpdates();
        this.setupRenewModal();
    }
    
    getClientId() {
        const urlParams = new URLSearchParams(window.location.search);
        return parseInt(urlParams.get('id')) || 0;
    }
    
    getCSRFToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }
    
    setupEventListeners() {
        // Copy button handler
        document.addEventListener('click', (e) => this.handleCopyClick(e));
        
        // Status change buttons
        document.addEventListener('click', (e) => this.handleStatusChange(e));
        
        // Auto recheck button
        document.addEventListener('click', (e) => this.handleAutoRecheck(e));
    }
    
    // Toast notifications
    showToast(message, type = 'success', timeout = 2800) {
        const box = document.createElement('div');
        box.className = `app-toast ${type === 'success' ? 'success' : 'error'}`;
        box.textContent = message || 'Done';
        document.body.appendChild(box);
        
        setTimeout(() => box.classList.add('hide'), timeout - 200);
        setTimeout(() => box.remove(), timeout);
    }
    
    // Restore toast after reload
    restoreToast() {
        const t = sessionStorage.getItem('toast');
        if (t) {
            try {
                const o = JSON.parse(t);
                this.showToast(o.message, o.type || 'success', 2800);
            } catch (e) {
                console.error('Error parsing toast data:', e);
            }
            sessionStorage.removeItem('toast');
        }
    }
    
    // Custom confirm dialog
    async customConfirm({title = 'Confirm', message = 'Are you sure?', okText = 'OK', cancelText = 'Cancel'}) {
        return new Promise((resolve) => {
            const bd = document.createElement('div');
            bd.className = 'app-confirm-backdrop';
            bd.innerHTML = `
                <div class="app-confirm-box" role="dialog" aria-modal="true">
                    <div class="app-confirm-title">${title}</div>
                    <div class="app-confirm-text">${message}</div>
                    <div class="app-confirm-actions">
                        <button class="app-btn secondary" data-act="cancel">${cancelText}</button>
                        <button class="app-btn primary" data-act="ok">${okText}</button>
                    </div>
                </div>`;
            
            document.body.appendChild(bd);
            
            const close = (v) => {
                document.removeEventListener('keydown', onKey);
                bd.remove();
                resolve(v);
            };
            
            const onKey = (e) => {
                if (e.key === 'Escape') close(false);
                if (e.key === 'Enter') close(true);
            };
            
            bd.addEventListener('click', e => {
                if (e.target.dataset.act === 'ok') close(true);
                if (e.target.dataset.act === 'cancel' || e.target === bd) close(false);
            });
            
            document.addEventListener('keydown', onKey);
            setTimeout(() => bd.querySelector('[data-act="ok"]')?.focus(), 10);
        });
    }
    
    // Status change handler
    async handleStatusChange(e) {
        const btn = e.target.closest('[onclick*="changeStatus"]');
        if (!btn) return;
        
        e.preventDefault();
        
        const onclick = btn.getAttribute('onclick');
        const matches = onclick.match(/changeStatus\([^,]+,\s*(\d+),\s*['"]([^'"]+)['"]\)/);
        if (!matches) return;
        
        const [, id, action] = matches;
        await this.changeStatus(btn, parseInt(id), action);
    }
    
    // Auto recheck handler
    async handleAutoRecheck(e) {
        const btn = e.target.closest('[onclick*="autoRecheck"]');
        if (!btn) return;
        
        e.preventDefault();
        
        const onclick = btn.getAttribute('onclick');
        const matches = onclick.match(/autoRecheck\([^,]+,\s*(\d+)\)/);
        if (!matches) return;
        
        const [, id] = matches;
        await this.autoRecheck(btn, parseInt(id));
    }
    
    // Change client status
    async changeStatus(btn, id, action) {
        const ok = await this.customConfirm({
            title: (action === 'disable') ? 'Disable client?' : (action === 'kick' ? 'Disconnect client?' : 'Enable client?'),
            message: `Are you sure you want to ${action} this client?`,
            okText: (action === 'disable') ? 'Disable' : 'Yes',
            cancelText: 'Cancel'
        });
        
        if (!ok) return;
        
        const oldHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span>';
        btn.classList.add('loading');
        
        try {
            const response = await fetch(`${this.apiSingle}?action=${encodeURIComponent(action)}&id=${encodeURIComponent(id)}&csrf_token=${this.csrfToken}`);
            const data = await response.json();
            
            if (data.status === 'success') {
                const msg = data.message || 'Done';
                sessionStorage.setItem('toast', JSON.stringify({message: msg, type: 'success'}));
                location.reload();
            } else {
                this.showToast(data.message || 'Operation failed', 'error', 3000);
                btn.disabled = false;
                btn.innerHTML = oldHTML;
                btn.classList.remove('loading');
            }
        } catch (error) {
            this.showToast('Request failed', 'error', 3000);
            btn.disabled = false;
            btn.innerHTML = oldHTML;
            btn.classList.remove('loading');
        }
    }
    
    // Auto recheck
    async autoRecheck(btn, id) {
        const ok = await this.customConfirm({
            title: 'Auto re-evaluate?',
            message: 'Run auto control now based on current ledger balance.',
            okText: 'Run now',
            cancelText: 'Cancel'
        });
        
        if (!ok) return;
        
        const old = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span>';
        btn.classList.add('loading');
        
        try {
            const response = await fetch(this.apiAutoControl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': this.csrfToken
                },
                body: new URLSearchParams({
                    client_id: String(id),
                    csrf_token: this.csrfToken
                })
            });
            
            const data = await response.json();
            
            if (data.ok) {
                sessionStorage.setItem('toast', JSON.stringify({
                    message: data.msg || ('Action: ' + (data.action || 'done')),
                    type: 'success'
                }));
                location.reload();
            } else {
                this.showToast(data.msg || 'Auto control failed', 'error', 3000);
                btn.disabled = false;
                btn.innerHTML = old;
                btn.classList.remove('loading');
            }
        } catch (error) {
            this.showToast('Request failed', 'error', 3000);
            btn.disabled = false;
            btn.innerHTML = old;
            btn.classList.remove('loading');
        }
    }
    
    // Copy text handler
    async handleCopyClick(e) {
        const btn = e.target.closest('.btn-copy');
        if (!btn) return;
        
        e.preventDefault();
        
        let text = (btn.getAttribute('data-copy') || '').trim();
        if (!text) {
            const sel = btn.getAttribute('data-copy-el');
            if (sel) {
                const el = document.querySelector(sel);
                if (el) text = (el.textContent || '').trim();
            }
        }
        
        try {
            await this.copyTextRobust(text);
            this.showToast('copied', 'success', 1600);
        } catch (err) {
            this.showToast('copy failed', 'error', 1800);
        }
    }
    
    // Robust copy function
    async copyTextRobust(text) {
        text = (text || '').trim();
        if (!text || text === '-' || text === '—') throw new Error('empty');
        
        // Try modern API (https/localhost)
        if (navigator.clipboard && window.isSecureContext !== false) {
            await navigator.clipboard.writeText(text);
            return;
        }
        
        // Fallback for http or blocked clipboard
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, text.length);
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        if (!ok) throw new Error('fallback-failed');
    }
    
    // Live status updates
    async loadLiveStatus() {
        try {
            const response = await fetch(`${this.apiLiveStatus}?id=${this.clientId}`, {cache: 'no-store'});
            const data = await response.json();
            
            this.updateDeviceInfo(data);
            this.updateLiveFields(data);
            this.updateStatusBadges(data);
        } catch (error) {
            console.error('Error loading live status:', error);
        }
    }
    
    updateDeviceInfo(data) {
        // Device Vendor
        const dv = document.getElementById('device-vendor');
        if (dv) {
            dv.textContent = (data.device_vendor && data.device_vendor.trim() !== '') ? data.device_vendor : '—';
        }
        
        // Router/Active MAC
        const rmacEl = document.getElementById('router-mac');
        const amacEl = document.getElementById('active-mac');
        const rBtn = document.getElementById('btn-copy-router');
        const aBtn = document.getElementById('btn-copy-active');
        
        const rmac = (data.router_mac && data.router_mac.trim() !== '') ? data.router_mac : (data.caller_id || '—');
        const amac = (data.active_mac && data.active_mac.trim() !== '') ? data.active_mac : (data.caller_id || '—');
        
        if (rmacEl) rmacEl.textContent = rmac || '—';
        if (amacEl) amacEl.textContent = amac || '—';
        
        if (rBtn) {
            if (rmac && rmac !== '—') {
                rBtn.style.display = '';
                rBtn.setAttribute('data-copy', rmac);
            } else {
                rBtn.style.display = 'none';
                rBtn.setAttribute('data-copy', '');
            }
        }
        
        if (aBtn) {
            if (amac && amac !== '—') {
                aBtn.style.display = '';
                aBtn.setAttribute('data-copy', amac);
            } else {
                aBtn.style.display = 'none';
                aBtn.setAttribute('data-copy', '');
            }
        }
        
        // OUI fallback if vendor missing
        if (dv && (dv.textContent === '—' || dv.textContent === '') && rmac && rmac !== '—') {
            this.fetchMacVendor(rmac);
        }
    }
    
    async fetchMacVendor(mac) {
        try {
            const response = await fetch(`${this.apiMacVendor}?mac=${encodeURIComponent(mac)}&csv=${encodeURIComponent('/assets/mac_vendors.csv')}`, {cache: 'no-store'});
            const data = await response.json();
            if (data && data.vendor) {
                const dv = document.getElementById('device-vendor');
                if (dv) dv.textContent = data.vendor;
            }
        } catch (error) {
            console.error('Error fetching MAC vendor:', error);
        }
    }
    
    updateLiveFields(data) {
        const fields = {
            'live-ip': data.ip ?? '—',
            'uptime': data.uptime ?? '—',
            'last-seen': data.last_seen ?? '—',
            'total-dl': data.total_download_gb != null ? data.total_download_gb + ' GB' : '—',
            'total-ul': data.total_upload_gb != null ? data.total_upload_gb + ' GB' : '—',
            'rx-rate': data.rx_rate || '0 Kbps',
            'tx-rate': data.tx_rate || '0 Kbps'
        };
        
        Object.entries(fields).forEach(([id, value]) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        });
    }
    
    updateStatusBadges(data) {
        const st = document.getElementById('live-status');
        const namePill = document.getElementById('name-online');
        
        if (st) {
            st.textContent = data.online ? 'Online' : 'Offline';
            st.className = 'badge ' + (data.online ? 'bg-success' : 'bg-danger');
        }
        
        if (namePill) {
            namePill.textContent = (data.online ? ' Online' : ' Offline');
            namePill.innerHTML = '<i class="bi bi-wifi"></i>' + namePill.textContent;
            namePill.className = 'badge ' + (data.online ? 'bg-success' : 'bg-secondary');
            if (data.online) {
                namePill.style.backgroundColor = '#198754';
            } else {
                namePill.style.backgroundColor = '#6c757d';
            }
        }
    }
    
    startLiveStatusUpdates() {
        this.loadLiveStatus();
        this.liveTimer = setInterval(() => this.loadLiveStatus(), 10000);
        
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stopLiveStatusUpdates();
            } else {
                this.loadLiveStatus();
                this.startLiveStatusUpdates();
            }
        });
    }
    
    stopLiveStatusUpdates() {
        if (this.liveTimer) {
            clearInterval(this.liveTimer);
            this.liveTimer = null;
        }
    }
    
    // Renew modal setup
    setupRenewModal() {
        const monthsEl = document.getElementById('rn_months');
        const amountEl = document.getElementById('rn_amount');
        const invDateEl = document.getElementById('rn_invoice_date');
        const formEl = document.getElementById('renewForm');
        
        if (!monthsEl || !amountEl || !formEl) return;
        
        const monthlyBill = parseFloat(amountEl.dataset.monthlyBill || '0');
        const expCur = amountEl.dataset.expiryDate || '';
        
        const addMonths = (dateStr, m) => {
            if (!dateStr) return '';
            const d = new Date(dateStr + 'T00:00:00');
            if (isNaN(d)) return '';
            const dd = new Date(d.getTime());
            dd.setMonth(dd.getMonth() + m);
            return `${dd.getFullYear()}-${String(dd.getMonth() + 1).padStart(2, '0')}-${String(dd.getDate()).padStart(2, '0')}`;
        };
        
        const todayYMD = () => {
            const d = new Date();
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        };
        
        const maxDate = (a, b) => {
            if (!a) return b;
            if (!b) return a;
            return (a > b) ? a : b;
        };
        
        document.getElementById('renewModal')?.addEventListener('shown.bs.modal', () => {
            const m = parseInt(monthsEl.value || '1', 10);
            if (!amountEl.dataset.touched) {
                amountEl.value = (monthlyBill * (isNaN(m) ? 1 : m)).toFixed(2);
            }
            const base = maxDate(todayYMD(), expCur);
            document.getElementById('rn_exp_new').textContent = base ? addMonths(base, isNaN(m) ? 1 : m) : '—';
            document.getElementById('rn_exp_current').textContent = expCur || '—';
        });
        
        monthsEl.addEventListener('change', () => {
            const m = parseInt(monthsEl.value || '1', 10);
            if (!amountEl.dataset.touched) {
                amountEl.value = (monthlyBill * (isNaN(m) ? 1 : m)).toFixed(2);
            }
            const base = maxDate(todayYMD(), expCur);
            document.getElementById('rn_exp_new').textContent = base ? addMonths(base, isNaN(m) ? 1 : m) : '—';
        });
        
        amountEl.addEventListener('input', () => {
            amountEl.dataset.touched = '1';
        });
        
        formEl.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const months = parseInt(monthsEl.value || '1', 10);
            const amount = Number(amountEl.value || '0');
            const method = document.getElementById('rn_method').value || 'Cash';
            const note = document.getElementById('rn_note').value || '';
            const invdt = invDateEl.value || todayYMD();
            
            if (isNaN(months) || months <= 0) {
                this.showToast('Invalid months', 'error');
                return;
            }
            if (isNaN(amount) || amount <= 0) {
                this.showToast('Invalid amount', 'error');
                return;
            }
            
            try {
                const response = await fetch(this.apiRenew, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken
                    },
                    body: JSON.stringify({
                        client_id: this.clientId,
                        months,
                        amount,
                        method,
                        note,
                        invoice_date: invdt,
                        csrf_token: this.csrfToken
                    })
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    this.showToast(data.message || 'Renewed & Invoiced', 'success', 2200);
                    const url = data.invoice_id
                        ? `/public/invoice_view.php?id=${encodeURIComponent(data.invoice_id)}`
                        : `/public/invoices.php?client_id=${this.clientId}`;
                    setTimeout(() => window.location.href = url, 700);
                } else {
                    this.showToast(data.message || 'Renew failed', 'error', 3000);
                }
            } catch (err) {
                this.showToast('Request failed', 'error', 3000);
            }
        });
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.clientViewManager = new ClientViewManager();
    window.clientViewManager.restoreToast();
});

