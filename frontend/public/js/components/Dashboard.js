function renderDashboardEvents(events) {
    var container = document.getElementById('dashboard-events-list');
    var activeEvents = events.filter(function(e) { return e.status === 'active'; });
    if (activeEvents.length === 0) {
        container.innerHTML = '<p class="empty-state">No active events</p>';
        return;
    }
    var html = '';
    for (var i = 0; i < activeEvents.length; i++) {
        var e = activeEvents[i];
        html += '<div class="event-card" onclick="showPage(\'events\')">';
        html += '<div class="event-card-header">';
        html += '<span class="event-severity ' + e.severity + '">' + e.severity + '</span>';
        html += '<span class="event-card-title">' + escapeHtml(e.title) + '</span>';
        html += '<span class="event-status ' + e.status + '">' + e.status + '</span>';
        html += '</div>';
        html += '<div class="event-card-meta">';
        html += '<span>Created: ' + formatDate(e.created_at) + '</span>';
        if (e.location) html += '<span>Location: ' + escapeHtml(e.location) + '</span>';
        html += '<span>By: ' + escapeHtml(e.created_by_name || 'Unknown') + '</span>';
        html += '</div></div>';
    }
    container.innerHTML = html;
}

function renderEvents(events) {
    var container = document.getElementById('events-list');
    if (!events || events.length === 0) {
        container.innerHTML = '<p class="empty-state">No events found</p>';
        return;
    }
    var html = '';
    for (var i = 0; i < events.length; i++) {
        var e = events[i];
        var canResolve = currentUser && (currentUser.role === 'admin' || currentUser.role === 'responder' || currentUser.role === 'operator');
        var resolveBtn = (e.status === 'active' && canResolve)
            ? '<button class="btn btn-small btn-outline" onclick="event.stopPropagation();resolveEvent(' + e.id + ')">Resolve</button>'
            : '';
        html += '<div class="event-card" onclick="loadEventMessages(' + e.id + ',\'' + escapeHtml(e.title) + '\')">';
        html += '<div class="event-card-header">';
        html += '<span class="event-severity ' + e.severity + '">' + e.severity + '</span>';
        html += '<span class="event-card-title">' + escapeHtml(e.title) + '</span>';
        html += '<span class="event-status ' + e.status + '">' + e.status + '</span>';
        html += resolveBtn;
        html += '</div>';
        if (e.description) html += '<p style="font-size:13px;color:var(--text-secondary);margin-bottom:8px;">' + escapeHtml(e.description) + '</p>';
        html += '<div class="event-card-meta">';
        html += '<span>Severity: ' + e.severity + '</span>';
        if (e.location) html += '<span>Location: ' + escapeHtml(e.location) + '</span>';
        html += '<span>Created: ' + formatDate(e.created_at) + '</span>';
        html += '<span>By: ' + escapeHtml(e.created_by_name || 'Unknown') + '</span>';
        html += '</div></div>';
    }
    container.innerHTML = html;
}

function loadEventMessages(eventId, eventTitle) {
    var messageSelect = document.getElementById('message-event-select');
    messageSelect.value = eventId;
    showPage('messages');
    var pageHeader = document.querySelector('#page-messages .page-header h1');
    if (pageHeader) pageHeader.textContent = 'Messages - ' + eventTitle;
    loadMessages();
}
