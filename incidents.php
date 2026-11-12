<?php
require_once 'includes/auth.php'; require_role(['ADMIN', 'OFFICIAL']);
$pdo = db(); $msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $id = (int)$_POST['id']; $status = $_POST['status']; $note = trim($_POST['review_note']);
        if (!in_array($status, ['NEW', 'IN_PROGRESS', 'RESOLVED'], true)) throw new InvalidArgumentException('Invalid incident status');
        if (!q($pdo, "SELECT id FROM incident WHERE id=?", [$id])->fetch()) throw new InvalidArgumentException('Incident not found');
        q($pdo, "INSERT INTO incident_review (incident_id,status,review_note,reviewed_by) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE status=VALUES(status), review_note=VALUES(review_note), reviewed_by=VALUES(reviewed_by)",
          [$id, $status, $note ?: null, user()['id']]);
        audit($pdo, user()['id'], 'UPDATE', 'incident', $id, null, ['status' => $status, 'review_note' => $note]);
        $msg = 'Incident review saved.';
    } catch (Throwable $t) { $err = $t->getMessage(); }
}
$rows = q($pdo, "SELECT i.*, v.reg_no, t.scheduled_departure, u.full_name AS reporter,
                 r.status AS review_status, r.review_note, reviewer.full_name AS reviewer
                 FROM incident i JOIN vehicle v ON v.id=i.vehicle_id
                 LEFT JOIN trip t ON t.id=i.trip_id LEFT JOIN app_user u ON u.id=i.reported_by
                 LEFT JOIN incident_review r ON r.incident_id=i.id
                 LEFT JOIN app_user reviewer ON reviewer.id=r.reviewed_by
                 ORDER BY COALESCE(r.status,'NEW')='RESOLVED', i.reported_at DESC")->fetchAll();
$title = 'Incidents'; include 'includes/header.php'; ?>
<h1>Incident management</h1>
<?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<table><thead><tr><th>Reported</th><th>Vehicle / trip</th><th>Type</th><th>Description</th><th>Reporter</th><th>Review</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
<td><?= e($r['reported_at']) ?></td><td><?= e($r['reg_no']) ?><?= $r['trip_id'] ? ' / Trip ' . (int)$r['trip_id'] : '' ?></td>
<td><?= e($r['incident_type']) ?></td><td><?= e($r['description'] ?? '') ?></td><td><?= e($r['reporter'] ?? '') ?></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<label>Status</label><select name="status"><?php foreach (['NEW','IN_PROGRESS','RESOLVED'] as $s): ?><option <?= ($r['review_status'] ?? 'NEW') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
<label>Review / resolution</label><textarea name="review_note" rows="2" maxlength="1000"><?= e($r['review_note'] ?? '') ?></textarea>
<?php if ($r['reviewer']): ?><small>Last reviewed by <?= e($r['reviewer']) ?></small><?php endif; ?><button>Save review</button></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6">No incidents reported.</td></tr><?php endif; ?>
</tbody></table>
<?php include 'includes/footer.php';
