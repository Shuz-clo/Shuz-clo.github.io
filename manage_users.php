<?php
require_once __DIR__ . '/bootstrap.php';
// ============================================================
// manage_users.php — COMPLETE FIXED FILE
//
// FIX (Bug E): admin-count check for demote/delete runs inside a
//              transaction with SELECT … FOR UPDATE on admin rows.
// FIX (Bug 6): every $conn->rollback() is guarded so strict-mode
//              mysqli cannot double-throw after commit(). Also the
//              outer catch now rolls back the transaction (it did
//              not before).
// FIX (Bug 2): repaired stray HTML in the hint paragraph.
// ============================================================

require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/AccessGroups.php';
require_once __DIR__ . '/Raters.php';

require_role('admin');

const MANAGE_ROLES = ['admin', 'rater', 'user'];
const MIN_PASSWORD_LENGTH = 8;
const MAX_PASSWORD_LENGTH = 72;
$salaryGrades = access_position_salary_grades($conn);

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function flash(string $type, string $msg): void {
    flash_set($type, $msg);
}

function redirect_back(): void {
    header('Location: manage_users.php');
    exit;
}

function admin_count(mysqli $conn): int {
    $res = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'");
    return (int)($res->fetch_assoc()['c'] ?? 0);
}

function revoke_user_tokens(mysqli $conn, int $userId): void {
    $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function password_problem(string $pw): ?string {
    $len = strlen($pw);
    if ($len < MIN_PASSWORD_LENGTH) return 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.';
    if ($len > MAX_PASSWORD_LENGTH) return 'Password must be at most ' . MAX_PASSWORD_LENGTH . ' characters.';
    return null;
}

function submitted_access_field(): string {
    $choice = trim((string)($_POST['field_name'] ?? ''));
    if ($choice !== 'Other') return $choice;
    $other = trim((string)($_POST['field_other'] ?? ''));
    return $other !== '' ? $other : 'Other';
}

$myId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
        redirect_back();
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'link_candidate') {
            $candidateId = (int)($_POST['candidate_id'] ?? 0);
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($candidateId < 1 || $userId < 1) {
                flash('error', 'Choose a candidate record and a Standard User account.');
            } else {
                $stmt = $conn->prepare("SELECT id, username FROM users WHERE id = ? AND role = 'user' LIMIT 1");
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $targetUser = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$targetUser) {
                    flash('error', 'The selected account is not a Standard User.');
                } else {
                    $conn->begin_transaction();
                    try {
                        $stmt = $conn->prepare('SELECT user_id FROM candidates WHERE id = ? LIMIT 1 FOR UPDATE');
                        $stmt->bind_param('i', $candidateId);
                        $stmt->execute();
                        $candidate = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if (!$candidate) {
                            $conn->rollback();
                            flash('error', 'Candidate record not found.');
                        } elseif ($candidate['user_id'] !== null && (int)$candidate['user_id'] !== $userId) {
                            $conn->rollback();
                            flash('error', 'This candidate is already linked to another account. Unlink it first.');
                        } else {
                            $stmt = $conn->prepare('UPDATE candidates SET user_id = ? WHERE id = ?');
                            $stmt->bind_param('ii', $userId, $candidateId);
                            $stmt->execute();
                            $stmt->close();
                            $conn->commit();
                            flash('success', 'Candidate record linked to ' . $targetUser['username'] . '.');
                        }
                    } catch (Throwable $e) {
                        try { $conn->rollback(); } catch (Throwable $ignored) {}
                        throw $e;
                    }
                }
            }

        } elseif ($action === 'unlink_candidate') {
            $candidateId = (int)($_POST['candidate_id'] ?? 0);
            if ($candidateId < 1) {
                flash('error', 'Invalid candidate record.');
            } else {
                $stmt = $conn->prepare('UPDATE candidates SET user_id = NULL WHERE id = ?');
                $stmt->bind_param('i', $candidateId);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();
                flash($changed > 0 ? 'success' : 'error', $changed > 0 ? 'Candidate record unlinked.' : 'Candidate link not found.');
            }

        } elseif ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role     = $_POST['role'] ?? '';
            $region = (string)($_POST['region'] ?? '');
            $fieldName = submitted_access_field();
            $salaryGradeValue = (string)($_POST['salary_grade'] ?? '');

            if (!preg_match('/^[\p{L}0-9 .\-]{3,100}$/u', $username)) {
                flash('error', 'Username must be 3-100 characters and can only contain letters (including ñ), numbers, spaces, dots, and hyphens.');
            } elseif (!in_array($role, MANAGE_ROLES, true)) {
                flash('error', 'Please choose a valid role.');
            } elseif (!array_key_exists($region, ACCESS_REGIONS)
                || !access_field_is_valid($fieldName)
                || !access_position_salary_grade_is_valid($conn, $salaryGradeValue, $salaryGrades)) {
                flash('error', 'Please choose a valid region, field, and salary grade.');
            } elseif (($problem = password_problem($password)) !== null) {
                flash('error', $problem);
            } elseif (find_user_by_username($conn, $username) !== null) {
                flash('error', "The username '$username' is already taken.");
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $salaryGrade = (int)$salaryGradeValue;
                $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, region, field_name, salary_grade) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssssi', $username, $hash, $role, $region, $fieldName, $salaryGrade);
                $stmt->execute();
                $stmt->close();
                flash('success', "Account '$username' created as $role.");
            }

        } elseif ($action === 'update_access_profile') {
            $id = (int)($_POST['id'] ?? 0);
            $region = (string)($_POST['region'] ?? '');
            $fieldName = submitted_access_field();
            $salaryGradeValue = (string)($_POST['salary_grade'] ?? '');
            $target = $id > 0 ? find_user_by_id($conn, $id) : null;

            if (!$target) {
                flash('error', 'User not found.');
            } elseif (!array_key_exists($region, ACCESS_REGIONS)
                || !access_field_is_valid($fieldName)
                || !access_position_salary_grade_is_valid($conn, $salaryGradeValue, $salaryGrades)) {
                flash('error', 'Please choose a valid region, field, and salary grade.');
            } else {
                $salaryGrade = (int)$salaryGradeValue;
                $stmt = $conn->prepare("UPDATE users SET region = ?, field_name = ?, salary_grade = ? WHERE id = ?");
                $stmt->bind_param('ssii', $region, $fieldName, $salaryGrade, $id);
                $stmt->execute();
                $stmt->close();
                flash('success', "Access profile updated for '" . $target['username'] . "'.");
            }

        } elseif ($action === 'change_role') {
            $id   = (int)($_POST['id'] ?? 0);
            $role = $_POST['role'] ?? '';
            $target = $id > 0 ? find_user_by_id($conn, $id) : null;

            if (!$target) {
                flash('error', 'User not found.');
            } elseif (!in_array($role, MANAGE_ROLES, true)) {
                flash('error', 'Please choose a valid role.');
            } elseif ($id === $myId) {
                flash('error', "You can't change your own role.");
            } else {
                $conn->begin_transaction();
                try {
                    $conn->query("SELECT id FROM users WHERE role = 'admin' FOR UPDATE");
                    $adminTotal = admin_count($conn);
                    if ($target['role'] === 'admin' && $role !== 'admin' && $adminTotal <= 1) {
                        try { $conn->rollback(); } catch (Throwable $ignored) {}
                        flash('error', 'You must keep at least one admin account.');
                    } else {
                        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
                        $stmt->bind_param('si', $role, $id);
                        $stmt->execute();
                        $stmt->close();
                        revoke_user_tokens($conn, $id);
                        $conn->commit();
                        audit_log($conn, 0, 'role_change',
                            ['user_id' => $id, 'username' => $target['username'], 'role' => $target['role']],
                            ['user_id' => $id, 'username' => $target['username'], 'role' => $role]);
                        flash('success', "'" . $target['username'] . "' is now $role. It applies at their next login.");
                    }
                } catch (Throwable $e) {
                    try { $conn->rollback(); } catch (Throwable $ignored) {}
                    throw $e;
                }
            }

        } elseif ($action === 'reset_password') {
            $id       = (int)($_POST['id'] ?? 0);
            $password = $_POST['password'] ?? '';
            $target   = $id > 0 ? find_user_by_id($conn, $id) : null;

            if (!$target) {
                flash('error', 'User not found.');
            } elseif (($problem = password_problem($password)) !== null) {
                flash('error', $problem);
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->bind_param('si', $hash, $id);
                $stmt->execute();
                $stmt->close();
                revoke_user_tokens($conn, $id);
                audit_log($conn, 0, 'password_reset', null, ['user_id' => $id, 'username' => $target['username']]);
                flash('success', "Password updated for '" . $target['username'] . "'.");
            }

        } elseif ($action === 'delete') {
            $id     = (int)($_POST['id'] ?? 0);
            $target = $id > 0 ? find_user_by_id($conn, $id) : null;

            if (!$target) {
                flash('error', 'User not found.');
            } elseif ($id === $myId) {
                flash('error', "You can't delete your own account.");
            } else {
                $conn->begin_transaction();
                try {
                    $conn->query("SELECT id FROM users WHERE role = 'admin' FOR UPDATE");
                    $adminTotal = admin_count($conn);
                    if ($target['role'] === 'admin' && $adminTotal <= 1) {
                        try { $conn->rollback(); } catch (Throwable $ignored) {}
                        flash('error', 'You must keep at least one admin account.');
                    } else {
                        revoke_user_tokens($conn, $id);
                        $stmt = $conn->prepare('UPDATE candidates SET user_id = NULL WHERE user_id = ?');
                        $stmt->bind_param('i', $id);
                        $stmt->execute();
                        $stmt->close();
                        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->bind_param('i', $id);
                        $stmt->execute();
                        $stmt->close();
                        $conn->commit();
                        audit_log($conn, 0, 'delete',
                            ['user_id' => $id, 'username' => $target['username']], null);
                        flash('success', "Account '" . $target['username'] . "' deleted.");
                    }
                } catch (Throwable $e) {
                    try { $conn->rollback(); } catch (Throwable $ignored) {}
                    throw $e;
                }
            }

        } else {
            flash('error', 'Unknown action.');
        }
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('manage_users error: ' . $e->getMessage());
        flash('error', DEBUG ? $e->getMessage() : 'Something went wrong. Please try again.');
    }

    redirect_back();
}

$flash = null;
if (($m = flash_get('success')) !== null)   $flash = ['type' => 'success', 'msg' => $m];
elseif (($m = flash_get('error')) !== null) $flash = ['type' => 'error',   'msg' => $m];
$users = [];
$res = $conn->query("SELECT id, username, role, region, field_name, salary_grade, created_at FROM users ORDER BY FIELD(role,'admin','rater','user'), username");
while ($row = $res->fetch_assoc()) {
    $users[] = $row;
}
$candidateRecords = [];
$res = $conn->query("SELECT c.id, c.candidate_name, c.position_title, c.user_id, u.username
    FROM candidates c LEFT JOIN users u ON u.id = c.user_id ORDER BY c.id DESC");
while ($row = $res->fetch_assoc()) {
    $candidateRecords[] = $row;
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users</title>
    <style>
        :root {
            --primary: #107c41;
            --primary-dark: #0b5c30;
            --danger: #c0392b;
            --danger-dark: #962d22;
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            background: linear-gradient(135deg, #f4f6f9 0%, #e8ecf1 100%);
            margin: 0;
            padding: 24px 16px;
            color: #1a1a1a;
        }
        .wrap { max-width: 980px; margin: 0 auto; }
        .topbar {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 10px; margin-bottom: 18px;
        }
        .topbar h1 { margin: 0; font-size: 22px; }
        .topbar .nav a {
            font-size: 13px; color: #555; text-decoration: none; margin-left: 14px;
        }
        .topbar .nav a:hover { color: var(--primary); text-decoration: underline; }
        .card {
            background: #fff; border-radius: 14px; padding: 22px 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08); margin-bottom: 20px;
        }
        .card h2 { margin: 0 0 14px; font-size: 16px; }
        .alert {
            padding: 10px 14px; border-radius: 8px; font-size: 13px;
            margin-bottom: 18px; border: 1px solid transparent;
        }
        .alert-success { background: #e8f5e9; border-color: #a9d08e; color: #1e5e2a; }
        .alert-error   { background: #fdecea; border-color: #f5c2c0; color: #7a1f16; }
        .add-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .add-form .f { flex: 1 1 180px; }
        label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 5px; color: #333; }
        input[type="text"], input[type="password"], select {
            width: 100%; padding: 9px 10px; border: 1px solid #d5d9e0;
            border-radius: 8px; font-size: 14px; background: #fff;
        }
        input:focus, select:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(16,124,65,0.12);
        }
        .hint { font-size: 12px; color: #777; margin: 10px 0 0; }
        button {
            padding: 9px 16px; border: none; border-radius: 8px; font-size: 13px;
            font-weight: 600; cursor: pointer; background: var(--primary); color: #fff;
        }
        button:hover { background: var(--primary-dark); }
        button.danger { background: var(--danger); }
        button.danger:hover { background: var(--danger-dark); }
        button.small { padding: 7px 12px; }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #eef0f4; vertical-align: middle; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #777; }
        .inline { display: flex; gap: 6px; align-items: center; }
        .inline input, .inline select { width: auto; min-width: 120px; padding: 7px 8px; font-size: 13px; }
        .badge {
            display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px;
            border-radius: 20px; background: #e8ecf1; color: #444; margin-left: 6px;
        }
        .badge.you { background: #e8f5e9; color: #1e5e2a; }
        .muted { color: #999; font-size: 12px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <h1>Manage Users</h1>
        <div class="nav no-print">
            <a href="results_2.php">&larr; Results</a>
            <a href="audit_viewer.php">Score Audit</a>
            <a href="bulk_users.php">Bulk Users</a>
            <a href="logout.php">Log out (<?php echo h($_SESSION['username'] ?? ''); ?>)</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>">
            <?php echo h($flash['msg']); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Add a new account</h2>
        <form method="POST" action="manage_users.php" class="add-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="create">
            <div class="f">
                <label for="new_username">Username</label>
                <input type="text" id="new_username" name="username" required pattern="[\p{L}0-9 .\-]{3,100}" title="3 to 100 characters: letters (including ñ), numbers, spaces, dots, and hyphens." autocomplete="off">
            </div>
            <div class="f">
                <label for="new_password">Password</label>
                <input type="password" id="new_password" name="password" required
                       minlength="<?php echo MIN_PASSWORD_LENGTH; ?>" maxlength="<?php echo MAX_PASSWORD_LENGTH; ?>"
                       autocomplete="new-password">
            </div>
            <div class="f">
                <label for="new_role">Role</label>
                <select id="new_role" name="role" required>
                    <option value="rater">Rater</option>
                    <option value="user">Standard User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="f">
                <label for="new_region">Region</label>
                <select id="new_region" name="region" required>
                    <option value="">-- Select region --</option>
                    <?php foreach (ACCESS_REGIONS as $value => $label): ?>
                        <option value="<?php echo h($value); ?>"><?php echo h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="f">
                <label for="new_field">Field</label>
                <select id="new_field" name="field_name" required>
                    <option value="">-- Select field --</option>
                    <?php foreach (ACCESS_FIELDS as $value): ?>
                        <option value="<?php echo h($value); ?>"><?php echo h($value); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="field_other" maxlength="100" placeholder="Enter field name" aria-label="Other field name" hidden>
            </div>
            <div class="f">
                <label for="new_salary_grade">Salary grade</label>
                <select id="new_salary_grade" name="salary_grade" required <?php echo $salaryGrades ? '' : 'disabled'; ?>>
                    <option value="">-- Select salary grade --</option>
                    <?php foreach ($salaryGrades as $grade): ?>
                        <option value="<?php echo $grade; ?>"><?php echo $grade; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$salaryGrades): ?><span class="muted">No Regional Office salary grades exist. Seed or import positions first.</span><?php endif; ?>
            </div>
            <button type="submit">Create account</button>
        </form>

        <p class="hint">
            Passwords: <?php echo MIN_PASSWORD_LENGTH; ?>-<?php echo MAX_PASSWORD_LENGTH; ?> characters.
            Give the username and password to the person yourself. Passwords can't be viewed later, only reset.
        </p>
    </div>

    <div class="card">
        <h2>Link candidate records to Standard User accounts</h2>
        <p class="hint">Standard Users can only see candidate records explicitly linked to their account. Existing records are not matched automatically by name.</p>
        <form method="POST" action="manage_users.php" class="add-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="link_candidate">
            <div class="f">
                <label for="candidate_link_user">Standard User account</label>
                <select id="candidate_link_user" name="user_id" required>
                    <option value="">-- Select account --</option>
                    <?php foreach ($users as $user): if ($user['role'] !== 'user') continue; ?>
                        <option value="<?php echo (int)$user['id']; ?>"><?php echo h($user['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="f">
                <label for="candidate_link_record">Candidate record</label>
                <select id="candidate_link_record" name="candidate_id" required>
                    <option value="">-- Select candidate --</option>
                    <?php foreach ($candidateRecords as $candidate): ?>
                        <?php
                        $candidateLabel = '#' . $candidate['id'] . ' · ' . ($candidate['candidate_name'] ?: 'Unnamed candidate')
                            . ' · ' . ($candidate['position_title'] ?: 'No position');
                        if (!empty($candidate['username'])) $candidateLabel .= ' · Linked to ' . $candidate['username'];
                        ?>
                        <option value="<?php echo (int)$candidate['id']; ?>"><?php echo h($candidateLabel); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">Link record</button>
        </form>
        <?php if (!$candidateRecords): ?>
            <p class="hint">No candidate records are available to link yet.</p>
        <?php endif; ?>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Candidate record</th><th>Linked account</th><th class="no-print"></th></tr></thead>
                <tbody>
                <?php foreach ($candidateRecords as $candidate): if ($candidate['user_id'] === null) continue; ?>
                    <tr>
                        <td><?php echo h(($candidate['candidate_name'] ?: 'Unnamed candidate') . ' · ' . ($candidate['position_title'] ?: 'No position')); ?></td>
                        <td><?php echo h($candidate['username'] ?? 'Deleted account'); ?></td>
                        <td class="no-print">
                            <form method="POST" action="manage_users.php">
                                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                <input type="hidden" name="action" value="unlink_candidate">
                                <input type="hidden" name="candidate_id" value="<?php echo (int)$candidate['id']; ?>">
                                <button type="submit" class="small danger">Unlink</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Accounts (<?php echo count($users); ?>)</h2>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Access profile</th>
                        <th>Reset password</th>
                        <th>Created</th>
                        <th class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): $isMe = ((int)$u['id'] === $myId); ?>
                    <tr>
                        <td>
                            <?php echo h($u['username']); ?>
                            <?php if ($isMe): ?><span class="badge you">you</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isMe): ?>
                                <?php echo h($u['role']); ?> <span class="muted">(can't change own)</span>
                            <?php else: ?>
                                <form method="POST" action="manage_users.php" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                    <input type="hidden" name="action" value="change_role">
                                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                    <select name="role" aria-label="Role for <?php echo h($u['username']); ?>">
                                        <?php foreach (MANAGE_ROLES as $r): ?>
                                            <option value="<?php echo h($r); ?>" <?php echo $u['role'] === $r ? 'selected' : ''; ?>>
                                                <?php echo $r === 'user' ? 'Standard User' : ucfirst($r); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="small">Save</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" action="manage_users.php" class="inline">
                                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                <input type="hidden" name="action" value="update_access_profile">
                                <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                <select name="region" required aria-label="Region for <?php echo h($u['username']); ?>">
                                    <option value="">-- Region --</option>
                                    <?php foreach (ACCESS_REGIONS as $value => $label): ?>
                                        <option value="<?php echo h($value); ?>" <?php echo $u['region'] === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="field_name" required aria-label="Field for <?php echo h($u['username']); ?>">
                                    <option value="">-- Field --</option>
                                    <?php foreach (ACCESS_FIELDS as $value): ?>
                                        <option value="<?php echo h($value); ?>" <?php echo ($u['field_name'] === $value || ($value === 'Other' && !in_array($u['field_name'], ACCESS_FIELDS, true))) ? 'selected' : ''; ?>><?php echo h($value); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="field_other" maxlength="100" placeholder="Other field" aria-label="Other field for <?php echo h($u['username']); ?>"
                                       value="<?php echo !in_array($u['field_name'], ACCESS_FIELDS, true) ? h($u['field_name']) : ''; ?>"
                                       <?php echo ($u['field_name'] === 'Other' || !in_array($u['field_name'], ACCESS_FIELDS, true)) ? '' : 'hidden'; ?>>
                                <select name="salary_grade" required aria-label="Salary grade for <?php echo h($u['username']); ?>" <?php echo $salaryGrades ? '' : 'disabled'; ?>>
                                    <option value="">-- Grade --</option>
                                    <?php foreach ($salaryGrades as $grade): ?>
                                        <option value="<?php echo $grade; ?>" <?php echo (string)$u['salary_grade'] === (string)$grade ? 'selected' : ''; ?>><?php echo $grade; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="small">Save</button>
                            </form>
                        </td>
                        <td>
                            <form method="POST" action="manage_users.php" class="inline" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                <input type="password" name="password" placeholder="New password" required
                                       minlength="<?php echo MIN_PASSWORD_LENGTH; ?>" maxlength="<?php echo MAX_PASSWORD_LENGTH; ?>"
                                       autocomplete="new-password">
                                <button type="submit" class="small">Reset</button>
                            </form>
                        </td>
                        <td class="muted"><?php echo h($u['created_at']); ?></td>
                        <td class="no-print">
                            <?php if (!$isMe): ?>
                                <form method="POST" action="manage_users.php"
                                      onsubmit="return confirm('Delete account <?php echo h(addslashes($u['username'])); ?>? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                    <button type="submit" class="small danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                    <tr><td colspan="6" class="muted">No accounts yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    document.querySelectorAll('form').forEach(form => {
        const fieldSelect = form.querySelector('select[name="field_name"]');
        const otherFieldInput = form.querySelector('input[name="field_other"]');
        if (!fieldSelect || !otherFieldInput) return;
        fieldSelect.addEventListener('change', () => {
            otherFieldInput.hidden = fieldSelect.value !== 'Other';
            if (fieldSelect.value !== 'Other') otherFieldInput.value = '';
        });
    });
</script>
</body>
</html>
