<?php
require_once 'includes/auth.php'; require_role(['ADMIN', 'OFFICIAL', 'CLERK', 'CONDUCTOR', 'DRIVER', 'OWNER']);
$pdo = db(); $msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $current = q($pdo, "SELECT password_hash FROM app_user WHERE id=? AND status='ACTIVE'", [user()['id']])->fetch();
    if (!$current || !password_verify((string)($_POST['current_password'] ?? ''), $current['password_hash'])) {
        $err = 'Current password is incorrect.';
    } elseif (strlen((string)($_POST['new_password'] ?? '')) < 12) {
        $err = 'New password must be at least 12 characters.';
    } elseif ((string)($_POST['new_password'] ?? '') !== (string)($_POST['confirm_password'] ?? '')) {
        $err = 'New password confirmation does not match.';
    } else {
        $pdo->beginTransaction();
        try {
            q($pdo, "UPDATE app_user SET password_hash=?,failed_logins=0,locked_until=NULL WHERE id=?",
              [password_hash($_POST['new_password'], PASSWORD_DEFAULT), user()['id']]);
            audit($pdo, user()['id'], 'UPDATE', 'app_user', user()['id'], null, ['action' => 'PASSWORD_CHANGED']);
            $pdo->commit();
            session_regenerate_id(true);
            $msg = 'Password updated.';
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = $t->getMessage();
        }
    }
}
$title = 'My account'; include 'includes/header.php'; ?>
<h1>My account</h1>
<p>Signed in as <?= e(user()['name']) ?> (<?= e(user()['role']) ?>).</p>
<?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<h2>Change password</h2>
<form method="post"><?= csrf_field() ?>
<label>Current password</label><input type="password" name="current_password" required>
<label>New password (at least 12 characters)</label><input type="password" name="new_password" minlength="12" required>
<label>Confirm new password</label><input type="password" name="confirm_password" minlength="12" required>
<button>Update password</button></form>
<?php include 'includes/footer.php';
