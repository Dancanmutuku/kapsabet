<?php
require_once 'includes/auth.php'; require_role(['DRIVER', 'OWNER']);
$pdo = db(); $title = 'My dashboard'; include 'includes/header.php'; ?>
<h1>Welcome, <?= e(user()['name']) ?></h1>
<?php if (has_role('DRIVER')): ?>
<h2>My assigned trips</h2>
<?php
$crewId = q($pdo, "SELECT id FROM crew_member WHERE user_id=? AND crew_type='DRIVER'", [user()['id']])->fetchColumn();
$rows = $crewId ? q($pdo, "SELECT t.id,t.scheduled_departure,t.status,v.reg_no,
                           CONCAT(o.stage_name,' to ',d.stage_name) AS route_name
                           FROM trip t JOIN vehicle v ON v.id=t.vehicle_id JOIN route r ON r.id=t.route_id
                           JOIN stage o ON o.id=r.origin_stage_id JOIN stage d ON d.id=r.dest_stage_id
                           WHERE t.driver_id=? ORDER BY t.scheduled_departure DESC", [$crewId])->fetchAll() : [];
?>
<table><thead><tr><th>Trip</th><th>Departure</th><th>Route</th><th>Vehicle</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= (int)$r['id'] ?></td><td><?= e($r['scheduled_departure']) ?></td><td><?= e($r['route_name']) ?></td><td><?= e($r['reg_no']) ?></td><td><?= e($r['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5">No trips assigned.</td></tr><?php endif; ?></tbody></table>
<?php else: ?>
<h2>My vehicles</h2>
<?php $rows = q($pdo, "SELECT id,reg_no,make_model,seat_capacity,status FROM vehicle WHERE owner_id=? ORDER BY reg_no", [user()['id']])->fetchAll(); ?>
<table><thead><tr><th>Registration</th><th>Make/model</th><th>Seats</th><th>Status</th><th>Compliance</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['reg_no']) ?></td><td><?= e($r['make_model'] ?? '') ?></td><td><?= (int)$r['seat_capacity'] ?></td><td><?= e($r['status']) ?></td><td><?= e(compliance_status($pdo, (int)$r['id'])) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5">No vehicles are registered to your account.</td></tr><?php endif; ?></tbody></table>
<?php endif; ?>
<?php include 'includes/footer.php';
