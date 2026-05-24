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
 * Generate edge n-grams for a title string.
 *
 * Each word is broken into all its leading prefixes (min length 2).
 * Example: "Welcome Packet" →
 *   ["we","wel","welc","welco","welcom","welcome",
 *    "pa","pac","pack","packe","packet"]
 *
 * This is the token set stored in the inverted index. Searching for
 * "welco" is an exact lookup against this set — O(log n) via the index.
 */
function edge_ngrams(string $text, int $min = 2): array {
    $tokens = [];
    $words  = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($words as $word) {
        for ($len = $min; $len <= strlen($word); $len++) {
            $tokens[] = substr($word, 0, $len);
        }
    }
    return array_unique($tokens);
}

/**
 * (Re-)index a document's title in the search token table.
 * Clears existing tokens first so it is safe to call on update too.
 */
function index_document(int $id, string $title): void {
    $db = db();
    $db->prepare('DELETE FROM document_search_tokens WHERE document_id = ?')
       ->execute([$id]);
    $stmt = $db->prepare('INSERT INTO document_search_tokens (token, document_id) VALUES (?, ?)');
    foreach (edge_ngrams($title) as $token) {
        $stmt->execute([$token, $id]);
    }
}
