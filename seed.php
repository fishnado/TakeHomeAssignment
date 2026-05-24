<?php

require __DIR__ . '/lib/bootstrap.php';

$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

// Apply migrations in order on top of the base schema.
// Since seed.php always starts from a clean DB, every migration replays
// on every run — no need to track which have been applied.
foreach (glob(__DIR__ . '/migrations/*.sql') as $file) {
    $pdo->exec(file_get_contents($file));
}

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('freddy@folio.example', 'Freddy Folio')
");

$seedTitle = 'Welcome Packet';
$seedSlug  = generate_document_slug($seedTitle);

$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by, slug)
    VALUES (?, ?, 1, ?)
');
$stmt->execute([
    $seedTitle,
    "Welcome to Folio!\n\nThis is the body of your welcome packet.",
    $seedSlug,
]);
$docId = (int) $pdo->lastInsertId();

$token = random_token();
$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$stmt->execute([$docId, $token, 'recipient@example.com']);

echo "Seeded db.sqlite.\n";
echo "Admin:        http://localhost:8000/admin.php\n";
echo "Sample share: http://localhost:8000/share/{$seedSlug}/{$token}\n";
