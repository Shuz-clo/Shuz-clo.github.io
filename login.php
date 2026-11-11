<?php
// ============================================================
// login.php — COMPLETE FIXED FILE
//
// FIX (Bug 6): remember_admin cookie uses SameSite=Lax.
// FIX (Bug 7): remember-me token rotated on every auto-login.
// FIX:          cookie deletion uses array setcookie() signature.
// ============================================================

require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';
require_once __DIR__ . '/config.php';
ensure_auth_schema($conn);
purge_expired_tokens($conn);

if (is_logged_in()) {
    header("Location: " . landing_page_for(current_role()));
    exit;
}

$error   = flash_get('error') ?? '';
$success = flash_get('success');

// ---------- Auto-login via remember-me cookie ----------
if (!empty($_COOKIE['remember_admin'])) {
    $user = verify_remember_token($conn, $_COOKIE['remember_admin']);
    if ($user) {
        delete_remember_token($conn, $_COOKIE['remember_admin']);
        $newToken = issue_remember_token($conn, (int)$user['id'], 30);
        setcookie('remember_admin', $newToken, [
            'expires'  => time() + (86400 * 30),
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_regenerate_id(true);
        $_SESSION['user_id']       = (int)$user['id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['role']          = $user['role'];
        $_SESSION['last_activity'] = time();

        header("Location: " . landing_page_for($user['role']));
        exit;
    } else {
        setcookie('remember_admin', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

$lockSecondsLeft = is_locked_out($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($lockSecondsLeft !== null) {
        $error = 'Too many attempts. Try again in ' . max(1, ceil($lockSecondsLeft / 60)) . ' minute(s).';
    } elseif (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $username = mb_substr(trim($_POST['username'] ?? ''), 0, 100);
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
                    'samesite' => 'Lax',
                ]);
            } else {
                setcookie('remember_admin', '', [
                    'expires'  => time() - 3600,
                    'path'     => '/',
                    'secure'   => !empty($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="mockup.css?v=<?php echo filemtime(__DIR__ . '/mockup.css'); ?>">
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <script src="ui-enhancements.js?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.js'); ?>" defer></script>
    <style>
        #login { min-height: 100vh; padding: 32px 18px; background: radial-gradient(ellipse at top left, #e6f2e9 0, transparent 48%), linear-gradient(135deg, #f5f7f6, #e9efeb); }
        #login .lc { width: min(100%, 440px); padding: 38px 36px 30px; border: 1px solid rgba(16, 124, 65, .12); border-top: 4px solid var(--br); border-radius: 18px; box-shadow: 0 18px 48px rgba(27, 55, 39, .12); }
        #login .lc h2 { font-size: 26px; letter-spacing: -.03em; }
        #login .lc .sub { margin-bottom: 28px; }
        #login .fl { margin-bottom: 20px; }
        #login .fl input[type="text"], #login .pw input { min-height: 46px; }
        #login .rm { margin: 2px 0 22px; }
        #login .sb { min-height: 48px; border-radius: 9px; }
        #login .sw { margin-top: 16px; }
        #login .bk { margin-top: 20px; }
        .alert { padding: 10px 14px; border: 1px solid transparent; border-radius: 8px; margin-bottom: 18px; font-size: 13px; }
        .alert-error { background: #fdecea; border-color: #f5c2c0; color: #7a1f16; }
        .alert-warn { background: #fff6e0; border-color: #f0dca0; color: #7a5a00; }
        .alert-success { background: #e6f4ea; border-color: #b7e1cd; color: #137333; }
        @media (max-width: 480px) {
            #login { padding: 18px 14px; }
            #login .lc { padding: 30px 22px 24px; }
        }
    </style>
</head>
<body>
    <section id="login"><div class="lc">
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

            <div class="fl">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus
                       placeholder="Your username" autocomplete="username"
                       <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
            </div>

            <div class="fl">
                <label for="password">Password</label>
                <div class="pw">
                    <input type="password" id="password" name="password" required
                           placeholder="Your password" autocomplete="current-password"
                           <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
                    <button type="button" class="pt" id="pwToggle" aria-label="Show password" tabindex="-1">
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

            <label class="rm">
                <input type="checkbox" name="remember" value="1">
                Remember me on this device
            </label>

            <button type="submit" class="sb" <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
                Log In
            </button>
        </form>

        <div class="sw">Forgot your password? <a href="password_reset.php">Request admin approval to change it</a></div>
        <div class="sw">Don't have an account? <a href="register.php">Sign Up</a></div>
        <a href="index.php" class="bk">&larr; Return to Scoring Sheet</a>
    </div></section>

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
