function populateEventSelectors() {
    var selects = ['message-event-select', 'alert-event', 'xml-export-event'];
    apiRequest('/events').then(function (data) {
        var events = data.events || [];
        for (var s = 0; s < selects.length; s++) {
            var select = document.getElementById(selects[s]);
            if (!select) continue;
            var currentValue = select.value;
            select.innerHTML = '';
            var defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = selects[s] === 'alert-event' ? 'Select event for alert' : selects[s] === 'xml-export-event' ? 'Select event to export' : 'Select an event to view messages';
            select.appendChild(defaultOption);
            for (var i = 0; i < events.length; i++) {
                if (events[i].title === 'Live Chat') continue;
                var option = document.createElement('option');
                option.value = events[i].id;
                option.textContent = (events[i].severity ? '[' + events[i].severity.toUpperCase() + '] ' : '') + events[i].title;
                select.appendChild(option);
            }
            if (currentValue) select.value = currentValue;
        }
    }).catch(function (err) { console.error('Failed to populate selectors:', err); });
}

function renderMessages(messages) {
    var board = document.getElementById('message-board');
    if (!messages || messages.length === 0) {
        board.innerHTML = '<p class="empty-state">No messages yet. Send the first message.</p>';
        return;
    }
    var html = '';
    for (var i = 0; i < messages.length; i++) {
        html += getMessageHTML(messages[i]);
    }
    board.innerHTML = html;
    board.scrollTop = board.scrollHeight;
}

function getMessageHTML(msg) {
    var priorityClass = msg.priority || 'normal';
    var senderLabel = msg.message_type === 'system' ? 'System' : (msg.sender_name || 'System');
    var badgeHtml = '';
    if (msg.priority && msg.priority !== 'normal' || msg.message_type === 'system') {
        var badgeText = msg.message_type === 'system' ? 'SYSTEM' : msg.priority.toUpperCase();
        badgeHtml = '<span class="message-badge ' + (msg.message_type === 'system' ? 'system' : priorityClass) + '">' + badgeText + '</span>';
    }
    return '<div class="message-item ' + priorityClass + '">' +
        '<div class="message-meta">' +
        '<span class="message-sender">' + escapeHtml(senderLabel) + '</span>' + badgeHtml +
        '<span class="message-time">' + formatDate(msg.created_at) + '</span>' +
        '</div>' +
        '<div class="message-content">' + escapeHtml(msg.content) + '</div>' +
        '</div>';
}

function sendUrgentCommand(eventId, command) {
    apiRequest('/messages?event_id=' + eventId, { method: 'POST', body: JSON.stringify({ content: command, priority: 'urgent' }) })
        .then(function () { loadMessages(); })
        .catch(function (err) { showToast('Failed to send command: ' + err.message, 'error'); });
}
