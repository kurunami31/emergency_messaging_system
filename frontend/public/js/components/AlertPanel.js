function renderAlerts(alerts) {
    var container = document.getElementById('alerts-list');
    if (!alerts || alerts.length === 0) {
        container.innerHTML = '<p class="empty-state">No alerts found</p>';
        return;
    }
    var html = '';
    var filter = document.getElementById('alert-filter').value;
    for (var i = 0; i < alerts.length; i++) {
        var a = alerts[i];
        if (filter === 'active' && a.is_acknowledged) continue;
        var acknowledgedClass = a.is_acknowledged ? 'acknowledged' : 'unacknowledged';
        var canAck = currentUser && (currentUser.role === 'admin' || currentUser.role === 'responder' || currentUser.role === 'operator');
        var ackButtonHtml = '';
        if (!a.is_acknowledged && canAck) {
            ackButtonHtml = '<button class="btn btn-small btn-primary" onclick="acknowledgeAlert(' + a.id + ')">Acknowledge</button>';
        }
        html += '<div class="alert-card ' + acknowledgedClass + '">';
        html += '<div class="alert-card-header">';
        html += '<span class="alert-type-badge">' + escapeHtml(a.type) + '</span>';
        html += '<span class="alert-card-title">' + escapeHtml(a.title) + '</span>';
        html += ackButtonHtml;
        html += '</div>';
        html += '<div class="alert-card-message">' + escapeHtml(a.message) + '</div>';
        html += '<div class="alert-card-meta">';
        html += '<span>Event: ' + escapeHtml(a.event_title || 'Unknown') + '</span>';
        html += '<span>Target: ' + a.target_role + '</span>';
        html += '<span>Created: ' + formatDate(a.created_at) + '</span>';
        if (a.acknowledged_by_name) html += '<span>Acknowledged by: ' + escapeHtml(a.acknowledged_by_name) + '</span>';
        html += '</div></div>';
    }
    if (!html) {
        container.innerHTML = '<p class="empty-state">No alerts match the current filter</p>';
    } else {
        container.innerHTML = html;
    }
}

function populateAlertEvents() {
    var select = document.getElementById('alert-event');
    apiRequest('/events').then(function(data) {
        var events = data.events || [];
        select.innerHTML = '<option value="">Select event</option>';
        for (var i = 0; i < events.length; i++) {
            var option = document.createElement('option');
            option.value = events[i].id;
            option.textContent = '[' + events[i].severity.toUpperCase() + '] ' + events[i].title;
            select.appendChild(option);
        }
    });
}
