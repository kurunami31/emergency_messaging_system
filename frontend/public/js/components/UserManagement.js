var ROLE_HIERARCHY = ['victim', 'viewer', 'operator', 'responder', 'admin'];

function renderUsers(users) {
    var container = document.getElementById('users-list');
    if (!users || users.length === 0) {
        container.innerHTML = '<p class="empty-state">No users found</p>';
        return;
    }
    var html = '';
    var isAdmin = currentUser && currentUser.role === 'admin';
    for (var i = 0; i < users.length; i++) {
        var u = users[i];
        var initials = (u.display_name || '?').charAt(0).toUpperCase();
        html += '<div class="user-card">';
        html += '<div class="user-card-avatar">' + initials + '</div>';
        html += '<div class="user-card-info">';
        html += '<div class="user-card-name">' + escapeHtml(u.display_name) + '</div>';
        html += '<div class="user-card-email">' + escapeHtml(u.email) + '</div>';
        html += '</div>';
        if (isAdmin) {
            html += '<select class="user-card-role-select" onchange="updateUserRole(' + u.id + ', this.value)">';
            for (var r = 0; r < ROLE_HIERARCHY.length; r++) {
                var selected = u.role === ROLE_HIERARCHY[r] ? ' selected' : '';
                html += '<option value="' + ROLE_HIERARCHY[r] + '"' + selected + '>' + ROLE_HIERARCHY[r] + '</option>';
            }
            html += '</select>';
            html += '<span class="status-indicator" style="margin-left:8px;">' + (u.is_active ? 'Active' : 'Inactive') + '</span>';
        } else {
            html += '<span class="user-role" style="position:static;color:var(--text-secondary);">' + u.role + '</span>';
        }
        html += '</div>';
    }
    container.innerHTML = html;
}
