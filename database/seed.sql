USE emergency_messaging_system;

INSERT IGNORE INTO users (google_id, email, display_name, role, is_active)
VALUES ('admin-placeholder', 'admin@emergency.local', 'System Administrator', 'admin', 1);

INSERT INTO emergency_events (title, severity, description, status, created_by, created_at)
VALUES (
    'System Test Event',
    'low',
    'This is a test event to verify system functionality.',
    'active',
    1,
    NOW()
);

INSERT INTO alerts (event_id, type, target_role, title, message, created_at)
VALUES (
    1,
    'test',
    'all',
    'System Test Alert',
    'This is a test alert. No action required.',
    NOW()
);

INSERT INTO messages (event_id, sender_id, content, message_type, priority, created_at)
VALUES (1, 1, 'Emergency event created. Monitoring situation.', 'text', 'normal', NOW());

INSERT INTO messages (event_id, sender_id, content, message_type, priority, created_at)
VALUES (1, 1, 'All systems operational. Stand by for further updates.', 'system', 'normal', NOW());
