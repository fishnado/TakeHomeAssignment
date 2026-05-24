<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

// --- Scheduled publishing ---

test('document with future publish_at is not yet available', function () {
    $db = db();
    $db->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ')->execute(['Future Doc', 'body', date('Y-m-d H:i:s', strtotime('+1 day'))]);
    $id = (int) $db->lastInsertId();

    $row = $db->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $row->execute([$id]);
    $doc = $row->fetch();

    assert_true($doc !== false, 'document not found');
    assert_true($doc['publish_at'] > date('Y-m-d H:i:s'), 'publish_at should be in the future');
});

test('document with null publish_at is immediately available', function () {
    $db = db();
    $db->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, NULL)
    ')->execute(['Immediate Doc', 'body']);
    $id = (int) $db->lastInsertId();

    $row = $db->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $row->execute([$id]);
    $doc = $row->fetch();

    assert_true($doc !== false, 'document not found');
    assert_true($doc['publish_at'] === null, 'publish_at should be null for immediate publish');
});

test('document with past publish_at is available', function () {
    $db = db();
    $db->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ')->execute(['Past Doc', 'body', date('Y-m-d H:i:s', strtotime('-1 day'))]);
    $id = (int) $db->lastInsertId();

    $row = $db->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $row->execute([$id]);
    $doc = $row->fetch();

    assert_true($doc !== false, 'document not found');
    assert_true($doc['publish_at'] < date('Y-m-d H:i:s'), 'publish_at should be in the past');
});

// --- Edge n-gram search ---

test('edge_ngrams generates correct prefixes for a single word', function () {
    $tokens = edge_ngrams('Welcome');
    assert_true(in_array('we',      $tokens), 'missing "we"');
    assert_true(in_array('wel',     $tokens), 'missing "wel"');
    assert_true(in_array('welc',    $tokens), 'missing "welc"');
    assert_true(in_array('welcome', $tokens), 'missing "welcome"');
    assert_true(!in_array('w',      $tokens), 'single-char token should be excluded (min=2)');
});

test('search finds seeded document by partial title prefix', function () {
    // "welco" is an edge n-gram of "Welcome" — should resolve to the seeded doc.
    $words        = ['welco'];
    $placeholders = '?';
    $stmt = db()->prepare("
        SELECT d.title
        FROM documents d
        WHERE d.id IN (
            SELECT document_id FROM document_search_tokens
            WHERE token IN ($placeholders)
            GROUP BY document_id HAVING COUNT(DISTINCT token) >= 1
        )
    ");
    $stmt->execute($words);
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected a result for "welco"');
    assert_true($row['title'] === 'Welcome Packet', 'wrong document: ' . var_export($row['title'], true));
});

test('search returns no results for a non-matching query', function () {
    $stmt = db()->prepare("
        SELECT document_id FROM document_search_tokens WHERE token = ?
    ");
    $stmt->execute(['zzznomatch']);
    $row = $stmt->fetch();
    assert_true($row === false, 'expected no results for nonsense token');
});

test('multi-word search requires all words to match (AND logic)', function () {
    // "welco" AND "pa" must both appear — only "Welcome Packet" satisfies both.
    $words        = ['welco', 'pa'];
    $placeholders = implode(',', array_fill(0, count($words), '?'));
    $stmt = db()->prepare("
        SELECT d.title
        FROM documents d
        WHERE d.id IN (
            SELECT document_id FROM document_search_tokens
            WHERE token IN ($placeholders)
            GROUP BY document_id HAVING COUNT(DISTINCT token) >= " . count($words) . "
        )
    ");
    $stmt->execute($words);
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected a result for "welco pa"');
    assert_true($row['title'] === 'Welcome Packet', 'wrong document');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
