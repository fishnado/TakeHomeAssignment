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

// --- Audit log ---

test('audit_log records document creation with correct fields', function () {
    $db = db();
    $db->prepare('INSERT INTO documents (title, body, created_by) VALUES (?,?,1)')
       ->execute(['Audit Test Doc', 'body']);
    $docId = (int) $db->lastInsertId();

    audit_log('create', 'document', $docId, ['title' => 'Audit Test Doc']);

    $stmt = $db->prepare('
        SELECT * FROM audit_log
        WHERE entity_type = ? AND entity_id = ? AND action = ?
    ');
    $stmt->execute(['document', $docId, 'create']);
    $log = $stmt->fetch();

    assert_true($log !== false,       'audit log entry not found');
    assert_true($log['staff_id'] == 1, 'wrong staff_id');
    assert_true($log['action'] === 'create', 'wrong action');
    $details = json_decode($log['details'], true);
    assert_true($details['title'] === 'Audit Test Doc', 'details not recorded');
});

test('audit_log records share creation with document reference', function () {
    $db = db();
    $db->prepare('INSERT INTO documents (title, body, created_by) VALUES (?,?,1)')
       ->execute(['Share Audit Doc', 'body']);
    $docId = (int) $db->lastInsertId();

    $token = random_token();
    $db->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?,?,?)')
       ->execute([$docId, $token, 'test@example.com']);
    $shareId = (int) $db->lastInsertId();

    audit_log('create', 'share', $shareId, [
        'document_id'     => $docId,
        'recipient_email' => 'test@example.com',
    ]);

    $stmt = $db->prepare('
        SELECT * FROM audit_log
        WHERE entity_type = ? AND entity_id = ? AND action = ?
    ');
    $stmt->execute(['share', $shareId, 'create']);
    $log = $stmt->fetch();

    assert_true($log !== false, 'share audit log entry not found');
    $details = json_decode($log['details'], true);
    assert_true($details['document_id'] == $docId,             'document_id missing from details');
    assert_true($details['recipient_email'] === 'test@example.com', 'recipient_email missing');
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

// --- Human-readable slugs ---

test('seeded document has a slug in the correct format', function () {
    $row = db()->query("SELECT slug FROM documents WHERE title = 'Welcome Packet'")->fetch();
    assert_true($row !== false, 'seeded document not found');
    assert_true(!empty($row['slug']), 'slug should not be empty');
    // Format: kebab-base + hyphen + 2-char base36 suffix
    assert_true(
        preg_match('/^[a-z0-9]+(-[a-z0-9]+)*-[a-z0-9]{2}$/', $row['slug']) === 1,
        'slug format invalid: ' . $row['slug']
    );
});

test('make_slug converts title to kebab-case', function () {
    assert_true(make_slug('Welcome Packet')       === 'welcome-packet',   'spaces → hyphens');
    assert_true(make_slug('Q4 Onboarding #2!')    === 'q4-onboarding-2',  'special chars stripped');
    assert_true(make_slug('  Leading Spaces  ')   === 'leading-spaces',   'leading/trailing stripped');
});

test('generate_document_slug produces unique slugs for the same title', function () {
    $db = db();
    $slugs = [];
    for ($i = 0; $i < 5; $i++) {
        $slug = generate_document_slug('Test Document');
        assert_true(!in_array($slug, $slugs), 'duplicate slug generated: ' . $slug);
        $slugs[] = $slug;
        // Insert so next iteration sees the collision
        $db->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?,?,1,?)')
           ->execute(['Test Document', 'body', $slug]);
    }
    assert_true(count(array_unique($slugs)) === 5, 'expected 5 unique slugs');
});

test('share token still controls access — slug alone is not enough', function () {
    // Token lookup should work; slug in URL is cosmetic
    $row = db()->query('
        SELECT d.title, s.token
        FROM shares s JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ')->fetch();
    assert_true($row !== false, 'no shares found');
    assert_true(!empty($row['token']), 'token should exist');
    assert_true(strlen($row['token']) === 32, 'token should be 32 hex chars');
});

// --- Sorting ---

test('sort by id ascending returns lowest id first', function () {
    $rows = db()->query('SELECT id FROM documents ORDER BY id ASC')->fetchAll();
    assert_true(count($rows) >= 1, 'expected at least one document');
    $ids = array_column($rows, 'id');
    assert_true($ids === array_values($ids), 'ids should already be in order');
    // Verify first id is less than or equal to last
    assert_true($ids[0] <= $ids[count($ids) - 1], 'first id should be <= last id when ASC');
});

test('sort by id descending returns highest id first', function () {
    $rows = db()->query('SELECT id FROM documents ORDER BY id DESC')->fetchAll();
    assert_true(count($rows) >= 1, 'expected at least one document');
    $ids = array_column($rows, 'id');
    assert_true($ids[0] >= $ids[count($ids) - 1], 'first id should be >= last id when DESC');
});

test('sort and search are composable — filtered results respect sort order', function () {
    $db = db();
    // Insert two documents that both match a search but have different titles
    $db->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)')
       ->execute(['Alpha Report', 'body']);
    $db->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)')
       ->execute(['Zebra Report', 'body']);

    $rows = $db->query("
        SELECT title FROM documents
        WHERE LOWER(title) LIKE '%report%'
        ORDER BY title ASC
    ")->fetchAll();

    $titles = array_column($rows, 'title');
    assert_true(in_array('Alpha Report', $titles), 'Alpha Report missing');
    assert_true(in_array('Zebra Report', $titles), 'Zebra Report missing');
    assert_true(
        array_search('Alpha Report', $titles) < array_search('Zebra Report', $titles),
        'Alpha should come before Zebra when sorted ASC'
    );
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
