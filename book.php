<?php
require_once 'includes/auth.php';
$pdo = db(); $msg = $err = '';
$lookup = null; $lookupReference = (string)($_POST['reference'] ?? ''); $lookupPhone = (string)($_POST['phone'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = $_POST['action'] ?? 'reserve';
        if ($action === 'lookup') {
            $lookup = find_demo_reservation($pdo, $lookupReference, $lookupPhone);
            if (!$lookup) $err = 'Reservation not found. Check the demo reference and phone number.';
        } elseif ($action === 'cancel') {
            cancel_demo_reservation($pdo, $lookupReference, $lookupPhone);
            $msg = 'Reservation cancelled. The simulated payment was reversed; no money was moved.';
            $lookup = find_demo_reservation($pdo, $lookupReference, $lookupPhone);
        } else {
            $r = add_demo_reservation($pdo, (int)($_POST['trip'] ?? 0), (int)($_POST['seat'] ?? 0),
                (string)($_POST['name'] ?? ''), (string)($_POST['phone'] ?? ''), isset($_POST['consent']));
            $msg = 'Reservation confirmed. DEMO payment recorded: receipt ' . $r['reference'] . ', fare KES ' . number_format($r['fare'], 2) . '. No real payment was processed or collected.';
        }
    } catch (Throwable $t) { $err = $t->getMessage(); }
}
$trips = q($pdo, "SELECT t.id, t.scheduled_departure, v.reg_no, v.seat_capacity,
                  CONCAT(o.stage_name,' to ',d.stage_name) AS route_name, r.id AS route_id,
                  (SELECT GROUP_CONCAT(b.seat_no ORDER BY b.seat_no) FROM booking b
                   WHERE b.trip_id=t.id AND b.status IN ('HELD','CONFIRMED','BOARDED')) AS taken_seats
                  FROM trip t JOIN vehicle v ON v.id=t.vehicle_id JOIN route r ON r.id=t.route_id
                  JOIN stage o ON o.id=r.origin_stage_id JOIN stage d ON d.id=r.dest_stage_id
                  WHERE t.status='LOADING' AND t.scheduled_departure>=NOW()
                  ORDER BY t.scheduled_departure")->fetchAll();
$selectedTrip = (int)($_POST['trip'] ?? 0);
$title = 'Reserve a seat'; include 'includes/header.php'; ?>
<h1>Reserve a seat</h1>
<p><strong>Demo mode only:</strong> this flow records a simulated payment and receipt for testing. It does not contact M-Pesa or any payment provider, and no money is moved or collected.</p>
<?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<?php if ($trips): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reserve">
<label>Departure</label><select name="trip" id="trip" required>
<?php foreach ($trips as $t): ?><option value="<?= (int)$t['id'] ?>" data-capacity="<?= (int)$t['seat_capacity'] ?>" data-taken="<?= e($t['taken_seats'] ?? '') ?>" <?= $selectedTrip === (int)$t['id'] ? 'selected' : '' ?>><?= e(date('Y-m-d H:i', strtotime($t['scheduled_departure'])) . ' - ' . $t['route_name'] . ' - ' . $t['reg_no'] . ' - KES ' . number_format((float)current_fare($pdo, (int)$t['route_id'], substr($t['scheduled_departure'], 0, 10)), 2)) ?></option><?php endforeach; ?>
</select>
<label>Available seat</label><select name="seat" id="seat" required></select>
<label>Passenger name</label><input name="name" maxlength="100" required>
<label>Phone number</label><input name="phone" placeholder="07XX XXX XXX" required>
<label><input type="checkbox" name="consent" required> I consent to storing my name and phone number for this reservation.</label>
<button>Confirm demo reservation</button></form>
<script>
(function () {
  var trip = document.getElementById('trip'), seat = document.getElementById('seat');
  function refreshSeats() {
    var option = trip.options[trip.selectedIndex], capacity = parseInt(option.getAttribute('data-capacity'), 10);
    var taken = (option.getAttribute('data-taken') || '').split(','), reserved = {};
    for (var i = 0; i < taken.length; i++) reserved[taken[i]] = true;
    seat.innerHTML = '';
    for (var n = 1; n <= capacity; n++) if (!reserved[String(n)]) {
      var item = document.createElement('option');
      item.value = n; item.textContent = 'Seat ' + n; seat.appendChild(item);
    }
    if (!seat.options.length) {
      var empty = document.createElement('option');
      empty.value = ''; empty.textContent = 'No seats available'; seat.appendChild(empty);
    }
  }
  trip.addEventListener('change', refreshSeats);
  refreshSeats();
})();
</script>
<?php else: ?><p>No departures are currently open for reservations.</p><?php endif; ?>
<h2>Find or cancel a reservation</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="lookup">
<label>DEMO receipt reference</label><input name="reference" value="<?= e($lookupReference) ?>" required>
<label>Phone number used for reservation</label><input name="phone" value="<?= e($lookupPhone) ?>" placeholder="07XX XXX XXX" required>
<button>Find reservation</button></form>
<?php if ($lookup): ?><h3>Reservation details</h3>
<p>Trip <?= (int)$lookup['trip_id'] ?> - <?= e($lookup['route_name']) ?> - <?= e(date('Y-m-d H:i', strtotime($lookup['scheduled_departure']))) ?> - Seat <?= (int)$lookup['seat_no'] ?> - Fare KES <?= number_format((float)$lookup['fare_amount'], 2) ?></p>
<p>Reservation: <?= e($lookup['booking_status']) ?> | Simulated payment: <?= e($lookup['payment_status']) ?> | Receipt: <?= e($lookup['receipt_no']) ?></p>
<?php if ($lookup['booking_status'] === 'CONFIRMED' && $lookup['trip_status'] === 'LOADING'): ?>
<form method="post" data-confirm="Cancel this reservation? The demo payment will be reversed."><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
<input type="hidden" name="reference" value="<?= e($lookupReference) ?>"><input type="hidden" name="phone" value="<?= e($lookupPhone) ?>">
<button>Cancel reservation</button></form>
<?php endif; endif; ?>
<?php include 'includes/footer.php';
