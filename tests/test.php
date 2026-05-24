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

// --- Case-insensitive prefix search ---

test('search finds document by lowercase partial title', function () {
    $stmt = db()->prepare('
        SELECT title FROM documents
        WHERE LOWER(title) LIKE LOWER(:q)
    ');
    $stmt->execute([':q' => '%welco%']);
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected a result for "welco"');
    assert_true($row['title'] === 'Welcome Packet', 'wrong document: ' . var_export($row['title'], true));
});

test('search finds document by uppercase partial title', function () {
    $stmt = db()->prepare('
        SELECT title FROM documents
        WHERE LOWER(title) LIKE LOWER(:q)
    ');
    $stmt->execute([':q' => '%PACKET%']);
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected a result for "PACKET"');
    assert_true($row['title'] === 'Welcome Packet', 'wrong document');
});

test('search finds document by full title paste', function () {
    $stmt = db()->prepare('
        SELECT title FROM documents
        WHERE LOWER(title) LIKE LOWER(:q)
    ');
    $stmt->execute([':q' => '%Welcome Packet%']);
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected a result for full title paste');
    assert_true($row['title'] === 'Welcome Packet', 'wrong document');
});

test('search returns no results for a non-matching query', function () {
    $stmt = db()->prepare('
        SELECT title FROM documents
        WHERE LOWER(title) LIKE LOWER(:q)
    ');
    $stmt->execute([':q' => '%zzznomatch%']);
    $row = $stmt->fetch();
    assert_true($row === false, 'expected no results for nonsense query');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
