<?php
require_once 'includes/auth.php'; require_role(['ADMIN', 'OFFICIAL', 'CLERK', 'CONDUCTOR']);
$pdo = db(); $msg = $err = ''; $uid = user()['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $a = $_POST['action'] ?? '';
        if ($a === 'queue' && has_role('ADMIN', 'OFFICIAL', 'CLERK')) { register_queue($pdo, (int)$_POST['vehicle'], (int)$_POST['stage'], $uid); $msg = 'Vehicle added to the queue.'; }
        elseif ($a === 'open' && has_role('ADMIN', 'OFFICIAL', 'CLERK')) { open_trip($pdo, (int)$_POST['queue'], (int)$_POST['driver'], (int)$_POST['conductor'], (int)$_POST['route'], $_POST['sched'], $uid); $msg = 'Trip opened.'; }
        elseif ($a === 'start' && has_role('ADMIN', 'OFFICIAL', 'CLERK')) { start_scheduled_trip($pdo, (int)$_POST['trip'], $uid); $msg = 'Scheduled trip is now open for boarding.'; }
        elseif ($a === 'depart' && has_role('ADMIN', 'OFFICIAL', 'CLERK')) { confirm_departure($pdo, (int)$_POST['trip'], (float)$_POST['fee'], $uid); $msg = 'Departure confirmed.'; }
        elseif ($a === 'board' && has_role('ADMIN', 'OFFICIAL', 'CONDUCTOR')) { $r = add_boarding($pdo, (int)$_POST['trip'], (int)$_POST['seat'], $_POST['method'], $uid, $_POST['mpesa'] ?? null); $msg = 'Passenger recorded. Simulated receipt ' . $r['receipt_no'] . ', fare KES ' . number_format($r['fare'], 2) . '. No real payment was processed.'; }
        elseif ($a === 'checkin' && has_role('ADMIN', 'OFFICIAL', 'CONDUCTOR')) { $receipt = check_in_reservation($pdo, (int)$_POST['trip'], (int)$_POST['booking'], $uid); $msg = 'Reservation checked in. Simulated receipt ' . $receipt . '; no real payment was processed.'; }
        elseif ($a === 'cancel_reservation' && has_role('ADMIN', 'OFFICIAL', 'CONDUCTOR')) { cancel_reservation($pdo, (int)$_POST['trip'], (int)$_POST['booking'], $uid); $msg = 'Reservation cancelled.'; }
        elseif ($a === 'close' && has_role('ADMIN', 'OFFICIAL', 'CONDUCTOR')) { $s = close_trip($pdo, (int)$_POST['trip'], (float)$_POST['cash'], $uid); $msg = 'Trip closed. Reconciliation result: ' . $s; }
        else { throw new RuntimeException('Action not permitted for your role.'); }
    } catch (Throwable $t) { $err = $t->getMessage(); }
}
ensure_daily_trips($pdo);
$stages = q($pdo, "SELECT * FROM stage")->fetchAll();
$vehicles = q($pdo, "SELECT id, reg_no FROM vehicle WHERE status='ACTIVE' ORDER BY reg_no")->fetchAll();
$routes = q($pdo, "SELECT r.id,r.origin_stage_id,CONCAT(o.stage_name,' to ',d.stage_name) AS name FROM route r JOIN stage o ON o.id=r.origin_stage_id JOIN stage d ON d.id=r.dest_stage_id")->fetchAll();
$crew = q($pdo, "SELECT c.id,c.crew_type,u.full_name FROM crew_member c JOIN app_user u ON u.id=c.user_id JOIN role r ON r.id=u.role_id WHERE u.status='ACTIVE' AND r.role_name=c.crew_type")->fetchAll();
$queue = q($pdo, "SELECT qe.*, v.reg_no, s.stage_name FROM queue_entry qe JOIN vehicle v ON v.id=qe.vehicle_id JOIN stage s ON s.id=qe.stage_id WHERE qe.queue_date=CURDATE() AND qe.status='WAITING' ORDER BY qe.stage_id, qe.queue_position")->fetchAll();
$trips = q($pdo, "SELECT t.*,v.reg_no,CONCAT(o.stage_name,' to ',d.stage_name) AS route_name
                  FROM trip t JOIN vehicle v ON v.id=t.vehicle_id JOIN route r ON r.id=t.route_id
                  JOIN stage o ON o.id=r.origin_stage_id JOIN stage d ON d.id=r.dest_stage_id
                  WHERE (t.status='SCHEDULED' AND DATE(t.scheduled_departure)=CURDATE()) OR t.status IN ('LOADING','DEPARTED')
                  ORDER BY t.scheduled_departure")->fetchAll();
$title = 'Trips'; include 'includes/header.php'; ?>
<h1>Queue, trips and boarding</h1>
<?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<?php if (has_role('ADMIN', 'OFFICIAL', 'CLERK')): ?>
<h2>Queue register (today)</h2>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="queue">
<div><label>Vehicle</label><select name="vehicle"><?php foreach ($vehicles as $v): ?><option value="<?= $v['id'] ?>"><?= e($v['reg_no']) ?></option><?php endforeach; ?></select></div>
<div><label>Stage</label><select name="stage"><?php foreach ($stages as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['stage_name']) ?></option><?php endforeach; ?></select></div>
<div><button>Register arrival</button></div></form>
<table><thead><tr><th>Stage</th><th>Position</th><th>Vehicle</th><th>Queued at</th><th>Open trip</th></tr></thead><tbody>
<?php foreach ($queue as $x): ?><tr><td><?= e($x['stage_name']) ?></td><td><?= (int)$x['queue_position'] ?></td><td><?= e($x['reg_no']) ?></td><td><?= e($x['queued_at']) ?></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="open"><input type="hidden" name="queue" value="<?= $x['id'] ?>">
<select name="driver"><?php foreach ($crew as $c) if ($c['crew_type'] === 'DRIVER'): ?><option value="<?= $c['id'] ?>"><?= e($c['full_name']) ?></option><?php endif; ?></select>
<select name="conductor"><?php foreach ($crew as $c) if ($c['crew_type'] === 'CONDUCTOR'): ?><option value="<?= $c['id'] ?>"><?= e($c['full_name']) ?></option><?php endif; ?></select>
<select name="route" required><?php $hasRoute = false; foreach ($routes as $r) { if ((int)$r['origin_stage_id'] === (int)$x['stage_id']) { $hasRoute = true; ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php } } if (!$hasRoute) { ?><option value="" selected disabled>No route starts at this stage</option><?php } ?></select>
<input type="datetime-local" name="sched" value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>" required><button>Open trip</button></form></td></tr>
<?php endforeach; if (!$queue): ?><tr><td colspan="5">Queue is empty.</td></tr><?php endif; ?></tbody></table>
<?php endif; ?>
<h2>Today's scheduled and open trips</h2>
<?php foreach ($trips as $t): $taken = q($pdo, "SELECT seat_no FROM booking WHERE trip_id=? AND status IN ('HELD','CONFIRMED','BOARDED')", [$t['id']])->fetchAll(PDO::FETCH_COLUMN); $reservations = q($pdo, "SELECT b.id,b.seat_no,b.fare_amount,p.full_name FROM booking b JOIN passenger p ON p.id=b.passenger_id WHERE b.trip_id=? AND b.channel='ONLINE' AND b.status='CONFIRMED' ORDER BY b.seat_no", [$t['id']])->fetchAll(); ?>
<div style="border:1px solid #888;padding:8px;margin:8px 0">
<strong>Trip <?= $t['id'] ?>: <?= e($t['route_name']) ?>, <?= e(date('Y-m-d H:i', strtotime($t['scheduled_departure']))) ?>, <?= e($t['reg_no']) ?></strong> (<?= e($t['status']) ?>), seats left <?= seats_left($pdo, (int)$t['id']) ?>
<div class="seats"><?php for ($i = 1; $i <= 14; $i++): ?><span class="<?= in_array($i, $taken) ? 'taken' : '' ?>"><?= $i ?></span><?php endfor; ?></div>
<?php if ($reservations): ?><h3>Online reservations</h3><table><thead><tr><th>Seat</th><th>Passenger</th><th>Fare</th><th>Action</th></tr></thead><tbody>
<?php foreach ($reservations as $r): ?><tr><td><?= (int)$r['seat_no'] ?></td><td><?= e($r['full_name']) ?></td><td>KES <?= number_format((float)$r['fare_amount'], 2) ?></td><td>
<?php if ($t['status'] === 'SCHEDULED' && substr($t['scheduled_departure'], 0, 10) === q($pdo, "SELECT CURDATE() AS today")->fetch()['today'] && has_role('ADMIN','OFFICIAL','CLERK')): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="start"><input type="hidden" name="trip" value="<?= (int)$t['id'] ?>"><button>Start today's trip</button></form>
<?php endif; ?>
<?php if ($t['status'] === 'LOADING' && has_role('ADMIN','OFFICIAL','CONDUCTOR')): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="checkin"><input type="hidden" name="trip" value="<?= (int)$t['id'] ?>"><input type="hidden" name="booking" value="<?= (int)$r['id'] ?>"><button>Check in</button></form>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_reservation"><input type="hidden" name="trip" value="<?= (int)$t['id'] ?>"><input type="hidden" name="booking" value="<?= (int)$r['id'] ?>"><button>Cancel</button></form>
<?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
<?php if ($t['status'] === 'LOADING' && has_role('ADMIN', 'OFFICIAL', 'CONDUCTOR')): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="board"><input type="hidden" name="trip" value="<?= $t['id'] ?>">
<label>Seat</label><input type="number" name="seat" min="1" max="14" required>
<label>Simulated payment method (fare comes from the fare table)</label><select name="method" required><option value="CASH">Simulated cash</option><option value="MPESA">Simulated M-Pesa</option></select>
<p>No real cash or M-Pesa payment is collected. Do not enter real payment details.</p><button>Add passenger</button></form>
<?php endif; ?>
<?php if ($t['status'] === 'LOADING' && has_role('ADMIN', 'OFFICIAL', 'CLERK')): ?>
<form method="post" data-confirm="Confirm departure?"><?= csrf_field() ?><input type="hidden" name="action" value="depart"><input type="hidden" name="trip" value="<?= $t['id'] ?>">
<label>Simulated stage fee (KES)</label><input type="number" step="0.01" min="0" name="fee" required><button>Confirm departure</button></form>
<?php endif; ?>
<?php if ($t['status'] === 'DEPARTED' && has_role('ADMIN', 'OFFICIAL', 'CONDUCTOR')): ?>
<form method="post" data-confirm="Close this trip?"><?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="trip" value="<?= $t['id'] ?>">
<label>Simulated cash count (KES)</label><input type="number" step="0.01" min="0" name="cash" required><button>Close trip</button></form>
<?php endif; ?></div>
<?php endforeach; if (!$trips): ?><p>No open trips.</p><?php endif; ?>
<?php include 'includes/footer.php';
