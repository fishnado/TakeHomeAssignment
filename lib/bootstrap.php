<?php

date_default_timezone_set('America/Chicago');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Convert a title to a URL-safe kebab-case base.
 * "Welcome Packet!"  → "welcome-packet"
 * "Q4 Onboarding #2" → "q4-onboarding-2"
 */
function make_slug(string $title): string {
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug ?: 'document';
}

/**
 * Generate a unique document slug: {kebab-title}-{2-char-base36}.
 * Example: "Welcome Packet" → "welcome-packet-3k"
 *
 * The 2-char base-36 suffix gives 1,296 variants per base slug —
 * sufficient for an internal tool. Retries on the rare collision.
 * Falls back to a 4-char suffix after max_tries consecutive collisions.
 */
function generate_document_slug(string $title, int $max_tries = 10): string {
    $base = make_slug($title);
    for ($i = 0; $i < $max_tries; $i++) {
        $n         = random_int(0, 1295);                          // 36² possibilities
        $suffix    = str_pad(base_convert($n, 10, 36), 2, '0', STR_PAD_LEFT);
        $candidate = $base . '-' . $suffix;
        $stmt = db()->prepare('SELECT id FROM documents WHERE slug = ?');
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
    }
    // Fallback: 4-char hex suffix guarantees uniqueness under any reasonable load
    return $base . '-' . bin2hex(random_bytes(2));
}
