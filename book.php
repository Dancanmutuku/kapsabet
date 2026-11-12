<?php
require_once 'includes/auth.php';
$pdo = db(); $msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $r = add_demo_reservation($pdo, (int)$_POST['trip'], (int)$_POST['seat'], $_POST['name'], $_POST['phone'], isset($_POST['consent']));
        $msg = 'Reservation confirmed. Reference ' . $r['reference'] . ', fare KES ' . number_format($r['fare'], 2) . '. No payment was collected.';
    } catch (Throwable $t) { $err = $t->getMessage(); }
}
$trips = q($pdo, "SELECT t.id, t.scheduled_departure, v.reg_no, v.seat_capacity,
                  CONCAT(o.stage_name,' to ',d.stage_name) AS route_name, r.id AS route_id
                  FROM trip t JOIN vehicle v ON v.id=t.vehicle_id JOIN route r ON r.id=t.route_id
                  JOIN stage o ON o.id=r.origin_stage_id JOIN stage d ON d.id=r.dest_stage_id
                  WHERE t.status='LOADING' AND t.scheduled_departure>=NOW()
                  ORDER BY t.scheduled_departure")->fetchAll();
$title = 'Reserve a seat'; include 'includes/header.php'; ?>
<h1>Reserve a seat</h1>
<p><strong>Prototype only:</strong> this reservation does not contact M-Pesa or collect money. At check-in, staff can record a clearly labeled demo fare for reporting; no actual payment is processed.</p>
<?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<?php if ($trips): ?>
<form method="post"><?= csrf_field() ?>
<label>Departure</label><select name="trip" required>
<?php foreach ($trips as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e(date('Y-m-d H:i', strtotime($t['scheduled_departure'])) . ' - ' . $t['route_name'] . ' - ' . $t['reg_no'] . ' - KES ' . number_format((float)current_fare($pdo, (int)$t['route_id'], substr($t['scheduled_departure'], 0, 10)), 2)) ?></option><?php endforeach; ?>
</select>
<label>Seat number</label><input type="number" name="seat" min="1" max="14" required>
<label>Passenger name</label><input name="name" maxlength="100" required>
<label>Phone number</label><input name="phone" placeholder="07XX XXX XXX" required>
<label><input type="checkbox" name="consent" required> I consent to storing my name and phone number for this reservation.</label>
<button>Confirm demo reservation</button></form>
<?php else: ?><p>No departures are currently open for reservations.</p><?php endif; ?>
<?php include 'includes/footer.php';
