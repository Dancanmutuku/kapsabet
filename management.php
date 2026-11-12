<?php
require_once 'includes/auth.php'; require_role(['ADMIN']);
$pdo = db(); $msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'stage') {
            $name = trim($_POST['stage_name']);
            if ($name === '') throw new InvalidArgumentException('Stage name is required');
            $pdo->beginTransaction();
            q($pdo, "INSERT INTO stage (stage_name,town) VALUES (?,?)", [$name, trim($_POST['town']) ?: null]);
            $id = (int)$pdo->lastInsertId();
            audit($pdo, user()['id'], 'CREATE', 'stage', $id, null, ['name' => $name]);
            $pdo->commit();
            $msg = 'Stage added.';
        } elseif ($action === 'route') {
            $origin = (int)$_POST['origin']; $dest = (int)$_POST['destination'];
            $distance = (float)$_POST['distance']; $fare = (float)$_POST['fare']; $from = $_POST['effective_from'];
            if ($origin < 1 || $dest < 1 || $origin === $dest) throw new InvalidArgumentException('Choose different origin and destination stages');
            if ($distance <= 0 || $fare <= 0) throw new InvalidArgumentException('Distance and fare must be greater than zero');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !checkdate((int)substr($from, 5, 2), (int)substr($from, 8, 2), (int)substr($from, 0, 4))) {
                throw new InvalidArgumentException('Effective date is invalid');
            }
            $pdo->beginTransaction();
            q($pdo, "INSERT INTO route (origin_stage_id,dest_stage_id,distance_km) VALUES (?,?,?)", [$origin, $dest, $distance]);
            $id = (int)$pdo->lastInsertId();
            q($pdo, "INSERT INTO fare (route_id,amount,effective_from,approved_by) VALUES (?,?,?,?)", [$id, $fare, $from, user()['id']]);
            audit($pdo, user()['id'], 'CREATE', 'route', $id, null, ['origin' => $origin, 'destination' => $dest]);
            $pdo->commit();
            $msg = 'Route and initial fare added.';
        } elseif ($action === 'user') {
            $name = trim($_POST['full_name']); $username = trim($_POST['username']); $password = $_POST['password'];
            $roleId = (int)$_POST['role_id'];
            if ($name === '' || $username === '' || strlen($password) < 12) throw new InvalidArgumentException('Name and username are required; password must be at least 12 characters');
            $role = q($pdo, "SELECT role_name FROM role WHERE id=?", [$roleId])->fetch();
            if (!$role) throw new InvalidArgumentException('Choose a valid role');
            $phone = trim($_POST['phone']) === '' ? null : normalize_phone($_POST['phone']);
            if (trim($_POST['phone']) !== '' && !$phone) throw new InvalidArgumentException('Phone number is invalid');
            $licence = trim($_POST['licence']);
            if (in_array($role['role_name'], ['DRIVER', 'CONDUCTOR'], true) && $licence === '') throw new InvalidArgumentException('A licence number is required for drivers and conductors');
            $pdo->beginTransaction();
            q($pdo, "INSERT INTO app_user (role_id,full_name,username,phone_no,password_hash) VALUES (?,?,?,?,?)",
              [$roleId, $name, $username, $phone, password_hash($password, PASSWORD_DEFAULT)]);
            $id = (int)$pdo->lastInsertId();
            if (in_array($role['role_name'], ['DRIVER', 'CONDUCTOR'], true)) {
                q($pdo, "INSERT INTO crew_member (user_id,crew_type,licence_no) VALUES (?,?,?)", [$id, $role['role_name'], $licence]);
            }
            audit($pdo, user()['id'], 'CREATE', 'app_user', $id, null, ['username' => $username, 'role' => $role['role_name']]);
            $pdo->commit();
            $msg = 'User added' . (in_array($role['role_name'], ['DRIVER', 'CONDUCTOR'], true) ? ' with crew profile' : '') . '.';
        } elseif ($action === 'user_status') {
            $id = (int)$_POST['id']; $status = $_POST['status'];
            if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) throw new InvalidArgumentException('Invalid user status');
            if ($id === (int)user()['id'] && $status === 'INACTIVE') throw new InvalidArgumentException('You cannot deactivate your own account');
            $old = q($pdo, "SELECT status FROM app_user WHERE id=?", [$id])->fetch();
            if (!$old) throw new InvalidArgumentException('User not found');
            $pdo->beginTransaction();
            q($pdo, "UPDATE app_user SET status=? WHERE id=?", [$status, $id]);
            audit($pdo, user()['id'], 'UPDATE', 'app_user', $id, $old, ['status' => $status]);
            $pdo->commit();
            $msg = 'User status updated.';
        } elseif ($action === 'vehicle_status') {
            $id = (int)$_POST['id']; $status = $_POST['status'];
            if (!in_array($status, ['ACTIVE', 'SUSPENDED', 'RETIRED'], true)) throw new InvalidArgumentException('Invalid vehicle status');
            $old = q($pdo, "SELECT status FROM vehicle WHERE id=?", [$id])->fetch();
            if (!$old) throw new InvalidArgumentException('Vehicle not found');
            $pdo->beginTransaction();
            q($pdo, "UPDATE vehicle SET status=? WHERE id=?", [$status, $id]);
            audit($pdo, user()['id'], 'UPDATE', 'vehicle', $id, $old, ['status' => $status]);
            $pdo->commit();
            $msg = 'Vehicle status updated.';
        } elseif ($action === 'document') {
            $id = (int)$_POST['vehicle_id']; $type = $_POST['doc_type']; $expiry = $_POST['expiry_date'];
            if (!in_array($type, ['INSURANCE', 'INSPECTION'], true)) throw new InvalidArgumentException('Invalid document type');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) || !checkdate((int)substr($expiry, 5, 2), (int)substr($expiry, 8, 2), (int)substr($expiry, 0, 4))) {
                throw new InvalidArgumentException('Expiry date is invalid');
            }
            $pdo->beginTransaction();
            q($pdo, "INSERT INTO vehicle_document (vehicle_id,doc_type,expiry_date) VALUES (?,?,?)", [$id, $type, $expiry]);
            $docId = (int)$pdo->lastInsertId();
            audit($pdo, user()['id'], 'CREATE', 'vehicle_document', $docId, null, ['vehicle_id' => $id, 'type' => $type, 'expiry' => $expiry]);
            $pdo->commit();
            $msg = 'Vehicle document updated.';
        } else {
            throw new InvalidArgumentException('Unknown management action');
        }
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $t->getMessage();
    }
}
$stages = q($pdo, "SELECT * FROM stage ORDER BY stage_name")->fetchAll();
$routes = q($pdo, "SELECT r.id,r.distance_km,CONCAT(o.stage_name,' to ',d.stage_name) AS route_name FROM route r JOIN stage o ON o.id=r.origin_stage_id JOIN stage d ON d.id=r.dest_stage_id ORDER BY route_name")->fetchAll();
$roles = q($pdo, "SELECT id,role_name FROM role ORDER BY role_name")->fetchAll();
$users = q($pdo, "SELECT u.id,u.full_name,u.username,u.phone_no,u.status,r.role_name,c.crew_type,c.licence_no FROM app_user u JOIN role r ON r.id=u.role_id LEFT JOIN crew_member c ON c.user_id=u.id ORDER BY u.full_name")->fetchAll();
$vehicles = q($pdo, "SELECT v.id,v.reg_no,v.status,v.seat_capacity,u.full_name AS owner FROM vehicle v JOIN app_user u ON u.id=v.owner_id ORDER BY v.reg_no")->fetchAll();
$title = 'Management'; include 'includes/header.php'; ?>
<h1>System management</h1>
<?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>

<h2>Add a stage</h2>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="stage">
<div><label>Stage name</label><input name="stage_name" maxlength="60" required></div>
<div><label>Town</label><input name="town" maxlength="40"></div><div><button>Add stage</button></div></form>
<h2>Add a route and initial fare</h2>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="route">
<div><label>Origin</label><select name="origin" required><?php foreach ($stages as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['stage_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Destination</label><select name="destination" required><?php foreach ($stages as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['stage_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Distance (km)</label><input type="number" step="0.1" min="0.1" name="distance" required></div>
<div><label>Initial fare (KES)</label><input type="number" step="0.01" min="0.01" name="fare" required></div>
<div><label>Fare effective from</label><input type="date" name="effective_from" value="<?= e(date('Y-m-d')) ?>" required></div><div><button>Add route</button></div></form>
<p>Routes and stages are retained once referenced by trips; add new records rather than deleting operational history.</p>
<h2>Routes</h2><table><thead><tr><th>Route</th><th>Distance</th></tr></thead><tbody>
<?php foreach ($routes as $r): ?><tr><td><?= e($r['route_name']) ?></td><td><?= e($r['distance_km']) ?> km</td></tr><?php endforeach; ?>
</tbody></table>

<h2>Add staff or crew account</h2>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="user">
<div><label>Full name</label><input name="full_name" maxlength="100" required></div>
<div><label>Username</label><input name="username" maxlength="40" required></div>
<div><label>Phone (optional)</label><input name="phone" placeholder="07XX XXX XXX"></div>
<div><label>Role</label><select name="role_id" required><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['role_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Password (at least 12 characters)</label><input type="password" name="password" minlength="12" required></div>
<div><label>Licence (required for driver/conductor)</label><input name="licence" maxlength="30"></div><div><button>Add account</button></div></form>
<h2>Staff and crew</h2><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Phone</th><th>Licence</th><th>Status</th><th>Update</th></tr></thead><tbody>
<?php foreach ($users as $u): ?><tr><td><?= e($u['full_name']) ?></td><td><?= e($u['username']) ?></td><td><?= e($u['role_name']) ?><?= $u['crew_type'] ? ' / ' . e($u['crew_type']) : '' ?></td><td><?= e($u['phone_no'] ?? '') ?></td><td><?= e($u['licence_no'] ?? '') ?></td><td><?= e($u['status']) ?></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="user_status"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><select name="status"><option <?= $u['status'] === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option><option <?= $u['status'] === 'INACTIVE' ? 'selected' : '' ?>>INACTIVE</option></select><button>Save</button></form></td></tr><?php endforeach; ?>
</tbody></table>

<h2>Vehicle status and documents</h2><table><thead><tr><th>Registration</th><th>Owner</th><th>Seats</th><th>Status</th><th>Change status</th><th>Add updated document</th></tr></thead><tbody>
<?php foreach ($vehicles as $v): ?><tr><td><?= e($v['reg_no']) ?></td><td><?= e($v['owner']) ?></td><td><?= (int)$v['seat_capacity'] ?></td><td><?= e($v['status']) ?></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="vehicle_status"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><select name="status"><?php foreach (['ACTIVE','SUSPENDED','RETIRED'] as $s): ?><option <?= $v['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select><button>Save</button></form></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="document"><input type="hidden" name="vehicle_id" value="<?= (int)$v['id'] ?>"><select name="doc_type"><option>INSURANCE</option><option>INSPECTION</option></select><input type="date" name="expiry_date" required><button>Record document</button></form></td></tr><?php endforeach; ?>
</tbody></table>
<?php include 'includes/footer.php';
