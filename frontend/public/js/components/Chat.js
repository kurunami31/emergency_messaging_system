let chatPollInterval = null;
let lastChatMessageTime = null;
let isChatScrolledToBottom = true;

function startChatPolling() {
    stopChatPolling();
    loadChatMessages();
    chatPollInterval = setInterval(loadChatMessages, 3000);
}

function stopChatPolling() {
    if (chatPollInterval) {
        clearInterval(chatPollInterval);
        chatPollInterval = null;
    }
}

async function loadChatMessages() {
    try {
        var url = '/chat';
        var isFullLoad = !lastChatMessageTime;
        if (lastChatMessageTime) url += '?since=' + encodeURIComponent(lastChatMessageTime);
        var data = await apiRequest(url);
        var messages = data.messages || [];
        if (isFullLoad) {
            var container = document.getElementById('chat-messages');
            if (container) container.innerHTML = '';
            if (messages.length === 0) {
                var el = document.createElement('p');
                el.className = 'empty-state';
                el.textContent = 'No messages yet. Start the conversation!';
                document.getElementById('chat-messages').appendChild(el);
            }
        }
        if (messages.length > 0) {
            lastChatMessageTime = messages[messages.length - 1].created_at;
            renderChatMessages(messages);
        }
    } catch (err) {
        if (!lastChatMessageTime) {
            var container = document.getElementById('chat-messages');
            if (container) container.innerHTML = '<p class="empty-state">Failed to load chat. Make sure the server is running.</p>';
        }
    }
}

async function sendChatMessage() {
    var input = document.getElementById('chat-input');
    var content = input.value.trim();
    if (!content) return;
    input.value = '';
    input.style.height = 'auto';
    try {
        await apiRequest('/chat', { method: 'POST', body: JSON.stringify({ content: content }) });
        lastChatMessageTime = null;
        await loadChatMessages();
    } catch (err) {
        showToast('Failed to send message: ' + err.message, 'error');
    }
}

function renderChatMessages(messages) {
    var container = document.getElementById('chat-messages');
    if (!container) return;
    var shouldScroll = isChatScrolledToBottom || !lastChatMessageTime;
    for (var i = 0; i < messages.length; i++) {
        var msg = messages[i];
        var existing = container.querySelector('[data-msg-id="' + msg.id + '"]');
        if (existing) continue;
        var isMine = currentUser && msg.sender_id == currentUser.id;
        var div = document.createElement('div');
        div.className = 'chat-msg' + (isMine ? ' chat-msg-mine' : '') + (msg.message_type === 'system' ? ' chat-msg-system' : '');
        div.setAttribute('data-msg-id', msg.id);
        var sender = msg.sender_name || 'System';
        var time = formatDate(msg.created_at);
        div.innerHTML = '<div class="chat-msg-header"><span class="chat-msg-sender">' + escapeHtml(sender) + '</span><span class="chat-msg-time">' + time + '</span></div><div class="chat-msg-content">' + escapeHtml(msg.content) + '</div>';
        container.appendChild(div);
    }
    if (shouldScroll) {
        container.scrollTop = container.scrollHeight;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var chatContainer = document.getElementById('chat-messages');
    if (chatContainer) {
        chatContainer.addEventListener('scroll', function () {
            isChatScrolledToBottom = (chatContainer.scrollTop + chatContainer.clientHeight >= chatContainer.scrollHeight - 20);
        });
    }
});
