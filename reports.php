<?php
require_once 'includes/auth.php'; require_role(['ADMIN', 'OFFICIAL']);
$pdo = db(); $err = '';
$from = $_GET['from'] ?? date('Y-m-01'); $to = $_GET['to'] ?? date('Y-m-d');
$validDate = function ($value) {
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
        && checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4));
};
$rows = [];
if (!$validDate($from) || !$validDate($to) || $from > $to) {
    $err = 'Choose a valid date range with the start date on or before the end date.';
} else {
    $rows = q($pdo, "SELECT t.id,t.scheduled_departure,t.status,t.recon_status,t.expected_revenue,t.counted_cash,
                     v.reg_no,CONCAT(o.stage_name,' to ',d.stage_name) AS route_name,
                     (SELECT COUNT(*) FROM booking b WHERE b.trip_id=t.id AND b.status='BOARDED') AS boarded,
                     (SELECT COALESCE(SUM(p.amount),0) FROM payment p WHERE p.trip_id=t.id AND p.status='SUCCESS') AS recorded_revenue,
                     (SELECT COALESCE(SUM(p.amount),0) FROM payment p WHERE p.trip_id=t.id AND p.status='SUCCESS' AND p.method='CASH') AS cash_received
                     FROM trip t JOIN vehicle v ON v.id=t.vehicle_id JOIN route rt ON rt.id=t.route_id
                     JOIN stage o ON o.id=rt.origin_stage_id JOIN stage d ON d.id=rt.dest_stage_id
                     WHERE DATE(t.scheduled_departure) BETWEEN ? AND ? ORDER BY t.scheduled_departure DESC", [$from, $to])->fetchAll();
}
$totalRevenue = 0.0; $totalCash = 0.0; $totalBoarded = 0;
foreach ($rows as $r) { $totalRevenue += (float)$r['recorded_revenue']; $totalCash += (float)$r['cash_received']; $totalBoarded += (int)$r['boarded']; }
$title = 'Trip and financial reports'; include 'includes/header.php'; ?>
<h1>Trip and financial reports</h1>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="get" class="row"><div><label>From</label><input type="date" name="from" value="<?= e($from) ?>" required></div>
<div><label>To</label><input type="date" name="to" value="<?= e($to) ?>" required></div><div><button>Run report</button></div></form>
<?php if (!$err): ?>
<p>Trips: <?= count($rows) ?> | Passengers boarded: <?= $totalBoarded ?> | Recorded revenue: KES <?= number_format($totalRevenue, 2) ?> | Recorded cash: KES <?= number_format($totalCash, 2) ?></p>
<table><thead><tr><th>Departure</th><th>Trip</th><th>Route</th><th>Vehicle</th><th>Status</th><th>Boarded</th><th>Recorded revenue</th><th>Cash</th><th>Expected revenue</th><th>Counted cash</th><th>Reconciliation</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['scheduled_departure']) ?></td><td><?= (int)$r['id'] ?></td><td><?= e($r['route_name']) ?></td><td><?= e($r['reg_no']) ?></td><td><?= e($r['status']) ?></td><td><?= (int)$r['boarded'] ?></td><td><?= number_format((float)$r['recorded_revenue'], 2) ?></td><td><?= number_format((float)$r['cash_received'], 2) ?></td><td><?= $r['expected_revenue'] === null ? 'N/A' : number_format((float)$r['expected_revenue'], 2) ?></td><td><?= $r['counted_cash'] === null ? 'N/A' : number_format((float)$r['counted_cash'], 2) ?></td><td><?= e($r['recon_status']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="11">No trips found for this date range.</td></tr><?php endif; ?>
</tbody></table><?php endif; ?>
<p>Recorded revenue includes successful demo reservations in this prototype; these records do not represent money collected.</p>
<?php include 'includes/footer.php';
