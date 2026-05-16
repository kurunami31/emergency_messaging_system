async function loadHotlines() {
    try {
        var data = await apiRequest('/hotlines');
        renderHotlines(data.hotlines || []);
    } catch (err) {
        var container = document.getElementById('hotlines-list');
        if (container) container.innerHTML = '<p class="empty-state">Failed to load hotlines: ' + err.message + '</p>';
    }
}

function renderHotlines(hotlines) {
    var container = document.getElementById('hotlines-list');
    if (!container) return;
    if (!hotlines || hotlines.length === 0) {
        container.innerHTML = '<p class="empty-state">No hotlines available.</p>';
        return;
    }
    var html = '';
    for (var i = 0; i < hotlines.length; i++) {
        var h = hotlines[i];
        html += '<div class="hotline-card">';
        html += '<div class="hotline-card-header">';
        html += '<span class="hotline-category">' + escapeHtml(h.category) + '</span>';
        html += '<span class="hotline-agency">' + escapeHtml(h.agency) + '</span>';
        html += '</div>';
        html += '<div class="hotline-numbers">';
        for (var j = 0; j < h.numbers.length; j++) {
            var num = h.numbers[j];
            var cleanNum = num.replace(/[^0-9]/g, '');
            html += '<a href="tel:' + cleanNum + '" class="hotline-number">' + escapeHtml(num) + '</a>';
        }
        html += '</div>';
        html += '</div>';
    }
    container.innerHTML = html;
}
