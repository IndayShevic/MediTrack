<?php
// Database connection helper
// Adjust credentials to match your XAMPP MySQL setup

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_NAME = getenv('DB_NAME') ?: 'meditrack';
$DB_USER = getenv('DB_USER') ?: 'shev';
$DB_PASS = getenv('DB_PASS') ?: 'shev';
$DB_CHARSET = 'utf8mb4';

function db(): PDO {
    static $pdo = null;
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    try {
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Database connection failed.';
        exit;
    }
    return $pdo;
}

function public_base_path(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    $pos = strpos($script, '/public/');
    if ($pos !== false) {
        return substr($script, 0, $pos + 8); // include '/public/'
    }
    // If script is exactly under /public (e.g., /public/index.php)
    if (substr($script, -11) === '/index.php' && str_contains($script, '/public')) {
        $end = strrpos($script, '/');
        return substr($script, 0, $end + 1);
    }
    return '/';
}

function base_url(string $path = ''): string {
    $base = rtrim(public_base_path(), '/');
    $suffix = ltrim($path, '/');
    return $base . ($suffix !== '' ? '/' . $suffix : '/');
}

function redirect_to(string $path): void {
    header('Location: ' . base_url($path));
    exit;
}

function get_setting(string $key, ?string $default = null): ?string {
    try {
        $stmt = db()->prepare('SELECT value_text FROM settings WHERE key_name = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['value_text'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function set_flash(string $msg, string $type = 'info'): void {
    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_type'] = $type;
}

function get_flash(): array {
    $msg = $_SESSION['flash_msg'] ?? '';
    $type = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
    return [$msg, $type];
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_auth(array $roles = []): void {
    $user = current_user();
    if (!$user) {
        redirect_to('index.php');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

// Return BHW user id assigned to the resident's purok; null if none
function getAssignedBhwIdForResident(int $residentId): ?int {
    // Get BHW assigned to the same purok as the resident
    // Since purok_id is unique across the system, this automatically includes barangay filtering
    $sql = 'SELECT u.id FROM residents r JOIN users u ON u.purok_id = r.purok_id AND u.role = "bhw" WHERE r.id = ? LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$residentId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

// Format full name with middle initial
function format_full_name(string $first_name, string $last_name, ?string $middle_initial = null, ?string $suffix = null): string {
    $name = trim($first_name . ' ' . $last_name);
    if (!empty($middle_initial)) {
        $middle_initial = trim($middle_initial);
        if (!empty($middle_initial)) {
            $name = trim($first_name . ' ' . $middle_initial . ' ' . $last_name);
        }
    }
    if (!empty($suffix)) {
        $suffix = trim($suffix);
        if (!empty($suffix)) {
            $name = $name . ' ' . $suffix;
        }
    }
    return $name;
}

// Get all active relationships from database
function get_relationships(): array {
    try {
        // Check if relationships table exists, if not return default list
        $stmt = db()->query("SHOW TABLES LIKE 'relationships'");
        if ($stmt->rowCount() === 0) {
            // Fallback to default relationships if table doesn't exist
            return [
                ['name' => 'Self', 'display_order' => 1],
                ['name' => 'Father', 'display_order' => 2],
                ['name' => 'Mother', 'display_order' => 3],
                ['name' => 'Son', 'display_order' => 4],
                ['name' => 'Daughter', 'display_order' => 5],
                ['name' => 'Brother', 'display_order' => 6],
                ['name' => 'Sister', 'display_order' => 7],
                ['name' => 'Husband', 'display_order' => 8],
                ['name' => 'Wife', 'display_order' => 9],
                ['name' => 'Spouse', 'display_order' => 10],
                ['name' => 'Grandfather', 'display_order' => 11],
                ['name' => 'Grandmother', 'display_order' => 12],
                ['name' => 'Uncle', 'display_order' => 13],
                ['name' => 'Aunt', 'display_order' => 14],
                ['name' => 'Nephew', 'display_order' => 15],
                ['name' => 'Niece', 'display_order' => 16],
                ['name' => 'Cousin', 'display_order' => 17],
                ['name' => 'Other', 'display_order' => 99],
            ];
        }
        
        $stmt = db()->query('SELECT name, display_order FROM relationships WHERE is_active = 1 ORDER BY display_order ASC, name ASC');
        $relationships = $stmt->fetchAll();
        
        // If no relationships found, return default list
        if (empty($relationships)) {
            return [
                ['name' => 'Self', 'display_order' => 1],
                ['name' => 'Father', 'display_order' => 2],
                ['name' => 'Mother', 'display_order' => 3],
                ['name' => 'Son', 'display_order' => 4],
                ['name' => 'Daughter', 'display_order' => 5],
                ['name' => 'Brother', 'display_order' => 6],
                ['name' => 'Sister', 'display_order' => 7],
                ['name' => 'Husband', 'display_order' => 8],
                ['name' => 'Wife', 'display_order' => 9],
                ['name' => 'Spouse', 'display_order' => 10],
                ['name' => 'Grandfather', 'display_order' => 11],
                ['name' => 'Grandmother', 'display_order' => 12],
                ['name' => 'Uncle', 'display_order' => 13],
                ['name' => 'Aunt', 'display_order' => 14],
                ['name' => 'Nephew', 'display_order' => 15],
                ['name' => 'Niece', 'display_order' => 16],
                ['name' => 'Cousin', 'display_order' => 17],
                ['name' => 'Other', 'display_order' => 99],
            ];
        }
        
        return $relationships;
    } catch (Throwable $e) {
        // Fallback to default relationships on error
        return [
            ['name' => 'Self', 'display_order' => 1],
            ['name' => 'Father', 'display_order' => 2],
            ['name' => 'Mother', 'display_order' => 3],
            ['name' => 'Son', 'display_order' => 4],
            ['name' => 'Daughter', 'display_order' => 5],
            ['name' => 'Brother', 'display_order' => 6],
            ['name' => 'Sister', 'display_order' => 7],
            ['name' => 'Husband', 'display_order' => 8],
            ['name' => 'Wife', 'display_order' => 9],
            ['name' => 'Spouse', 'display_order' => 10],
            ['name' => 'Grandfather', 'display_order' => 11],
            ['name' => 'Grandmother', 'display_order' => 12],
            ['name' => 'Uncle', 'display_order' => 13],
            ['name' => 'Aunt', 'display_order' => 14],
            ['name' => 'Nephew', 'display_order' => 15],
            ['name' => 'Niece', 'display_order' => 16],
            ['name' => 'Cousin', 'display_order' => 17],
            ['name' => 'Other', 'display_order' => 99],
        ];
    }
}

// Generate HTML options for relationship dropdown
function get_relationship_options(?string $selected = null, bool $include_empty = true): string {
    $relationships = get_relationships();
    $html = '';
    
    if ($include_empty) {
        $html .= '<option value="">Select relationship</option>';
    }
    
    foreach ($relationships as $rel) {
        $name = htmlspecialchars($rel['name']);
        $is_selected = ($selected !== null && $selected === $rel['name']) ? ' selected' : '';
        $html .= "<option value=\"{$name}\"{$is_selected}>{$name}</option>";
    }
    
    return $html;
}

// FEFO allocate quantity from earliest-expiring batches; returns total allocated
function fefoAllocate(int $medicineId, int $quantity, int $requestId = 0): int {
    if ($quantity <= 0) return 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $allocated = 0;
        $q = $pdo->prepare('SELECT id, quantity_available FROM medicine_batches WHERE medicine_id = ? AND quantity_available > 0 AND expiry_date > CURDATE() ORDER BY expiry_date ASC, id ASC FOR UPDATE');
        $q->execute([$medicineId]);
        while ($row = $q->fetch()) {
            if ($allocated >= $quantity) break;
            $take = min((int)$row['quantity_available'], $quantity - $allocated);
            if ($take <= 0) continue;
            $upd = $pdo->prepare('UPDATE medicine_batches SET quantity_available = quantity_available - ? WHERE id = ?');
            $upd->execute([$take, (int)$row['id']]);
            if ($requestId > 0) {
                $ins = $pdo->prepare('INSERT INTO request_fulfillments (request_id, batch_id, quantity) VALUES (?,?,?)');
                $ins->execute([$requestId, (int)$row['id'], $take]);
            }
            $allocated += $take;
        }
        $pdo->commit();
        return $allocated;
    } catch (Throwable $e) {
        $pdo->rollBack();
        return 0;
    }
}


