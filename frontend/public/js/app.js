function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    try { var d = new Date(dateStr); return d.toLocaleString(); } catch (e) { return dateStr; }
}

function getInitials(name) {
    if (!name) return '?';
    return name.charAt(0).toUpperCase();
}

function renderLogs(logs) {
    var container = document.getElementById('audit-logs-list');
    if (!logs || logs.length === 0) {
        container.innerHTML = '<p class="empty-state">No audit log entries found</p>';
        return;
    }
    var html = '';
    for (var i = 0; i < logs.length; i++) {
        var log = logs[i];
        html += '<div class="log-entry">';
        html += '<div><span class="log-entry-time">' + formatDate(log.created_at) + '</span> - ';
        html += '<span class="log-entry-user">' + escapeHtml(log.user_name || 'System') + '</span> - ';
        html += '<span class="log-entry-action">' + escapeHtml(log.action) + '</span></div>';
        if (log.details) html += '<div class="log-entry-details">' + escapeHtml(log.details) + '</div>';
        if (log.ip_address) html += '<div class="log-entry-details">IP: ' + escapeHtml(log.ip_address) + '</div>';
        html += '</div>';
    }
    container.innerHTML = html;
}

function populateXMLExportEvents() {
    var select = document.getElementById('xml-export-event');
    apiRequest('/events').then(function(data) {
        var events = data.events || [];
        select.innerHTML = '<option value="">Select event to export</option>';
        for (var i = 0; i < events.length; i++) {
            var option = document.createElement('option');
            option.value = events[i].id;
            option.textContent = '[' + events[i].severity.toUpperCase() + '] ' + events[i].title;
            select.appendChild(option);
        }
    }).catch(function(err) { console.error('Failed to populate XML export events:', err); });
}

function updateUIForRole() {
    if (!currentUser) return;
    var userBadge = document.getElementById('user-badge');
    var userName = document.getElementById('user-name');
    var userRole = document.getElementById('user-role');

    userBadge.textContent = getInitials(currentUser.display_name);
    if (currentUser.avatar_url) {
        userBadge.innerHTML = '<img src="' + escapeHtml(currentUser.avatar_url) + '" alt="Avatar">';
    }
    userName.textContent = currentUser.display_name;
    userRole.textContent = currentUser.role;

    var role = currentUser.role;
    var isVictim = role === 'victim';
    var isProvider = role === 'responder' || role === 'operator';
    var isAdmin = role === 'admin';
    var canCreateEvent = isAdmin || isProvider;
    var canDispatchAlert = isAdmin || role === 'responder';
    var canAccessAdmin = isAdmin;

    var createBtn = document.getElementById('create-event-btn');
    if (createBtn) createBtn.classList.toggle('hidden', !canCreateEvent);
    var alertBtn = document.getElementById('create-alert-btn');
    if (alertBtn) alertBtn.classList.toggle('hidden', !canDispatchAlert);
    var distressBtn = document.getElementById('send-distress-btn');
    if (distressBtn) distressBtn.classList.toggle('hidden', !isVictim);
    var sosBtn = document.getElementById('sos-btn');
    if (sosBtn) sosBtn.classList.toggle('hidden', !isVictim);

    var adminNav = document.querySelector('.side-link[data-page="admin"]');
    if (adminNav) adminNav.classList.toggle('hidden', !canAccessAdmin);
    var distressNav = document.querySelector('.side-link[data-page="distress"]');
    if (distressNav) distressNav.classList.remove('hidden');
}

async function initApp() {
    var hasSession = await checkSession();
    if (hasSession && currentUser) {
        showApp();
    } else {
        var callbackSuccess = await handleGoogleCallback();
        if (callbackSuccess) showApp();
    }
}

function showApp() {
    document.getElementById('login-page').classList.add('hidden');
    document.getElementById('main-app').classList.remove('hidden');

    var savedTheme = localStorage.getItem('ems_theme') || 'light';
    if (currentUser && currentUser.theme_preference) savedTheme = currentUser.theme_preference;
    document.documentElement.classList.add(savedTheme === 'dark' ? 'dark-mode' : 'light-mode');
    if (savedTheme === 'dark') {
        document.querySelector('.theme-icon-light').classList.add('hidden');
        document.querySelector('.theme-icon-dark').classList.remove('hidden');
    }

    updateUIForRole();
    loadDashboardStats();
    loadAnnouncements();
    connectWebSocket();
    showPage('dashboard');
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initApp);
} else {
    initApp();
}
