<?php
declare(strict_types=1);

/**
 * Appointment admin.
 *
 * A working list, not a log: every request carries a status so it is obvious
 * what has been answered and what has not. Dark to match the site.
 */

require __DIR__ . '/../lib/util.php';
require __DIR__ . '/../lib/db.php';

start_session();

// A leftover hash generator is a standing invitation. Refuse to run until it
// is gone rather than quietly depending on nobody finding it.
if (is_file(__DIR__ . '/../make-hash.php')) {
    http_response_code(503);
    exit('Finish setup first: delete make-hash.php from the server.');
}

$config = config();

// ---------------------------------------------------------------- logout ---
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

// ----------------------------------------------------------------- login ---
$loginError = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password']) && empty($_SESSION['admin'])) {
    // A fixed delay on every attempt, right or wrong, so timing reveals nothing
    // and brute forcing is slow.
    usleep(400000);
    if (password_verify((string) $_POST['password'], (string) ($config['admin_password_hash'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
    } else {
        $loginError = 'Incorrect password.';
    }
}

$authed = !empty($_SESSION['admin']);

// ---------------------------------------------------------------- update ---
if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['update_id'])) {
    check_csrf($_POST['csrf'] ?? null);

    $status = (string) ($_POST['status'] ?? 'new');
    if (!in_array($status, statuses(), true)) {
        $status = 'new';
    }

    db()->prepare('UPDATE appointments SET status = ?, admin_notes = ?, updated_at = ? WHERE id = ?')
        ->execute([
            $status,
            clean($_POST['admin_notes'] ?? '', 4000),
            (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            (int) $_POST['update_id'],
        ]);

    // Redirect after post, so a refresh does not resubmit.
    header('Location: ./?' . http_build_query(array_filter([
        'status' => $_GET['status'] ?? '',
        'saved' => '1',
    ])));
    exit;
}

// ------------------------------------------------------------------ data ---
$rows = [];
$counts = [];
if ($authed) {
    $filter = (string) ($_GET['status'] ?? '');
    if (!in_array($filter, statuses(), true)) {
        $filter = '';
    }

    $sql = 'SELECT * FROM appointments';
    $params = [];
    if ($filter !== '') {
        $sql .= ' WHERE status = ?';
        $params[] = $filter;
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 500';

    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();

    foreach (db()->query('SELECT status, COUNT(*) AS n FROM appointments GROUP BY status') as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
}

$highlight = (int) ($_GET['id'] ?? 0);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Appointments · Bassett Court Holdings</title>
<style>
  :root {
    --page:#0a0e14; --card:#121821; --sunken:#0e131b; --line:#1f2836; --strong:#303c4c;
    --text:#f2f5f8; --dim:#aab4c2; --muted:#7d8899; --accent:#d4a35f;
    color-scheme: dark;
  }
  * { box-sizing: border-box; }
  body {
    margin:0; background:var(--page); color:var(--text); font:15px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;
    -webkit-font-smoothing:antialiased;
  }
  a { color:var(--accent); }
  .wrap { max-width:1180px; margin:0 auto; padding:24px 16px 72px; }
  header.bar { display:flex; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:28px; }
  h1 { font-size:1.5rem; letter-spacing:-.02em; margin:0; }
  .muted { color:var(--muted); }
  .tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
  .tab {
    display:inline-flex; align-items:center; gap:7px; padding:7px 13px; border-radius:999px;
    border:1px solid var(--line); background:var(--sunken); color:var(--dim);
    font-size:.8125rem; font-weight:600; text-decoration:none;
  }
  .tab[aria-current="true"] { border-color:var(--accent); color:var(--text); background:rgba(212,163,95,.14); }
  .tab .n { font-variant-numeric:tabular-nums; opacity:.65; font-size:.75rem; }
  .card {
    background:var(--card); border:1px solid var(--line); border-radius:14px;
    padding:18px; margin-bottom:14px;
  }
  .card.hi { border-color:var(--accent); box-shadow:0 0 0 1px var(--accent); }
  .top { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start; }
  .who { font-size:1.0625rem; font-weight:700; }
  .meta { color:var(--muted); font-size:.8125rem; margin-top:3px; }
  .pill { font-size:.6875rem; font-weight:700; text-transform:uppercase; letter-spacing:.09em;
          padding:4px 9px; border-radius:999px; white-space:nowrap; }
  .s-new{background:var(--accent);color:#0a0e14}
  .s-contacted{background:#2f5a86;color:#fff}
  .s-scheduled{background:#4a6b52;color:#fff}
  .s-sold{background:#3c3f46;color:#cbd3dd}
  .s-closed{background:#23272c;color:#8b95a3}
  dl.facts { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px 20px; margin:16px 0 0; }
  dl.facts dt { color:var(--muted); font-size:.6875rem; text-transform:uppercase; letter-spacing:.1em; font-weight:700; }
  dl.facts dd { margin:3px 0 0; font-size:.9375rem; word-break:break-word; }
  .note { margin-top:14px; padding-top:14px; border-top:1px solid var(--line); color:var(--dim); font-size:.9375rem; white-space:pre-wrap; }
  form.row { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-top:16px; padding-top:16px; border-top:1px solid var(--line); }
  label.f { display:flex; flex-direction:column; gap:5px; font-size:.6875rem; text-transform:uppercase;
            letter-spacing:.1em; font-weight:700; color:var(--muted); }
  select, input[type=text], input[type=password], textarea {
    background:var(--sunken); border:1px solid var(--line); border-radius:8px; color:var(--text);
    padding:9px 11px; font:inherit; font-size:.875rem; min-width:150px;
  }
  textarea { min-width:280px; flex:1; resize:vertical; }
  button {
    background:var(--accent); color:#0a0e14; border:0; border-radius:8px;
    padding:10px 18px; font:inherit; font-weight:700; font-size:.875rem; cursor:pointer;
  }
  button.ghost { background:transparent; color:var(--dim); border:1px solid var(--strong); }
  .login { max-width:360px; margin:16vh auto; }
  .err { color:#ef6d62; font-size:.875rem; margin-top:10px; }
  .saved { background:rgba(74,107,82,.22); border:1px solid #4a6b52; border-radius:10px;
           padding:10px 14px; margin-bottom:18px; font-size:.875rem; }
  .empty { text-align:center; padding:72px 20px; color:var(--muted); }
  @media (max-width:640px){ .wrap{padding-top:16px} dl.facts{grid-template-columns:1fr 1fr} }
</style>
</head>
<body>
<div class="wrap">

<?php if (!$authed): ?>
  <div class="login">
    <h1>Appointments</h1>
    <p class="muted" style="margin:6px 0 20px;font-size:.875rem">Bassett Court Holdings</p>
    <form method="post">
      <label class="f" style="width:100%">Password
        <input type="password" name="password" autocomplete="current-password" autofocus required style="width:100%">
      </label>
      <button type="submit" style="margin-top:14px;width:100%">Sign in</button>
      <?php if ($loginError !== ''): ?><p class="err"><?= e($loginError) ?></p><?php endif; ?>
    </form>
  </div>
<?php else: ?>

  <header class="bar">
    <h1>Appointments</h1>
    <span class="muted"><?= array_sum($counts) ?> total</span>
    <a class="tab" href="?logout=1" style="margin-left:auto">Sign out</a>
  </header>

  <?php if (isset($_GET['saved'])): ?><div class="saved">Saved.</div><?php endif; ?>

  <nav class="tabs">
    <a class="tab" href="./" <?= ($_GET['status'] ?? '') === '' ? 'aria-current="true"' : '' ?>>
      All <span class="n"><?= array_sum($counts) ?></span>
    </a>
    <?php foreach (statuses() as $s): ?>
      <a class="tab" href="?status=<?= e($s) ?>" <?= ($_GET['status'] ?? '') === $s ? 'aria-current="true"' : '' ?>>
        <?= e(ucfirst($s)) ?> <span class="n"><?= (int) ($counts[$s] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$rows): ?>
    <div class="empty">
      <p style="font-size:1.125rem;color:var(--dim)">Nothing here yet.</p>
      <p>Requests from the booking form land on this page.</p>
    </div>
  <?php endif; ?>

  <?php foreach ($rows as $r): ?>
    <article class="card<?= $highlight === (int) $r['id'] ? ' hi' : '' ?>" id="r<?= (int) $r['id'] ?>">
      <div class="top">
        <div>
          <div class="who"><?= e($r['name']) ?></div>
          <div class="meta">
            #<?= (int) $r['id'] ?> ·
            <?= e((new DateTimeImmutable($r['created_at']))->format('D j M Y, g:ia')) ?>
          </div>
        </div>
        <span class="pill s-<?= e($r['status']) ?>"><?= e($r['status']) ?></span>
      </div>

      <dl class="facts">
        <?php if ($r['phone']): ?>
          <div><dt>Phone</dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $r['phone'])) ?>"><?= e($r['phone']) ?></a></dd></div>
        <?php endif; ?>
        <?php if ($r['email']): ?>
          <div><dt>Email</dt><dd><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></dd></div>
        <?php endif; ?>
        <?php if ($r['vehicle_label']): ?>
          <div><dt>Vehicle</dt><dd><?= e($r['vehicle_label']) ?></dd></div>
        <?php endif; ?>
        <?php if ($r['preferred_day_label'] || $r['preferred_time']): ?>
          <div><dt>Wants</dt><dd><?= e(trim($r['preferred_day_label'] . ' ' . $r['preferred_time'])) ?></dd></div>
        <?php endif; ?>
        <?php if ($r['trade_in']): ?>
          <div><dt>Trade-in</dt><dd><?= e($r['trade_in']) ?></dd></div>
        <?php endif; ?>
      </dl>

      <?php if ($r['message']): ?>
        <div class="note"><?= e($r['message']) ?></div>
      <?php endif; ?>

      <form class="row" method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="update_id" value="<?= (int) $r['id'] ?>">
        <label class="f">Status
          <select name="status">
            <?php foreach (statuses() as $s): ?>
              <option value="<?= e($s) ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="f" style="flex:1">Notes
          <textarea name="admin_notes" rows="1" placeholder="What happened on the call…"><?= e($r['admin_notes']) ?></textarea>
        </label>
        <button type="submit">Save</button>
      </form>
    </article>
  <?php endforeach; ?>

<?php endif; ?>
</div>
</body>
</html>
