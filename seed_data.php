<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/config.php';
$pdo = db();
$roles = [];
foreach (q($pdo, "SELECT id,role_name FROM role")->fetchAll() as $row) $roles[$row['role_name']] = (int)$row['id'];
foreach (['ADMIN', 'OFFICIAL', 'CLERK', 'CONDUCTOR', 'DRIVER', 'OWNER'] as $name) {
    if (!isset($roles[$name])) throw new RuntimeException('Import sql/database.sql before creating sample data');
}
$stages = [];
foreach (q($pdo, "SELECT id,stage_name FROM stage")->fetchAll() as $row) $stages[$row['stage_name']] = (int)$row['id'];
if (!isset($stages['Kapsabet stage']) || !isset($stages['Eldoret stage'])) {
    throw new RuntimeException('Import sql/database.sql before creating sample data');
}
$route = q($pdo, "SELECT id FROM route WHERE origin_stage_id=? AND dest_stage_id=?",
    [$stages['Kapsabet stage'], $stages['Eldoret stage']])->fetch();
if (!$route) throw new RuntimeException('The Kapsabet to Eldoret route is missing');

$pdo->beginTransaction();
try {
    $accounts = [
        ['ADMIN', 'System Administrator', 'admin', '+254700000001'],
        ['OFFICIAL', 'J. Kamau', 'official', '+254700000002'],
        ['CLERK', 'Queue Clerk', 'clerk', '+254700000003'],
        ['CONDUCTOR', 'L. Njeri', 'conductor', '+254700000004'],
        ['DRIVER', 'P. Mutai', 'driver', '+254700000005'],
        ['OWNER', 'W. Kiprono', 'owner', '+254700000006']
    ];
    $users = [];
    foreach ($accounts as $account) {
        list($role, $name, $username, $phone) = $account;
        $existing = q($pdo, "SELECT id,role_id FROM app_user WHERE username=?", [$username])->fetch();
        if ($existing) {
            if ((int)$existing['role_id'] !== $roles[$role]) throw new RuntimeException('Existing seeded username has an unexpected role: ' . $username);
            $users[$username] = (int)$existing['id'];
            continue;
        }
        q($pdo, "INSERT INTO app_user (role_id,full_name,username,phone_no,password_hash) VALUES (?,?,?,?,?)",
            [$roles[$role], $name, $username, $phone, password_hash('Pass@1234', PASSWORD_DEFAULT)]);
        $users[$username] = (int)$pdo->lastInsertId();
    }

    foreach ([['driver', 'DRIVER', 'DL-1001'], ['conductor', 'CONDUCTOR', 'PSV-2001']] as $member) {
        list($username, $type, $licence) = $member;
        $existing = q($pdo, "SELECT id,crew_type FROM crew_member WHERE user_id=?", [$users[$username]])->fetch();
        if (!$existing) q($pdo, "INSERT INTO crew_member (user_id,crew_type,licence_no) VALUES (?,?,?)", [$users[$username], $type, $licence]);
        elseif ($existing['crew_type'] !== $type) throw new RuntimeException('Existing crew profile has an unexpected type: ' . $username);
    }

    $vehicles = [
        ['KDA 123X', 'Toyota HiAce', 14, '+6 months', '+8 months'],
        ['KCB 456A', 'Toyota HiAce', 14, '+3 months', '+5 months'],
        ['KDD 789B', 'Toyota HiAce', 14, '-10 days', '+5 months']
    ];
    $sampleVehicle = 0;
    foreach ($vehicles as $vehicle) {
        list($registration, $model, $seats, $insurance, $inspection) = $vehicle;
        $existing = q($pdo, "SELECT id FROM vehicle WHERE reg_no=?", [$registration])->fetch();
        if ($existing) $id = (int)$existing['id'];
        else $id = add_vehicle($pdo, $users['owner'], $registration, $model, $seats,
            date('Y-m-d', strtotime($insurance)), date('Y-m-d', strtotime($inspection)), $users['official']);
        if ($registration === 'KDA 123X') $sampleVehicle = $id;
    }

    $activeTrip = q($pdo, "SELECT id FROM trip WHERE status IN ('LOADING','SCHEDULED') LIMIT 1")->fetch();
    $waiting = q($pdo, "SELECT id FROM queue_entry WHERE status='WAITING' AND queue_date=CURDATE() LIMIT 1")->fetch();
    if (!$activeTrip && !$waiting && $sampleVehicle
        && compliance_status($pdo, $sampleVehicle) !== 'EXPIRED'
        && compliance_status($pdo, $sampleVehicle) !== 'MISSING') {
        $queue = register_queue($pdo, $sampleVehicle, $stages['Kapsabet stage'], $users['official']);
        open_trip($pdo, $queue,
            (int)q($pdo, "SELECT id FROM crew_member WHERE user_id=?", [$users['driver']])->fetchColumn(),
            (int)q($pdo, "SELECT id FROM crew_member WHERE user_id=?", [$users['conductor']])->fetchColumn(),
            (int)$route['id'], date('Y-m-d H:i:s', strtotime('+1 day')), $users['official']);
    }

    $pdo->commit();
    echo "Sample data is ready. Seeded records were not overwritten. Initial passwords for newly created accounts only: Pass@1234.\n";
} catch (Throwable $t) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $t;
}
