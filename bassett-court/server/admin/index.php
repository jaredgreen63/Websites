<?php
declare(strict_types=1);

/**
 * Lead desk.
 *
 * A working list, not a log. Two things land here: requests from the booking
 * form on the site, and leads typed in by hand after a call or a walk-in.
 * Both carry a status, so it is always obvious what has been answered.
 */

require __DIR__ . '/../lib/util.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/leads.php';
require __DIR__ . '/../lib/notify.php';

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
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$filter = (string) ($_GET['status'] ?? '');
if (!in_array($filter, statuses(), true)) {
    $filter = '';
}

// ----------------------------------------------------- test a notification ---
$testResults = null;
if ($authed && $method === 'POST' && isset($_POST['test_notify'])) {
    check_csrf($_POST['csrf'] ?? null);

    // Aim the link at a real record when there is one, so the test proves the
    // deep link as well as the delivery.
    $latest = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM appointments')->fetchColumn();

    $testResults = deliver_lead($latest, [
        'name' => 'Test message — no action needed',
        'phone' => (string) ($config['sms']['to'] ?? ''),
        'vehicle_label' => 'Checking notifications',
        'preferred_day_label' => '',
        'preferred_time' => '',
    ], $config);

    if ($testResults === []) {
        $testResults = [['channel' => 'none', 'target' => '', 'error' => 'No channels are configured in config.php.']];
    }
}

// ------------------------------------------------------------ add a lead ---
$addError = '';
$draft = [];
if ($authed && $method === 'POST' && isset($_POST['add_lead'])) {
    check_csrf($_POST['csrf'] ?? null);

    $draft = [
        'name' => clean($_POST['name'] ?? '', 120),
        'phone' => clean($_POST['phone'] ?? '', 40),
        'email' => clean($_POST['email'] ?? '', 160),
        'vehicle_label' => clean($_POST['vehicle_label'] ?? '', 200),
        'preferred_day_label' => clean($_POST['preferred_day_label'] ?? '', 40),
        'trade_in' => clean($_POST['trade_in'] ?? '', 60),
        'message' => clean($_POST['message'] ?? '', 4000),
    ];
    $draft['status'] = in_array($_POST['status'] ?? '', statuses(), true)
        ? (string) $_POST['status']
        : 'new';

    if ($draft['name'] === '') {
        $addError = 'Give the lead a name.';
    } elseif ($draft['phone'] === '' && $draft['email'] === '') {
        $addError = 'Add a phone number or an email address, so there is a way to reach them.';
    } elseif ($draft['email'] !== '' && !filter_var($draft['email'], FILTER_VALIDATE_EMAIL)) {
        $addError = 'That email address does not look right.';
    } else {
        $newId = create_lead(db(), $draft, 'manual', $draft['status']);
        header('Location: ./?' . http_build_query(['id' => $newId, 'added' => 1]));
        exit;
    }
}

// ---------------------------------------------------------------- update ---
if ($authed && $method === 'POST' && isset($_POST['update_id'])) {
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
        'status' => $filter,
        'saved' => '1',
    ])));
    exit;
}

// ------------------------------------------------------------------ data ---
$rows = [];
$counts = [];
if ($authed) {
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

$total = array_sum($counts);
$highlight = (int) ($_GET['id'] ?? 0);
$draftOpen = $addError !== '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0a0e14">
<title>Lead desk · Bassett Court Holdings</title>
<link rel="icon" href="/logo-mark.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --page:#0a0e14; --card:#121821; --sunken:#0e131b; --line:#1f2836; --strong:#303c4c;
    --text:#f2f5f8; --dim:#aab4c2; --muted:#7d8899;
    --gold:#d4a35f; --gold-lift:#e8c186;
    --body:"Inter",ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;
    --display:"Plus Jakarta Sans","Inter",ui-sans-serif,system-ui,sans-serif;
    color-scheme: dark;
  }
  * { box-sizing:border-box; }
  body {
    margin:0; background:var(--page); color:var(--text);
    font:15px/1.55 var(--body); -webkit-font-smoothing:antialiased; position:relative;
  }
  /* A soft wash of gold behind the masthead, so the page opens with weight. */
  body::before {
    content:''; position:absolute; inset:0 0 auto 0; height:460px; pointer-events:none; z-index:0;
    background:radial-gradient(900px 320px at 50% -130px, rgba(212,163,95,.14), transparent 72%);
  }
  .wrap { position:relative; z-index:1; max-width:1180px; margin:0 auto; padding:0 16px 80px; }
  a { color:var(--gold); }

  /* ---------------------------------------------------------- masthead --- */
  .brand {
    display:flex; align-items:center; gap:14px; padding:20px 0 26px;
    border-bottom:1px solid var(--line); margin-bottom:30px;
  }
  .brand img {
    height:40px; width:auto; display:block; border-radius:9px;
    border:1px solid rgba(212,163,95,.38); box-shadow:0 2px 14px -4px rgba(0,0,0,.7);
  }
  .brand .names { line-height:1.25; }
  .brand .eyebrow {
    font-size:.625rem; text-transform:uppercase; letter-spacing:.22em;
    color:var(--gold); font-weight:700;
  }
  .brand .co { font-family:var(--display); font-size:.9375rem; font-weight:700; letter-spacing:-.01em; }
  .brand .right { margin-left:auto; display:flex; align-items:center; gap:10px; }

  h1 {
    font-family:var(--display); font-size:clamp(1.75rem,4vw,2.375rem);
    letter-spacing:-.035em; margin:0; font-weight:800;
  }
  .lede { color:var(--muted); font-size:.9375rem; margin:7px 0 0; }
  .rule { width:54px; height:3px; border-radius:2px; margin:18px 0 26px;
          background:linear-gradient(90deg,var(--gold-lift),var(--gold)); }

  /* ------------------------------------------------------------- tiles --- */
  .tiles { display:grid; grid-template-columns:repeat(auto-fit,minmax(132px,1fr)); gap:10px; margin-bottom:28px; }
  .tile {
    display:block; text-decoration:none; color:inherit; position:relative; overflow:hidden;
    background:var(--card); border:1px solid var(--line); border-radius:13px; padding:15px 16px 14px;
    transition:border-color .16s, transform .16s, background .16s;
  }
  .tile:hover { border-color:var(--strong); transform:translateY(-2px); }
  .tile .n {
    font-family:var(--display); font-size:1.875rem; font-weight:800;
    font-variant-numeric:tabular-nums; letter-spacing:-.03em; line-height:1.05; display:block;
  }
  .tile .k {
    font-size:.625rem; text-transform:uppercase; letter-spacing:.17em;
    color:var(--muted); font-weight:700; margin-top:6px; display:block;
  }
  .tile[aria-current="true"] {
    border-color:var(--gold); background:linear-gradient(180deg,rgba(212,163,95,.13),rgba(212,163,95,.04));
  }
  .tile[aria-current="true"]::after {
    content:''; position:absolute; left:0; top:0; bottom:0; width:3px;
    background:linear-gradient(180deg,var(--gold-lift),var(--gold));
  }
  .tile[aria-current="true"] .k { color:var(--gold); }

  /* --------------------------------------------------------- add panel --- */
  details.add {
    border:1px solid var(--line); border-radius:14px; background:var(--card);
    margin-bottom:26px; overflow:hidden;
  }
  details.add[open] { border-color:rgba(212,163,95,.42); }
  details.add > summary {
    list-style:none; cursor:pointer; padding:15px 18px; display:flex; align-items:center; gap:11px;
    font-family:var(--display); font-weight:700; font-size:.9375rem; letter-spacing:-.01em;
  }
  details.add > summary::-webkit-details-marker { display:none; }
  .plus {
    width:25px; height:25px; flex:none; border-radius:50%; display:grid; place-items:center;
    background:linear-gradient(140deg,var(--gold-lift),var(--gold)); color:#0a0e14;
    font-weight:800; font-size:1rem; line-height:1;
  }
  details.add > summary .hint { margin-left:auto; color:var(--muted); font:500 .8125rem/1 var(--body); }
  .addbody { padding:4px 18px 20px; border-top:1px solid var(--line); }
  .grid {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr));
    gap:14px; margin-top:18px;
  }
  .grid .wide { grid-column:1/-1; }

  /* ------------------------------------------------------ notifications --- */
  .notify { border:1px solid var(--line); border-radius:14px; background:var(--card);
            margin-bottom:26px; overflow:hidden; }
  .notify > summary { list-style:none; cursor:pointer; padding:15px 18px; display:flex;
            align-items:center; gap:11px; font-family:var(--display); font-weight:700;
            font-size:.9375rem; letter-spacing:-.01em; }
  .notify > summary::-webkit-details-marker { display:none; }
  .notify > summary .hint { margin-left:auto; color:var(--muted); font:500 .8125rem/1 var(--body); }
  .bell { width:25px; height:25px; flex:none; border-radius:50%; display:grid; place-items:center;
          background:rgba(212,163,95,.16); color:var(--gold); font-size:.8125rem; }
  .notifybody { padding:4px 18px 20px; border-top:1px solid var(--line); }
  .chan { display:flex; gap:10px; align-items:baseline; padding:9px 0; border-bottom:1px solid var(--line);
          font-size:.875rem; }
  .chan:last-of-type { border-bottom:0; }
  .chan .k { flex:0 0 82px; color:var(--muted); font-size:.625rem; text-transform:uppercase;
             letter-spacing:.15em; font-weight:700; }
  .chan .v { word-break:break-all; }
  .chan .off { color:var(--muted); }
  .res { margin-top:4px; }
  .res li { list-style:none; padding:7px 0; font-size:.875rem; }
  .res .good::before { content:'✓ '; color:#7fb089; font-weight:700; }
  .res .bad::before  { content:'✕ '; color:#ef6d62; font-weight:700; }
  .res .bad { color:#ef6d62; }

  /* --------------------------------------------------------- the cards --- */
  .card {
    background:var(--card); border:1px solid var(--line); border-radius:14px;
    padding:18px; margin-bottom:13px; transition:border-color .16s;
  }
  .card:hover { border-color:var(--strong); }
  .card.hi { border-color:var(--gold); box-shadow:0 0 0 1px var(--gold), 0 10px 34px -18px rgba(212,163,95,.6); }
  .top { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start; }
  .who { font-family:var(--display); font-size:1.125rem; font-weight:700; letter-spacing:-.018em; }
  .meta { color:var(--muted); font-size:.8125rem; margin-top:4px; }
  .src {
    display:inline-block; font-size:.625rem; text-transform:uppercase; letter-spacing:.13em;
    font-weight:700; padding:2px 7px; border-radius:5px; border:1px solid var(--line);
    color:var(--muted); vertical-align:1px;
  }
  .src.manual { color:var(--gold); border-color:rgba(212,163,95,.4); background:rgba(212,163,95,.09); }
  .pill {
    font-size:.625rem; font-weight:800; text-transform:uppercase; letter-spacing:.13em;
    padding:5px 11px; border-radius:999px; white-space:nowrap;
  }
  .s-new{background:linear-gradient(140deg,var(--gold-lift),var(--gold));color:#0a0e14}
  .s-contacted{background:#2f5a86;color:#fff}
  .s-scheduled{background:#4a6b52;color:#fff}
  .s-sold{background:#3c3f46;color:#cbd3dd}
  .s-closed{background:#23272c;color:#8b95a3}
  dl.facts { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:13px 20px; margin:17px 0 0; }
  dl.facts dt { color:var(--muted); font-size:.625rem; text-transform:uppercase; letter-spacing:.15em; font-weight:700; }
  dl.facts dd { margin:4px 0 0; font-size:.9375rem; word-break:break-word; }
  .note {
    margin-top:15px; padding:13px 15px; border-radius:10px; background:var(--sunken);
    border:1px solid var(--line); color:var(--dim); font-size:.9375rem; white-space:pre-wrap;
  }
  form.row {
    display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;
    margin-top:16px; padding-top:16px; border-top:1px solid var(--line);
  }

  /* --------------------------------------------------------- controls --- */
  label.f {
    display:flex; flex-direction:column; gap:6px; font-size:.625rem; text-transform:uppercase;
    letter-spacing:.15em; font-weight:700; color:var(--muted);
  }
  select, input[type=text], input[type=tel], input[type=email], input[type=password], textarea {
    background:var(--sunken); border:1px solid var(--line); border-radius:9px; color:var(--text);
    padding:10px 12px; font:400 .875rem/1.45 var(--body); min-width:0; width:100%;
    transition:border-color .14s, box-shadow .14s;
  }
  select:focus, input:focus, textarea:focus {
    outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(212,163,95,.16);
  }
  ::placeholder { color:#5e6a7a; }
  textarea { resize:vertical; }
  form.row label.f { flex:1; min-width:150px; }
  button {
    background:linear-gradient(140deg,var(--gold-lift),var(--gold)); color:#0a0e14; border:0;
    border-radius:9px; padding:11px 20px; font:700 .875rem/1 var(--body); cursor:pointer;
    letter-spacing:.01em; transition:filter .14s, transform .14s;
  }
  button:hover { filter:brightness(1.07); }
  button:active { transform:translateY(1px); }
  a.ghost, button.ghost {
    background:none; color:var(--dim); border:1px solid var(--strong); border-radius:999px;
    padding:8px 15px; font:600 .8125rem/1 var(--body); text-decoration:none; display:inline-block;
  }
  a.ghost:hover, button.ghost:hover { color:var(--text); border-color:var(--dim); }

  /* ----------------------------------------------------------- notices --- */
  .flash {
    display:flex; align-items:center; gap:9px; border-radius:11px; padding:11px 15px;
    margin-bottom:20px; font-size:.875rem; font-weight:500;
    background:rgba(74,107,82,.2); border:1px solid #4a6b52;
  }
  .err { color:#ef6d62; font-size:.875rem; margin:14px 0 0; }
  .err.box {
    background:rgba(239,109,98,.12); border:1px solid rgba(239,109,98,.55);
    border-radius:10px; padding:11px 14px; margin-top:16px;
  }
  .empty {
    text-align:center; padding:66px 24px; border:1px dashed var(--line);
    border-radius:14px; color:var(--muted);
  }
  .empty .big { font-family:var(--display); font-size:1.1875rem; font-weight:700; color:var(--dim); margin:0 0 7px; }

  /* ------------------------------------------------------------- login --- */
  .login { max-width:392px; margin:13vh auto; }
  .login .box { background:var(--card); border:1px solid var(--line); border-radius:17px; padding:34px 30px; }
  .login img {
    height:58px; width:auto; display:block; margin:0 auto 20px; border-radius:13px;
    border:1px solid rgba(212,163,95,.38); box-shadow:0 6px 26px -8px rgba(212,163,95,.45);
  }
  .login h1 { font-size:1.5rem; text-align:center; }
  .login .sub { text-align:center; color:var(--muted); font-size:.8125rem; margin:8px 0 26px; }
  .login button { width:100%; margin-top:17px; padding:13px; font-size:.9375rem; }

  @media (max-width:640px) {
    .brand { padding-top:16px; }
    dl.facts { grid-template-columns:1fr 1fr; }
    form.row label.f { flex-basis:100%; }
  }
</style>
</head>
<body>
<div class="wrap">

<?php if (!$authed): ?>

  <div class="login">
    <div class="box">
      <img src="/logo-mark.png" alt="">
      <h1>Lead desk</h1>
      <p class="sub">Bassett Court Holdings</p>
      <form method="post">
        <label class="f">Password
          <input type="password" name="password" autocomplete="current-password" autofocus required>
        </label>
        <button type="submit">Sign in</button>
        <?php if ($loginError !== ''): ?><p class="err"><?= e($loginError) ?></p><?php endif; ?>
      </form>
    </div>
  </div>

<?php else: ?>

  <div class="brand">
    <img src="/logo-mark.png" alt="">
    <div class="names">
      <div class="eyebrow">Bassett Court</div>
      <div class="co">Holdings</div>
    </div>
    <div class="right"><a class="ghost" href="?logout=1">Sign out</a></div>
  </div>

  <h1>Lead desk</h1>
  <p class="lede">
    Everyone who has asked about a vehicle — from the website, or added here after a call.
  </p>
  <div class="rule"></div>

  <?php if (isset($_GET['added'])): ?>
    <div class="flash">Lead added.</div>
  <?php elseif (isset($_GET['saved'])): ?>
    <div class="flash">Saved.</div>
  <?php endif; ?>

  <nav class="tiles">
    <a class="tile" href="./" <?= $filter === '' ? 'aria-current="true"' : '' ?>>
      <span class="n"><?= $total ?></span><span class="k">All leads</span>
    </a>
    <?php foreach (statuses() as $s): ?>
      <a class="tile" href="?status=<?= e($s) ?>" <?= $filter === $s ? 'aria-current="true"' : '' ?>>
        <span class="n"><?= (int) ($counts[$s] ?? 0) ?></span><span class="k"><?= e(ucfirst($s)) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <details class="notify"<?= $testResults !== null ? ' open' : '' ?>>
    <summary><span class="bell">!</span> Notifications <span class="hint">Where new bookings are sent</span></summary>
    <div class="notifybody">
      <?php
        $smsTo = implode(', ', recipients($config['sms']['to'] ?? ''));
        $mailTo = implode(', ', recipients($config['notify_email'] ?? ''));
        $hook = trim((string) ($config['notify_url'] ?? ''));
      ?>
      <div class="chan"><span class="k">Text</span>
        <span class="v<?= $smsTo === '' ? ' off' : '' ?>"><?= $smsTo === '' ? 'off' : e($smsTo) ?></span></div>
      <div class="chan"><span class="k">Email</span>
        <span class="v<?= $mailTo === '' ? ' off' : '' ?>"><?= $mailTo === '' ? 'off' : e($mailTo) ?></span></div>
      <div class="chan"><span class="k">Webhook</span>
        <span class="v<?= $hook === '' ? ' off' : '' ?>"><?= $hook === '' ? 'off' : e(mask_url($hook)) ?></span></div>

      <?php if ($testResults !== null): ?>
        <ul class="res">
          <?php foreach ($testResults as $r): ?>
            <?php
              // The webhook URL is a credential, and a failing result line is
              // exactly what gets screenshotted. Mask it here too, not just in
              // the summary above.
              $shown = $r['channel'] === 'webhook' ? mask_url((string) $r['target']) : (string) $r['target'];
            ?>
            <li class="<?= $r['error'] === '' ? 'good' : 'bad' ?>">
              <?= e($r['channel']) ?><?= $shown !== '' ? ' to ' . e($shown) : '' ?>
              — <?= $r['error'] === '' ? 'sent' : e($r['error']) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <form method="post" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="test_notify" value="1">
        <button type="submit">Send a test</button>
      </form>
      <p style="margin:12px 0 0;color:var(--muted);font-size:.8125rem">
        Edit <strong>config.php</strong> on the server to change any of these.
      </p>
    </div>
  </details>

  <details class="add"<?= $draftOpen ? ' open' : '' ?>>
    <summary><span class="plus">+</span> Add a lead <span class="hint">Walk-in, phone call, referral</span></summary>
    <div class="addbody">
      <?php if ($addError !== ''): ?><p class="err box"><?= e($addError) ?></p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="add_lead" value="1">
        <div class="grid">
          <label class="f">Name
            <input type="text" name="name" required maxlength="120" placeholder="Marcus Webb"
                   value="<?= e($draft['name'] ?? '') ?>">
          </label>
          <label class="f">Phone
            <input type="tel" name="phone" maxlength="40" placeholder="(864) 555-0199"
                   value="<?= e($draft['phone'] ?? '') ?>">
          </label>
          <label class="f">Email
            <input type="email" name="email" maxlength="160" placeholder="optional"
                   value="<?= e($draft['email'] ?? '') ?>">
          </label>
          <label class="f">Vehicle they want
            <input type="text" name="vehicle_label" maxlength="200" placeholder="2023 Silverado, or a 3-row SUV"
                   value="<?= e($draft['vehicle_label'] ?? '') ?>">
          </label>
          <label class="f">When they can come in
            <input type="text" name="preferred_day_label" maxlength="40" placeholder="Thursday afternoon"
                   value="<?= e($draft['preferred_day_label'] ?? '') ?>">
          </label>
          <label class="f">Trade-in
            <input type="text" name="trade_in" maxlength="60" placeholder="2016 Altima, 110k"
                   value="<?= e($draft['trade_in'] ?? '') ?>">
          </label>
          <label class="f">Status
            <select name="status">
              <?php foreach (statuses() as $s): ?>
                <option value="<?= e($s) ?>" <?= ($draft['status'] ?? 'new') === $s ? 'selected' : '' ?>>
                  <?= e(ucfirst($s)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="f wide">What they said
            <textarea name="message" rows="3" maxlength="4000"
                      placeholder="Came by about the Tahoe. Financing through his credit union, wants to bring his wife Saturday."><?= e($draft['message'] ?? '') ?></textarea>
          </label>
        </div>
        <button type="submit" style="margin-top:17px">Add lead</button>
      </form>
    </div>
  </details>

  <?php if (!$rows): ?>
    <div class="empty">
      <p class="big"><?= $filter === '' ? 'No leads yet.' : 'Nothing ' . e($filter) . '.' ?></p>
      <p style="margin:0">Requests from the booking form land here on their own. Anything else, add it above.</p>
    </div>
  <?php endif; ?>

  <?php foreach ($rows as $r): ?>
    <article class="card<?= $highlight === (int) $r['id'] ? ' hi' : '' ?>" id="r<?= (int) $r['id'] ?>">
      <div class="top">
        <div>
          <div class="who"><?= e($r['name']) ?></div>
          <div class="meta">
            #<?= (int) $r['id'] ?> ·
            <?= e((new DateTimeImmutable($r['created_at']))->format('D j M Y, g:ia')) ?> ·
            <span class="src<?= ($r['source'] ?? 'web') === 'manual' ? ' manual' : '' ?>"><?= e(lead_source_label($r['source'] ?? 'web')) ?></span>
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
        <label class="f" style="flex:0 0 auto;min-width:150px">Status
          <select name="status">
            <?php foreach (statuses() as $s): ?>
              <option value="<?= e($s) ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="f" style="flex:1 1 280px">Notes
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
