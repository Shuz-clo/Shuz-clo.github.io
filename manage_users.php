<?php
// Admin-only user management: add accounts, change roles, reset passwords, delete.
require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';

require_role('admin');
ensure_auth_schema($conn);

const MANAGE_ROLES = ['admin', 'rater', 'user'];
const MIN_PASSWORD_LENGTH = 8;
const MAX_PASSWORD_LENGTH = 72; // bcrypt only uses the first 72 bytes

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

// Signs the user out of every "Remember me" device
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

$myId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
        redirect_back();
    }

    $action = $_POST['action'] ?? '';

    try {
if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role     = $_POST['role'] ?? '';

            if (!preg_match('/^[\p{L}0-9 .\-]{3,100}$/u', $username)) {
                flash('error', 'Username must be 3-100 characters and can only contain letters (including ñ), numbers, spaces, dots, and hyphens.');
            } elseif (!in_array($role, MANAGE_ROLES, true)) {
                flash('error', 'Please choose a valid role.');
            } elseif (($problem = password_problem($password)) !== null) {
                flash('error', $problem);
            } elseif (find_user_by_username($conn, $username) !== null) {
                flash('error', "The username '$username' is already taken.");
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
                $stmt->bind_param('sss', $username, $hash, $role);
                $stmt->execute();
                $stmt->close();
                flash('success', "Account '$username' created as $role.");
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
            } elseif ($target['role'] === 'admin' && $role !== 'admin' && admin_count($conn) <= 1) {
                flash('error', 'You must keep at least one admin account.');
            } else {
                $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->bind_param('si', $role, $id);
                $stmt->execute();
                $stmt->close();
                revoke_user_tokens($conn, $id);
                flash('success', "'" . $target['username'] . "' is now $role. It applies at their next login.");
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
                flash('success', "Password updated for '" . $target['username'] . "'.");
            }

        } elseif ($action === 'delete') {
            $id     = (int)($_POST['id'] ?? 0);
            $target = $id > 0 ? find_user_by_id($conn, $id) : null;

            if (!$target) {
                flash('error', 'User not found.');
            } elseif ($id === $myId) {
                flash('error', "You can't delete your own account.");
            } elseif ($target['role'] === 'admin' && admin_count($conn) <= 1) {
                flash('error', 'You must keep at least one admin account.');
            } else {
                $conn->begin_transaction();
                revoke_user_tokens($conn, $id);
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                flash('success', "Account '" . $target['username'] . "' deleted.");
            }

        } else {
            flash('error', 'Unknown action.');
        }
    } catch (Exception $e) {
        try { $conn->rollback(); } catch (Exception $ignored) {}
        error_log('manage_users error: ' . $e->getMessage());
        flash('error', DEBUG ? $e->getMessage() : 'Something went wrong. Please try again.');
    }

    redirect_back();
}

// ---- Page data ----
$flash = null;
if (($m = flash_get('success')) !== null)   $flash = ['type' => 'success', 'msg' => $m];
elseif (($m = flash_get('error')) !== null) $flash = ['type' => 'error',   'msg' => $m];
$users = [];
$res = $conn->query("SELECT id, username, role, created_at FROM users ORDER BY FIELD(role,'admin','rater','user'), username");
while ($row = $res->fetch_assoc()) {
    $users[] = $row;
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
            <button type="submit">Create account</button>
        </form>
        <p class="hint">
            title="3 to 100 characters: letters (including ñ), numbers, spaces, dots, and hyphens." autocomplete="off">
            </div> Passwords: <?php echo MIN_PASSWORD_LENGTH; ?>-<?php echo MAX_PASSWORD_LENGTH; ?> characters.
            Give the username and password to the person yourself. Passwords can't be viewed later, only reset.
        </p>
    </div>

    <div class="card">
        <h2>Accounts (<?php echo count($users); ?>)</h2>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Role</th>
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
                    <tr><td colspan="5" class="muted">No accounts yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>