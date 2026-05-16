var API_BASE = 'http://127.0.0.1:8000/api';
var WS_URL = 'ws://127.0.0.1:3001';
let wsConnection = null;
let authToken = null;
let currentUser = null;
let distressPollInterval = null;
let lastDistressCount = 0;
let knownDistressIds = new Set();

async function apiRequest(endpoint, options) {
    if (!options) options = {};
    var url = API_BASE + endpoint;
    var config = {
        headers: { 'Content-Type': 'application/json' },
    };
    for (var key in options) config[key] = options[key];
    if (authToken) config.headers['Authorization'] = 'Bearer ' + authToken;

    try {
        var response = await fetch(url, config);
        var ct = response.headers.get('content-type') || '';
        if (ct.includes('xml')) {
            var text = await response.text();
            if (!response.ok) throw new Error('Request failed: ' + response.status);
            return text;
        }
        var data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Request failed: ' + response.status);
        return data;
    } catch (err) {
        if (err.name === 'TypeError' && err.message === 'Failed to fetch') {
            throw new Error('Network error. Is the server running?');
        }
        throw err;
    }
}

function connectWebSocket() {
    if (wsConnection && wsConnection.readyState === WebSocket.OPEN) return;
    try {
        wsConnection = new WebSocket(WS_URL);
        wsConnection.onopen = function () {
            if (currentUser) wsConnection.send(JSON.stringify({ type: 'auth', user_id: currentUser.id, role: currentUser.role }));
        };
        wsConnection.onclose = function () { setTimeout(connectWebSocket, 5000); };
        wsConnection.onerror = function () {};
        wsConnection.onmessage = function (event) {
            try { handleWebSocketMessage(JSON.parse(event.data)); } catch (e) {}
        };
    } catch (err) { setTimeout(connectWebSocket, 5000); }
}

function handleWebSocketMessage(msg) {
    if (msg.type === 'message' || msg.type === 'alert' || msg.type === 'distress') {
        loadDashboardStats();
        if (msg.type === 'alert') showToast('New alert: ' + (msg.data.title || ''), 'error');
        if (msg.type === 'distress') showToast('New distress signal: ' + (msg.data.title || ''), 'error');
    }
}

function startDistressPolling() {
    stopDistressPolling();
    distressPollInterval = setInterval(function () {
        if (currentUser && currentUser.role !== 'victim') {
            pollDistressSignals();
        }
    }, 10000);
}

function stopDistressPolling() {
    if (distressPollInterval) {
        clearInterval(distressPollInterval);
        distressPollInterval = null;
    }
}

async function pollDistressSignals() {
    try {
        var data = await apiRequest('/distress');
        var signals = data.signals || [];
        var currentIds = new Set(signals.map(function (s) { return s.id; }));
        if (knownDistressIds.size > 0) {
            var newSignals = signals.filter(function (s) { return !knownDistressIds.has(s.id) && s.status === 'active'; });
            for (var i = 0; i < newSignals.length; i++) {
                showToast('New distress signal from ' + (newSignals[i].victim_name || 'a victim') + ': ' + newSignals[i].title, 'error');
            }
        }
        knownDistressIds = currentIds;
        lastDistressCount = signals.length;
        var badge = document.querySelector('.side-link[data-page="distress"] .nav-badge');
        if (!badge && signals.length > 0) {
            var link = document.querySelector('.side-link[data-page="distress"]');
            if (link) {
                badge = document.createElement('span');
                badge.className = 'nav-badge';
                link.appendChild(badge);
            }
        }
        if (badge) {
            var activeCount = signals.filter(function (s) { return s.status === 'active'; }).length;
            badge.textContent = activeCount > 0 ? activeCount : '';
            badge.style.display = activeCount > 0 ? '' : 'none';
        }
    } catch (e) {}
}

async function handleGoogleLogin() {
    try { var data = await apiRequest('/auth?action=login'); if (data.auth_url) window.location.href = data.auth_url; }
    catch (err) { showToast('Login failed: ' + err.message, 'error'); }
}

async function handleGoogleCallback() {
    var params = new URLSearchParams(window.location.search);
    var code = params.get('code');
    if (!code) return false;
    try {
        var data = await apiRequest('/auth?action=callback&code=' + encodeURIComponent(code));
        if (data.success && data.token) {
            authToken = data.token; currentUser = data.user;
            localStorage.setItem('ems_token', data.token); localStorage.setItem('ems_user', JSON.stringify(data.user));
            window.history.replaceState({}, document.title, '/');
            return true;
        }
    } catch (err) { console.error('OAuth callback error:', err); }
    return false;
}

async function checkSession() {
    var token = localStorage.getItem('ems_token');
    var user = localStorage.getItem('ems_user');
    if (token && user) {
        authToken = token; currentUser = JSON.parse(user);
        try {
            var data = await apiRequest('/auth?action=session');
            if (data.authenticated) { currentUser = data.user; authToken = data.token || authToken; return true; }
        } catch (err) { console.log('Stored session invalid:', err.message); }
        localStorage.removeItem('ems_token'); localStorage.removeItem('ems_user');
        authToken = null; currentUser = null;
    }
    try {
        var data = await apiRequest('/auth?action=session');
        if (data.authenticated) { currentUser = data.user; authToken = data.token || null; return true; }
    } catch (err) { console.log('Session check failed:', err.message); }
    return false;
}

async function handleEmailLogin() {
    var email = document.getElementById('login-email').value.trim();
    var password = document.getElementById('login-password').value;
    if (!email || !password) { showToast('Please enter your email and password', 'error'); return; }
    try {
        var response = await fetch(API_BASE + '/auth?action=email_login', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email, password: password }),
        });
        var data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Login failed');
        if (data.success) {
            authToken = data.token; currentUser = data.user;
            localStorage.setItem('ems_token', data.token); localStorage.setItem('ems_user', JSON.stringify(data.user));
            showApp();
        }
    } catch (err) { showToast('Login failed: ' + err.message, 'error'); }
}

async function handleRegister() {
    var displayName = document.getElementById('reg-name').value.trim();
    var email = document.getElementById('reg-email').value.trim();
    var password = document.getElementById('reg-password').value;
    var role = document.getElementById('reg-role').value;
    if (!displayName || !email || !password) { showToast('Please fill in all fields', 'error'); return; }
    if (password.length < 6) { showToast('Password must be at least 6 characters', 'error'); return; }
    try {
        var response = await fetch(API_BASE + '/auth?action=register', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ display_name: displayName, email: email, password: password, role: role }),
        });
        var data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Registration failed');
        if (data.success) {
            authToken = data.token; currentUser = data.user;
            localStorage.setItem('ems_token', data.token); localStorage.setItem('ems_user', JSON.stringify(data.user));
            showApp();
            showToast('Account created successfully!', 'success');
        }
    } catch (err) { showToast('Registration failed: ' + err.message, 'error'); }
}

function switchAuthTab(tab) {
    document.querySelectorAll('.auth-tab').forEach(function (t) {
        t.classList.toggle('active', t.textContent.trim().toLowerCase() === tab);
    });
    document.getElementById('auth-login').classList.toggle('hidden', tab !== 'login');
    document.getElementById('auth-register').classList.toggle('hidden', tab !== 'register');
}

async function handleDevLogin() {
    var name = document.getElementById('dev-name');
    if (!name) return;
    var displayName = name.value.trim();
    if (!displayName) { showToast('Please enter your name', 'error'); return; }
    try {
        var response = await fetch(API_BASE + '/auth?action=dev_login', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'display_name=' + encodeURIComponent(displayName) + '&email=',
        });
        var data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Dev login failed');
        if (data.success) {
            authToken = data.token; currentUser = data.user;
            localStorage.setItem('ems_token', data.token); localStorage.setItem('ems_user', JSON.stringify(data.user));
            showApp();
        }
    } catch (err) { showToast('Dev login failed: ' + err.message, 'error'); }
}

async function handleLogout() {
    try { await apiRequest('/auth?action=logout'); } catch (e) {}
    localStorage.removeItem('ems_token'); localStorage.removeItem('ems_user');
    authToken = null; currentUser = null;
    if (wsConnection) wsConnection.close();
    document.getElementById('login-page').classList.remove('hidden');
    document.getElementById('main-app').classList.add('hidden');
}

async function loadEvents() {
    var filter = document.getElementById('event-filter').value;
    var search = document.getElementById('event-search') ? document.getElementById('event-search').value.trim() : '';
    var params = [];
    if (filter !== 'all') params.push('status=' + filter);
    if (search) params.push('search=' + encodeURIComponent(search));
    var endpoint = '/events' + (params.length ? '?' + params.join('&') : '');
    try {
        var data = await apiRequest(endpoint);
        renderEvents(data.events || []);
    } catch (err) { showToast('Failed to load events: ' + err.message, 'error'); }
}

async function createEvent() {
    var title = document.getElementById('event-title').value.trim();
    var severity = document.getElementById('event-severity').value;
    var location = document.getElementById('event-location').value.trim();
    var description = document.getElementById('event-description').value.trim();
    if (!title) { showToast('Event title is required', 'error'); return; }
    try {
        await apiRequest('/events', { method: 'POST', body: JSON.stringify({ title: title, severity: severity, location: location, description: description }) });
        hideModal('create-event-modal');
        showToast('Event created successfully', 'success');
        loadEvents(); loadDashboardStats();
        document.getElementById('event-title').value = '';
        document.getElementById('event-location').value = '';
        document.getElementById('event-description').value = '';
    } catch (err) { showToast('Failed to create event: ' + err.message, 'error'); }
}

async function resolveEvent(eventId) {
    if (!confirm('Resolve this event?')) return;
    try {
        await apiRequest('/events?id=' + eventId, { method: 'PUT', body: JSON.stringify({ status: 'resolved' }) });
        showToast('Event resolved', 'success');
        loadEvents(); loadDashboardStats();
    } catch (err) { showToast('Failed to resolve event: ' + err.message, 'error'); }
}

async function loadMessages() {
    var eventId = document.getElementById('message-event-select').value;
    var board = document.getElementById('message-board');
    var inputArea = document.getElementById('message-input-area');
    if (!eventId) {
        board.innerHTML = '<div class="message-board-placeholder"><p>Select an event above to view its message thread.</p></div>';
        inputArea.classList.add('hidden'); return;
    }
    inputArea.classList.remove('hidden');
    var quickReplies = document.getElementById('quick-replies');
    if (quickReplies) quickReplies.classList.toggle('hidden', !currentUser || currentUser.role !== 'victim');
    try {
        var data = await apiRequest('/messages?event_id=' + eventId);
        renderMessages(data.messages || []);
    } catch (err) { board.innerHTML = '<p class="empty-state">Failed to load messages: ' + err.message + '</p>'; }
}

async function sendMessage(content) {
    var eventId = document.getElementById('message-event-select').value;
    var msgContent = content || document.getElementById('message-input').value.trim();
    var priority = document.getElementById('message-priority').value;
    var fileInput = document.getElementById('msg-file-input');
    var file = fileInput ? fileInput.files[0] : null;
    if (!eventId) { showToast('Select an event first', 'error'); return; }
    if (!msgContent && !file) { showToast('Message content or file is required', 'error'); return; }

    try {
        if (file) {
            var formData = new FormData();
            formData.append('content', msgContent || '');
            formData.append('priority', priority);
            formData.append('attachment', file);
            var resp = await fetch(API_BASE + '/messages?event_id=' + eventId, {
                method: 'POST', headers: { 'Authorization': 'Bearer ' + authToken }, body: formData
            });
            var data = await resp.json();
            if (!resp.ok) throw new Error(data.error || 'Failed to send');
        } else {
            var data = await apiRequest('/messages?event_id=' + eventId, { method: 'POST', body: JSON.stringify({ content: msgContent, priority: priority }) });
        }
        document.getElementById('message-input').value = '';
        if (fileInput) fileInput.value = '';
        loadMessages();
    } catch (err) { showToast('Failed to send message: ' + err.message, 'error'); }
}

function exportCSV(type) {
    var url = API_BASE + '/system?action=export_csv&type=' + encodeURIComponent(type) + '&token=' + encodeURIComponent(authToken || '');
    window.open(url, '_blank');
}

function sendQuickMessage(text) {
    var input = document.getElementById('message-input');
    if (input) input.value = text;
    sendMessage(text);
}

async function loadAlerts() {
    var search = document.getElementById('alert-search') ? document.getElementById('alert-search').value.trim() : '';
    var typeFilter = document.getElementById('alert-type-filter') ? document.getElementById('alert-type-filter').value : '';
    var params = [];
    if (search) params.push('search=' + encodeURIComponent(search));
    if (typeFilter) params.push('type=' + encodeURIComponent(typeFilter));
    var endpoint = '/alerts' + (params.length ? '?' + params.join('&') : '');
    try { var data = await apiRequest(endpoint); renderAlerts(data.alerts || []); }
    catch (err) { showToast('Failed to load alerts: ' + err.message, 'error'); }
}

async function dispatchAlert() {
    var eventId = document.getElementById('alert-event').value;
    var type = document.getElementById('alert-type').value;
    var targetRole = document.getElementById('alert-target').value;
    var title = document.getElementById('alert-title').value.trim();
    var message = document.getElementById('alert-message').value.trim();
    if (!eventId || !title || !message) { showToast('Event, title, and message are required', 'error'); return; }
    try {
        await apiRequest('/alerts', { method: 'POST', body: JSON.stringify({ event_id: parseInt(eventId), type: type, target_role: targetRole, title: title, message: message }) });
        hideModal('create-alert-modal');
        showToast('Alert dispatched', 'success');
        loadAlerts(); loadDashboardStats();
    } catch (err) { showToast('Failed to dispatch alert: ' + err.message, 'error'); }
}

async function acknowledgeAlert(alertId) {
    try { await apiRequest('/alerts?id=' + alertId, { method: 'PUT' }); showToast('Alert acknowledged', 'success'); loadAlerts(); loadDashboardStats(); }
    catch (err) { showToast('Failed to acknowledge: ' + err.message, 'error'); }
}

async function loadUsers() {
    var search = document.getElementById('user-search') ? document.getElementById('user-search').value.trim() : '';
    var endpoint = '/users' + (search ? '?search=' + encodeURIComponent(search) : '');
    try { var data = await apiRequest(endpoint); renderUsers(data.users || []); }
    catch (err) { showToast('Failed to load users: ' + err.message, 'error'); }
}

async function updateUserRole(userId, role) {
    try { await apiRequest('/users?id=' + userId, { method: 'PUT', body: JSON.stringify({ role: role }) }); showToast('User role updated', 'success'); loadUsers(); }
    catch (err) { showToast('Failed to update role: ' + err.message, 'error'); }
}

async function loadDistressSignals() {
    try {
        var data = await apiRequest('/distress');
        var signals = data.signals || [];
        knownDistressIds = new Set(signals.map(function (s) { return s.id; }));
        lastDistressCount = signals.length;
        renderDistressSignals(signals);
    }
    catch (err) { showToast('Failed to load distress signals: ' + err.message, 'error'); }
}

async function sendDistressSignal() {
    var title = document.getElementById('distress-title').value.trim();
    var description = document.getElementById('distress-description').value.trim();
    var location = document.getElementById('distress-location').value.trim();
    var eventId = document.getElementById('distress-event').value;
    if (!title) { showToast('Please describe what you need help with', 'error'); return; }
    try {
        var data = await apiRequest('/distress', { method: 'POST', body: JSON.stringify({ title: title, description: description, location: location, event_id: eventId ? parseInt(eventId) : null }) });
        hideModal('send-distress-modal');
        var sosMsg = 'Distress signal sent! Help is on the way.';
        if (data.emergency_contact_notified) sosMsg += ' Your emergency contact has been notified.';
        showToast(sosMsg, 'success');
        document.getElementById('distress-title').value = '';
        document.getElementById('distress-description').value = '';
        document.getElementById('distress-location').value = '';
        document.getElementById('distress-event').value = '';
        loadDistressSignals();
    } catch (err) { showToast('Failed to send distress signal: ' + err.message, 'error'); }
}

async function respondToDistress(signalId) {
    try { await apiRequest('/distress?id=' + signalId, { method: 'PUT', body: JSON.stringify({ status: 'responded' }) }); showToast('You have responded to this distress signal', 'success'); loadDistressSignals(); }
    catch (err) { showToast('Failed to respond: ' + err.message, 'error'); }
}

async function resolveDistressSignal(signalId) {
    try { await apiRequest('/distress?id=' + signalId, { method: 'PUT', body: JSON.stringify({ status: 'resolved' }) }); showToast('Distress signal resolved', 'success'); loadDistressSignals(); }
    catch (err) { showToast('Failed to resolve: ' + err.message, 'error'); }
}

function showSendDistressModal() {
    populateDistressEventSelect();
    showModal('send-distress-modal');
}

function populateDistressEventSelect() {
    var select = document.getElementById('distress-event');
    apiRequest('/events').then(function (data) {
        var events = data.events || [];
        select.innerHTML = '<option value="">Not related to a specific event</option>';
        for (var i = 0; i < events.length; i++) {
            var option = document.createElement('option');
            option.value = events[i].id;
            option.textContent = '[' + events[i].severity.toUpperCase() + '] ' + events[i].title;
            select.appendChild(option);
        }
    }).catch(function () { select.innerHTML = '<option value="">Not related to a specific event</option>'; });
}

async function handleSOS() {
    if (!currentUser || currentUser.role !== 'victim') return;
    if (!confirm('Send an SOS emergency signal? Help will be notified immediately.')) return;
    try {
        var data = await apiRequest('/distress', { method: 'POST', body: JSON.stringify({ title: 'SOS EMERGENCY - I need immediate help!', description: 'Urgent emergency situation. Immediate assistance required.', location: '', event_id: null }) });
        var sosBtn = document.getElementById('sos-btn');
        if (sosBtn) { sosBtn.classList.add('sos-active'); sosBtn.textContent = 'SOS SENT!'; setTimeout(function () { sosBtn.classList.remove('sos-active'); sosBtn.textContent = 'SOS'; }, 5000); }
        var sosMsg = 'SOS signal sent! Help is on the way.';
        if (data.emergency_contact_notified) sosMsg += ' Your emergency contact has been notified.';
        showToast(sosMsg, 'success');
        loadDistressSignals();
    } catch (err) { showToast('Failed to send SOS: ' + err.message, 'error'); }
}

function toggleTheme() {
    var html = document.documentElement;
    var isDark = html.classList.contains('dark-mode');
    html.classList.toggle('dark-mode', !isDark);
    html.classList.toggle('light-mode', isDark);
    document.querySelector('.theme-icon-light').classList.toggle('hidden', !isDark);
    document.querySelector('.theme-icon-dark').classList.toggle('hidden', isDark);
    localStorage.setItem('ems_theme', isDark ? 'light' : 'dark');
    if (currentUser) apiRequest('/auth?action=update_profile', { method: 'POST', body: JSON.stringify({ theme_preference: isDark ? 'light' : 'dark' }) }).catch(function(){});
}

async function loadAnnouncements() {
    try {
        var data = await apiRequest('/announcements');
        var bar = document.getElementById('announcements-bar');
        if (data.announcements && data.announcements.length > 0) {
            var a = data.announcements[0];
            bar.innerHTML = '<div class="announcement-item"><strong>' + escapeHtml(a.title) + ':</strong> ' + escapeHtml(a.content) + ' <button class="announcement-close" onclick="this.parentElement.parentElement.classList.add(\'hidden\')">&times;</button></div>';
            bar.classList.remove('hidden');
        } else {
            bar.classList.add('hidden');
        }
    } catch (e) {}
}

async function showForgotPassword() {
    var email = prompt('Enter your email address:');
    if (!email) return;
    try {
        var data = await apiRequest('/auth?action=forgot_password', { method: 'POST', body: JSON.stringify({ email: email }) });
        if (data.success) {
            var resetPw = prompt('Reset token (check console): ' + data.reset_token + '\n\nEnter the reset token:');
            if (!resetPw) return;
            var newPw = prompt('Enter your new password (min 6 characters):');
            if (!newPw || newPw.length < 6) { showToast('Password must be at least 6 characters', 'error'); return; }
            var result = await apiRequest('/auth?action=reset_password', { method: 'POST', body: JSON.stringify({ token: resetPw, new_password: newPw }) });
            if (result.success) showToast('Password reset successfully!', 'success');
        }
    } catch (err) { showToast('Password reset failed: ' + err.message, 'error'); }
}

function toggleSidebar() {
    var sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('collapsed');
    var isCollapsed = sidebar.classList.contains('collapsed');
    document.querySelector('.toggle-hamburger').classList.toggle('hidden', isCollapsed);
    document.querySelector('.toggle-close').classList.toggle('hidden', !isCollapsed);
}

function toggleTheme() {
    var html = document.documentElement;
    var isDark = html.classList.contains('dark-mode');
    html.classList.toggle('dark-mode', !isDark);
    html.classList.toggle('light-mode', isDark);
    var lightIcon = document.querySelector('.theme-icon-light');
    var darkIcon = document.querySelector('.theme-icon-dark');
    if (lightIcon) lightIcon.classList.toggle('hidden', !isDark);
    if (darkIcon) darkIcon.classList.toggle('hidden', isDark);
    localStorage.setItem('ems_theme', isDark ? 'light' : 'dark');
    if (currentUser) apiRequest('/auth?action=update_profile', { method: 'POST', body: JSON.stringify({ theme_preference: isDark ? 'light' : 'dark' }) }).catch(function(){});
}

async function loadAnnouncements() {
    try { var data = await apiRequest('/announcements'); var bar = document.getElementById('announcements-bar'); if (data.announcements && data.announcements.length > 0) { var a = data.announcements[0]; bar.innerHTML = '<div class="announcement-item"><strong>' + escapeHtml(a.title) + ':</strong> ' + escapeHtml(a.content) + ' <button class="announcement-close" onclick="this.parentElement.parentElement.classList.add(\'hidden\')">&times;</button></div>'; bar.classList.remove('hidden'); } else { bar.classList.add('hidden'); } } catch (e) {}
}

async function showForgotPassword() {
    var email = prompt('Enter your email address:');
    if (!email) return;
    try { var data = await apiRequest('/auth?action=forgot_password', { method: 'POST', body: JSON.stringify({ email: email }) }); if (data.success) { var resetPw = prompt('Reset token (check server console): ' + (data.reset_token || '') + '\n\nEnter the reset token:'); if (!resetPw) return; var newPw = prompt('Enter your new password (min 6 characters):'); if (!newPw || newPw.length < 6) { showToast('Password must be at least 6 characters', 'error'); return; } var result = await apiRequest('/auth?action=reset_password', { method: 'POST', body: JSON.stringify({ token: resetPw, new_password: newPw }) }); if (result.success) showToast('Password reset successfully!', 'success'); } } catch (err) { showToast('Password reset failed: ' + err.message, 'error'); }
}

function exportCSV(type) { window.open(API_BASE + '/system?action=export_csv&type=' + encodeURIComponent(type) + '&token=' + encodeURIComponent(authToken || ''), '_blank'); }

var eventMap = null;
function showEventMap() {
    var container = document.getElementById('map-container'); if (!container) return;
    container.classList.remove('hidden');
    setTimeout(function() {
        if (eventMap) eventMap.remove();
        if (typeof L !== 'undefined') {
            eventMap = L.map('event-map').setView([14.5, 121], 10);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: ' OpenStreetMap' }).addTo(eventMap);
            apiRequest('/events').then(function(data) { (data.events || []).forEach(function(e) { if (e.location) L.marker([14.5 + Math.random()*0.1, 121 + Math.random()*0.1]).addTo(eventMap).bindPopup('<b>' + escapeHtml(e.title) + '</b><br>' + escapeHtml(e.location)); }); }).catch(function(){});
            setTimeout(function(){ if (eventMap) eventMap.invalidateSize(); }, 300);
        }
    }, 100);
}

function printReport() {
    apiRequest('/events').then(function(data) {
        var events = data.events || [];
        var w = window.open('', '_blank');
        w.document.write('<html><head><title>Emergency Report</title><style>body{font-family:Arial;padding:40px;}h1{color:#1e293b;}table{width:100%;border-collapse:collapse;margin-top:20px;}th,td{padding:10px;text-align:left;border-bottom:1px solid #ddd;}th{background:#f1f5f9;}.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;}.critical{background:#dc2626;color:white;}.high{background:#ea580c;color:white;}.medium{background:#ca8a04;color:white;}.low{background:#64748b;color:white;}</style></head><body>');
        w.document.write('<h1>Emergency Incident Report</h1><p>Generated: ' + new Date().toLocaleString() + '</p><p>Total Events: ' + events.length + '</p><table><thead><tr><th>Title</th><th>Severity</th><th>Status</th><th>Location</th><th>Created</th></tr></thead><tbody>');
        events.forEach(function(e) { w.document.write('<tr><td>' + escapeHtml(e.title) + '</td><td><span class="badge ' + e.severity + '">' + e.severity + '</span></td><td>' + e.status + '</td><td>' + (e.location || 'N/A') + '</td><td>' + (e.created_at || '') + '</td></tr>'); });
        w.document.write('</tbody></table></body></html>'); w.document.close();
        setTimeout(function(){ w.print(); }, 500);
    }).catch(function(err) { showToast('Failed to generate report: ' + err.message, 'error'); });
}

async function loadResources(eventId) {
    if (!eventId) return;
    try { var data = await apiRequest('/events?id=' + eventId); var ev = data.event; var el = document.getElementById('resource-event-name'); if (el) el.textContent = '- ' + (ev ? ev.title : ''); } catch (e) {}
    var panel = document.getElementById('event-resources-panel'); if (panel) panel.classList.remove('hidden');
    var container = document.getElementById('resources-list'); if (!container) return;
    container.innerHTML = '<p class="empty-state">Loading resources...</p>';
    try {
        var data = await apiRequest('/system?action=resources&event_id=' + eventId);
        var resources = data.resources || [];
        if (resources.length === 0) { container.innerHTML = '<p class="empty-state">No resources assigned</p>'; return; }
        var html = '';
        for (var i = 0; i < resources.length; i++) { var r = resources[i]; html += '<div class="event-card" style="cursor:default;"><div class="event-card-header"><span class="event-severity ' + r.type + '">' + r.type + '</span><span class="event-card-title">' + escapeHtml(r.name) + '</span><span class="event-status ' + r.status + '">' + r.status + '</span></div><div class="event-card-meta"><span>Qty: ' + r.quantity + '</span>' + (r.notes ? '<span>' + escapeHtml(r.notes) + '</span>' : '') + '</div></div>'; }
        container.innerHTML = html;
    } catch (err) { container.innerHTML = '<p class="empty-state">Failed to load resources</p>'; }
}

async function showAddResourceModal() {
    var eventId = document.getElementById('message-event-select') ? document.getElementById('message-event-select').value : '';
    if (!eventId) eventId = prompt('Enter Event ID:'); if (!eventId) return;
    var name = prompt('Resource name:'); if (!name) return;
    var type = prompt('Type (personnel/equipment/supplies):', 'personnel');
    var qty = prompt('Quantity:', '1');
    try { await apiRequest('/system?action=add-resource&event_id=' + eventId, { method: 'POST', body: JSON.stringify({ name: name, type: type, quantity: parseInt(qty) || 1 }) }); showToast('Resource added', 'success'); loadResources(eventId); } catch (err) { showToast('Failed: ' + err.message, 'error'); }
}

function loadPermissions() {
    var tbody = document.getElementById('permissions-body'); if (!tbody) return;
    var perms = [
        { name: 'View Events', roles: ['admin','responder','operator','viewer','victim'] },
        { name: 'Create Events', roles: ['admin','responder','operator'] },
        { name: 'Resolve Events', roles: ['admin','responder','operator'] },
        { name: 'View Alerts', roles: ['admin','responder','operator','viewer','victim'] },
        { name: 'Dispatch Alerts', roles: ['admin','responder'] },
        { name: 'Acknowledge Alerts', roles: ['admin','responder','operator'] },
        { name: 'Send Messages', roles: ['admin','responder','operator','viewer','victim'] },
        { name: 'View Distress Signals', roles: ['admin','responder','operator'] },
        { name: 'Respond to Distress', roles: ['admin','responder','operator'] },
        { name: 'Send SOS/Distress', roles: ['victim'] },
        { name: 'View Users', roles: ['admin','responder','operator'] },
        { name: 'Manage Users', roles: ['admin'] },
        { name: 'System Automation', roles: ['admin'] },
        { name: 'XML Tools', roles: ['admin'] },
        { name: 'View Audit Logs', roles: ['admin'] },
        { name: 'Admin Panel', roles: ['admin'] },
    ];
    var allRoles = ['admin','responder','operator','viewer','victim'];
    var html = '';
    for (var i = 0; i < perms.length; i++) {
        html += '<tr style="border-bottom:1px solid var(--border-color);"><td style="padding:10px 12px;font-weight:500;">' + perms[i].name + '</td>';
        for (var r = 0; r < allRoles.length; r++) {
            var has = perms[i].roles.indexOf(allRoles[r]) !== -1;
            html += '<td style="text-align:center;padding:10px 12px;">' + (has ? '<span style="color:var(--color-success);font-weight:700;">&#10003;</span>' : '<span style="color:var(--text-light);">&#8212;</span>') + '</td>';
        }
        html += '</tr>';
    }
    tbody.innerHTML = html;
}

async function showTimeline(eventId, eventTitle) {
    var nameEl = document.getElementById('timeline-event-name'); if (nameEl) nameEl.textContent = '- ' + escapeHtml(eventTitle);
    var panel = document.getElementById('event-timeline-panel'); if (panel) panel.classList.remove('hidden');
    var container = document.getElementById('timeline-list'); if (!container) return;
    container.innerHTML = '<p class="empty-state">Loading timeline...</p>';
    try {
        var data = await apiRequest('/system?action=timeline&event_id=' + eventId);
        var entries = data.timeline || [];
        if (entries.length === 0) { container.innerHTML = '<p class="empty-state">No timeline entries</p>'; return; }
        var html = '<div style="position:relative;padding-left:24px;border-left:2px solid var(--border-color);">';
        for (var i = 0; i < entries.length; i++) {
            var e = entries[i];
            html += '<div style="position:relative;margin-bottom:16px;padding-left:16px;">';
            html += '<div style="position:absolute;left:-29px;top:4px;width:12px;height:12px;border-radius:50%;background:var(--color-primary);border:2px solid var(--bg-primary);"></div>';
            html += '<div style="font-size:12px;color:var(--text-secondary);">' + formatDate(e.created_at) + '</div>';
            html += '<div style="font-weight:600;font-size:14px;">' + escapeHtml(e.action) + '</div>';
            if (e.description) html += '<div style="font-size:13px;color:var(--text-secondary);">' + escapeHtml(e.description) + '</div>';
            if (e.user_name) html += '<div style="font-size:11px;color:var(--text-light);">by ' + escapeHtml(e.user_name) + '</div>';
            html += '</div>';
        }
        html += '</div>';
        container.innerHTML = html;
    } catch (err) { container.innerHTML = '<p class="empty-state">Failed to load timeline</p>'; }
}

async function loadDashboardStats() {
    try {
        var data = await apiRequest('/events');
        var events = data.events || [];
        document.getElementById('stat-critical').textContent = events.filter(function (e) { return e.severity === 'critical' && e.status === 'active'; }).length;
        document.getElementById('stat-active').textContent = events.filter(function (e) { return e.status === 'active'; }).length;
        renderDashboardEvents(events);
        try { var alertData = await apiRequest('/alerts'); document.getElementById('stat-alerts').textContent = (alertData.alerts || []).filter(function (a) { return !a.is_acknowledged; }).length; } catch (e) {}
        try { var userData = await apiRequest('/users'); document.getElementById('stat-users').textContent = (userData.users || []).length; } catch (e) {}
        try { var hlData = await apiRequest('/hotlines'); renderDashboardHotlines(hlData.hotlines || []); } catch (e) {}
    } catch (err) { console.error('Failed to load dashboard stats:', err); }
}

function renderDashboardHotlines(hotlines) {
    var container = document.getElementById('dashboard-hotlines-list');
    if (!container) return;
    if (!hotlines || hotlines.length === 0) {
        container.innerHTML = '<p class="empty-state">No hotlines available.</p>';
        return;
    }
    var html = '';
    for (var i = 0; i < Math.min(hotlines.length, 4); i++) {
        var h = hotlines[i];
        var shortName = h.agency.replace(/\s*\(.*?\)/g, '').trim();
        if (shortName.length > 30) shortName = shortName.substring(0, 28) + '...';
        html += '<div class="hotline-strip-item" onclick="showPage(\'hotlines\')">';
        html += '<span class="hotline-strip-agency">' + escapeHtml(shortName) + '</span>';
        html += '<span class="hotline-strip-numbers">' + escapeHtml(h.numbers.join(' | ')) + '</span>';
        html += '</div>';
    }
    if (hotlines.length > 4) {
        html += '<div class="hotline-strip-item hotline-strip-more" onclick="showPage(\'hotlines\')">View all ' + hotlines.length + ' hotlines &rarr;</div>';
    }
    container.innerHTML = html;
}

function showProfileModal() {
    if (!currentUser) return;
    document.getElementById('profile-name').value = currentUser.display_name || '';
    document.getElementById('profile-email').value = currentUser.email || '';
    document.getElementById('profile-phone').value = currentUser.phone || '';
    document.getElementById('profile-avatar-input').value = '';
    document.getElementById('profile-avatar-name').textContent = '';
    var preview = document.getElementById('profile-avatar-preview');
    if (currentUser.avatar_url && currentUser.avatar_url.indexOf('/avatars/') === 0) {
        preview.src = currentUser.avatar_url;
        preview.classList.remove('hidden');
    } else {
        preview.classList.add('hidden');
    }
    document.getElementById('profile-emergency-name').value = currentUser.emergency_contact_name || '';
    document.getElementById('profile-emergency-phone').value = currentUser.emergency_contact_phone || '';
    document.getElementById('profile-current-pw').value = '';
    document.getElementById('profile-new-pw').value = '';
    showModal('profile-modal');
}

document.addEventListener('change', function(e) {
    if (e.target && e.target.id === 'profile-avatar-input') {
        var file = e.target.files[0];
        var nameSpan = document.getElementById('profile-avatar-name');
        var preview = document.getElementById('profile-avatar-preview');
        if (file) {
            nameSpan.textContent = file.name;
            var reader = new FileReader();
            reader.onload = function(ev) {
                preview.src = ev.target.result;
                preview.classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        } else {
            nameSpan.textContent = '';
            preview.classList.add('hidden');
        }
    }
});

async function saveProfile() {
    var name = document.getElementById('profile-name').value.trim();
    var email = document.getElementById('profile-email').value.trim();
    var phone = document.getElementById('profile-phone').value.trim();
    var currentPw = document.getElementById('profile-current-pw').value;
    var newPw = document.getElementById('profile-new-pw').value;
    var emergencyName = document.getElementById('profile-emergency-name').value.trim();
    var emergencyPhone = document.getElementById('profile-emergency-phone').value.trim();
    var avatarFile = document.getElementById('profile-avatar-input').files[0];
    if (!name) { showToast('Display name is required', 'error'); return; }

    try {
        var body = { display_name: name, email: email, phone: phone, emergency_contact_name: emergencyName, emergency_contact_phone: emergencyPhone };
        if (currentPw && newPw) { body.current_password = currentPw; body.new_password = newPw; }

        var data = await apiRequest('/auth?action=update_profile', { method: 'POST', body: JSON.stringify(body) });

        if (avatarFile) {
            var formData = new FormData();
            formData.append('avatar', avatarFile);
            var uploadResp = await fetch(API_BASE + '/auth?action=upload_avatar', {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + authToken },
                body: formData,
            });
            var uploadData = await uploadResp.json();
            if (uploadData.success) {
                data.user.avatar_url = uploadData.avatar_url;
            }
        }

        if (data.success) {
            currentUser = data.user;
            localStorage.setItem('ems_user', JSON.stringify(data.user));
            document.getElementById('user-name').textContent = currentUser.display_name;
            var badge = document.getElementById('user-badge');
            if (badge) {
                if (currentUser.avatar_url) {
                    badge.innerHTML = '<img src="' + currentUser.avatar_url + '" alt="Avatar">';
                } else {
                    badge.textContent = getInitials(currentUser.display_name);
                }
            }
            hideModal('profile-modal');
            showToast('Profile updated successfully', 'success');
        }
    } catch (err) { showToast('Failed to update profile: ' + err.message, 'error'); }
}

function showToast(message, type) {
    var toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'toast ' + (type || '');
    toast.classList.remove('hidden');
    setTimeout(function () { toast.classList.add('hidden'); }, 4000);
}

function showModal(id) { document.getElementById(id).classList.remove('hidden'); }
function hideModal(id) { document.getElementById(id).classList.add('hidden'); }

function showPage(pageId) {
    document.querySelectorAll('.page-content').forEach(function (p) { p.classList.remove('active'); });
    document.querySelectorAll('.side-link').forEach(function (l) { l.classList.remove('active'); });
    document.getElementById('page-' + pageId).classList.add('active');
    var navLink = document.querySelector('.side-link[data-page="' + pageId + '"]');
    if (navLink) navLink.classList.add('active');
    switch (pageId) {
        case 'events': loadEvents(); break;
        case 'messages': populateEventSelectors(); break;
        case 'alerts': loadAlerts(); break;
        case 'distress':
            startDistressPolling();
            loadDistressSignals();
            break;
        case 'livechat':
            startChatPolling();
            break;
        case 'hotlines': loadHotlines(); break;
        case 'admin': loadUsers(); loadAuditLogs(); populateXMLExportEvents(); break;
        default:
            stopDistressPolling();
            stopChatPolling();
            break;
    }
}

function showAdminTab(tabId) {
    document.querySelectorAll('.admin-panel').forEach(function (p) { p.classList.remove('active'); });
    document.querySelectorAll('.admin-tab').forEach(function (t) { t.classList.remove('active'); });
    document.getElementById('admin-' + tabId).classList.add('active');
    var adminTab = document.querySelector('.admin-tab[data-admin-tab="' + tabId + '"]');
    if (adminTab) adminTab.classList.add('active');
    switch (tabId) {
        case 'users': loadUsers(); break;
        case 'logs': loadAuditLogs(); break;
        case 'xml': populateXMLExportEvents(); break;
        case 'permissions': loadPermissions(); break;
    }
}

function showCreateEventModal() { showModal('create-event-modal'); }
function showCreateAlertModal() { populateAlertEvents(); showModal('create-alert-modal'); }

async function runHealthCheck() {
    var output = document.getElementById('system-output');
    output.textContent = 'Running health check...';
    try { var data = await apiRequest('/system?action=health'); output.textContent = JSON.stringify(data, null, 2); }
    catch (err) { output.textContent = 'Error: ' + err.message; }
}

async function generateReport() {
    var output = document.getElementById('system-output');
    output.textContent = 'Generating daily report...';
    try { var data = await apiRequest('/system?action=report'); output.textContent = JSON.stringify(data, null, 2); }
    catch (err) { output.textContent = 'Error: ' + err.message; }
}

async function runArchive() {
    var output = document.getElementById('system-output');
    output.textContent = 'Archiving resolved events...';
    try { var data = await apiRequest('/system?action=archive&days=7'); output.textContent = JSON.stringify(data, null, 2); loadEvents(); loadDashboardStats(); }
    catch (err) { output.textContent = 'Error: ' + err.message; }
}

async function runEscalate() {
    var output = document.getElementById('system-output');
    output.textContent = 'Escalating unattended alerts...';
    try { var data = await apiRequest('/system?action=escalate&minutes=30'); output.textContent = JSON.stringify(data, null, 2); loadAlerts(); }
    catch (err) { output.textContent = 'Error: ' + err.message; }
}

async function retryDeadLetter() {
    var output = document.getElementById('system-output');
    output.textContent = 'Retrying dead letter messages...';
    try { var data = await apiRequest('/system?action=retry-dead-letter'); output.textContent = JSON.stringify(data, null, 2); }
    catch (err) { output.textContent = 'Error: ' + err.message; }
}

async function loadResources(eventId) {
    if (!eventId) return;
    try { var data = await apiRequest('/events?id=' + eventId); var ev = data.event; document.getElementById('resource-event-name').textContent = '- ' + (ev ? ev.title : ''); } catch (e) {}
    document.getElementById('event-resources-panel').classList.remove('hidden');
    var container = document.getElementById('resources-list');
    container.innerHTML = '<p class="empty-state">Loading resources...</p>';
    try {
        var data = await apiRequest('/system?action=resources&event_id=' + eventId);
        var resources = data.resources || [];
        if (resources.length === 0) { container.innerHTML = '<p class="empty-state">No resources assigned to this event</p>'; return; }
        var html = '';
        for (var i = 0; i < resources.length; i++) {
            var r = resources[i];
            html += '<div class="event-card" style="cursor:default;">';
            html += '<div class="event-card-header"><span class="event-severity ' + r.type + '">' + r.type + '</span>';
            html += '<span class="event-card-title">' + escapeHtml(r.name) + '</span>';
            html += '<span class="event-status ' + r.status + '">' + r.status + '</span></div>';
            html += '<div class="event-card-meta"><span>Qty: ' + r.quantity + '</span>';
            if (r.notes) html += '<span>' + escapeHtml(r.notes) + '</span>';
            html += '</div></div>';
        }
        container.innerHTML = html;
    } catch (err) { container.innerHTML = '<p class="empty-state">Failed to load resources</p>'; }
}

async function showAddResourceModal() {
    var eventId = document.getElementById('message-event-select').value || prompt('Enter Event ID:');
    if (!eventId) return;
    var name = prompt('Resource name:');
    if (!name) return;
    var type = prompt('Type (personnel/equipment/supplies):', 'personnel');
    var qty = prompt('Quantity:', '1');
    try {
        await apiRequest('/system?action=add-resource&event_id=' + eventId, { method: 'POST', body: JSON.stringify({ name: name, type: type, quantity: parseInt(qty) || 1 }) });
        showToast('Resource added', 'success');
        loadResources(eventId);
    } catch (err) { showToast('Failed: ' + err.message, 'error'); }
}

var eventMap = null;

function showEventMap() {
    var container = document.getElementById('map-container');
    container.classList.remove('hidden');
    setTimeout(function() {
        if (eventMap) eventMap.remove();
        eventMap = L.map('event-map').setView([14.5, 121], 10);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: ' OpenStreetMap' }).addTo(eventMap);
        apiRequest('/events').then(function(data) {
            var events = data.events || [];
            events.forEach(function(e) {
                if (e.location) {
                    L.marker([14.5 + Math.random()*0.1, 121 + Math.random()*0.1])
                        .addTo(eventMap)
                        .bindPopup('<b>' + escapeHtml(e.title) + '</b><br>' + escapeHtml(e.location) + '<br>Severity: ' + e.severity);
                }
            });
        }).catch(function(){});
        setTimeout(function(){ eventMap.invalidateSize(); }, 300);
    }, 100);
}

function printReport() {
    apiRequest('/events').then(function(data) {
        var events = data.events || [];
        var w = window.open('', '_blank');
        w.document.write('<html><head><title>Emergency Report</title>');
        w.document.write('<style>body{font-family:Arial;padding:40px;}h1{color:#1e293b;}table{width:100%;border-collapse:collapse;margin-top:20px;}th,td{padding:10px;text-align:left;border-bottom:1px solid #ddd;}th{background:#f1f5f9;}.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;}.critical{background:#dc2626;color:white;}.high{background:#ea580c;color:white;}.medium{background:#ca8a04;color:white;}.low{background:#64748b;color:white;}.active{color:#16a34a;}.resolved{color:#64748b;}</style></head><body>');
        w.document.write('<h1>Emergency Incident Report</h1>');
        w.document.write('<p>Generated: ' + new Date().toLocaleString() + '</p>');
        w.document.write('<p>Total Events: ' + events.length + '</p>');
        w.document.write('<table><thead><tr><th>Title</th><th>Severity</th><th>Status</th><th>Location</th><th>Created</th></tr></thead><tbody>');
        events.forEach(function(e) {
            w.document.write('<tr><td>' + escapeHtml(e.title) + '</td><td><span class="badge ' + e.severity + '">' + e.severity + '</span></td><td class="' + e.status + '">' + e.status + '</td><td>' + (e.location || 'N/A') + '</td><td>' + (e.created_at || '') + '</td></tr>');
        });
        w.document.write('</tbody></table></body></html>');
        w.document.close();
        setTimeout(function(){ w.print(); }, 500);
    }).catch(function(err) { showToast('Failed to generate report: ' + err.message, 'error'); });
}

async function loadAuditLogs() {
    try { var data = await apiRequest('/system?action=logs&limit=100'); renderLogs(data.logs || []); }
    catch (err) { showToast('Failed to load logs: ' + err.message, 'error'); }
}

async function exportMessagesXML() {
    var eventId = document.getElementById('xml-export-event').value;
    if (!eventId) { showToast('Select an event to export', 'error'); return; }
    try {
        var xml = await apiRequest('/system?action=export-xml&event_id=' + eventId);
        var blob = new Blob([xml], { type: 'application/xml' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = 'event_' + eventId + '_export.xml'; a.click();
        URL.revokeObjectURL(url);
        showToast('XML exported successfully', 'success');
    } catch (err) { showToast('Export failed: ' + err.message, 'error'); }
}

async function importMessagesXML() {
    var xml = document.getElementById('xml-import-input').value.trim();
    if (!xml) { showToast('Paste XML data to import', 'error'); return; }
    try {
        var data = await apiRequest('/system?action=import-xml', { method: 'POST', body: xml, headers: { 'Content-Type': 'application/xml' } });
        document.getElementById('xml-import-result').innerHTML = '<p class="success-message">Imported ' + (data.imported_count || 0) + ' messages successfully.</p>';
        showToast('XML imported: ' + (data.imported_count || 0) + ' messages', 'success');
    } catch (err) { document.getElementById('xml-import-result').innerHTML = '<p class="error-message">Import failed: ' + err.message + '</p>'; }
}
