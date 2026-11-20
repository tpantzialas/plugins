(() => {
  'use strict';
  const $ = (s, root = document) => root.querySelector(s);
  const $$ = (s, root = document) => [...root.querySelectorAll(s)];
  // WordPress may serialize numerically keyed PHP arrays as JSON objects.
  // Accept either representation so one unusual term list cannot stop the page.
  const asList = value => Array.isArray(value) ? value : (value && typeof value === 'object' ? Object.values(value) : []);
  let csrf = $('meta[name="csrf-token"]')?.content || '';
  let state = { sites: [], site: null, type: 'product', selected: new Map(), places: [], page: 1, pages: 1, searchTimer: 0, polling: 0, ordersPage: 1, ordersPages: 1, orderSearchTimer: 0, currentOrder: null, activeView: 'social', salesYear: 0, statsDays: 28 };
  const toast = (message, error = false) => { const el = $('#toast'); el.textContent = message; el.classList.toggle('bad', error); el.classList.add('show'); clearTimeout(el._timer); el._timer = setTimeout(() => el.classList.remove('show'), 3500); };
  async function request(path, data = null, method = data ? 'POST' : 'GET') {
    const headers = { Accept: 'application/json' };
    if (data !== null) headers['Content-Type'] = 'application/json';
    if (method !== 'GET' && csrf) headers['X-CSRF-Token'] = csrf;
    const response = await fetch(path, { method, headers, body: data === null ? undefined : JSON.stringify(data), credentials: 'same-origin', cache: 'no-store' });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) throw Object.assign(new Error(json.error || 'Δεν ολοκληρώθηκε η ενέργεια.'), { status: response.status });
    return json;
  }
  function authMode(setup = false) {
    $('#login-form').classList.toggle('hidden', setup);
    $('#setup-form').classList.toggle('hidden', !setup);
    $('#show-setup').classList.toggle('hidden', setup || document.body.dataset.setupAvailable !== '1');
    $('#auth-title').textContent = setup ? 'Δημιουργία λογαριασμού' : 'Σύνδεση στη Danae';
    $('#auth-help').textContent = setup ? 'Αυτή είναι η αρχική ρύθμιση. Ο λογαριασμός σου είναι ιδιωτικός.' : 'Χρησιμοποίησε τον λογαριασμό Danae Mobile.';
  }
  function showWorkspace() {
    $('#auth').classList.add('hidden'); $('#workspace').classList.remove('hidden'); $('#logout').classList.remove('hidden');
    if (!state.sites.length) { $('#shop-empty').classList.remove('hidden'); $('#shop-content').classList.add('hidden'); return; }
    $('#shop-empty').classList.add('hidden'); $('#shop-content').classList.remove('hidden');
    const select = $('#shop-select'); select.replaceChildren();
    state.sites.forEach(site => { const option = new Option(site.site_name, site.id); select.add(option); });
    select.classList.toggle('hidden', state.sites.length < 2);
    state.site = state.sites.find(s => Number(s.id) === Number(select.value)) || state.sites[0];
    select.value = state.site.id; syncOrderShopSelect(); paintSite(); loadShop();
    openDeepLink();
  }
  function paintSite() { if (!state.site) return; $('#shop-name').textContent = state.site.site_name; const link = $('#shop-domain'); link.href = state.site.site_url; link.textContent = new URL(state.site.site_url).host; }
  function syncOrderShopSelect() {
    ['orders', 'sales', 'stats'].forEach(prefix => {
      const select = $(`#${prefix}-shop-select`); select.replaceChildren();
      state.sites.forEach(site => select.add(new Option(site.site_name, site.id)));
      select.classList.toggle('hidden', state.sites.length < 2);
      if (state.site) select.value = String(state.site.id);
      if (state.site) { $(`#${prefix}-shop-name`).textContent = state.site.site_name; const link = $(`#${prefix}-shop-domain`); link.href = state.site.site_url; link.textContent = new URL(state.site.site_url).host; }
    });
  }
  function setView(view) {
    state.activeView = ['social', 'orders', 'sales', 'stats'].includes(view) ? view : 'social';
    ['social', 'orders', 'sales', 'stats'].forEach(name => { $(`#${name}-panel`).classList.toggle('hidden', state.activeView !== name); $(`#tab-${name}`).classList.toggle('active', state.activeView === name); });
    if (!state.site) return;
    if (view === 'orders') loadOrders(true);
    if (view === 'sales') loadSales(state.salesYear || new Date().getFullYear());
    if (view === 'stats') loadSocialStats();
  }
  function openDeepLink() {
    const params = new URLSearchParams(location.search);
    if (params.get('view') !== 'orders') return;
    const siteId = Number(params.get('site_id') || 0), orderId = Number(params.get('order_id') || 0);
    const target = state.sites.find(site => Number(site.id) === siteId);
    if (target) { state.site = target; $('#shop-select').value = String(target.id); syncOrderShopSelect(); paintSite(); }
    setView('orders');
    if (orderId && target) openOrder(orderId);
    history.replaceState({}, '', location.pathname);
  }
  async function refreshMe() {
    try { const me = await request('/api/me', null, 'GET'); csrf = me.csrf || csrf; state.sites = me.sites || []; $('#connection-state').textContent = 'Συνδεδεμένη συνεδρία'; showWorkspace(); updatePushUI(); }
    catch { $('#auth').classList.remove('hidden'); $('#workspace').classList.add('hidden'); $('#logout').classList.add('hidden'); authMode(false); }
  }
  function pushSupported() { return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window; }
  function isInstalledApp() { return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true; }
  function appKeyBytes(value) { const raw = atob(value.replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(raw, char => char.charCodeAt(0)); }
  async function updatePushUI() {
    const button = $('#push-toggle'); if (!button) return;
    if (!pushSupported()) { button.disabled = true; $('#push-status').textContent = 'Η συσκευή ή το πρόγραμμα περιήγησης δεν υποστηρίζει ειδοποιήσεις Web Push.'; return; }
    if (/iPhone|iPad|iPod/.test(navigator.userAgent) && !isInstalledApp()) { $('#push-status').textContent = 'Στο iPhone, πρόσθεσε πρώτα τη Danae στην οθόνη Αφετηρίας και άνοιξέ την από το εικονίδιο για να ενεργοποιήσεις ειδοποιήσεις.'; }
    if (Notification.permission === 'denied') { button.disabled = true; button.textContent = 'Ειδοποιήσεις αποκλείστηκαν'; $('#push-status').textContent = 'Οι ειδοποιήσεις έχουν αποκλειστεί από τις ρυθμίσεις του browser. Επίτρεψέ τες από τις ρυθμίσεις ιστοτόπου και ξαναφόρτωσε τη Danae.'; return; }
    try { const registration = await navigator.serviceWorker.ready; const subscription = await registration.pushManager.getSubscription(); button.disabled = false; button.textContent = subscription ? 'Απενεργοποίηση ειδοποιήσεων' : 'Ενεργοποίηση ειδοποιήσεων'; if (subscription) $('#push-status').textContent = 'Οι ειδοποιήσεις είναι ενεργές σε αυτή τη συσκευή. Θα ειδοποιείσαι μόλις υποβληθεί νέα παραγγελία.'; }
    catch { button.disabled = true; $('#push-status').textContent = 'Δεν ήταν δυνατή η προετοιμασία ειδοποιήσεων. Άνοιξε τη Danae με HTTPS και ξαναδοκίμασε.'; }
  }
  async function togglePush() {
    const button = $('#push-toggle'); button.disabled = true;
    try {
      if (!pushSupported()) throw new Error('Αυτή η συσκευή δεν υποστηρίζει ειδοποιήσεις Web Push.');
      const registration = await navigator.serviceWorker.ready; let subscription = await registration.pushManager.getSubscription();
      if (subscription) { await request('/api/push/unsubscribe', { endpoint: subscription.endpoint }); await subscription.unsubscribe(); toast('Οι ειδοποιήσεις απενεργοποιήθηκαν σε αυτή τη συσκευή.'); }
      else {
        if (/iPhone|iPad|iPod/.test(navigator.userAgent) && !isInstalledApp()) throw new Error('Στο iPhone, πρόσθεσε πρώτα τη Danae στην οθόνη Αφετηρίας και άνοιξέ την από εκεί.');
        const permission = await Notification.requestPermission(); if (permission !== 'granted') throw new Error('Δεν δόθηκε άδεια για ειδοποιήσεις.');
        const key = await request('/api/push/key', null, 'GET'); subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: appKeyBytes(key.public_key) });
        await request('/api/push/subscribe', { subscription: subscription.toJSON() }); toast('Οι ειδοποιήσεις πωλήσεων ενεργοποιήθηκαν.');
      }
    } catch (error) { toast(error.message || 'Δεν ενεργοποιήθηκαν οι ειδοποιήσεις.', true); }
    finally { await updatePushUI(); }
  }
  async function loadShop() {
    if (!state.site) return;
    state.selected.clear(); updateSelected(); $('#items').innerHTML = '<div class="loading">Φόρτωση περιεχομένου…</div>'; $('#places').innerHTML = '<div class="loading">Φόρτωση καναλιών…</div>';
    try {
      const [placesResult, categoriesResult] = await Promise.allSettled([request(`/api/social/${state.site.id}/places`), loadCategories()]);
      state.places = placesResult.status === 'fulfilled' ? asList(placesResult.value.places) : []; $('#places').innerHTML = '';
      state.places.forEach(place => { const label = document.createElement('label'); label.className = 'place-option'; const input = document.createElement('input'); input.type = 'checkbox'; input.value = place.key; input.addEventListener('change', updateSelected); const span = document.createElement('span'); span.innerHTML = `<b>${escapeHtml(place.label)}</b><small>${escapeHtml(place.source)} · ${escapeHtml(place.medium)} · ${escapeHtml(place.content)}</small>`; label.append(input, span); $('#places').append(label); });
      if (placesResult.status === 'rejected') $('#places').innerHTML = `<div class="error-card">${escapeHtml(placesResult.reason.message)}</div>`;
      if (categoriesResult.status === 'rejected') toast(categoriesResult.reason.message, true);
      await loadItems(true);
    } catch (error) { if (error.status === 401) { await refreshMe(); return; } $('#items').innerHTML = `<div class="error-card">${escapeHtml(error.message)}</div>`; $('#places').innerHTML = ''; }
  }
  async function loadCategories() {
    const sel = $('#category-filter'); sel.replaceChildren(new Option('Όλες οι κατηγορίες', '0'));
    const data = await request(`/api/social/${state.site.id}/categories?type=${encodeURIComponent(state.type)}`, null, 'GET');
    const cats = asList(data.categories); sel.classList.toggle('hidden', !cats.length);
    cats.forEach(cat => sel.add(new Option(`${cat.name} (${cat.count})`, cat.id)));
  }
  async function loadItems(reset = false) {
    if (!state.site) return;
    if (reset) { state.page = 1; $('#items').innerHTML = '<div class="loading">Αναζήτηση στο e-shop…</div>'; }
    const params = new URLSearchParams({ type: state.type, page: String(state.page), per_page: '20', search: $('#item-search').value.trim(), category_id: $('#category-filter').value || '0' });
    try {
      const result = await request(`/api/social/${state.site.id}/items?${params}`, null, 'GET'); state.pages = Number(result.pages) || 1;
      if (reset) $('#items').replaceChildren();
      const items = asList(result.items);
      const wrongType = items.filter(item => item?.type && item.type !== state.type);
      if (wrongType.length) {
        $('#items').innerHTML = `<div class="error-card">Το WordPress επέστρεψε ${escapeHtml(wrongType[0].type_label || wrongType[0].type)} ενώ έχεις επιλέξει ${state.type === 'product' ? 'Προϊόντα' : state.type === 'page' ? 'Σελίδες' : 'Άρθρα'}. Έλεγξε την έκδοση της γέφυρας Smart SEO D.A.N.A.E. ή ανανέωσε τη σύνδεση.</div>`;
        $('#load-more').classList.add('hidden'); return;
      }
      items.forEach(item => $('#items').append(renderItem(item)));
      if (!items.length && reset) $('#items').innerHTML = '<div class="empty-list">Δεν βρέθηκαν δημοσιευμένα στοιχεία με αυτά τα φίλτρα.</div>';
      $('#load-more').classList.toggle('hidden', state.page >= state.pages || state.pages < 2);
    } catch (error) { $('#items').innerHTML = `<div class="error-card">${escapeHtml(error.message)}</div>`; }
  }
  function renderItem(item) {
    const card = document.createElement('label'); card.className = 'item-card'; const check = document.createElement('input'); check.type = 'checkbox'; check.value = item.id; check.checked = state.selected.has(Number(item.id));
    check.addEventListener('change', () => { if (check.checked) state.selected.set(Number(item.id), item); else state.selected.delete(Number(item.id)); card.classList.toggle('chosen', check.checked); updateSelected(); });
    const image = document.createElement('span'); image.className = 'item-thumb'; if (item.thumbnail_url) { const img = document.createElement('img'); img.src = item.thumbnail_url; img.alt = ''; img.loading = 'lazy'; image.append(img); } else image.textContent = (item.type_label || item.type || 'D').slice(0,1).toUpperCase();
    const info = document.createElement('span'); info.className = 'item-info'; const title = document.createElement('b'); title.textContent = item.title; const meta = document.createElement('small'); meta.textContent = [item.type_label, item.sku ? `SKU ${item.sku}` : '', item.category || ''].filter(Boolean).join(' · '); info.append(title, meta);
    const preview = document.createElement('a'); preview.className = 'item-open'; preview.href = item.url; preview.target = '_blank'; preview.rel = 'noopener'; preview.textContent = '↗'; preview.setAttribute('aria-label', 'Άνοιγμα σε νέα καρτέλα'); preview.addEventListener('click', e => e.stopPropagation());
    card.append(check, image, info, preview); if (check.checked) card.classList.add('chosen'); return card;
  }
  function updateSelected() { const n = state.selected.size; const p = $$('#places input:checked').length; const combinations = n * p; $('#selected-count').textContent = `${n} επιλεγμένα · ${combinations} σύνδεσμοι`; $('#generate').disabled = !n || !p || combinations > 200; if (combinations > 200) toast('Για έως 200 συνδέσμους, επέλεξε λιγότερα προϊόντα ή κανάλια.', true); }

  const orderStatusLabels = { pending: 'Σε αναμονή πληρωμής', processing: 'Σε επεξεργασία', 'on-hold': 'Σε αναμονή', completed: 'Ολοκληρωμένη', cancelled: 'Ακυρωμένη', refunded: 'Επιστράφηκε', failed: 'Απέτυχε', 'checkout-draft': 'Πρόχειρη ολοκλήρωση' };
  const orderFieldLabels = { first_name: 'Όνομα', last_name: 'Επώνυμο', company: 'Εταιρεία', address_1: 'Διεύθυνση', address_2: 'Διεύθυνση 2', city: 'Πόλη', state: 'Περιφέρεια', postcode: 'Ταχυδρομικός κώδικας', country: 'Χώρα', email: 'Email', phone: 'Τηλέφωνο' };
  async function loadOrders(reset = true) {
    if (!state.site) return;
    if (reset) { state.ordersPage = 1; $('#orders-list').innerHTML = '<div class="loading">Φόρτωση παραγγελιών…</div>'; }
    const params = new URLSearchParams({ page: String(state.ordersPage), per_page: '20', status: $('#order-status-filter').value || 'all', search: $('#order-search').value.trim() });
    try {
      const data = await request(`/api/orders/${state.site.id}?${params}`, null, 'GET');
      state.ordersPages = Number(data.pages) || 1; if (reset) $('#orders-list').replaceChildren();
      const statuses = data.statuses && typeof data.statuses === 'object' ? data.statuses : {};
      const statusSelect = $('#order-status-filter'); const keep = statusSelect.value || 'all';
      if (!statusSelect.dataset.loaded) { Object.entries(statuses).forEach(([key, label]) => { const value = key.replace(/^wc-/, ''); statusSelect.add(new Option(label, value)); }); statusSelect.value = keep; statusSelect.dataset.loaded = '1'; }
      const rows = asList(data.orders); rows.forEach(order => $('#orders-list').append(renderOrder(order)));
      if (!rows.length && reset) $('#orders-list').innerHTML = '<div class="empty-list">Δεν βρέθηκαν παραγγελίες.</div>';
      $('#orders-more').classList.toggle('hidden', state.ordersPage >= state.ordersPages || state.ordersPages < 2);
    } catch (error) { $('#orders-list').innerHTML = `<div class="error-card">${escapeHtml(error.message)}</div>`; }
  }
  const money = (value, currency = 'EUR') => { try { return new Intl.NumberFormat('el-GR', { style: 'currency', currency: currency || 'EUR' }).format(Number(value || 0)); } catch { return `${Number(value || 0).toFixed(2)} ${currency}`; } };
  async function loadSales(year = 0) {
    if (!state.site) return;
    $('#sales-months').innerHTML = '<div class="loading">Φόρτωση πωλήσεων…</div>';
    try {
      let data = await request(`/api/sales/${state.site.id}`, null, 'GET');
      const years = asList(data.years).map(Number);
      const select = $('#sales-year'); const selected = Number(year || state.salesYear || new Date().getFullYear());
      if (!select.dataset.loaded) { select.replaceChildren(...years.map(y => new Option(String(y), String(y)))); if (!years.includes(selected) && years.length) state.salesYear = years[0]; else state.salesYear = selected; select.value = String(state.salesYear || years[0] || new Date().getFullYear()); select.dataset.loaded = '1'; }
      else if (year) { state.salesYear = Number(year); select.value = String(year); }
      const chosen = Number(select.value || state.salesYear || new Date().getFullYear()); state.salesYear = chosen;
      if (data.year !== chosen) data = await request(`/api/sales/${state.site.id}?year=${chosen}`, null, 'GET');
      const months = asList(data.months).filter(row => Number(row.year) === chosen);
      const hasTotals = data.totals && typeof data.totals === 'object';
      const totals = hasTotals ? data.totals : months.reduce((sum, row) => { sum.paid_revenue += Number(row.paid_revenue || 0); sum.order_value += Number(row.order_value || 0); sum.orders += Number(row.orders || 0); return sum; }, { paid_revenue: 0, order_value: 0, orders: 0 });
      const paid = Number(totals.paid_revenue || 0);
      const value = Number(totals.order_value || 0);
      const orders = Number(totals.orders || 0);
      const monthNames = ['Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος','Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];
      const currentMonth = months.find(row => Number(row.month) === new Date().getMonth() + 1) || months[0];
      const monthLabel = currentMonth ? monthNames[Number(currentMonth.month)-1] : 'Τρέχων μήνας';
      $('#sales-current').innerHTML = `<article class="metric-card sales-primary"><small>Τζίρος ${monthLabel}</small><b>${money(currentMonth?.paid_revenue || 0, data.currency)}</b></article><article class="metric-card"><small>Επιβεβαιωμένος τζίρος έτους ${chosen}</small><b>${money(paid, data.currency)}</b></article><article class="metric-card"><small>Συνολική αξία παραγγελιών</small><b>${money(value, data.currency)}</b></article><article class="metric-card"><small>Παραγγελίες</small><b>${orders.toLocaleString('el-GR')}</b></article>`;
      $('#sales-months').innerHTML = months.length ? months.map(row => `<article class="sales-row"><b>${monthNames[Number(row.month)-1] || row.month}</b><span>${Number(row.orders || 0)} παραγγελίες · ${money(row.paid_revenue, data.currency)} επιβεβαιωμένος τζίρος</span><small>Αξία παραγγελιών: ${money(row.order_value, data.currency)}</small></article>`).join('') : '<div class="empty-list">Δεν υπάρχουν παραγγελίες για αυτό το έτος.</div>';
    } catch (error) { $('#sales-current').replaceChildren(); $('#sales-months').innerHTML = `<div class="error-card">${escapeHtml(error.message)}</div>`; }
  }
  async function loadSocialStats() {
    if (!state.site) return;
    const days = 28; state.statsDays = days;
    $('#stats-campaigns').innerHTML = '<div class="loading">Φόρτωση Social Stats…</div>'; $('#stats-landings').replaceChildren(); $('#stats-summary').replaceChildren();
    try {
      const data = await request(`/api/social/${state.site.id}/analytics?days=${days}`, null, 'GET');
      if (!data.summary || typeof data.summary !== 'object' || !('visitors_known' in data.summary)) throw new Error('Το Smart SEO D.A.N.A.E. σε αυτό το e-shop είναι παλιότερο από την 6.8.5. Ενημέρωσέ το για να εμφανιστούν σωστά τα Social Stats.');
      const campaigns = asList(data.campaigns);
      const totals = data.summary;
      const currency = data.currency || 'EUR';
      const visitorCount = totals.visitors_known && totals.visitors !== null && totals.visitors !== undefined ? Number(totals.visitors).toLocaleString('el-GR') : '—';
      $('#stats-summary').innerHTML = `<article class="metric-card"><small>Επισκέπτες καμπανιών</small><b>${visitorCount}</b></article><article class="metric-card"><small>Επισκέψεις</small><b>${Number(totals.sessions || 0).toLocaleString('el-GR')}</b></article><article class="metric-card"><small>Αγορές GA4</small><b>${Number(totals.purchases || 0).toLocaleString('el-GR')}</b></article><article class="metric-card sales-primary"><small>Τζίρος GA4</small><b>${money(totals.revenue || 0, currency)}</b></article>`;
      const box = $('#stats-campaigns'); box.replaceChildren();
      if (!campaigns.length) box.innerHTML = '<div class="empty-list">Δεν βρέθηκαν επισκέψεις από Social Links για αυτή την περίοδο. Έλεγξε ότι οι σύνδεσμοι έχουν UTM σήμανση και ότι το GA4 καταγράφει δεδομένα.</div>';
      else {
        campaigns.forEach(row => {
          const card = document.createElement('article'); card.className = 'sales-row';
          const content = row.content ? ` · ${row.content}` : '';
          const visitors = row.visitors === null || row.visitors === undefined ? '—' : Number(row.visitors).toLocaleString('el-GR');
          card.innerHTML = `<b>${escapeHtml(row.campaign || 'Καμπάνια χωρίς όνομα')}</b><span>${escapeHtml(row.source || '')} / ${escapeHtml(row.medium || '')}${escapeHtml(content)}</span><small>${visitors} επισκέπτες · ${Number(row.sessions || 0).toLocaleString('el-GR')} επισκέψεις · ${Number(row.purchases || 0).toLocaleString('el-GR')} αγορές · ${money(row.revenue || 0, currency)}</small>`;
          box.append(card);
        });
      }
      const landingBox = $('#stats-landings'); landingBox.replaceChildren();
      const landings = asList(data.landings);
      if (!landings.length) landingBox.innerHTML = '<div class="empty-list">Δεν υπάρχουν διαθέσιμες σελίδες προορισμού για αυτή την περίοδο.</div>';
      else {
        landings.forEach(row => { const card = document.createElement('article'); card.className = 'sales-row'; card.innerHTML = `<b>${escapeHtml(row.path || row.landing || '/')}</b><span>${escapeHtml(row.source || '')} / ${escapeHtml(row.medium || '')}${row.content ? ` · ${escapeHtml(row.content)}` : ''}</span><small>${Number(row.sessions || 0).toLocaleString('el-GR')} επισκέψεις</small>`; landingBox.append(card); });
      }
    } catch (error) { $('#stats-campaigns').innerHTML = `<div class="error-card">${escapeHtml(error.message)}</div>`; $('#stats-landings').replaceChildren(); }
  }
  function renderOrder(order) {
    const button = document.createElement('button'); button.type = 'button'; button.className = 'order-row';
    const visual = order.product_image_url ? `<span class="order-mark order-product-mark"><img src="${escapeHtml(order.product_image_url)}" alt="" loading="lazy"></span>` : '<span class="order-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3.75h12a1 1 0 0 1 1 1v15.5l-7-3.5-7 3.5V4.75a1 1 0 0 1 1-1Z"/><path d="M9 8h6M9 11.5h6"/></svg></span>';
    button.innerHTML = `${visual}<span class="order-number">#${escapeHtml(order.number)}</span><span class="order-main"><b>${escapeHtml(order.customer || 'Πελάτης χωρίς όνομα')}</b><small>${escapeHtml(order.date)} · ${Number(order.items_count || 0)} προϊόντα · ${escapeHtml(order.payment_method || 'Τρόπος πληρωμής —')}</small></span><span class="order-side"><b>${escapeHtml(order.total)}</b><small>${escapeHtml(order.status_label)}</small></span><span class="order-chevron">›</span>`;
    button.addEventListener('click', () => openOrder(order.id)); return button;
  }
  async function openOrder(orderId) {
    if (!state.site) return;
    $('#orders-browser').classList.add('hidden'); $('#order-detail').classList.remove('hidden'); $('#order-detail-content').innerHTML = '<div class="loading">Φόρτωση παραγγελίας…</div>';
    try { state.currentOrder = await request(`/api/orders/${state.site.id}/${orderId}`, null, 'GET'); renderOrderDetail(state.currentOrder); window.scrollTo({ top: $('#orders-panel').offsetTop, behavior: 'smooth' }); }
    catch (error) { $('#order-detail-content').innerHTML = `<div class="error-card">${escapeHtml(error.message)}</div>`; }
  }
  function renderOrderDetail(order) {
    $('#order-detail-title').textContent = `Παραγγελία #${order.number}`;
    const statusOptions = order.available_statuses && typeof order.available_statuses === 'object' ? Object.entries(order.available_statuses) : Object.entries(orderStatusLabels);
    const statusHtml = statusOptions.map(([key, label]) => { const value = key.replace(/^wc-/, ''); return `<option value="${escapeHtml(value)}" ${value === order.status ? 'selected' : ''}>${escapeHtml(label)}</option>`; }).join('');
    const fields = (group, data) => `<div class="order-fields"><h4>${group === 'billing' ? 'Στοιχεία χρέωσης' : 'Στοιχεία αποστολής'}</h4>${Object.entries(orderFieldLabels).filter(([key]) => group === 'billing' || key !== 'email' && key !== 'phone').map(([key, label]) => `<label>${label}<input name="${group}[${key}]" value="${escapeHtml(data?.[key] || '')}" ${key === 'email' ? 'type="email"' : 'type="text"'}></label>`).join('')}</div>`;
    const itemRows = asList(order.items).map(item => `<div class="order-line"><span class="order-product-image">${item.image_url ? `<img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name)}" loading="lazy">` : '<span class="order-image-fallback" aria-label="Χωρίς εικόνα προϊόντος">◇</span>'}</span><span class="order-product-copy"><b>${escapeHtml(item.name)}</b><small>${item.sku ? `SKU ${escapeHtml(item.sku)} · ` : ''}Ποσότητα ${Number(item.quantity || 0)} · ${escapeHtml(item.total)}</small></span></div>`).join('') || '<p class="order-muted">Δεν υπάρχουν προϊόντα στη γραμμή.</p>';
    const noteRows = asList(order.notes).map(note => `<div class="order-note"><small>${escapeHtml(note.date)}${note.customer_note ? ' · Σημείωση πελάτη' : ' · Εσωτερική σημείωση'}</small><p>${escapeHtml(note.content)}</p></div>`).join('') || '<p class="order-muted">Δεν υπάρχουν σημειώσεις.</p>';
    $('#order-detail-content').innerHTML = `<div class="order-summary"><div><small>${escapeHtml(order.date)} · ${escapeHtml(order.status_label)}</small><h3>${escapeHtml(order.total)}</h3></div><div><b>${escapeHtml(order.customer || 'Πελάτης')}</b><small>${escapeHtml(order.email || '')}</small><small>${escapeHtml(order.payment_method || '')}${order.transaction_id ? ` · ${escapeHtml(order.transaction_id)}` : ''}</small></div></div><section class="order-section"><h4>Προϊόντα και χρεώσεις</h4>${itemRows}<div class="order-totals"><span>Μεταφορικά</span><b>${escapeHtml(order.shipping_total || '—')}</b><span>Φόροι</span><b>${escapeHtml(order.tax_total || '—')}</b><span>Σύνολο</span><b>${escapeHtml(order.total)}</b></div></section><form id="order-edit-form"><label class="order-status-field">Κατάσταση παραγγελίας<select name="status">${statusHtml}</select></label>${fields('billing', order.billing)}${fields('shipping', order.shipping)}<label class="order-textarea">Σημείωση πελάτη<textarea name="customer_note" rows="3">${escapeHtml(order.customer_note || '')}</textarea></label><label class="order-textarea">Νέα εσωτερική σημείωση<textarea name="private_note" rows="3" placeholder="Ορατή μόνο στους διαχειριστές του e-shop"></textarea></label><p class="order-help">Η αλλαγή κατάστασης εκτελείται στο WooCommerce και μπορεί να ενεργοποιήσει τα αντίστοιχα email του καταστήματος. Οι εσωτερικές σημειώσεις δεν αποστέλλονται στον πελάτη.</p><button class="primary save-order" type="submit">Αποθήκευση αλλαγών</button></form><section class="order-section"><h4>Ιστορικό σημειώσεων</h4>${noteRows}</section>`;
    $('#order-edit-form').addEventListener('submit', saveOrder);
  }
  async function saveOrder(event) {
    event.preventDefault(); const order = state.currentOrder; if (!order || !state.site) return;
    const form = new FormData(event.currentTarget); const currentStatus = String(order.status); const nextStatus = String(form.get('status'));
    if (currentStatus !== nextStatus && !window.confirm('Η αλλαγή κατάστασης μπορεί να στείλει το αντίστοιχο email του WooCommerce στον πελάτη. Να συνεχίσω;')) return;
    const payload = { status: nextStatus, customer_note: form.get('customer_note'), private_note: form.get('private_note'), billing: {}, shipping: {} };
    for (const [key, value] of form.entries()) { const match = key.match(/^(billing|shipping)\[([a-z_]+)\]$/); if (match) payload[match[1]][match[2]] = value; }
    const button = event.currentTarget.querySelector('.save-order'); button.disabled = true; button.textContent = 'Αποθήκευση…';
    try { state.currentOrder = await request(`/api/orders/${state.site.id}/${order.id}`, payload); renderOrderDetail(state.currentOrder); toast('Οι αλλαγές αποθηκεύτηκαν στο WooCommerce.'); loadOrders(true); }
    catch (error) { toast(error.message, true); }
    finally { const next = $('#order-edit-form .save-order'); if (next) { next.disabled = false; next.textContent = 'Αποθήκευση αλλαγών'; } }
  }
  async function pairSite() {
    try { const data = await request('/api/pair/start', {}); $('#pair-code').textContent = data.code; $('#pair-card').classList.remove('hidden'); toast('Ο κωδικός δημιουργήθηκε. Πέρασέ τον στο WordPress.'); const before = new Map(state.sites.map(site => [String(site.id), String(site.last_seen_at || '')])); clearInterval(state.polling); let ticks = 0; state.polling = setInterval(async () => { ticks++; try { const me = await request('/api/me', null, 'GET'); csrf = me.csrf || csrf; const paired = me.sites.some(site => !before.has(String(site.id)) || String(site.last_seen_at || '') !== before.get(String(site.id))); if (paired) { clearInterval(state.polling); state.sites = me.sites; $('#pair-card').classList.add('hidden'); showWorkspace(); toast('Το e-shop συνδέθηκε ή επανασυνδέθηκε.'); } } catch {} if (ticks >= 120) clearInterval(state.polling); }, 5000); }
    catch (error) { toast(error.message, true); }
  }
  async function generate() {
    const places = $$('#places input:checked').map(input => input.value); const itemIds = [...state.selected.keys()];
    if (!places.length || !itemIds.length) return;
    $('#generate').disabled = true; $('#generate').textContent = 'Δημιουργία…';
    try { const result = await request(`/api/social/${state.site.id}/links`, { item_ids: itemIds, places, suffix: $('#campaign-suffix').value, label: $('#content-label').value }); showResults(result.links || []); toast('Οι σύνδεσμοι δημιουργήθηκαν.'); }
    catch (error) { toast(error.message, true); }
    finally { $('#generate').innerHTML = 'Δημιουργία συνδέσμων <span>→</span>'; updateSelected(); }
  }
  function showResults(rows) {
    const box = $('#results'); box.replaceChildren(); box.classList.remove('hidden'); const head = document.createElement('div'); head.className = 'result-heading'; const title = document.createElement('h3'); title.textContent = `Έτοιμοι σύνδεσμοι · ${rows.length}`; const copyAll = document.createElement('button'); copyAll.className = 'secondary'; copyAll.textContent = 'Αντιγραφή όλων'; copyAll.addEventListener('click', () => copy(rows.map(r => `${r.title} — ${r.place_label}\n${r.url}`).join('\n\n'))); head.append(title, copyAll); box.append(head);
    rows.forEach(row => { const card = document.createElement('article'); card.className = 'result-row'; const label = document.createElement('div'); label.className = 'result-meta'; label.innerHTML = `<b>${escapeHtml(row.title)}</b><small>${escapeHtml(row.place_label)} · ${escapeHtml(row.content)}</small>`; const url = document.createElement('input'); url.readOnly = true; url.value = row.url; url.setAttribute('aria-label', 'UTM σύνδεσμος'); const actions = document.createElement('div'); actions.className = 'result-actions'; const copyBtn = document.createElement('button'); copyBtn.className = 'secondary'; copyBtn.textContent = 'Αντιγραφή'; copyBtn.addEventListener('click', () => copy(row.url)); const share = document.createElement('button'); share.className = 'quiet'; share.textContent = 'Κοινοποίηση'; share.addEventListener('click', async () => { try { if (navigator.share) await navigator.share({ title: row.title, url: row.url }); else await copy(row.url); } catch {} }); actions.append(copyBtn, share); card.append(label, url, actions); box.append(card); }); box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  async function copy(text) { try { await navigator.clipboard.writeText(text); toast('Αντιγράφηκε στο πρόχειρο.'); } catch { const ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; document.body.append(ta); ta.select(); document.execCommand('copy'); ta.remove(); toast('Αντιγράφηκε στο πρόχειρο.'); } }
  function escapeHtml(value) { return String(value ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c])); }

  $('#login-form').addEventListener('submit', async e => { e.preventDefault(); const f = new FormData(e.currentTarget); try { await request('/api/login', { username: f.get('username'), password: f.get('password') }); await refreshMe(); } catch (error) { $('#auth-error').textContent = error.message; } });
  $('#setup-form').addEventListener('submit', async e => { e.preventDefault(); const f = new FormData(e.currentTarget); try { await request('/api/setup', { setup_key: f.get('setup_key'), username: f.get('username'), password: f.get('password') }); await refreshMe(); } catch (error) { $('#auth-error').textContent = error.message; } });
  $('#show-setup').addEventListener('click', () => authMode(true)); $('#pair-site').addEventListener('click', pairSite); $('#pair-empty').addEventListener('click', pairSite); $('#pair-refresh').addEventListener('click', pairSite);
  $('#shop-select').addEventListener('change', () => { state.site = state.sites.find(s => Number(s.id) === Number($('#shop-select').value)); syncOrderShopSelect(); paintSite(); $('#results').classList.add('hidden'); loadShop(); });
  $('#orders-shop-select').addEventListener('change', () => { state.site = state.sites.find(s => Number(s.id) === Number($('#orders-shop-select').value)); $('#shop-select').value = String(state.site?.id || ''); syncOrderShopSelect(); paintSite(); loadOrders(true); });
  $('#sales-shop-select').addEventListener('change', () => { state.site = state.sites.find(s => Number(s.id) === Number($('#sales-shop-select').value)); $('#shop-select').value = String(state.site?.id || ''); syncOrderShopSelect(); paintSite(); loadSales(Number($('#sales-year').value || 0)); });
  $('#stats-shop-select').addEventListener('change', () => { state.site = state.sites.find(s => Number(s.id) === Number($('#stats-shop-select').value)); $('#shop-select').value = String(state.site?.id || ''); syncOrderShopSelect(); paintSite(); loadSocialStats(); });
  $('#tab-social').addEventListener('click', () => { setView('social'); $('#social-panel').scrollIntoView({ behavior: 'smooth', block: 'start' }); });
  $('#tab-orders').addEventListener('click', () => { setView('orders'); $('#orders-panel').scrollIntoView({ behavior: 'smooth', block: 'start' }); });
  $('#tab-sales').addEventListener('click', () => { setView('sales'); $('#sales-panel').scrollIntoView({ behavior: 'smooth', block: 'start' }); });
  $('#tab-stats').addEventListener('click', () => { setView('stats'); $('#stats-panel').scrollIntoView({ behavior: 'smooth', block: 'start' }); });
  $('#sales-refresh').addEventListener('click', () => loadSales(Number($('#sales-year').value || 0))); $('#sales-year').addEventListener('change', () => loadSales(Number($('#sales-year').value || 0))); 
  $('#stats-refresh').addEventListener('click', loadSocialStats);
  $('#orders-refresh').addEventListener('click', () => loadOrders(true));
  $('#order-search').addEventListener('input', () => { clearTimeout(state.orderSearchTimer); state.orderSearchTimer = setTimeout(() => loadOrders(true), 350); });
  $('#order-status-filter').addEventListener('change', () => loadOrders(true));
  $('#orders-more').addEventListener('click', () => { state.ordersPage++; loadOrders(false); });
  $('#order-back').addEventListener('click', () => { state.currentOrder = null; $('#order-detail').classList.add('hidden'); $('#orders-browser').classList.remove('hidden'); });
  $('#type-tabs').addEventListener('click', async e => { const button = e.target.closest('button[data-type]'); if (!button || button.dataset.type === state.type) return; $$('#type-tabs button').forEach(b => b.classList.toggle('active', b === button)); state.type = button.dataset.type; $('#category-filter').value = '0'; await loadCategories(); await loadItems(true); });
  $('#item-search').addEventListener('input', () => { clearTimeout(state.searchTimer); state.searchTimer = setTimeout(() => loadItems(true), 350); });
  $('#category-filter').addEventListener('change', () => loadItems(true)); $('#load-more').addEventListener('click', () => { state.page++; loadItems(false); });
  $('#places').addEventListener('change', updateSelected); $('#generate').addEventListener('click', generate);
  $('#push-toggle').addEventListener('click', togglePush);
  $('#logout').addEventListener('click', async () => { try { await request('/api/logout', {}); location.reload(); } catch (error) { toast(error.message, true); } });
  if ('serviceWorker' in navigator) window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' }).then(registration => registration.update()).catch(() => {}));
  refreshMe();
})();
