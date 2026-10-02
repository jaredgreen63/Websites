<?php
declare(strict_types=1);
/**
 * One-time helper: turn a password into the hash config.php expects.
 * Delete this file afterwards — the admin will not load while it exists.
 */
$hash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['password'])) {
    $hash = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
}
?><!doctype html>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Generate admin password hash</title>
<style>
 body{background:#0a0e14;color:#f2f5f8;font:15px/1.6 ui-sans-serif,system-ui,sans-serif;margin:0;padding:12vh 16px}
 .w{max-width:620px;margin:0 auto}
 input,button{font:inherit;border-radius:8px;padding:10px 12px;border:1px solid #303c4c}
 input{background:#0e131b;color:#f2f5f8;width:100%}
 button{background:#d4a35f;color:#0a0e14;border:0;font-weight:700;margin-top:12px;cursor:pointer}
 code{display:block;background:#0e131b;border:1px solid #303c4c;border-radius:8px;padding:14px;
      margin-top:14px;word-break:break-all;font-size:13px;color:#d4a35f}
 .warn{background:rgba(239,109,98,.14);border:1px solid #ef6d62;border-radius:10px;padding:12px 14px;margin-top:22px;font-size:14px}
</style>
<div class="w">
<h1 style="letter-spacing:-.02em">Admin password</h1>
<p style="color:#aab4c2">Pick a password, paste the result into <code style="display:inline;padding:2px 6px;background:#0e131b">config.php</code>, then delete this file.</p>
<form method="post">
  <input type="password" name="password" placeholder="New admin password" autofocus required minlength="10">
  <button type="submit">Generate</button>
</form>
<?php if ($hash !== ''): ?>
  <p style="margin-top:22px;color:#aab4c2">Put this in <strong>config.php</strong> as <strong>admin_password_hash</strong>:</p>
  <code><?= htmlspecialchars($hash, ENT_QUOTES) ?></code>
  <div class="warn"><strong>Now delete make-hash.php.</strong> Anyone who finds it can generate hashes against your site. The admin page refuses to load until it is gone.</div>
<?php endif; ?>
</div>
