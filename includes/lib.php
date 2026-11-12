<?php
// Core business functions. Each takes a PDO so they can be unit and integration tested.
function db(): PDO {
    static $p;
    if (!$p) {
        $p = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $p;
}
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function q(PDO $pdo, string $sql, array $a = []): PDOStatement { $s = $pdo->prepare($sql); $s->execute($a); return $s; }

function normalize_reg(string $r): string { return strtoupper(preg_replace('/\s+/', ' ', trim($r))); }
function valid_reg_no(string $r): bool { return (bool)preg_match('/^K[A-Z]{2} \d{3}[A-Z]$/', normalize_reg($r)); }
function normalize_phone(string $p): ?string {
    $p = preg_replace('/[\s-]/', '', $p);
    if (preg_match('/^07\d{8}$/', $p)) return '+254' . substr($p, 1);
    if (preg_match('/^\+2547\d{8}$/', $p)) return $p;
    return null;
}
function audit(PDO $pdo, ?int $uid, string $action, string $table, int $rid, $old = null, $new = null): void {
    q($pdo, "INSERT INTO audit_log (user_id,action,table_name,record_id,old_values,new_values) VALUES (?,?,?,?,?,?)",
      [$uid, $action, $table, $rid, $old === null ? null : json_encode($old), $new === null ? null : json_encode($new)]);
}

// Fare management (FR-26, FR-47)
function current_fare(PDO $pdo, int $route_id, ?string $date = null): ?float {
    $date = $date ?: date('Y-m-d');
    $r = q($pdo, "SELECT amount FROM fare WHERE route_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC LIMIT 1",
           [$route_id, $date, $date])->fetch();
    return $r ? (float)$r['amount'] : null;
}
function set_fare(PDO $pdo, int $route_id, float $amount, string $from, int $uid): void {
    if ($amount <= 0) throw new InvalidArgumentException('Fare must be greater than zero');
    $cur = q($pdo, "SELECT id, effective_from FROM fare WHERE route_id=? AND effective_to IS NULL ORDER BY effective_from DESC LIMIT 1", [$route_id])->fetch();
    if ($cur && $from <= $cur['effective_from']) throw new InvalidArgumentException('New fare must start after the current fare start date');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if ($cur) q($pdo, "UPDATE fare SET effective_to=DATE_SUB(?, INTERVAL 1 DAY) WHERE id=?", [$from, $cur['id']]);
        q($pdo, "INSERT INTO fare (route_id,amount,effective_from,approved_by) VALUES (?,?,?,?)", [$route_id, $amount, $from, $uid]);
        audit($pdo, $uid, 'CREATE', 'fare', (int)$pdo->lastInsertId(), null, ['route' => $route_id, 'amount' => $amount, 'from' => $from]);
        if ($own) $pdo->commit();
    } catch (Throwable $t) { if ($own) $pdo->rollBack(); throw $t; }
}

// Vehicle compliance (FR-35): VALID, EXPIRING (30 days), EXPIRED or MISSING
function compliance_status(PDO $pdo, int $vehicle_id, ?string $today = null): string {
    $today = $today ?: date('Y-m-d');
    $rows = q($pdo, "SELECT doc_type, MAX(expiry_date) AS e FROM vehicle_document WHERE vehicle_id=? GROUP BY doc_type", [$vehicle_id])->fetchAll();
    if (count($rows) < 2) return 'MISSING';
    $min = min(array_column($rows, 'e'));
    if ($min < $today) return 'EXPIRED';
    if ($min <= date('Y-m-d', strtotime($today . ' +30 days'))) return 'EXPIRING';
    return 'VALID';
}
function add_vehicle(PDO $pdo, int $owner, string $reg, string $model, int $seats, string $ins, string $insp, int $uid): int {
    if (!valid_reg_no($reg)) throw new InvalidArgumentException('Registration number format is invalid (example KDA 123X)');
    if ($seats < 1 || $seats > 14) throw new InvalidArgumentException('Seat capacity must be between 1 and 14');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        q($pdo, "INSERT INTO vehicle (owner_id,reg_no,make_model,seat_capacity) VALUES (?,?,?,?)", [$owner, normalize_reg($reg), $model, $seats]);
        $id = (int)$pdo->lastInsertId();
        q($pdo, "INSERT INTO vehicle_document (vehicle_id,doc_type,expiry_date) VALUES (?,?,?),(?,?,?)", [$id, 'INSURANCE', $ins, $id, 'INSPECTION', $insp]);
        audit($pdo, $uid, 'CREATE', 'vehicle', $id, null, ['reg' => normalize_reg($reg)]);
        if ($own) $pdo->commit();
        return $id;
    } catch (PDOException $x) {
        if ($own) $pdo->rollBack();
        if ($x->getCode() == 23000) throw new RuntimeException('Registration number already exists');
        throw $x;
    }
}

// Queue and dispatch (FR-07 to FR-11)
function ensure_default_daily_schedule(PDO $pdo): int {
    $count = (int)q($pdo, "SELECT COUNT(*) AS n FROM trip_schedule")->fetch()['n'];
    if ($count > 0) return $count;

    $routes = q($pdo, "SELECT r.id,o.stage_name AS origin,d.stage_name AS destination
                       FROM route r JOIN stage o ON o.id=r.origin_stage_id JOIN stage d ON d.id=r.dest_stage_id")
        ->fetchAll();
    $routeIds = [];
    foreach ($routes as $route) {
        if ($route['origin'] === 'Kapsabet stage' && $route['destination'] === 'Eldoret stage') $routeIds['outbound'] = (int)$route['id'];
        if ($route['origin'] === 'Eldoret stage' && $route['destination'] === 'Kapsabet stage') $routeIds['return'] = (int)$route['id'];
    }
    if (!isset($routeIds['outbound'], $routeIds['return'])) return 0;

    $vehicleId = null;
    foreach (q($pdo, "SELECT id FROM vehicle WHERE status='ACTIVE' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $status = compliance_status($pdo, (int)$id);
        if ($status === 'VALID' || $status === 'EXPIRING') { $vehicleId = (int)$id; break; }
    }
    if ($vehicleId === null) return 0;

    $crew = q($pdo, "SELECT c.id,c.crew_type FROM crew_member c
                     JOIN app_user u ON u.id=c.user_id JOIN role r ON r.id=u.role_id
                     WHERE u.status='ACTIVE' AND r.role_name=c.crew_type ORDER BY c.id")->fetchAll();
    $driverId = $conductorId = null;
    foreach ($crew as $member) {
        if ($member['crew_type'] === 'DRIVER' && $driverId === null) $driverId = (int)$member['id'];
        if ($member['crew_type'] === 'CONDUCTOR' && $conductorId === null) $conductorId = (int)$member['id'];
    }
    if ($driverId === null || $conductorId === null) return 0;

    $slots = [
        ['06:00:00', $routeIds['outbound']],
        ['10:00:00', $routeIds['return']],
        ['14:00:00', $routeIds['outbound']],
        ['18:00:00', $routeIds['return']],
    ];
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if ((int)q($pdo, "SELECT COUNT(*) AS n FROM trip_schedule")->fetch()['n'] === 0) {
            foreach ($slots as [$time, $routeId]) {
                q($pdo, "INSERT INTO trip_schedule (route_id,departure_time,vehicle_id,driver_id,conductor_id)
                         VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
                    [$routeId, $time, $vehicleId, $driverId, $conductorId]);
            }
        }
        if ($own) $pdo->commit();
    } catch (Throwable $t) {
        if ($own) $pdo->rollBack();
        throw $t;
    }
    return (int)q($pdo, "SELECT COUNT(*) AS n FROM trip_schedule")->fetch()['n'];
}

function ensure_daily_trips(PDO $pdo, int $days = 30): int {
    if ($days < 1 || $days > 60) throw new InvalidArgumentException('Daily trip generation must be between 1 and 60 days');
    ensure_default_daily_schedule($pdo);
    $schedules = q($pdo, "SELECT s.id,s.route_id,s.departure_time,s.vehicle_id,s.driver_id,s.conductor_id,
                                 v.status AS vehicle_status, d.crew_type AS driver_type, du.status AS driver_status,
                                 dr.role_name AS driver_role, c.crew_type AS conductor_type, cu.status AS conductor_status,
                                 cr.role_name AS conductor_role
                          FROM trip_schedule s JOIN vehicle v ON v.id=s.vehicle_id
                          JOIN crew_member d ON d.id=s.driver_id JOIN app_user du ON du.id=d.user_id
                          JOIN role dr ON dr.id=du.role_id
                          JOIN crew_member c ON c.id=s.conductor_id JOIN app_user cu ON cu.id=c.user_id
                          JOIN role cr ON cr.id=cu.role_id
                          WHERE s.active=TRUE ORDER BY s.departure_time")->fetchAll();
    if (!$schedules) return 0;

    $clock = q($pdo, "SELECT CURDATE() AS today,NOW() AS now")->fetch();
    $serviceDate = DateTimeImmutable::createFromFormat('!Y-m-d', $clock['today']);
    if (!$serviceDate) throw new RuntimeException('Unable to determine the database service date');
    $created = 0;
    foreach ($schedules as $schedule) {
        if ($schedule['vehicle_status'] !== 'ACTIVE' || !in_array(compliance_status($pdo, (int)$schedule['vehicle_id']), ['VALID', 'EXPIRING'], true)
            || $schedule['driver_type'] !== 'DRIVER' || $schedule['driver_role'] !== 'DRIVER' || $schedule['driver_status'] !== 'ACTIVE'
            || $schedule['conductor_type'] !== 'CONDUCTOR' || $schedule['conductor_role'] !== 'CONDUCTOR' || $schedule['conductor_status'] !== 'ACTIVE') {
            continue;
        }
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $serviceDate->modify('+' . $offset . ' days')->format('Y-m-d');
            $departure = $date . ' ' . $schedule['departure_time'];
            if ($departure <= $clock['now']) continue;
            $insert = q($pdo, "INSERT INTO trip (schedule_id,vehicle_id,route_id,driver_id,conductor_id,scheduled_departure,status)
                               VALUES (?,?,?,?,?,?, 'SCHEDULED')
                               ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
                        [$schedule['id'], $schedule['vehicle_id'], $schedule['route_id'], $schedule['driver_id'],
                         $schedule['conductor_id'], $departure]);
            if ($insert->rowCount() === 1) $created++;
        }
    }
    return $created;
}

function register_queue(PDO $pdo, int $vehicle_id, int $stage_id, int $uid): int {
    $v = q($pdo, "SELECT status FROM vehicle WHERE id=?", [$vehicle_id])->fetch();
    if (!$v || $v['status'] !== 'ACTIVE') throw new RuntimeException('Vehicle is not active');
    $c = compliance_status($pdo, $vehicle_id);
    if ($c === 'EXPIRED' || $c === 'MISSING') throw new RuntimeException('Compliance documents are ' . strtolower($c));
    $pos = (int)q($pdo, "SELECT COALESCE(MAX(queue_position),0)+1 AS p FROM queue_entry WHERE stage_id=? AND queue_date=CURDATE()", [$stage_id])->fetch()['p'];
    try {
        q($pdo, "INSERT INTO queue_entry (vehicle_id,stage_id,registered_by,queue_date,queue_position) VALUES (?,?,?,CURDATE(),?)", [$vehicle_id, $stage_id, $uid, $pos]);
    } catch (PDOException $x) {
        if ($x->getCode() == 23000) throw new RuntimeException('Vehicle is already in the queue');
        throw $x;
    }
    $id = (int)$pdo->lastInsertId();
    audit($pdo, $uid, 'CREATE', 'queue_entry', $id, null, ['position' => $pos]);
    return $id;
}
function open_trip(PDO $pdo, int $queue_id, int $driver, int $conductor, int $route_id, string $sched, int $uid): int {
    $qe = q($pdo, "SELECT * FROM queue_entry WHERE id=? AND status='WAITING'", [$queue_id])->fetch();
    if (!$qe) throw new RuntimeException('Queue entry is not waiting');
    $front = q($pdo, "SELECT MIN(queue_position) AS m FROM queue_entry WHERE stage_id=? AND queue_date=? AND status='WAITING'", [$qe['stage_id'], $qe['queue_date']])->fetch()['m'];
    if ((int)$front !== (int)$qe['queue_position']) throw new RuntimeException('Only the vehicle at the front of the queue can open a trip');
    $crew = q($pdo, "SELECT c.id,c.crew_type,u.status,r.role_name FROM crew_member c
                     JOIN app_user u ON u.id=c.user_id JOIN role r ON r.id=u.role_id WHERE c.id IN (?,?)", [$driver, $conductor])->fetchAll();
    $byId = [];
    foreach ($crew as $member) $byId[(int)$member['id']] = $member;
    if (($byId[$driver]['crew_type'] ?? null) !== 'DRIVER' || ($byId[$driver]['role_name'] ?? null) !== 'DRIVER' || ($byId[$driver]['status'] ?? null) !== 'ACTIVE'
        || ($byId[$conductor]['crew_type'] ?? null) !== 'CONDUCTOR' || ($byId[$conductor]['role_name'] ?? null) !== 'CONDUCTOR' || ($byId[$conductor]['status'] ?? null) !== 'ACTIVE') {
        throw new RuntimeException('Select an active driver and conductor with the correct crew roles');
    }
    $route = q($pdo, "SELECT origin_stage_id FROM route WHERE id=?", [$route_id])->fetch();
    if (!$route || (int)$route['origin_stage_id'] !== (int)$qe['stage_id']) {
        throw new RuntimeException('The route must start at the queued vehicle stage');
    }
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        q($pdo, "INSERT INTO trip (queue_id,vehicle_id,route_id,driver_id,conductor_id,scheduled_departure,status) VALUES (?,?,?,?,?,?, 'LOADING')",
          [$queue_id, $qe['vehicle_id'], $route_id, $driver, $conductor, $sched]);
        $id = (int)$pdo->lastInsertId();
        audit($pdo, $uid, 'CREATE', 'trip', $id, null, ['queue' => $queue_id]);
        if ($own) $pdo->commit();
        return $id;
    } catch (Throwable $t) {
        if ($own) $pdo->rollBack();
        throw $t;
    }
}
function confirm_departure(PDO $pdo, int $trip_id, float $fee, int $uid): void {
    $t = q($pdo, "SELECT * FROM trip WHERE id=? AND status='LOADING'", [$trip_id])->fetch();
    if (!$t) throw new RuntimeException('Trip is not loading');
    if ($fee < 0) throw new InvalidArgumentException('Stage fee cannot be negative');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $reservations = q($pdo, "SELECT id FROM booking WHERE trip_id=? AND status='CONFIRMED' AND channel='ONLINE'", [$trip_id])->fetchAll(PDO::FETCH_COLUMN);
        q($pdo, "UPDATE payment p JOIN booking b ON b.id=p.booking_id
                 SET p.status='REVERSED'
                 WHERE b.trip_id=? AND b.status='CONFIRMED' AND b.channel='ONLINE' AND p.status='SUCCESS'", [$trip_id]);
        q($pdo, "UPDATE booking SET status='EXPIRED' WHERE trip_id=? AND status='CONFIRMED' AND channel='ONLINE'", [$trip_id]);
        foreach ($reservations as $booking_id) audit($pdo, $uid, 'UPDATE', 'booking', (int)$booking_id, ['status' => 'CONFIRMED'], ['status' => 'EXPIRED']);
        q($pdo, "UPDATE trip SET status='DEPARTED', departure_time=NOW(), stage_fee=? WHERE id=?", [$fee, $trip_id]);
        q($pdo, "UPDATE queue_entry SET status='DISPATCHED', stage_fee=? WHERE id=?", [$fee, $t['queue_id']]);
        audit($pdo, $uid, 'UPDATE', 'trip', $trip_id, ['status' => 'LOADING'], ['status' => 'DEPARTED']);
        if ($own) $pdo->commit();
    } catch (Throwable $t) {
        if ($own) $pdo->rollBack();
        throw $t;
    }
}
function start_scheduled_trip(PDO $pdo, int $trip_id, int $uid): void {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $trip = q($pdo, "SELECT status,scheduled_departure FROM trip WHERE id=? FOR UPDATE", [$trip_id])->fetch();
        if (!$trip || $trip['status'] !== 'SCHEDULED' || substr($trip['scheduled_departure'], 0, 10) !== q($pdo, "SELECT CURDATE() AS today")->fetch()['today']) {
            throw new RuntimeException('Only a scheduled trip for today can be started');
        }
        q($pdo, "UPDATE trip SET status='LOADING' WHERE id=?", [$trip_id]);
        audit($pdo, $uid, 'UPDATE', 'trip', $trip_id, ['status' => 'SCHEDULED'], ['status' => 'LOADING']);
        if ($own) $pdo->commit();
    } catch (Throwable $t) {
        if ($own) $pdo->rollBack();
        throw $t;
    }
}

// Boarding and payment (FR-14 to FR-16, FR-26, FR-29, FR-33)
function seats_left(PDO $pdo, int $trip_id): int {
    $r = q($pdo, "SELECT v.seat_capacity - (SELECT COUNT(*) FROM booking b WHERE b.trip_id=t.id AND b.status IN ('HELD','CONFIRMED','BOARDED')) AS s
                  FROM trip t JOIN vehicle v ON v.id=t.vehicle_id WHERE t.id=?", [$trip_id])->fetch();
    return $r ? (int)$r['s'] : 0;
}
function add_boarding(PDO $pdo, int $trip_id, int $seat, string $method, int $uid, ?string $mpesa = null, ?string $uuid = null): array {
    $uuid = $uuid ?: bin2hex(random_bytes(16));
    if (q($pdo, "SELECT id FROM booking WHERE client_uuid=?", [$uuid])->fetch()) return ['duplicate' => true];
    $t = q($pdo, "SELECT t.*, v.seat_capacity FROM trip t JOIN vehicle v ON v.id=t.vehicle_id WHERE t.id=?", [$trip_id])->fetch();
    if (!$t || $t['status'] !== 'LOADING') throw new RuntimeException('Trip is not open for boarding');
    if (seats_left($pdo, $trip_id) <= 0) {
        throw new RuntimeException('Vehicle is full. Boarding was blocked.');
    }
    if ($seat < 1 || $seat > (int)$t['seat_capacity']) throw new InvalidArgumentException('Invalid seat number');
    if (!in_array($method, ['MPESA', 'CASH'], true)) throw new InvalidArgumentException('Invalid payment method');
    if ($method === 'MPESA') {
        if (trim((string)$mpesa) !== '') throw new InvalidArgumentException('Simulated payment mode does not accept real M-Pesa receipt codes');
    }
    $fare = current_fare($pdo, (int)$t['route_id']);
    if ($fare === null) throw new RuntimeException('No approved fare for this route');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        q($pdo, "INSERT INTO booking (trip_id,seat_no,channel,fare_amount,status,created_by,client_uuid) VALUES (?,?,'CONDUCTOR',?,'BOARDED',?,?)", [$trip_id, $seat, $fare, $uid, $uuid]);
        $bid = (int)$pdo->lastInsertId();
        $rc = 'SIM' . date('ymd') . str_pad((string)$bid, 6, '0', STR_PAD_LEFT);
        q($pdo, "INSERT INTO payment (trip_id,booking_id,amount,method,mpesa_receipt_no,receipt_no,recorded_by) VALUES (?,?,?, ?,NULL,?,?)", [$trip_id, $bid, $fare, $method, $rc, $uid]);
        audit($pdo, $uid, 'CREATE', 'booking', $bid, null, ['seat' => $seat, 'fare' => $fare, 'method' => $method]);
        if ($own) $pdo->commit();
        return ['booking_id' => $bid, 'receipt_no' => $rc, 'fare' => $fare];
    } catch (PDOException $x) {
        if ($own) $pdo->rollBack();
        if ($x->getCode() == 23000) throw new RuntimeException('Seat is already taken or the entry is a duplicate');
        throw $x;
    }
}

function add_simulated_reservation(PDO $pdo, int $trip_id, int $seat, string $name, string $phone, bool $consent): array {
    $name = trim($name);
    $phone = normalize_phone($phone);
    if ($name === '') throw new InvalidArgumentException('Passenger name is required');
    if (!$phone) throw new InvalidArgumentException('Phone number is invalid');
    if (!$consent) throw new InvalidArgumentException('Consent is required before passenger details are stored');

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $t = q($pdo, "SELECT t.*, v.seat_capacity FROM trip t JOIN vehicle v ON v.id=t.vehicle_id WHERE t.id=? FOR UPDATE", [$trip_id])->fetch();
        $upcoming = $t ? (bool)q($pdo, "SELECT CAST(? AS DATETIME) >= NOW() AS is_upcoming", [$t['scheduled_departure']])->fetch()['is_upcoming'] : false;
        if (!$t || !in_array($t['status'], ['SCHEDULED', 'LOADING'], true) || !$upcoming) {
            throw new RuntimeException('This trip is not available for reservations');
        }
        if ($seat < 1 || $seat > (int)$t['seat_capacity']) throw new InvalidArgumentException('Invalid seat number');
        if (q($pdo, "SELECT id FROM booking WHERE trip_id=? AND seat_no=? AND status IN ('HELD','CONFIRMED','BOARDED')", [$trip_id, $seat])->fetch()) {
            throw new RuntimeException('This seat is no longer available');
        }
        $fare = current_fare($pdo, (int)$t['route_id'], substr($t['scheduled_departure'], 0, 10));
        if ($fare === null) throw new RuntimeException('No approved fare for this route');
        q($pdo, "INSERT INTO passenger (full_name,phone_no,consent_given) VALUES (?,?,TRUE)
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), full_name=VALUES(full_name), consent_given=TRUE", [$name, $phone]);
        $passenger = (int)$pdo->lastInsertId();
        $uuid = bin2hex(random_bytes(16));
        q($pdo, "INSERT INTO booking (trip_id,passenger_id,seat_no,channel,fare_amount,status,client_uuid)
                 VALUES (?,?,?,'ONLINE',?,'CONFIRMED',?)", [$trip_id, $passenger, $seat, $fare, $uuid]);
        $booking = (int)$pdo->lastInsertId();
        $reference = 'SIM' . date('ymd') . str_pad((string)$booking, 6, '0', STR_PAD_LEFT);
        q($pdo, "INSERT INTO payment (trip_id,booking_id,amount,method,status,receipt_no)
                 VALUES (?,?,?,'MPESA','SUCCESS',?)", [$trip_id, $booking, $fare, $reference]);
        audit($pdo, null, 'CREATE', 'booking', $booking, null, ['seat' => $seat, 'fare' => $fare, 'channel' => 'ONLINE', 'payment' => 'SIMULATED']);
        if ($own) $pdo->commit();
        return ['booking_id' => $booking, 'reference' => $reference, 'fare' => $fare];
    } catch (Throwable $t) {
        if ($own) $pdo->rollBack();
        if ($t instanceof PDOException && $t->getCode() == 23000) {
            throw new RuntimeException('This seat is no longer available');
        }
        throw $t;
    }
}

function check_in_reservation(PDO $pdo, int $trip_id, int $booking_id, int $uid): string {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $t = q($pdo, "SELECT id,status FROM trip WHERE id=? FOR UPDATE", [$trip_id])->fetch();
        $b = q($pdo, "SELECT id,fare_amount FROM booking WHERE id=? AND trip_id=? AND channel='ONLINE' AND status='CONFIRMED' FOR UPDATE", [$booking_id, $trip_id])->fetch();
        if (!$t || $t['status'] !== 'LOADING' || !$b) throw new RuntimeException('Reservation is no longer available for check-in');
        $payment = q($pdo, "SELECT receipt_no FROM payment WHERE booking_id=? AND status='SUCCESS' ORDER BY id DESC LIMIT 1", [$booking_id])->fetch();
        if ($payment) {
            $receipt = $payment['receipt_no'];
        } else {
            $receipt = 'SIM' . date('ymd') . str_pad((string)$booking_id, 6, '0', STR_PAD_LEFT);
            q($pdo, "INSERT INTO payment (trip_id,booking_id,amount,method,status,receipt_no)
                     VALUES (?,?,?,'MPESA','SUCCESS',?)", [$trip_id, $booking_id, $b['fare_amount'], $receipt]);
        }
        q($pdo, "UPDATE booking SET status='BOARDED' WHERE id=?", [$booking_id]);
        audit($pdo, $uid, 'UPDATE', 'booking', $booking_id, ['status' => 'CONFIRMED'], ['status' => 'BOARDED', 'payment' => 'SIMULATED']);
        if ($own) $pdo->commit();
        return $receipt;
    } catch (Throwable $t) {
        if ($own) $pdo->rollBack();
        throw $t;
    }
}

function is_simulated_reference(string $reference): bool {
    return (bool)preg_match('/^(?:SIM\d{12,}|[A-Z]{4}\d{12,})$/', strtoupper(trim($reference)));
}

function find_simulated_reservation(PDO $pdo, string $reference, string $phone): ?array {
    $phone = normalize_phone($phone);
    if (!$phone || !is_simulated_reference($reference)) return null;
    $r = q($pdo, "SELECT b.id AS booking_id,b.trip_id,b.seat_no,b.fare_amount,b.status AS booking_status,
                  t.status AS trip_status,t.scheduled_departure,p.status AS payment_status,p.receipt_no,
                  CONCAT(o.stage_name,' to ',d.stage_name) AS route_name
                  FROM booking b JOIN passenger ps ON ps.id=b.passenger_id
                  JOIN trip t ON t.id=b.trip_id JOIN payment p ON p.booking_id=b.id
                  JOIN route rt ON rt.id=t.route_id JOIN stage o ON o.id=rt.origin_stage_id
                  JOIN stage d ON d.id=rt.dest_stage_id
                  WHERE b.channel='ONLINE' AND p.receipt_no=? AND ps.phone_no=?
                  ORDER BY p.id DESC LIMIT 1", [strtoupper(trim($reference)), $phone])->fetch();
    return $r ?: null;
}

function cancel_simulated_reservation(PDO $pdo, string $reference, string $phone): void {
    $reference = strtoupper(trim($reference));
    $phone = normalize_phone($phone);
    if (!$phone || !is_simulated_reference($reference)) throw new InvalidArgumentException('Reservation reference or phone number is invalid');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $r = q($pdo, "SELECT b.id,b.trip_id,t.status AS trip_status,p.id AS payment_id,p.status AS payment_status
                      FROM booking b JOIN passenger ps ON ps.id=b.passenger_id
                      JOIN trip t ON t.id=b.trip_id JOIN payment p ON p.booking_id=b.id
                      WHERE b.channel='ONLINE' AND b.status='CONFIRMED' AND p.receipt_no=? AND ps.phone_no=?
                      FOR UPDATE", [$reference, $phone])->fetch();
        if (!$r || !in_array($r['trip_status'], ['SCHEDULED', 'LOADING'], true) || $r['payment_status'] !== 'SUCCESS') {
            throw new RuntimeException('Reservation cannot be cancelled; check the reference, phone number, and trip status');
        }
        q($pdo, "UPDATE payment SET status='REVERSED' WHERE id=?", [$r['payment_id']]);
        q($pdo, "UPDATE booking SET status='CANCELLED' WHERE id=?", [$r['id']]);
        audit($pdo, null, 'UPDATE', 'booking', (int)$r['id'], ['status' => 'CONFIRMED'], ['status' => 'CANCELLED', 'payment' => 'SIMULATED_REVERSED']);
        if ($own) $pdo->commit();
    } catch (Throwable $t) {
        if ($own) $pdo->rollBack();
        throw $t;
    }
}

function cancel_reservation(PDO $pdo, int $trip_id, int $booking_id, int $uid): void {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $t = q($pdo, "SELECT status FROM trip WHERE id=? FOR UPDATE", [$trip_id])->fetch();
        $b = q($pdo, "SELECT id FROM booking WHERE id=? AND trip_id=? AND channel='ONLINE' AND status='CONFIRMED' FOR UPDATE",
          [$booking_id, $trip_id])->fetch();
        if (!$t || !in_array($t['status'], ['SCHEDULED', 'LOADING'], true) || !$b) throw new RuntimeException('Reservation is no longer available for cancellation');
        q($pdo, "UPDATE payment SET status='REVERSED' WHERE booking_id=? AND status='SUCCESS'", [$booking_id]);
        q($pdo, "UPDATE booking SET status='CANCELLED' WHERE id=?", [$booking_id]);
        audit($pdo, $uid, 'UPDATE', 'booking', $booking_id, ['status' => 'CONFIRMED'], ['status' => 'CANCELLED']);
        if ($own) $pdo->commit();
    } catch (Throwable $t) {
        if ($own) $pdo->rollBack();
        throw $t;
    }
}

// Reconciliation (FR-17)
function reconcile(float $expected, float $recorded, float $cash_recorded, float $counted_cash): string {
    return (abs($expected - $recorded) < 0.005 && abs($cash_recorded - $counted_cash) < 0.005) ? 'RECONCILED' : 'MISMATCH';
}
function close_trip(PDO $pdo, int $trip_id, float $counted_cash, int $uid): string {
    $t = q($pdo, "SELECT * FROM trip WHERE id=? AND status='DEPARTED'", [$trip_id])->fetch();
    if (!$t) throw new RuntimeException('Only a departed trip can be closed');
    $b = q($pdo, "SELECT COUNT(*) n, COALESCE(SUM(fare_amount),0) f FROM booking WHERE trip_id=? AND status='BOARDED'", [$trip_id])->fetch();
    $p = q($pdo, "SELECT COALESCE(SUM(amount),0) a, COALESCE(SUM(CASE WHEN method='CASH' THEN amount END),0) c FROM payment WHERE trip_id=? AND status='SUCCESS'", [$trip_id])->fetch();
    $st = reconcile((float)$b['f'], (float)$p['a'], (float)$p['c'], $counted_cash);
    q($pdo, "UPDATE trip SET status='CLOSED', arrival_time=NOW(), expected_revenue=?, counted_cash=?, recon_status=? WHERE id=?", [$b['f'], $counted_cash, $st, $trip_id]);
    audit($pdo, $uid, 'UPDATE', 'trip', $trip_id, ['status' => 'DEPARTED'], ['status' => 'CLOSED', 'recon' => $st]);
    return $st;
}

// Feedback (FR-44)
function submit_feedback(PDO $pdo, ?int $trip_id, ?string $reg, string $cat, ?int $rating, string $msg, ?string $phone, bool $consent): int {
    $vid = null;
    if ($reg) {
        $v = q($pdo, "SELECT id FROM vehicle WHERE reg_no=?", [normalize_reg($reg)])->fetch();
        if (!$v) throw new InvalidArgumentException('Vehicle registration number not found');
        $vid = (int)$v['id'];
    }
    if (!$trip_id && !$vid) throw new InvalidArgumentException('Give a trip number or a vehicle registration number');
    if (trim($msg) === '') throw new InvalidArgumentException('Message is required');
    if (!in_array($cat, ['COMPLIMENT', 'COMPLAINT', 'SUGGESTION'], true)) throw new InvalidArgumentException('Invalid category');
    if ($rating !== null && ($rating < 1 || $rating > 5)) throw new InvalidArgumentException('Rating must be 1 to 5');
    $pid = null;
    if ($phone !== null && trim($phone) !== '') {
        $n = normalize_phone($phone);
        if (!$n) throw new InvalidArgumentException('Phone number is invalid');
        if (!$consent) throw new InvalidArgumentException('Consent is required before a phone number is stored');
        q($pdo, "INSERT INTO passenger (phone_no,consent_given) VALUES (?,TRUE) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", [$n]);
        $pid = (int)$pdo->lastInsertId();
    }
    q($pdo, "INSERT INTO feedback (trip_id,vehicle_id,passenger_id,category,rating,message) VALUES (?,?,?,?,?,?)", [$trip_id ?: null, $vid, $pid, $cat, $rating, trim($msg)]);
    return (int)$pdo->lastInsertId();
}
