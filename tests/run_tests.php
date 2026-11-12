<?php
require_once __DIR__ . '/../config.php';
$pdo = db(); $pass = 0; $fail = 0;
function check(string $name, bool $ok): void { global $pass, $fail; echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . "\n"; $ok ? $pass++ : $fail++; }
function throws(callable $f): bool { try { $f(); return false; } catch (Throwable $t) { return true; } }

echo "UNIT TESTS (no database)\n";
check('valid_reg_no accepts KDA 123X', valid_reg_no('kda 123x'));
check('valid_reg_no rejects 12ABC', !valid_reg_no('12ABC'));
check('normalize_phone 07 format', normalize_phone('0712 345 678') === '+254712345678');
check('normalize_phone rejects short number', normalize_phone('0712') === null);
check('reconcile matching totals', reconcile(500, 500, 250, 250) === 'RECONCILED');
check('reconcile cash short', reconcile(500, 500, 250, 200) === 'MISMATCH');
check('reconcile recorded below expected', reconcile(500, 250, 250, 250) === 'MISMATCH');

$pdo->beginTransaction();
$uid = (int)q($pdo, "SELECT id FROM app_user WHERE username='admin'")->fetch()['id'];
$owner = (int)q($pdo, "SELECT id FROM app_user WHERE username='owner'")->fetch()['id'];
$crew = q($pdo, "SELECT id, crew_type FROM crew_member")->fetchAll(PDO::FETCH_KEY_PAIR);
$drv = array_search('DRIVER', $crew); $cnd = array_search('CONDUCTOR', $crew);
$sfx = chr(ord('A') + random_int(0, 25));
$caseId = strtoupper(bin2hex(random_bytes(3)));
$baseFare = current_fare($pdo, 1);
$destination = (int)q($pdo, "SELECT id FROM stage WHERE stage_name='Eldoret stage'")->fetch()['id'];
q($pdo, "INSERT INTO stage (stage_name,town) VALUES (?,?)", ["Test origin $caseId", 'Test']);
$testStage1 = (int)$pdo->lastInsertId();
q($pdo, "INSERT INTO stage (stage_name,town) VALUES (?,?)", ["Test return $caseId", 'Test']);
$testStage2 = (int)$pdo->lastInsertId();
q($pdo, "INSERT INTO route (origin_stage_id,dest_stage_id,distance_km) VALUES (?,?,10)", [$testStage1, $destination]);
$testRoute1 = (int)$pdo->lastInsertId();
q($pdo, "INSERT INTO route (origin_stage_id,dest_stage_id,distance_km) VALUES (?,?,10)", [$testStage2, 1]);
$testRoute2 = (int)$pdo->lastInsertId();
q($pdo, "INSERT INTO fare (route_id,amount,effective_from,approved_by) VALUES (?,?,CURDATE(),?)", [$testRoute1, $baseFare, $uid]);
q($pdo, "INSERT INTO fare (route_id,amount,effective_from,approved_by) VALUES (?,?,CURDATE(),?)", [$testRoute2, $baseFare, $uid]);
$returnFare = current_fare($pdo, $testRoute2);

echo "\nUNIT TESTS (database functions)\n";
check('current_fare returns the active route fare', $baseFare !== null && $baseFare > 0);
set_fare($pdo, 1, 300, date('Y-m-d', strtotime('+10 days')), $uid);
check('old fare stays effective before new date', current_fare($pdo, 1) === $baseFare);
check('new fare effective on its date', current_fare($pdo, 1, date('Y-m-d', strtotime('+10 days'))) === 300.0);
check('set_fare rejects earlier start date', throws(fn() => set_fare($pdo, 1, 310, '2020-01-01', $uid)));
check('set_fare rejects zero amount', throws(fn() => set_fare($pdo, 1, 0, '2030-01-01', $uid)));
$v1 = add_vehicle($pdo, $owner, "KZ{$sfx} 111T", 'Test', 14, date('Y-m-d', strtotime('+90 days')), date('Y-m-d', strtotime('+90 days')), $uid);
check('compliance VALID', compliance_status($pdo, $v1) === 'VALID');
$v2 = add_vehicle($pdo, $owner, "KZ{$sfx} 222T", 'Test', 14, date('Y-m-d', strtotime('+10 days')), date('Y-m-d', strtotime('+90 days')), $uid);
check('compliance EXPIRING', compliance_status($pdo, $v2) === 'EXPIRING');
$v3 = add_vehicle($pdo, $owner, "KZ{$sfx} 333T", 'Test', 14, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+90 days')), $uid);
check('compliance EXPIRED', compliance_status($pdo, $v3) === 'EXPIRED');
check('add_vehicle rejects duplicate registration', throws(fn() => add_vehicle($pdo, $owner, "KZ{$sfx} 111T", 'T', 14, '2030-01-01', '2030-01-01', $uid)));
check('add_vehicle rejects bad registration', throws(fn() => add_vehicle($pdo, $owner, 'BAD', 'T', 14, '2030-01-01', '2030-01-01', $uid)));
check('add_vehicle rejects 20 seats', throws(fn() => add_vehicle($pdo, $owner, "KZ{$sfx} 444T", 'T', 20, '2030-01-01', '2030-01-01', $uid)));

echo "\nINTEGRATION TESTS (queue, trip, boarding, payment, closure)\n";
check('expired vehicle cannot join queue', throws(fn() => register_queue($pdo, $v3, $testStage1, $uid)));
$q1 = register_queue($pdo, $v1, $testStage1, $uid); $q2 = register_queue($pdo, $v2, $testStage1, $uid);
check('queue positions increase', (int)q($pdo, "SELECT queue_position FROM queue_entry WHERE id=?", [$q2])->fetch()['queue_position'] > (int)q($pdo, "SELECT queue_position FROM queue_entry WHERE id=?", [$q1])->fetch()['queue_position']);
check('vehicle cannot be queued twice', throws(fn() => register_queue($pdo, $v1, 1, $uid)));
check('second vehicle cannot open trip first', throws(fn() => open_trip($pdo, $q2, $drv, $cnd, $testRoute1, date('Y-m-d H:i:s'), $uid)));
check('trip route must start at queue stage', throws(fn() => open_trip($pdo, $q1, $drv, $cnd, 1, date('Y-m-d H:i:s'), $uid)));
check('trip crew roles must match assignments', throws(fn() => open_trip($pdo, $q1, $cnd, $drv, $testRoute1, date('Y-m-d H:i:s'), $uid)));
q($pdo, "UPDATE app_user SET status='INACTIVE' WHERE id=(SELECT user_id FROM crew_member WHERE id=?)", [$drv]);
check('inactive crew cannot be assigned to a trip', throws(fn() => open_trip($pdo, $q1, $drv, $cnd, $testRoute1, date('Y-m-d H:i:s'), $uid)));
q($pdo, "UPDATE app_user SET status='ACTIVE' WHERE id=(SELECT user_id FROM crew_member WHERE id=?)", [$drv]);
$trip = open_trip($pdo, $q1, $drv, $cnd, $testRoute1, date('Y-m-d H:i:s'), $uid);
check('open trip makes 14 seats available', seats_left($pdo, $trip) === 14);
$b1 = add_boarding($pdo, $trip, 1, 'CASH', $uid, null, 'uuid-test-0000000000000000000001');
check('boarding fare comes from the fare table', $b1['fare'] === $baseFare);
$dup = add_boarding($pdo, $trip, 1, 'CASH', $uid, null, 'uuid-test-0000000000000000000001');
check('offline resend with same client uuid is ignored', !empty($dup['duplicate']) && seats_left($pdo, $trip) === 13);
check('same seat cannot be booked twice', throws(fn() => add_boarding($pdo, $trip, 1, 'CASH', $uid)));
$b2 = add_boarding($pdo, $trip, 2, 'MPESA', $uid);
$simulatedPayment = q($pdo, "SELECT mpesa_receipt_no,receipt_no FROM payment WHERE booking_id=?", [$b2['booking_id']])->fetch();
check('simulated boarding stores a labeled receipt but no real M-Pesa code', $simulatedPayment['mpesa_receipt_no'] === null && strpos($simulatedPayment['receipt_no'], 'SIM') === 0);
check('real M-Pesa receipt codes are rejected in simulated mode', throws(fn() => add_boarding($pdo, $trip, 3, 'MPESA', $uid, 'QWE1234567')));
for ($s = 3; $s <= 14; $s++) add_boarding($pdo, $trip, $s, 'CASH', $uid);
check('vehicle full after 14 passengers', seats_left($pdo, $trip) === 0);
check('15th passenger blocked', throws(fn() => add_boarding($pdo, $trip, 1, 'CASH', $uid)));
check('cannot close a trip before departure', throws(fn() => close_trip($pdo, $trip, 3250, $uid)));
confirm_departure($pdo, $trip, 100, $uid);
check('departure removes vehicle from queue', q($pdo, "SELECT status FROM queue_entry WHERE id=?", [$q1])->fetch()['status'] === 'DISPATCHED');
check('boarding blocked after departure', throws(fn() => add_boarding($pdo, $trip, 1, 'CASH', $uid)));
check('wrong cash count gives MISMATCH', close_trip($pdo, $trip, 3000, $uid) === 'MISMATCH');
$q3 = register_queue($pdo, $v1, $testStage2, $uid);
$t2 = open_trip($pdo, $q3, $drv, $cnd, $testRoute2, date('Y-m-d H:i:s', strtotime('+1 hour')), $uid);
$reservation = add_simulated_reservation($pdo, $t2, 4, 'Test Passenger', '0722000099', true);
check('reservation creates a confirmed booking and simulated receipt', strpos($reservation['reference'], 'SIM') === 0
    && q($pdo, "SELECT status FROM booking WHERE id=?", [$reservation['booking_id']])->fetch()['status'] === 'CONFIRMED');
$reservationPayment = q($pdo, "SELECT status,method,mpesa_receipt_no,receipt_no FROM payment WHERE booking_id=?", [$reservation['booking_id']])->fetch();
check('reservation persists a clearly labeled simulated payment', $reservationPayment['status'] === 'SUCCESS'
    && $reservationPayment['method'] === 'MPESA' && $reservationPayment['mpesa_receipt_no'] === null
    && $reservationPayment['receipt_no'] === $reservation['reference']);
check('reserved seat cannot be sold again', throws(fn() => add_boarding($pdo, $t2, 4, 'CASH', $uid)));
check('reservation requires phone consent', throws(fn() => add_simulated_reservation($pdo, $t2, 6, 'Test Passenger', '0722000098', false)));
$noShow = add_simulated_reservation($pdo, $t2, 6, 'No Show', '0722000098', true);
$cancelled = add_simulated_reservation($pdo, $t2, 7, 'Cancelled Passenger', '0722000097', true);
check('passenger can find reservation using receipt and phone', find_simulated_reservation($pdo, $cancelled['reference'], '0722 000 097')['booking_id'] == $cancelled['booking_id']
    && find_simulated_reservation($pdo, $cancelled['reference'], '0722000000') === null);
cancel_simulated_reservation($pdo, $cancelled['reference'], '0722000097');
check('passenger cancellation releases seat and reverses simulated payment', q($pdo, "SELECT status FROM booking WHERE id=?", [$cancelled['booking_id']])->fetch()['status'] === 'CANCELLED'
    && q($pdo, "SELECT status FROM payment WHERE booking_id=?", [$cancelled['booking_id']])->fetch()['status'] === 'REVERSED'
    && add_simulated_reservation($pdo, $t2, 7, 'Replacement Passenger', '0722000096', true)['booking_id'] > 0);
$simulatedReceipt = check_in_reservation($pdo, $t2, $reservation['booking_id'], $uid);
check('reservation check-in preserves the same simulated receipt', $simulatedReceipt === $reservation['reference']
    && (int)q($pdo, "SELECT COUNT(*) c FROM payment WHERE booking_id=?", [$reservation['booking_id']])->fetch()['c'] === 1);
check('reservation cannot be checked in twice', throws(fn() => check_in_reservation($pdo, $t2, $reservation['booking_id'], $uid)));
add_boarding($pdo, $t2, 5, 'CASH', $uid); confirm_departure($pdo, $t2, 100, $uid);
check('unclaimed reservation expires at departure', q($pdo, "SELECT status FROM booking WHERE id=?", [$noShow['booking_id']])->fetch()['status'] === 'EXPIRED');
check('unclaimed simulated payment is reversed at departure', q($pdo, "SELECT status FROM payment WHERE booking_id=?", [$noShow['booking_id']])->fetch()['status'] === 'REVERSED');
check('correct cash count gives RECONCILED', close_trip($pdo, $t2, $returnFare, $uid) === 'RECONCILED');
check('audit log recorded actions', (int)q($pdo, "SELECT COUNT(*) c FROM audit_log WHERE user_id=?", [$uid])->fetch()['c'] > 10);
$fid = submit_feedback($pdo, null, "KZ{$sfx} 111T", 'COMPLAINT', 2, 'Overcharged', '0722000000', true);
check('feedback saved against vehicle', $fid > 0);
check('phone without consent rejected', throws(fn() => submit_feedback($pdo, null, "KZ{$sfx} 111T", 'COMPLAINT', 2, 'x', '0722000001', false)));
check('feedback without trip or vehicle rejected', throws(fn() => submit_feedback($pdo, null, null, 'COMPLAINT', 2, 'x', null, false)));
$pdo->rollBack();
echo "\nResult: $pass passed, $fail failed\n"; exit($fail ? 1 : 0);
