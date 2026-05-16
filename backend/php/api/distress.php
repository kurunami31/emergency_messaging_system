<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Models\BaseModel;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Alert;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user = AuthMiddleware::requireAuth();
$auditLog = new AuditLog();
$method = $_SERVER['REQUEST_METHOD'];
$signalId = isset($_GET['id']) ? (int)$_GET['id'] : null;

class DistressModel extends BaseModel
{
    protected string $table = 'distress_signals';

    public function getActiveSignals(): array
    {
        $stmt = $this->db->prepare(
            "SELECT ds.*, u.display_name as victim_name, u.phone as victim_phone,
                    u.emergency_contact_name as victim_emergency_name, u.emergency_contact_phone as victim_emergency_phone,
                    a.display_name as assigned_name
             FROM {$this->table} ds JOIN users u ON ds.victim_id = u.id
             LEFT JOIN users a ON ds.assigned_to = a.id
             WHERE ds.status = 'active' ORDER BY ds.created_at DESC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getSignalsByVictim(int $victimId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ds.*, u.display_name as victim_name, u.phone as victim_phone,
                    u.emergency_contact_name as victim_emergency_name, u.emergency_contact_phone as victim_emergency_phone,
                    a.display_name as assigned_name
             FROM {$this->table} ds JOIN users u ON ds.victim_id = u.id
             LEFT JOIN users a ON ds.assigned_to = a.id
             WHERE ds.victim_id = :vid ORDER BY ds.created_at DESC"
        );
        $stmt->execute([':vid' => $victimId]);
        return $stmt->fetchAll();
    }

    public function getSignalById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT ds.*, u.display_name as victim_name, u.phone as victim_phone,
                    u.emergency_contact_name as victim_emergency_name, u.emergency_contact_phone as victim_emergency_phone,
                    a.display_name as assigned_name
             FROM {$this->table} ds JOIN users u ON ds.victim_id = u.id
             LEFT JOIN users a ON ds.assigned_to = a.id
             WHERE ds.id = :id"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function createSignal(int $victimId, string $title, ?string $description, ?string $location, ?int $eventId): int
    {
        return $this->create(['victim_id' => $victimId, 'event_id' => $eventId, 'title' => $title, 'description' => $description, 'location' => $location, 'status' => 'active']);
    }

    public function assignResponder(int $id, int $responderId): bool { return $this->update($id, ['assigned_to' => $responderId, 'status' => 'responded']); }
    public function resolve(int $id): bool { return $this->update($id, ['status' => 'resolved']); }
}

$distressModel = new DistressModel();

switch ($method) {
    case 'GET':
        if ($signalId) {
            $signal = $distressModel->getSignalById($signalId);
            if (!$signal) { http_response_code(404); echo json_encode(['error' => 'Distress signal not found']); exit; }
            echo json_encode(['signal' => $signal]);
        } elseif ($user['role'] === User::ROLE_VICTIM) {
            echo json_encode(['signals' => $distressModel->getSignalsByVictim($user['id'])]);
        } else {
            echo json_encode(['signals' => $distressModel->getActiveSignals()]);
        }
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title'] ?? '');
        if (!$title) { http_response_code(400); echo json_encode(['error' => 'Title is required']); exit; }
        if ($user['role'] !== User::ROLE_VICTIM) { http_response_code(403); echo json_encode(['error' => 'Only victims can send distress signals']); exit; }

        $eventId = isset($input['event_id']) ? (int)$input['event_id'] : null;
        $signalId = $distressModel->createSignal($user['id'], $title, trim($input['description'] ?? ''), trim($input['location'] ?? ''), $eventId);
        $signal = $distressModel->getSignalById($signalId);

        if ($eventId) {
            $alert = new Alert();
            $alert->createAlert($eventId, 'test', 'all', "Distress: $title", "Victim {$user['display_name']} sent a distress signal: $title" . (!empty($input['location']) ? " at {$input['location']}" : ''));
        }

        $contactNotified = '';
        if (!empty($user['emergency_contact_name'])) {
            $auditLog->logAction($user['id'], 'emergency_contact_notified', "Emergency contact {$user['emergency_contact_name']} notified for distress signal #$signalId");
            $auditLog->logAction($user['id'], 'email_simulated', "Simulated email/SMS to {$user['emergency_contact_name']} ({$user['emergency_contact_phone']}) for distress signal #{$signalId}");
        }
        $auditLog->logAction($user['id'], 'distress_sent', "Distress signal #$signalId: $title");

        http_response_code(201);
        echo json_encode(['signal' => $signal, 'emergency_contact_notified' => !empty($user['emergency_contact_name'])]);
        break;

    case 'PUT':
        if (!$signalId) { http_response_code(400); echo json_encode(['error' => 'Signal ID required']); exit; }
        $signal = $distressModel->getSignalById($signalId);
        if (!$signal) { http_response_code(404); echo json_encode(['error' => 'Distress signal not found']); exit; }

        $input = json_decode(file_get_contents('php://input'), true);

        if ($user['role'] === User::ROLE_VICTIM && $signal['victim_id'] == $user['id']) {
            if (isset($input['status']) && $input['status'] === 'resolved') {
                $distressModel->resolve($signalId);
                $auditLog->logAction($user['id'], 'distress_resolved', "Victim resolved distress signal #$signalId");
            }
        } elseif ($user['role'] !== User::ROLE_VICTIM) {
            if (isset($input['status'])) {
                if ($input['status'] === 'responded') {
                    $distressModel->assignResponder($signalId, $user['id']);
                    $auditLog->logAction($user['id'], 'distress_responded', "Provider responded to distress signal #$signalId");
                } elseif ($input['status'] === 'resolved') {
                    $distressModel->resolve($signalId);
                    $auditLog->logAction($user['id'], 'distress_resolved', "Provider resolved distress signal #$signalId");
                }
            }
        } else {
            http_response_code(403); echo json_encode(['error' => 'Not authorized to update this signal']); exit;
        }

        echo json_encode(['signal' => $distressModel->getSignalById($signalId)]);
        break;
}
