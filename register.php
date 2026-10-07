<?php
require_once 'auth.php';
require_once __DIR__ . '/config.php';
require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';

ensure_auth_schema($conn);

// Already signed in? Send them where they belong.
if (is_logged_in()) {
    header('Location: ' . landing_page_for((string)current_role()));
    exit;
}

const REG_ALLOWED_ROLES = ['rater', 'user'];
const REG_MIN_PASSWORD  = 8;
const REG_MAX_PASSWORD  = 72;

$registrationCode = (defined('REGISTRATION_CODE') && REGISTRATION_CODE !== '') ? (string)REGISTRATION_CODE : null;

$error = '';
$old   = ['username' => '', 'role' => 'rater'];
$lockSecondsLeft = is_locked_out($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username   = preg_replace('/\s+/', ' ', trim((string)($_POST['username'] ?? '')));
    $password   = (string)($_POST['password'] ?? '');
    $confirm    = (string)($_POST['password_confirm'] ?? '');
    $postedRole = strtolower(trim((string)($_POST['role'] ?? '')));

    $old['username'] = $username;
    $old['role']     = in_array($postedRole, REG_ALLOWED_ROLES, true) ? $postedRole : 'rater';

    if (!empty($_POST['website'])) {
        flash_set('success', 'Account created successfully! Please sign in.');
        header('Location: login.php');
        exit;
    }

    if ($lockSecondsLeft !== null) {
        $error = 'Too many attempts. Try again in ' . max(1, ceil($lockSecondsLeft / 60)) . ' minute(s).';
    } elseif (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($postedRole === 'admin') {
        error_log('register.php: blocked attempt to self-register as admin from ' . client_ip());
        register_failed_attempt($conn);
        $error = 'Admin accounts cannot be created through registration.';
    } elseif (!in_array($postedRole, REG_ALLOWED_ROLES, true)) {
        $error = 'Please choose a valid role.';
    } elseif (isset($_SESSION['last_register']) && (time() - $_SESSION['last_register']) < 5) {
        $error = 'Please wait a few seconds before trying again.';
    } elseif (!preg_match('/^[\p{L}0-9 .\-]{3,100}$/u', $username)) {
        $error = 'Name must be 3-100 characters: letters (including ñ), numbers, spaces, dots, and hyphens only.';
    } elseif ($registrationCode !== null && !hash_equals($registrationCode, (string)($_POST['reg_code'] ?? ''))) {
        $error = 'The registration code is not correct.';
    } elseif (strlen($password) < REG_MIN_PASSWORD) {
        $error = 'Password must be at least ' . REG_MIN_PASSWORD . ' characters.';
    } elseif (strlen($password) > REG_MAX_PASSWORD) {
        $error = 'Password must be at most ' . REG_MAX_PASSWORD . ' characters.';
    } elseif (!hash_equals($password, $confirm)) {
        $error = 'The two passwords do not match.';
    } elseif (find_user_by_username($conn, $username) !== null) {
        $error = 'That name is already taken. Please choose another.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $role = $postedRole;

            $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
            $stmt->bind_param('sss', $username, $hash, $role);
            $stmt->execute();
            $stmt->close();

            $_SESSION['last_register'] = time();
            unset($_SESSION['csrf_token']);
            flash_set('success', 'Account created successfully! Please sign in.');
            header('Location: login.php');
            exit;
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1062) {
                $error = 'That name is already taken. Please choose another.';
            } else {
                error_log('register error: ' . $e->getMessage());
                $error = DEBUG ? $e->getMessage() : 'Could not create the account. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up</title>
    <style>
        :root {
            --primary: #107c41;
            --primary-dark: #0b5c30;
            --error-bg: #fdecea;
            --error-border: #f5c2c0;
            --error-text: #7a1f16;
            --warn-bg: #fff6e0;
            --warn-border: #f0dca0;
            --warn-text: #7a5a00;
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            background: linear-gradient(135deg, #f4f6f9 0%, #e8ecf1 100%);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: #fff;
            width: 100%;
            max-width: 400px;
            padding: 36px 32px;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        .card h2 { margin: 0 0 6px; font-size: 22px; color: #1a1a1a; text-align: center; }
        .card p.sub { margin: 0 0 24px; text-align: center; color: #777; font-size: 13px; }
        .alert {
            padding: 10px 14px; border-radius: 8px; font-size: 13px;
            margin-bottom: 18px; border: 1px solid transparent;
        }
        .alert-error { background: var(--error-bg); border-color: var(--error-border); color: var(--error-text); }
        .alert-warn  { background: var(--warn-bg);  border-color: var(--warn-border);  color: var(--warn-text); }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #333; }
        .field input[type="text"], .field input[type="password"], .field select {
            width: 100%; padding: 11px 12px; border: 1px solid #d5d9e0;
            border-radius: 8px; font-size: 14px; background: #fff;
            transition: border-color 0.15s;
        }
        .field input:focus, .field select:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(16,124,65,0.12);
        }
        .field .hint { font-size: 12px; color: #888; margin-top: 5px; }
        button.submit-btn {
            width: 100%; padding: 12px; margin-top: 6px; background: var(--primary); color: #fff;
            border: none; border-radius: 8px; font-size: 15px; font-weight: 600;
            cursor: pointer; transition: background 0.15s;
        }
        button.submit-btn:hover { background: var(--primary-dark); }
        button.submit-btn:disabled { background: #a8c9b6; cursor: not-allowed; }
        .switch-link {
            margin-top: 20px; text-align: center; font-size: 14px; color: #555;
        }
        .switch-link a { color: var(--primary); font-weight: 700; text-decoration: none; }
        .switch-link a:hover { text-decoration: underline; }
        @media (max-width: 420px) { .card { padding: 28px 20px; border-radius: 10px; } }
    </style>
</head>
<body>
    <div class="card">
        <h2>Create an Account</h2>
        <p class="sub">DENR Interview Scoring Sheet</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif ($lockSecondsLeft !== null): ?>
            <div class="alert alert-warn">
                Too many attempts. Try again in <?php echo max(1, ceil($lockSecondsLeft / 60)); ?> minute(s).
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true"
                   style="position:absolute; left:-9999px; width:1px; height:1px;">

            <div class="field">
    <label for="username">Full Name / Username</label>
    <input type="text" id="username" name="username" required autofocus
           minlength="3" maxlength="100" pattern="[\p{L}0-9 .\-]{3,100}"
           title="3 to 100 characters: letters (including ñ), numbers, spaces, dots, and hyphens."
           value="<?php echo htmlspecialchars($old['username']); ?>"
           placeholder="e.g. Juan Dela Cruz" autocomplete="username"
           <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
    <div class="hint">Letters (including ñ), numbers, spaces, dots and dashes. This is what you sign in with.</div>
</div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required
                       minlength="<?php echo REG_MIN_PASSWORD; ?>" maxlength="<?php echo REG_MAX_PASSWORD; ?>"
                       placeholder="At least <?php echo REG_MIN_PASSWORD; ?> characters" autocomplete="new-password"
                       <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
            </div>

            <div class="field">
                <label for="password_confirm">Confirm Password</label>
                <input type="password" id="password_confirm" name="password_confirm" required
                       minlength="<?php echo REG_MIN_PASSWORD; ?>" maxlength="<?php echo REG_MAX_PASSWORD; ?>"
                       placeholder="Type the password again" autocomplete="new-password"
                       <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
            </div>

            <div class="field">
                <label for="role">I am a</label>
                <select id="role" name="role" required <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
                    <option value="rater" <?php echo $old['role'] === 'rater' ? 'selected' : ''; ?>>Rater</option>
                    <option value="user"  <?php echo $old['role'] === 'user'  ? 'selected' : ''; ?>>Standard User</option>
                </select>
            </div>

            <?php if ($registrationCode !== null): ?>
            <div class="field">
                <label for="reg_code">Registration Code</label>
                <input type="password" id="reg_code" name="reg_code" required autocomplete="off"
                       placeholder="Ask your administrator"
                       <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
            </div>
            <?php endif; ?>

            <button type="submit" class="submit-btn" <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
                Sign Up
            </button>
        </form>

        <div class="switch-link">Already have an account? <a href="login.php">Sign In</a></div>
    </div>
</body>
</html>