<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body'] ?? '');
    $publish_at_raw = trim($_POST['publish_at'] ?? '');
    $publish_at_utc = trim($_POST['publish_at_utc'] ?? '');

    // JS submits publish_at_utc as a UTC ISO string (e.g. "2026-05-24T15:59:00.000Z").
    // Fall back to raw datetime-local interpreted as server timezone if JS is unavailable.
    $publish_at = null;
    if ($publish_at_utc !== '') {
        $ts = strtotime($publish_at_utc);
        $publish_at = $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : null;
    } elseif ($publish_at_raw !== '') {
        $ts = strtotime($publish_at_raw);
        $publish_at = $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, publish_at)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$title, $body, $staff['id'], $publish_at]);
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, [
            'title'      => $title,
            'publish_at' => $publish_at,
        ]);

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

// Case-insensitive prefix search on title.
// LOWER() on both sides ensures consistent matching regardless of how the
// title was entered. '%' suffix means the query matches anywhere inside
// the title, so "packet" finds "Welcome Packet" and a full paste works too.
$query = trim($_GET['q'] ?? '');
if ($query !== '') {
    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE LOWER(d.title) LIKE LOWER(:q)
        ORDER BY d.created_at DESC
    ');
    $stmt->execute([':q' => '%' . $query . '%']);
    $docs = $stmt->fetchAll();
} else {
    $docs = db()->query('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ')->fetchAll();
}

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at (optional)</label>
            <input type="datetime-local" id="publish_at" name="publish_at">
            <input type="hidden" id="publish_at_utc" name="publish_at_utc">
            <p class="field-hint">
                Leave blank to publish immediately.
                Your local time: <strong id="local-clock">—</strong>
                (<span id="local-tz">detecting&hellip;</span>)
            </p>
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>

    <form method="get" action="/admin.php" class="search-row">
        <input type="search" name="q" id="q" placeholder="Search by title…"
               value="<?= h($query) ?>" autocomplete="off">
        <button type="submit" class="btn">Search</button>
        <?php if ($query !== ''): ?>
            <a href="/admin.php" class="btn-link">Clear</a>
        <?php endif ?>
    </form>

    <?php if ($query !== ''): ?>
        <p class="search-meta">
            <?= count($docs) ?> result<?= count($docs) !== 1 ? 's' : '' ?>
            for <strong><?= h($query) ?></strong>
        </p>
    <?php endif ?>

    <?php if (empty($docs)): ?>
        <p class="empty">No documents yet.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td>
                            <?php if (!empty($d['publish_at'])): ?>
                                <span class="badge"
                                      data-publish-at="<?= h($d['publish_at']) ?>Z">
                                    <!-- updated live by JS every second -->
                                </span>
                            <?php else: ?>
                                <span class="badge badge-live">Live</span>
                            <?php endif ?>
                        </td>
                        <td><a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<script>
// Live clock + live status badges — both tick every second.
(function () {
    var clockEl = document.getElementById('local-clock');
    var tzEl    = document.getElementById('local-tz');
    var tz      = Intl.DateTimeFormat().resolvedOptions().timeZone;
    tzEl.textContent = tz;

    function updateBadges() {
        var now = Date.now();
        document.querySelectorAll('[data-publish-at]').forEach(function (el) {
            var publishAt = new Date(el.dataset.publishAt).getTime();
            if (now >= publishAt) {
                el.className     = 'badge badge-live';
                el.textContent   = 'Live';
                el.removeAttribute('data-publish-at'); // stop checking once live
            } else {
                el.className   = 'badge badge-scheduled';
                var secsLeft   = Math.ceil((publishAt - now) / 1000);
                var hh = Math.floor(secsLeft / 3600);
                var mm = Math.floor((secsLeft % 3600) / 60);
                var ss = secsLeft % 60;
                var countdown = hh > 0
                    ? hh + 'h ' + mm + 'm'
                    : mm > 0 ? mm + 'm ' + ss + 's'
                    : ss + 's';
                el.textContent = 'Live in ' + countdown;
            }
        });
    }

    function tick() {
        clockEl.textContent = new Date().toLocaleTimeString([], {
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });
        updateBadges();
    }
    tick();
    setInterval(tick, 1000);

    // On submit, convert datetime-local value from local time → UTC ISO string.
    // PHP reads publish_at_utc and stores it in UTC so comparisons are timezone-safe.
    var form       = document.querySelector('form');
    var dtInput    = document.getElementById('publish_at');
    var utcInput   = document.getElementById('publish_at_utc');

    form.addEventListener('submit', function () {
        if (dtInput.value) {
            // new Date(dateTimeLocalString) interprets the value as local time.
            utcInput.value = new Date(dtInput.value).toISOString();
        }
    });
}());
</script>

<?php render_footer(); ?>
