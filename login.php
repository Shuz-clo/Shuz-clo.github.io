<?php
require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';
require_once __DIR__ . '/config.php';

ensure_auth_schema($conn);
purge_expired_tokens($conn);

// Already logged in? Redirect to landing page.
if (is_logged_in()) {
    header("Location: " . landing_page_for(current_role()));
    exit;
}

$error   = flash_get('error') ?? '';
$success = flash_get('success');

// Auto-login if a valid "Remember Me" cookie is present
if (!empty($_COOKIE['remember_admin'])) {
    $user = verify_remember_token($conn, $_COOKIE['remember_admin']);
    if ($user) {
        session_regenerate_id(true);
        $_SESSION['user_id']       = (int)$user['id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['role']          = $user['role'];
        $_SESSION['last_activity'] = time();
        header("Location: " . landing_page_for($user['role']));
        exit;
    } else {
        setcookie('remember_admin', '', time() - 3600, '/', '', !empty($_SERVER['HTTPS']), true);
    }
}

$lockSecondsLeft = is_locked_out($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($lockSecondsLeft !== null) {
        $error = 'Too many attempts. Try again in ' . max(1, ceil($lockSecondsLeft / 60)) . ' minute(s).';
    } elseif (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $user     = $username !== '' ? find_user_by_username($conn, $username) : null;

        if ($user && password_verify($password, $user['password_hash'])) {
            reset_login_attempts($conn);
            session_regenerate_id(true);
            $_SESSION['user_id']       = (int)$user['id'];
            $_SESSION['username']      = $user['username'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['last_activity'] = time();
            unset($_SESSION['csrf_token']);

            if (!empty($_POST['remember'])) {
                $token = issue_remember_token($conn, (int)$user['id'], 30);
                setcookie('remember_admin', $token, [
                    'expires'  => time() + (86400 * 30),
                    'path'     => '/',
                    'secure'   => !empty($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
            } else {
                setcookie('remember_admin', '', time() - 3600, '/', '', !empty($_SERVER['HTTPS']), true);
            }

            header('Location: ' . landing_page_for($user['role']));
            exit;
        } else {
            register_failed_attempt($conn);
            sleep(1);
            $error = 'Wrong username or password.';
            $lockSecondsLeft = is_locked_out($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
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
            --success-bg: #e6f4ea;
            --success-border: #b7e1cd;
            --success-text: #137333;
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
        .login-card {
            background: #fff;
            width: 100%;
            max-width: 380px;
            padding: 36px 32px;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        .login-card h2 {
            margin: 0 0 6px;
            font-size: 22px;
            color: #1a1a1a;
            text-align: center;
        }
        .login-card p.sub {
            margin: 0 0 24px;
            text-align: center;
            color: #777;
            font-size: 13px;
        }
        .alert {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 18px;
            border: 1px solid transparent;
        }
        .alert-error   { background: var(--error-bg); border-color: var(--error-border); color: var(--error-text); }
        .alert-warn    { background: var(--warn-bg);  border-color: var(--warn-border);  color: var(--warn-text); }
        .alert-success { background: var(--success-bg); border-color: var(--success-border); color: var(--success-text); }
        .field { margin-bottom: 18px; }
        .field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #333;
        }
        .field input[type="text"] {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d5d9e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.15s;
        }
        .pw-wrap { position: relative; }
        .pw-wrap input {
            width: 100%;
            padding: 11px 42px 11px 12px;
            border: 1px solid #d5d9e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.15s;
        }
        .field input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(16,124,65,0.12);
        }
        .pw-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            background: none;
            border: none;
            padding: 4px;
            color: #888;
            display: flex;
        }
        .pw-toggle:hover { color: #333; }
        .remember-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #555;
            margin-bottom: 22px;
        }
        .remember-row input { cursor: pointer; width: 15px; height: 15px; }
        button.submit-btn {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }
        button.submit-btn:hover { background: var(--primary-dark); }
        button.submit-btn:disabled { background: #a8c9b6; cursor: not-allowed; }
        .switch-link {
            margin-top: 20px;
            text-align: center;
            font-size: 14px;
            color: #555;
        }
        .switch-link a {
            color: var(--primary);
            font-weight: 700;
            text-decoration: none;
        }
        .switch-link a:hover { text-decoration: underline; }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 14px;
            font-size: 13px;
            color: #888;
            text-decoration: none;
        }
        .back-link:hover { color: var(--primary); text-decoration: underline; }
        @media (max-width: 420px) {
            .login-card { padding: 28px 20px; border-radius: 10px; }
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>Sign In</h2>
        <p class="sub">DENR Interview Scoring Sheet</p>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif ($lockoutTime = is_locked_out($conn)): ?>
            <div class="alert alert-warn">
                Too many attempts. Try again in <?php echo max(1, ceil($lockoutTime / 60)); ?> minute(s).
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus
                       placeholder="Your username" autocomplete="username"
                       <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="pw-wrap">
                    <input type="password" id="password" name="password" required
                           placeholder="Your password" autocomplete="current-password"
                           <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
                    <button type="button" class="pw-toggle" id="pwToggle" aria-label="Show password" tabindex="-1">
                        <svg id="eyeOpen" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        <svg id="eyeClosed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
                            <path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a18.7 18.7 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 5c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"></path>
                            <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                    </button>
                </div>
            </div>

            <label class="remember-row">
                <input type="checkbox" name="remember" value="1">
                Remember me on this device
            </label>

            <button type="submit" class="submit-btn" <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
                Log In
            </button>
        </form>

        <div class="switch-link">Don't have an account? <a href="register.php">Sign Up</a></div>
        <a href="index.php" class="back-link">&larr; Return to Scoring Sheet</a>
    </div>

    <script>
        const toggle = document.getElementById('pwToggle');
        const input = document.getElementById('password');
        const eyeOpen = document.getElementById('eyeOpen');
        const eyeClosed = document.getElementById('eyeClosed');

        toggle.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            eyeOpen.style.display = show ? 'none' : 'block';
            eyeClosed.style.display = show ? 'block' : 'none';
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    </script>
</body>
</html>