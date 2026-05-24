<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff  = current_staff();
$docId  = (int) ($_GET['doc'] ?? 0);

$stmt = db()->prepare('
    SELECT d.*, s.name AS creator_name
    FROM documents d
    JOIN staff s ON s.id = d.created_by
    WHERE d.id = ?
');
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    render_header('Not found', $staff);
    ?>
    <div class="banner banner-error">Document not found.</div>
    <p><a href="/admin.php" class="back-link">← back to admin</a></p>
    <?php
    render_footer();
    exit;
}

render_header('Preview · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title"><?= h($doc['title']) ?></h1>

<p class="meta">
    Created by <?= h($doc['creator_name']) ?> · <?= h($doc['created_at']) ?>
    <?php if (!empty($doc['slug'])): ?>
        · <code><?= h($doc['slug']) ?></code>
    <?php endif ?>
    <?php if (!empty($doc['publish_at'])): ?>
        · Publishes <?= h($doc['publish_at']) ?> UTC
    <?php endif ?>
</p>

<?php if (!empty($doc['publish_at']) && $doc['publish_at'] > gmdate('Y-m-d H:i:s')): ?>
    <div class="banner banner-warn">
        This document is scheduled — recipients will see "not yet available" until <?= h($doc['publish_at']) ?> UTC.
    </div>
<?php endif ?>

<div class="card">
    <pre class="doc-body"><?= h($doc['body']) ?></pre>
</div>

<p>
    <a href="/share.php?doc=<?= (int) $doc['id'] ?>" class="btn">Create share link →</a>
</p>

<?php render_footer(); ?>
