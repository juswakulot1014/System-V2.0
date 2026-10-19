<?php
// shared/pages/create_account.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';

$__nav_section = 'system';

if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    $_SESSION['error'] = "Access Denied! Only the Principal can manage accounts.";
    header('Location: ' . URL_PREFIX . '/dashboard.php');
    exit();
}

/* ═══ ADD ACCOUNT ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_account'])) {
    verify_csrf();

    $fullname = trim((string)($_POST['fullname'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role     = trim((string)($_POST['role'] ?? ''));

    if ($fullname === '' || $username === '' || $password === '' || $role === '') {
        $_SESSION['error'] = "All fields are required!";
    } elseif (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $username)) {
        $_SESSION['error'] = "Username must be 3–50 characters and contain only letters, numbers, dots, dashes, or underscores.";
    } elseif (!in_array($role, ['registrar', 'superadmin'], true)) {
        $_SESSION['error'] = "Invalid role selected.";
    } elseif (($pw_err = validate_strong_password($password)) !== null) {
        $_SESSION['error'] = $pw_err;
    } else {
        $check = $conn->prepare("SELECT id FROM admins WHERE username = ? LIMIT 1");
        $check->bind_param("s", $username);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if ($exists) {
            $_SESSION['error'] = "Username already exists!";
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $stmt = $conn->prepare("INSERT INTO admins (fullname, username, password, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $fullname, $username, $hashed, $role);

            if ($stmt->execute()) {
                $new_id = (int)$conn->insert_id;
                $stmt->close();

                audit_log($conn, 'admin.create', [
                    'type'     => 'admin',
                    'id'       => $new_id,
                    'username' => $username,
                    'role'     => $role,
                ]);

                $_SESSION['success'] = "Account created for " . htmlspecialchars($fullname) . "!";
            } else {
                $stmt->close();
                $_SESSION['error'] = "Failed to create account.";
            }
        }
    }
    header('Location: ' . URL_PREFIX . '/create_account.php');
    exit();
}

/* ═══ DELETE ACCOUNT ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    verify_csrf();

    $account_id = (int)($_POST['account_id'] ?? 0);

    if ($account_id <= 0) {
        $_SESSION['error'] = "Invalid account ID.";
        header('Location: ' . URL_PREFIX . '/create_account.php');
        exit();
    }

    if ($account_id === (int)($_SESSION['admin_id'] ?? 0)) {
        $_SESSION['error'] = "You cannot delete your own account!";
    } else {
        $count_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM admins WHERE role = ?");
        $sa_role    = 'superadmin';
        $count_stmt->bind_param("s", $sa_role);
        $count_stmt->execute();
        $count_sa = (int)($count_stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $count_stmt->close();

        $role_stmt = $conn->prepare("SELECT fullname, username, role FROM admins WHERE id = ? LIMIT 1");
        $role_stmt->bind_param("i", $account_id);
        $role_stmt->execute();
        $target = $role_stmt->get_result()->fetch_assoc();
        $role_stmt->close();

        if (!$target) {
            $_SESSION['error'] = "Account not found.";
        } elseif ($target['role'] === 'superadmin' && $count_sa <= 1) {
            $_SESSION['error'] = "Cannot delete the last Super Admin account!";
        } else {
            $del = $conn->prepare("DELETE FROM admins WHERE id = ?");
            $del->bind_param("i", $account_id);
            $ok = $del->execute();
            $del->close();

            if ($ok) {
                audit_log($conn, 'admin.delete', [
                    'type'     => 'admin',
                    'id'       => $account_id,
                    'username' => $target['username'],
                    'role'     => $target['role'],
                ]);
                $_SESSION['success'] = "Account deleted!";
            } else {
                $_SESSION['error'] = "Failed to delete.";
            }
        }
    }
    header('Location: ' . URL_PREFIX . '/create_account.php');
    exit();
}

/* ═══ FETCH ACCOUNTS ═══ */
$accounts = $conn->query("SELECT id, fullname, username, role, created_at FROM admins ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

$total_accounts   = count($accounts);
$superadmin_count = 0;
$registrar_count  = 0;
foreach ($accounts as $a) {
    if (($a['role'] ?? '') === 'superadmin') $superadmin_count++;
    else                                     $registrar_count++;
}

$page_title = 'Accounts • USAT Admin';

$page_css = <<<'CSS'
    .table-admin tbody tr.is-self td{background:color-mix(in srgb, var(--amber) 5%, transparent)}
    .table-admin tbody tr.is-self:hover td{background:color-mix(in srgb, var(--amber) 8%, transparent)}

    .account-cell{display:flex;align-items:center;gap:.75rem;min-width:0}
    .account-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0;box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 15%, transparent)}
    .account-avatar.super{background:linear-gradient(135deg,#dc2626,#b91c1c);box-shadow:0 0 0 3px rgba(220,38,38,.15)}
    .account-info{min-width:0}
    .account-info .nm{font-weight:600;font-size:.88rem;line-height:1.2;color:var(--text)}
    .account-info .un{font-size:.74rem;color:var(--text-2);font-variant-numeric:tabular-nums}

    .form-control,.form-select{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:.5rem .8rem;font-size:.85rem;color:var(--text);transition:border-color .15s, box-shadow .15s}
    .form-control:focus,.form-select:focus{border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);outline:none}
    .form-text{font-size:.72rem;color:var(--text-3)}

    .modal-content{border-radius:var(--radius-lg);border:none;box-shadow:var(--shadow-lg)}
    .modal-header{padding:1rem 1.25rem;border-bottom:1px solid var(--border);border-top-left-radius:var(--radius-lg);border-top-right-radius:var(--radius-lg)}
    .modal-header.bg-danger{background:var(--red) !important}
    .modal-header .modal-title{font-size:.92rem}
    .modal-body{padding:1.25rem}
    .modal-footer{padding:.85rem 1.25rem;border-top:1px solid var(--border)}
    .form-label{font-size:.78rem;font-weight:600;color:var(--text-2);margin-bottom:.35rem;display:block}
    .input-group-icon{position:relative}
    .input-group-icon .bi{position:absolute;right:.8rem;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:.9rem}
    .input-group-icon .bi[style*="pointer-events"]{pointer-events:auto !important;cursor:pointer}

    .btn-xs{padding:.25rem .6rem;font-size:.72rem;border-radius:8px}
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

<div class="main-content" id="mainContent">

    <?php if (isset($_SESSION['success'])): ?>
        <div class="flash success">
            <i class="bi bi-check-circle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['success']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="flash error">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['error']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <h2>Admin Accounts</h2>
                <p class="sub">Manage system administrators</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="<?= URL_PREFIX ?>/storage_health.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-hdd-stack me-1"></i> Storage Health
            </a>
            <a href="<?= URL_PREFIX ?>/change_user_password.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-key me-1"></i> Change Password
            </a>
            <span class="pill danger" style="text-transform:none;letter-spacing:0;">
                <i class="bi bi-shield-lock"></i> Restricted Area
            </span>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= (int)$total_accounts ?></div>
                <div class="stat-label">Total Accounts</div>
                <div class="stat-hint">All admin users</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-shield-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= (int)$superadmin_count ?></div>
                <div class="stat-label">Super Admins</div>
                <div class="stat-hint">Full access</div>
            </div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-person-check-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= (int)$registrar_count ?></div>
                <div class="stat-label">Registrars</div>
                <div class="stat-hint">Enrollment staff</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>
                <i class="bi bi-people"></i> All Admin Accounts
                <span class="hint">(<?= (int)$total_accounts ?>)</span>
            </h3>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                <i class="bi bi-plus-lg me-1"></i> Add Account
            </button>
        </div>
        <div class="card-body no-padding">
            <?php if(!empty($accounts)): ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th style="width:70px">ID</th>
                                <th>Account</th>
                                <th>Role</th>
                                <th style="width:150px">Created</th>
                                <th style="width:120px;text-align:center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($accounts as $row):
                            $is_self  = ((int)$row['id'] === (int)($_SESSION['admin_id'] ?? 0));
                            $is_super = ($row['role'] === 'superadmin');
                            $parts    = preg_split('/\s+/', trim((string)$row['fullname']));
                            $initials = strtoupper(substr($parts[0] ?? '?', 0, 1) . substr(end($parts) ?: '', 0, 1));
                        ?>
                            <tr class="<?= $is_self ? 'is-self' : '' ?>">
                                <td class="cell-num">#<?= (int)$row['id'] ?></td>
                                <td>
                                    <div class="account-cell">
                                        <div class="account-avatar <?= $is_super ? 'super' : '' ?>"><?= htmlspecialchars($initials) ?></div>
                                        <div class="account-info">
                                            <div class="nm">
                                                <?= htmlspecialchars($row['fullname']) ?>
                                                <?php if($is_self): ?>
                                                    <span class="pill warning" style="font-size:.62rem;padding:.1rem .4rem">you</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="un">@<?= htmlspecialchars($row['username']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="pill <?= $is_super ? 'danger' : 'info' ?>" style="text-transform:none;letter-spacing:0;">
                                        <i class="bi bi-<?= $is_super ? 'shield-fill' : 'person-check' ?>"></i>
                                        <?= htmlspecialchars(ucfirst($row['role'])) ?>
                                    </span>
                                </td>
                                <td class="cell-muted"><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                                <td style="text-align:center">
                                    <?php if(!$is_self): ?>
                                        <button type="button"
                                                class="btn btn-outline-danger btn-xs delete-btn"
                                                data-id="<?= (int)$row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['fullname'], ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-trash3 me-1"></i> Delete
                                        </button>
                                    <?php else: ?>
                                        <span class="pill muted">Current</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-people"></i></div>
                    <div class="empty-title">No accounts found</div>
                    <div class="empty-sub">Create the first admin account to get started.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" autocomplete="off">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-person-plus me-2"></i>Create New Account</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="fullname" class="form-control" placeholder="e.g. Juan Dela Cruz" required maxlength="100" autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" placeholder="e.g. juan_admin" required maxlength="50" pattern="[A-Za-z0-9_.\-]{3,50}" autocomplete="off">
                        <div class="form-text mt-1">3–50 characters. Letters, numbers, dots, dashes, underscores only.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <div class="input-group-icon">
                            <input type="password" name="password" id="newAccountPassword" class="form-control" placeholder="Min. 10 characters" minlength="10" maxlength="128" required autocomplete="new-password">
                            <i class="bi bi-eye" id="togglePassword" style="pointer-events:auto;cursor:pointer" title="Show password"></i>
                        </div>
                        <div class="form-text mt-1">At least 10 characters with uppercase, lowercase, number, and special character.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" required>
                            <option value="registrar">Registrar</option>
                            <option value="superadmin">Super Admin</option>
                        </select>
                        <div class="form-text mt-1">Super Admin has full access including account management.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_account" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2 me-1"></i> Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Delete Account</h6>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Delete <strong id="deleteAccountName"></strong>? This cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="delete_account" value="1">
                    <input type="hidden" name="account_id" id="deleteAccountId">
                    <button class="btn btn-danger btn-sm"><i class="bi bi-trash3 me-1"></i> Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));

document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        document.getElementById('deleteAccountId').value = this.dataset.id;
        document.getElementById('deleteAccountName').textContent = this.dataset.name;
        deleteModal.show();
    });
});

const pw = document.getElementById('newAccountPassword');
const tp = document.getElementById('togglePassword');
if (pw && tp) {
    tp.addEventListener('click', () => {
        const show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        tp.className = 'bi bi-' + (show ? 'eye-slash' : 'eye');
    });
}

const addModal = document.getElementById('addAccountModal');
if (addModal) {
    addModal.addEventListener('hidden.bs.modal', function () {
        const form = this.querySelector('form');
        if (form) form.reset();
        if (pw) pw.type = 'password';
        if (tp) tp.className = 'bi bi-eye';
    });
}
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>