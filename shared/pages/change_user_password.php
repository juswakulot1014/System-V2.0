<?php
declare(strict_types=1);

// shared/pages/change_user_password.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/security.php';

$__nav_section = 'system';

$error   = '';
$success = '';

/* ═══ AJAX: live username existence check ═══ */
if (($_GET['action'] ?? '') === 'check_username') {
    header('Content-Type: application/json; charset=utf-8');

    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        http_response_code(403);
        echo json_encode(['exists' => false, 'valid' => false, 'error' => 'csrf']);
        exit;
    }

    $un = clean_string($_POST['username'] ?? '', 50);
    if ($un === '') {
        echo json_encode(['exists' => false, 'valid' => false]);
        exit;
    }

    $chk = $conn->prepare("SELECT id FROM admins WHERE username = ? LIMIT 1");
    if (!$chk) {
        echo json_encode(['exists' => false, 'valid' => true, 'error' => 'db']);
        exit;
    }
    $chk->bind_param('s', $un);
    $chk->execute();
    $exists = $chk->get_result()->num_rows === 1;
    $chk->close();

    echo json_encode(['exists' => $exists, 'valid' => true]);
    exit;
}

/* ═══ Rate limiting ═══ */
$max_attempts   = 3;
$lockout_time   = 900;
$rate_limit_key = 'pwd_change_attempts_' . (int)$_SESSION['admin_id'];

if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
}
if ($_SESSION[$rate_limit_key]['count'] >= $max_attempts) {
    $since = time() - (int)$_SESSION[$rate_limit_key]['last_attempt'];
    if ($since >= $lockout_time) {
        $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
    }
}

/* ═══ POST handling ═══ */
$admin_data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $is_locked = (
        $_SESSION[$rate_limit_key]['count'] >= $max_attempts &&
        (time() - (int)$_SESSION[$rate_limit_key]['last_attempt']) < $lockout_time
    );

    if ($is_locked) {
        $since     = time() - (int)$_SESSION[$rate_limit_key]['last_attempt'];
        $remaining = (int)ceil(($lockout_time - $since) / 60);
        $error     = "Too many attempts. Please try again in {$remaining} minute(s).";
    }

    /* Step 1: verify acting admin's password */
    if ($error === '') {
        $admin_password = (string)($_POST['admin_password'] ?? '');

        if ($admin_password === '') {
            $error = 'Please enter your own admin password for verification.';
        } else {
            $verify_stmt = $conn->prepare("SELECT password FROM admins WHERE id = ? LIMIT 1");
            if (!$verify_stmt) {
                $error = 'System error. Please try again later.';
            } else {
                $verify_stmt->bind_param('i', $_SESSION['admin_id']);
                $verify_stmt->execute();
                $verify_result = $verify_stmt->get_result();

                if ($verify_result->num_rows !== 1) {
                    $error = 'Your session is no longer valid. Please log out and log in again.';
                } else {
                    $admin_data = $verify_result->fetch_assoc();
                    if (!password_verify($admin_password, $admin_data['password'])) {
                        $error = 'Your admin password is incorrect. Action denied.';
                        $_SESSION[$rate_limit_key]['count']++;
                        $_SESSION[$rate_limit_key]['last_attempt'] = time();

                        audit_log($conn, 'password.change_denied', [
                            'type' => 'admin',
                            'id'   => (int)$_SESSION['admin_id'],
                        ]);
                        security_log('password.change_denied', [
                            'admin_id' => (int)$_SESSION['admin_id'],
                            'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
                        ]);
                    }
                }
                $verify_stmt->close();
            }
        }
    }

    /* Step 2: validate new password */
    if ($error === '') {
        $target_username  = clean_string($_POST['username'] ?? '', 50);
        $new_password     = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        if ($target_username === '' || $new_password === '' || $confirm_password === '') {
            $error = 'Please fill in all fields.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
        } elseif (strlen($new_password) < 8) {
            $error = 'New password must be at least 8 characters long.';
        } elseif (strlen($new_password) > 128) {
            $error = 'Password must not exceed 128 characters.';
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $error = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            $error = 'Password must contain at least one lowercase letter.';
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $error = 'Password must contain at least one number.';
        } elseif (!preg_match('/[^A-Za-z0-9]/', $new_password)) {
            $error = 'Password must contain at least one special character.';
        }
    }

    /* Step 3: look up target and update */
    if ($error === '') {
        $target_username = clean_string($_POST['username'] ?? '', 50);

        $stmt = $conn->prepare("
            SELECT id, fullname, username, role
            FROM admins
            WHERE username = ?
            LIMIT 1
        ");
        if (!$stmt) {
            $error = 'System error. Please try again later.';
        } else {
            $stmt->bind_param('s', $target_username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows !== 1) {
                $error = 'Username "' . htmlspecialchars($target_username, ENT_QUOTES, 'UTF-8') . '" does not exist.';
            } else {
                $user    = $result->fetch_assoc();
                $is_self = ((int)$user['id'] === (int)$_SESSION['admin_id']);

                if ($user['role'] === 'superadmin' && !$is_self) {
                    $error = "You cannot change another superadmin's password.";
                } elseif ($is_self && password_verify($new_password, $admin_data['password'])) {
                    $error = 'Your new password must be different from your current password.';
                } else {
                    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);

                    $upd = $conn->prepare("
                        UPDATE admins
                           SET password         = ?,
                               reset_token      = NULL,
                               reset_expires    = NULL,
                               remember_token   = NULL,
                               remember_expires = NULL
                         WHERE id = ?
                    ");
                    if (!$upd) {
                        error_log('Password change prepare failed: ' . $conn->error);
                        $error = 'System error. Please try again later.';
                    } else {
                        $upd->bind_param('si', $hashed_password, $user['id']);
                        if (!$upd->execute()) {
                            error_log('Password change execute failed: ' . $upd->error);
                            $error = 'System error. Please try again later.';
                        } else {
                            $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
                            $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

                            if ($is_self) {
                                audit_log($conn, 'password.self_change', [
                                    'type' => 'admin',
                                    'id'   => $user['id'],
                                ]);
                                $success = 'Your password has been updated successfully.';
                            } else {
                                audit_log($conn, 'password.change', [
                                    'type'     => 'admin',
                                    'id'       => $user['id'],
                                    'username' => $target_username,
                                ]);
                                $success = 'Password successfully updated for user: '
                                         . htmlspecialchars($target_username, ENT_QUOTES, 'UTF-8');
                            }

                            unset($_POST['username'], $_POST['new_password'], $_POST['confirm_password'], $_POST['admin_password']);
                        }
                        $upd->close();
                    }
                }
            }
            $stmt->close();
        }
    }
}

/* ═══ View data ═══ */
$csrf_token    = csrf_token();
$admin_name    = htmlspecialchars((string)($_SESSION['admin_name'] ?? 'Administrator'), ENT_QUOTES, 'UTF-8');

$attempts_used = (int)$_SESSION[$rate_limit_key]['count'];
$attempts_left = max(0, $max_attempts - $attempts_used);
$locked_until  = 0;
if ($attempts_used >= $max_attempts) {
    $since = time() - (int)$_SESSION[$rate_limit_key]['last_attempt'];
    if ($since < $lockout_time) $locked_until = (int)ceil(($lockout_time - $since) / 60);
}

$page_title = 'Change User Password • USAT Admin';

$page_css = <<<'CSS'
    .cw-wrap{max-width:640px;margin:0 auto;animation:cw-fade .25s ease}
    @keyframes cw-fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

    .cw-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);overflow:hidden}
    .cw-card-head{display:flex;align-items:center;gap:.85rem;padding:1.15rem 1.5rem;border-bottom:1px solid var(--border);background:linear-gradient(180deg,var(--surface),var(--surface-2))}
    .cw-card-head-icon{width:42px;height:42px;border-radius:11px;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;box-shadow:0 4px 12px rgba(79,70,229,.35)}
    .cw-card-head h3{font-size:1rem;font-weight:700;margin:0 0 .1rem}
    .cw-card-head p{font-size:.82rem;color:var(--text-2);margin:0}
    .cw-card-body{padding:1.5rem}

    .cw-field{margin-bottom:1.15rem}
    .cw-field-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem}
    .cw-field label{display:block;font-size:.75rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.55px;margin-bottom:.4rem}
    .cw-field-head label{margin-bottom:0}
    .cw-charcount{font-size:.72rem;color:var(--text-2);font-variant-numeric:tabular-nums;font-weight:500}

    .cw-input-wrap{position:relative}
    .cw-input-wrap input{width:100%;padding:.8rem 2.75rem .8rem 2.6rem;border:1.5px solid var(--border);border-radius:11px;font-size:.92rem;background:var(--surface-2);color:var(--text);font-family:inherit;transition:border-color .15s,box-shadow .15s,background .15s}
    .cw-input-wrap input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent);background:var(--surface)}
    .cw-input-icon{position:absolute;left:.95rem;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:1rem;pointer-events:none;transition:color .15s}
    .cw-input-wrap input:focus ~ .cw-input-icon{color:var(--accent)}
    .cw-eye{position:absolute;right:.5rem;top:50%;transform:translateY(-50%);width:32px;height:32px;border:none;background:transparent;color:var(--text-2);cursor:pointer;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:background .15s,color .15s}
    .cw-eye:hover{background:var(--surface-2);color:var(--accent)}

    .cw-hint{margin:.45rem 0 0;font-size:.78rem;color:var(--text-2);line-height:1.4}
    .cw-hint.is-valid{color:var(--green);font-weight:600}
    .cw-hint.is-invalid{color:var(--red);font-weight:600}
    .cw-hint i{margin-right:.25rem}

    .cw-divider{display:flex;align-items:center;gap:.75rem;margin:1.5rem 0 1.15rem}
    .cw-divider::before,.cw-divider::after{content:'';flex:1;height:1px;background:var(--border)}
    .cw-divider span{font-size:.7rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;white-space:nowrap;display:inline-flex;align-items:center;gap:.35rem}
    .cw-divider span i{font-size:.75rem;color:var(--accent)}

    .cw-strength{display:grid;grid-template-columns:repeat(5,1fr);gap:4px;margin-top:.55rem}
    .cw-strength-seg{height:5px;border-radius:3px;background:var(--border);transition:background .2s}
    .cw-strength-seg.active-weak{background:var(--red)}
    .cw-strength-seg.active-medium{background:var(--amber)}
    .cw-strength-seg.active-strong{background:var(--green)}

    .cw-reqs{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.6rem}
    .cw-req{display:inline-flex;align-items:center;gap:.35rem;padding:.22rem .6rem;border-radius:20px;font-size:.7rem;font-weight:600;background:var(--surface-2);color:var(--text-2);border:1px solid var(--border);transition:all .15s}
    .cw-req i{font-size:.6rem;color:var(--text-3);opacity:.7}
    .cw-req.met{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb,var(--green) 30%,transparent)}
    .cw-req.met i{color:var(--green);opacity:1}

    .cw-submit{width:100%;padding:.9rem 1.25rem;border:none;border-radius:11px;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;font-size:.95rem;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:.5rem;box-shadow:0 4px 14px rgba(79,70,229,.3);transition:transform .15s,box-shadow .15s,opacity .15s;margin-top:.75rem;letter-spacing:.2px}
    .cw-submit:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 6px 18px rgba(79,70,229,.4)}
    .cw-submit:disabled{opacity:.65;cursor:not-allowed}

    .cw-attempts{display:flex;align-items:center;gap:.55rem;padding:.65rem .9rem;border-radius:10px;background:var(--amber-soft);color:var(--amber);font-size:.82rem;font-weight:600;margin-bottom:1.15rem;border-left:4px solid var(--amber)}

    @media (max-width:640px){
        .cw-card-body{padding:1.15rem}
        .cw-card-head{padding:1rem 1.15rem}
    }
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

<div class="main-content" id="mainContent">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <div style="display:flex;align-items:center;gap:.4rem;font-size:.78rem;color:var(--text-2);margin-bottom:.25rem">
                    <a href="<?= URL_PREFIX ?>/create_account.php" style="color:var(--accent);text-decoration:none;font-weight:500">Accounts</a>
                    <i class="bi bi-chevron-right" style="font-size:.7rem;opacity:.6"></i>
                    <span>Change Password</span>
                </div>
                <h2>Change User Password</h2>
                <p class="sub">Reset another admin's password</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="<?= URL_PREFIX ?>/create_account.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <div class="cw-wrap">

        <?php if ($error !== ''): ?>
            <div class="flash error" role="alert">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash success" role="status">
                <i class="bi bi-check-circle-fill"></i>
                <span><?= $success ?></span>
            </div>
        <?php endif; ?>

        <?php if ($attempts_used > 0 && $locked_until === 0): ?>
            <div class="cw-attempts">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= $attempts_left ?> attempt<?= $attempts_left === 1 ? '' : 's' ?> remaining before lockout.</span>
            </div>
        <?php endif; ?>

        <div class="cw-card">
            <div class="cw-card-head">
                <div class="cw-card-head-icon"><i class="bi bi-key-fill"></i></div>
                <div>
                    <h3>Reset Password</h3>
                    <p>Enter the target username and set a new password.</p>
                </div>
            </div>

            <div class="cw-card-body">
                <form method="POST" id="passwordForm" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="cw-field">
                        <label for="username">Target Username</label>
                        <div class="cw-input-wrap">
                            <input type="text"
                                   id="username"
                                   name="username"
                                   placeholder="Enter username"
                                   required
                                   autofocus
                                   maxlength="50"
                                   autocomplete="off"
                                   value="">
                            <i class="bi bi-person cw-input-icon"></i>
                        </div>
                        <p class="cw-hint" id="usernameStatus"></p>
                    </div>

                    <div class="cw-field">
                        <div class="cw-field-head">
                            <label for="new_password">New Password</label>
                            <span class="cw-charcount" id="cwCharCount">0 / 128</span>
                        </div>
                        <div class="cw-input-wrap">
                            <input type="password"
                                   id="new_password"
                                   name="new_password"
                                   placeholder="Enter new password"
                                   required
                                   minlength="8"
                                   maxlength="128"
                                   autocomplete="new-password">
                            <i class="bi bi-lock-fill cw-input-icon"></i>
                            <button type="button" class="cw-eye" data-toggle-pw="new_password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="cw-strength" id="cwStrength">
                            <span class="cw-strength-seg"></span>
                            <span class="cw-strength-seg"></span>
                            <span class="cw-strength-seg"></span>
                            <span class="cw-strength-seg"></span>
                            <span class="cw-strength-seg"></span>
                        </div>
                        <div class="cw-reqs" id="cwReqs">
                            <span class="cw-req" data-req="length"><i class="bi bi-circle-fill"></i> 8+ chars</span>
                            <span class="cw-req" data-req="upper"><i class="bi bi-circle-fill"></i> A–Z</span>
                            <span class="cw-req" data-req="lower"><i class="bi bi-circle-fill"></i> a–z</span>
                            <span class="cw-req" data-req="num"><i class="bi bi-circle-fill"></i> 0–9</span>
                            <span class="cw-req" data-req="special"><i class="bi bi-circle-fill"></i> !@#</span>
                        </div>
                    </div>

                    <div class="cw-field">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="cw-input-wrap">
                            <input type="password"
                                   id="confirm_password"
                                   name="confirm_password"
                                   placeholder="Re-enter new password"
                                   required
                                   minlength="8"
                                   maxlength="128"
                                   autocomplete="new-password">
                            <i class="bi bi-lock-fill cw-input-icon"></i>
                            <button type="button" class="cw-eye" data-toggle-pw="confirm_password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <p class="cw-hint" id="matchStatus"></p>
                    </div>

                    <div class="cw-divider">
                        <span><i class="bi bi-shield-check"></i> Verification</span>
                    </div>

                    <div class="cw-field">
                        <label for="admin_password">Your Admin Password</label>
                        <div class="cw-input-wrap">
                            <input type="password"
                                   id="admin_password"
                                   name="admin_password"
                                   placeholder="Enter your password to confirm"
                                   required
                                   autocomplete="current-password">
                            <i class="bi bi-shield-lock-fill cw-input-icon"></i>
                            <button type="button" class="cw-eye" data-toggle-pw="admin_password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <p class="cw-hint">Required as proof that you're the one making this change. You have <?= $attempts_left ?> attempt<?= $attempts_left === 1 ? '' : 's' ?> left.</p>
                    </div>

                    <button type="submit" class="cw-submit" id="cwSubmit" <?= $locked_until > 0 ? 'disabled' : '' ?>>
                        <i class="bi <?= $locked_until > 0 ? 'bi-lock-fill' : 'bi-key-fill' ?>"></i>
                        <span><?= $locked_until > 0 ? "Locked for {$locked_until} min" : 'Change Password' ?></span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('[data-toggle-pw]').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = document.getElementById(btn.getAttribute('data-toggle-pw'));
        if (!input) return;
        const icon = btn.querySelector('i');
        if (input.type === 'password') { input.type = 'text'; if (icon) icon.className = 'bi bi-eye-slash'; }
        else { input.type = 'password'; if (icon) icon.className = 'bi bi-eye'; }
    });
});

(function () {
    const input = document.getElementById('new_password');
    const segs  = document.querySelectorAll('#cwStrength .cw-strength-seg');
    const reqs  = document.getElementById('cwReqs');
    const count = document.getElementById('cwCharCount');
    if (!input || !segs.length || !reqs) return;

    const rules = {
        length:  p => p.length >= 8,
        upper:   p => /[A-Z]/.test(p),
        lower:   p => /[a-z]/.test(p),
        num:     p => /[0-9]/.test(p),
        special: p => /[^A-Za-z0-9]/.test(p),
    };
    input.addEventListener('input', () => {
        const p = input.value;
        let met = 0;
        for (const [key, test] of Object.entries(rules)) {
            const pill = reqs.querySelector('[data-req="' + key + '"]');
            if (!pill) continue;
            const ok = test(p);
            pill.classList.toggle('met', ok);
            if (ok) met++;
        }
        const level = met <= 2 ? 'active-weak' : met <= 3 ? 'active-medium' : 'active-strong';
        segs.forEach((seg, i) => {
            seg.className = 'cw-strength-seg';
            if (i < met) seg.classList.add(level);
        });
        if (count) {
            const n = p.length;
            count.textContent = n + ' / 128';
            count.style.color = n > 128 ? 'var(--red)' : (n >= 8 ? 'var(--green)' : 'var(--text-2)');
        }
        checkMatch();
    });
})();

const newPwEl  = document.getElementById('new_password');
const confPwEl = document.getElementById('confirm_password');
const matchEl  = document.getElementById('matchStatus');
function checkMatch() {
    if (!matchEl) return;
    const a = newPwEl.value;
    const b = confPwEl.value;
    if (!b) { matchEl.className = 'cw-hint'; matchEl.textContent = ''; return; }
    if (a === b) {
        matchEl.className = 'cw-hint is-valid';
        matchEl.innerHTML = '<i class="bi bi-check-circle-fill"></i>Passwords match.';
    } else {
        matchEl.className = 'cw-hint is-invalid';
        matchEl.innerHTML = '<i class="bi bi-x-circle-fill"></i>Passwords do not match.';
    }
}
if (confPwEl) confPwEl.addEventListener('input', checkMatch);

const unEl     = document.getElementById('username');
const unStatus = document.getElementById('usernameStatus');
let unTimer = null;
let unLastChecked = '';

if (unEl && unStatus) {
    unEl.addEventListener('input', () => {
        clearTimeout(unTimer);
        const v = unEl.value.trim();
        if (!v) { unStatus.className = 'cw-hint'; unStatus.textContent = ''; unLastChecked = ''; return; }

        unTimer = setTimeout(async () => {
            if (v === unLastChecked) return;
            unLastChecked = v;
            unStatus.className = 'cw-hint';
            unStatus.innerHTML = '<i class="bi bi-hourglass-split"></i>Checking…';

            try {
                const fd = new FormData();
                fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
                fd.append('username', v);
                const r = await fetch('?action=check_username', { method: 'POST', body: fd });
                const j = await r.json();

                if (unEl.value.trim() !== v) return;

                if (j.exists) {
                    unStatus.className = 'cw-hint is-valid';
                    unStatus.innerHTML = '<i class="bi bi-check-circle-fill"></i>User found.';
                } else {
                    unStatus.className = 'cw-hint is-invalid';
                    unStatus.innerHTML = '<i class="bi bi-x-circle-fill"></i>No account with that username.';
                }
            } catch (e) {
                unStatus.className = 'cw-hint';
                unStatus.textContent = '';
            }
        }, 350);
    });
}

(function () {
    const form = document.getElementById('passwordForm');
    const btn  = document.getElementById('cwSubmit');
    if (!form || !btn) return;
    const btnText = btn.querySelector('span');
    const btnIcon = btn.querySelector('i');

    form.addEventListener('submit', function (e) {
        const np = newPwEl.value;
        const cp = confPwEl.value;
        const ap = document.getElementById('admin_password').value;
        const un = unEl.value.trim();

        if (!un || !np || !cp || !ap) {
            e.preventDefault();
            alert('Please fill in all fields.');
            return;
        }
        if (np !== cp) {
            e.preventDefault();
            alert('New passwords do not match.');
            return;
        }
        if (np.length < 8) {
            e.preventDefault();
            alert('Password must be at least 8 characters.');
            return;
        }
        if (!/[A-Z]/.test(np) || !/[a-z]/.test(np) || !/[0-9]/.test(np) || !/[^A-Za-z0-9]/.test(np)) {
            e.preventDefault();
            alert('Password must contain uppercase, lowercase, number, and special character.');
            return;
        }

        btn.disabled = true;
        if (btnIcon) btnIcon.className = 'spinner-border spinner-border-sm';
        if (btnText) btnText.textContent = 'Updating...';
    });
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>