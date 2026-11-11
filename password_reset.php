<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';

$error = '';
$success = flash_get('success') ?? '';
$csrf = csrf_token();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Reload the page and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $username = mb_substr(trim((string)($_POST['username'] ?? '')), 0, 100);

        if ($action === 'request') {
            try {
                if (preg_match('/^[\p{L}0-9 .\-]{3,100}$/u', $username)) {
                    $conn->begin_transaction();
                    $userStmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND role = 'rater' LIMIT 1 FOR UPDATE");
                    $userStmt->bind_param('s', $username);
                    $userStmt->execute();
                    $user = $userStmt->get_result()->fetch_assoc();
                    $userStmt->close();

                    if ($user) {
                        $userId = (int)$user['id'];
                        $requestStmt = $conn->prepare('SELECT id, status, expires_at FROM password_reset_requests WHERE user_id = ? LIMIT 1 FOR UPDATE');
                        $requestStmt->bind_param('i', $userId);
                        $requestStmt->execute();
                        $existingRequest = $requestStmt->get_result()->fetch_assoc();
                        $requestStmt->close();

                        $canCreateRequest = !$existingRequest
                            || in_array($existingRequest['status'], ['rejected', 'used', 'expired'], true)
                            || ($existingRequest['status'] === 'approved'
                                && $existingRequest['expires_at'] !== null
                                && strtotime((string)$existingRequest['expires_at']) <= time());
                        if ($canCreateRequest) {
                            if (!$existingRequest) {
                                $insertStmt = $conn->prepare("INSERT INTO password_reset_requests (user_id, status) VALUES (?, 'pending')");
                                $insertStmt->bind_param('i', $userId);
                                $insertStmt->execute();
                                $insertStmt->close();
                            } else {
                                $requestId = (int)$existingRequest['id'];
                                $updateStmt = $conn->prepare("UPDATE password_reset_requests
                                    SET status = 'pending', token_hash = NULL, requested_at = NOW(),
                                        approved_at = NULL, expires_at = NULL, completed_at = NULL, reviewed_by = NULL
                                    WHERE id = ?");
                                $updateStmt->bind_param('i', $requestId);
                                $updateStmt->execute();
                                $updateStmt->close();
                            }
                        }
                    }
                    $conn->commit();
                }
                flash_set('success', 'If this username belongs to an eligible rater, an administrator will review the request.');
                header('Location: password_reset.php');
                exit;
            } catch (Throwable $exception) {
                try { $conn->rollback(); } catch (Throwable $rollbackException) {
                    error_log('password_reset request rollback failed: ' . $rollbackException->getMessage());
                }
                error_log('password_reset request failed: ' . $exception->getMessage());
                $error = 'The request could not be submitted. Please try again later.';
            }
        } elseif ($action === 'reset') {
            $code = strtolower(trim((string)($_POST['code'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($username === '' || !preg_match('/^[a-f0-9]{64}$/', $code)) {
                $error = 'Enter the username and valid one-time code supplied by the administrator.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif (strlen($password) > 72) {
                $error = 'Password must be at most 72 characters.';
            } elseif (!hash_equals($password, $confirmPassword)) {
                $error = 'The two passwords do not match.';
            } else {
                $transactionStarted = false;
                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;
                    $stmt = $conn->prepare("SELECT pr.id, pr.user_id, pr.token_hash, u.username
                        FROM password_reset_requests pr
                        JOIN users u ON u.id = pr.user_id
                        WHERE u.username = ? AND u.role = 'rater'
                            AND pr.status = 'approved' AND pr.expires_at > NOW()
                        LIMIT 1 FOR UPDATE");
                    $stmt->bind_param('s', $username);
                    $stmt->execute();
                    $request = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    $submittedHash = hash('sha256', $code);
                    if (!$request || !is_string($request['token_hash'])
                        || !hash_equals($request['token_hash'], $submittedHash)) {
                        $conn->rollback();
                        $transactionStarted = false;
                        $error = 'The username or one-time code is invalid, expired, or already used.';
                    } else {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        if ($passwordHash === false) {
                            throw new RuntimeException('Password hashing failed.');
                        }
                        $userId = (int)$request['user_id'];
                        $updateUser = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                        $updateUser->bind_param('si', $passwordHash, $userId);
                        $updateUser->execute();
                        $updateUser->close();

                        $deleteTokens = $conn->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
                        $deleteTokens->bind_param('i', $userId);
                        $deleteTokens->execute();
                        $deleteTokens->close();

                        $requestId = (int)$request['id'];
                        $consume = $conn->prepare("UPDATE password_reset_requests
                            SET status = 'used', token_hash = NULL, completed_at = NOW()
                            WHERE id = ? AND status = 'approved'");
                        $consume->bind_param('i', $requestId);
                        $consume->execute();
                        if ($consume->affected_rows !== 1) {
                            $consume->close();
                            throw new RuntimeException('The password reset code was already consumed.');
                        }
                        $consume->close();

                        $conn->commit();
                        $transactionStarted = false;
                        flash_set('success', 'Your password has been changed. You can now sign in.');
                        header('Location: login.php');
                        exit;
                    }
                } catch (Throwable $exception) {
                    if ($transactionStarted) {
                        try { $conn->rollback(); } catch (Throwable $rollbackException) {
                            error_log('password_reset completion rollback failed: ' . $rollbackException->getMessage());
                        }
                    }
                    error_log('password_reset completion failed: ' . $exception->getMessage());
                    $error = 'The password could not be changed. Please request a new approval code.';
                }
            }
        } else {
            $error = 'Unknown password reset action.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rater Password Reset</title>
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <script src="ui-enhancements.js?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.js'); ?>" defer></script>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 16px; background: #f4f6f9; color: #202124; font-family: Arial, Helvetica, sans-serif; }
        main { max-width: 620px; margin: 0 auto; }
        .card { padding: 22px; margin: 18px 0; background: #fff; border: 1px solid #dce1e7; border-radius: 9px; }
        label { display: block; margin: 12px 0 5px; font-weight: 700; font-size: 13px; }
        input { width: 100%; padding: 10px 12px; border: 1px solid #cbd2d9; border-radius: 5px; font: inherit; }
        button { margin-top: 16px; min-height: 44px; padding: 10px 15px; border: 0; border-radius: 5px; background: #107c41; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        p { color: #555; line-height: 1.5; }
        .notice { padding: 11px 13px; margin: 14px 0; border-radius: 5px; }
        .error { background: #fdecea; color: #7a1f16; }
        .success { background: #e8f5e9; color: #14532d; }
        a { color: #107c41; font-weight: 700; }
    </style>
</head>
<body>
<main>
    <h1>Rater password reset</h1>
    <?php if ($error !== ''): ?><div class="notice error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="notice success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <section class="card">
        <h2>Request admin approval</h2>
        <p>Submit your username. If it belongs to a rater account, an administrator will review your request and provide a one-time code.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="request">
            <label for="request_username">Username</label>
            <input id="request_username" name="username" maxlength="100" required autocomplete="username">
            <button type="submit">Request approval</button>
        </form>
    </section>

    <section class="card">
        <h2>Set a new password</h2>
        <p>After an administrator approves your request, enter the one-time code they gave you. The code expires after 24 hours and works once.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="reset">
            <label for="reset_username">Username</label>
            <input id="reset_username" name="username" maxlength="100" required autocomplete="username">
            <label for="code">One-time code</label>
            <input id="code" name="code" minlength="64" maxlength="64" pattern="[A-Fa-f0-9]{64}" required autocomplete="off">
            <label for="new_password">New password</label>
            <input id="new_password" type="password" name="password" minlength="8" maxlength="72" required autocomplete="new-password">
            <label for="confirm_password">Confirm new password</label>
            <input id="confirm_password" type="password" name="confirm_password" minlength="8" maxlength="72" required autocomplete="new-password">
            <button type="submit">Change password</button>
        </form>
    </section>
    <p><a href="login.php">Return to sign in</a></p>
</main>
</body>
</html>
