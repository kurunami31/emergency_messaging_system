function renderDistressSignals(signals) {
    var container = document.getElementById('distress-list');
    if (!signals || signals.length === 0) {
        var isVictim = currentUser && currentUser.role === 'victim';
        container.innerHTML = '<p class="empty-state">' + (isVictim ? 'No distress signals sent. Click "Send Distress Signal" if you need help.' : 'No active distress signals from victims.') + '</p>';
        return;
    }
    var html = '';
    for (var i = 0; i < signals.length; i++) {
        var s = signals[i];
        var isVictim = currentUser && currentUser.role === 'victim';
        var isProvider = currentUser && (currentUser.role === 'responder' || currentUser.role === 'operator' || currentUser.role === 'admin');
        var statusClass = s.status;
        var statusLabel = s.status.charAt(0).toUpperCase() + s.status.slice(1);
        var actionHtml = '';
        if (isProvider && s.status === 'active') {
            actionHtml = '<button class="btn btn-small btn-primary" onclick="respondToDistress(' + s.id + ')">Respond</button>';
        }
        if (s.status === 'responded' && (s.assigned_to == currentUser.id || isVictim && s.victim_id == currentUser.id || isProvider)) {
            actionHtml += '<button class="btn btn-small btn-outline" onclick="resolveDistressSignal(' + s.id + ')" style="margin-left:4px;">Resolve</button>';
        }
        var assignedInfo = '';
        if (s.assigned_name) assignedInfo = '<span>Responder: ' + escapeHtml(s.assigned_name) + '</span>';

        html += '<div class="distress-card ' + statusClass + '">';
        html += '<div class="distress-card-header">';
        html += '<span class="distress-status ' + statusClass + '">' + statusLabel + '</span>';
        html += '<span class="distress-card-title">' + escapeHtml(s.title) + '</span>';
        html += actionHtml;
        html += '</div>';
        if (s.description) html += '<p class="distress-card-desc">' + escapeHtml(s.description) + '</p>';
        html += '<div class="distress-card-meta">';
        html += '<span>From: ' + escapeHtml(s.victim_name) + '</span>';
        if (s.victim_phone) html += '<span>Phone: ' + escapeHtml(s.victim_phone) + '</span>';
        if (s.location) html += '<span>Location: ' + escapeHtml(s.location) + '</span>';
        if (s.victim_emergency_name && !isVictim) {
            html += '<span>Emergency Contact: ' + escapeHtml(s.victim_emergency_name) + (s.victim_emergency_phone ? ' (' + escapeHtml(s.victim_emergency_phone) + ')' : '') + '</span>';
        }
        if (assignedInfo) html += assignedInfo;
        html += '<span>Sent: ' + formatDate(s.created_at) + '</span>';
        html += '</div></div>';
    }
    container.innerHTML = html;
}
