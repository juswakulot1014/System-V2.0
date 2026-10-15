<?php
declare(strict_types=1);

/**
 * profile.php — Self-service profile + password change for any logged-in admin.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/security.php';

$error   = '';
$success = '';

$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if ($admin_id <= 0) {
    header('Location: admin_login.php');
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   Admin photo directory + column auto-migration
   ═══════════════════════════════════════════════════════════════ */
if (!defined('ADMINS_PHOTO_DIR')) {
    if (defined('STUDENTS_PHOTO_DIR')) {
        define('ADMINS_PHOTO_DIR', rtrim(dirname(rtrim(STUDENTS_PHOTO_DIR, '/\\')), '/\\') . '/admin_photos/');
    } else {
        define('ADMINS_PHOTO_DIR', dirname(__DIR__) . '/uploads/admin_photos/');
    }
}
if (!is_dir(ADMINS_PHOTO_DIR)) {
    @mkdir(ADMINS_PHOTO_DIR, 0750, true);
}

try {
    $col_check = $conn->query("SHOW COLUMNS FROM admins LIKE 'photo'");
    if ($col_check && $col_check->num_rows === 0) {
        @$conn->query("ALTER TABLE admins ADD COLUMN photo VARCHAR(255) NULL DEFAULT NULL AFTER username");
    }
} catch (Throwable $e) {
    error_log('profile.php: auto-migrate admins.photo failed: ' . $e->getMessage());
}

/* ═══════════════════════════════════════════════════════════════
   Serve the current admin's photo inline  (profile.php?photo=1)
   ═══════════════════════════════════════════════════════════════ */
if (isset($_GET['photo']) && $_GET['photo'] === '1') {
    $stmt = $conn->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
    if (!$stmt) { http_response_code(500); exit('Server error'); }

    $stmt->bind_param('i', $admin_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['photo'])) {
        http_response_code(404);
        exit('No photo');
    }

    try {
        $abs = safe_path(ADMINS_PHOTO_DIR, (string)$row['photo']);
    } catch (RuntimeException $e) {
        security_log('profile.photo_path_escape', [
            'admin_id'  => $admin_id,
            'candidate' => (string)$row['photo'],
            'ip'        => client_ip(),
        ]);
        http_response_code(404);
        exit('Photo missing');
    }

    if (!is_file($abs) || !is_readable($abs)) {
        http_response_code(404);
        exit('Photo missing');
    }

    $fi   = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $fi ? (string)finfo_file($fi, $abs) : 'application/octet-stream';
    if ($fi) finfo_close($fi);

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        $mime = 'application/octet-stream';
    }

    while (ob_get_level()) ob_end_clean();

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox;");

    readfile($abs);
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   Rate limiting — 3 failed password attempts / 15 min
   ═══════════════════════════════════════════════════════════════ */
$max_attempts   = 3;
$lockout_time   = 900;
$rate_limit_key = 'pwd_self_attempts_' . $admin_id;

if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
}
if ($_SESSION[$rate_limit_key]['count'] >= $max_attempts) {
    $since = time() - (int)$_SESSION[$rate_limit_key]['last_attempt'];
    if ($since >= $lockout_time) {
        $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
    }
}

/* ═══════════════════════════════════════════════════════════════
   POST handling
   ═══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    /* ── Upload profile photo ─────────────────────────────────── */
    if (isset($_POST['upload_photo'])) {
        if (empty($_FILES['admin_photo']) || $_FILES['admin_photo']['error'] !== UPLOAD_ERR_OK) {
            $error = 'No file uploaded.';
        } else {
            try {
                $info = validate_upload($_FILES['admin_photo']['tmp_name'], $_FILES['admin_photo']['name'], [
                    'max_bytes'   => 2 * 1024 * 1024,
                    'allowed'     => ['image/jpeg', 'image/png'],
                    'image_min_w' => 100,
                    'image_min_h' => 100,
                ]);

                $oq = $conn->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
                $oq->bind_param('i', $admin_id);
                $oq->execute();
                $old = $oq->get_result()->fetch_assoc();
                $oq->close();

                if (!empty($old['photo'])) {
                    try {
                        $oabs = safe_path(ADMINS_PHOTO_DIR, (string)$old['photo']);
                        if (is_file($oabs)) @unlink($oabs);
                    } catch (RuntimeException $e) {
                        security_log('profile.old_photo_containment', [
                            'candidate' => (string)$old['photo'],
                            'admin_id'  => $admin_id,
                        ]);
                    }
                }

                $random_abs = move_upload_securely($_FILES['admin_photo']['tmp_name'], ADMINS_PHOTO_DIR, $info['ext']);
                $fn         = 'admin_' . $admin_id . '_' . bin2hex(random_bytes(8)) . '.' . $info['ext'];
                $final      = rtrim(ADMINS_PHOTO_DIR, '/\\') . DIRECTORY_SEPARATOR . $fn;

                if (!@rename($random_abs, $final)) {
                    @unlink($random_abs);
                    throw new RuntimeException('Could not finalize photo.');
                }
                @chmod($final, 0640);

                $upd = $conn->prepare("UPDATE admins SET photo = ? WHERE id = ?");
                $upd->bind_param('si', $fn, $admin_id);
                $upd->execute();
                $upd->close();

                audit_log($conn, 'admin.photo_update', [
                    'type'     => 'admin',
                    'id'       => $admin_id,
                    'filename' => $fn,
                ]);

                $success = 'Profile photo updated.';
            } catch (RuntimeException $e) {
                security_log('profile.photo_rejected', [
                    'admin_id' => $admin_id,
                    'reason'   => $e->getMessage(),
                    'ip'       => client_ip(),
                ]);
                $error = $e->getMessage();
            }
        }
    }

    /* ── Delete profile photo ─────────────────────────────────── */
    elseif (isset($_POST['delete_photo'])) {
        $q = $conn->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
        $q->bind_param('i', $admin_id);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();

        $old_photo = $row['photo'] ?? null;

        $u = $conn->prepare("UPDATE admins SET photo = NULL WHERE id = ?");
        $u->bind_param('i', $admin_id);
        $u->execute();
        $u->close();

        if (!empty($old_photo)) {
            try {
                $abs = safe_path(ADMINS_PHOTO_DIR, (string)$old_photo);
                if (is_file($abs)) @unlink($abs);
            } catch (RuntimeException $e) {
                security_log('profile.photo_delete_containment', [
                    'candidate' => (string)$old_photo,
                    'admin_id'  => $admin_id,
                ]);
            }
        }

        audit_log($conn, 'admin.photo_delete', [
            'type'     => 'admin',
            'id'       => $admin_id,
            'filename' => $old_photo,
        ]);

        $success = 'Profile photo removed.';
    }

    /* ── Change password ──────────────────────────────────────── */
    else {
        $is_locked = (
            $_SESSION[$rate_limit_key]['count'] >= $max_attempts &&
            (time() - (int)$_SESSION[$rate_limit_key]['last_attempt']) < $lockout_time
        );

        if ($is_locked) {
            $since     = time() - (int)$_SESSION[$rate_limit_key]['last_attempt'];
            $remaining = (int)ceil(($lockout_time - $since) / 60);
            $error     = "Too many attempts. Please try again in {$remaining} minute(s).";
        }

        $current_password = (string)($_POST['current_password'] ?? '');

        /* Step 1: verify current password */
        if ($error === '') {
            if ($current_password === '') {
                $error = 'Please enter your current password.';
            } else {
                $stmt = $conn->prepare("SELECT password FROM admins WHERE id = ? LIMIT 1");
                if (!$stmt) {
                    $error = 'System error. Please try again later.';
                } else {
                    $stmt->bind_param('i', $admin_id);
                    $stmt->execute();
                    $res = $stmt->get_result();

                    if ($res->num_rows !== 1) {
                        $error = 'Your session is no longer valid. Please log in again.';
                    } else {
                        $row = $res->fetch_assoc();
                        if (!password_verify($current_password, $row['password'])) {
                            $error = 'Your current password is incorrect.';
                            $_SESSION[$rate_limit_key]['count']++;
                            $_SESSION[$rate_limit_key]['last_attempt'] = time();

                            audit_log($conn, 'password.self_change_denied', [
                                'type' => 'admin',
                                'id'   => $admin_id,
                            ]);
                            security_log('password.self_change_denied', [
                                'admin_id' => $admin_id,
                                'ip'       => client_ip(),
                            ]);
                        }
                    }
                    $stmt->close();
                }
            }
        }

        /* Step 2: validate new password */
        $new_password     = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        if ($error === '') {
            if ($new_password === '' || $confirm_password === '') {
                $error = 'Please fill in all fields.';
            } elseif ($new_password !== $confirm_password) {
                $error = 'New passwords do not match.';
            } elseif ($new_password === $current_password) {
                $error = 'Your new password must be different from your current password.';
            } elseif (($pw_err = validate_strong_password($new_password, 8)) !== null) {
                $error = $pw_err;
            }
        }

        /* Step 3: update */
        if ($error === '') {
            $logout_others = !empty($_POST['logout_others']);
            $hashed = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);

            if ($logout_others) {
                $sql = "UPDATE admins SET password = ?, remember_token = NULL, remember_expires = NULL WHERE id = ?";
            } else {
                $sql = "UPDATE admins SET password = ? WHERE id = ?";
            }

            $upd = $conn->prepare($sql);
            if (!$upd) {
                error_log('Self password change prepare failed: ' . $conn->error);
                $error = 'System error. Please try again later.';
            } else {
                $upd->bind_param('si', $hashed, $admin_id);
                if (!$upd->execute()) {
                    error_log('Self password change execute failed: ' . $upd->error);
                    $error = 'System error. Please try again later.';
                } else {
                    $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
                    $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

                    audit_log($conn, 'password.self_change', [
                        'type'          => 'admin',
                        'id'            => $admin_id,
                        'logout_others' => $logout_others,
                    ]);

                    $success = 'Your password has been updated'
                             . ($logout_others ? ' and other devices were signed out.' : '.');

                    unset($_POST['current_password'], $_POST['new_password'], $_POST['confirm_password']);
                }
                $upd->close();
            }
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   View data
   ═══════════════════════════════════════════════════════════════ */
$csrf_token    = csrf_token();
$attempts_used = (int)$_SESSION[$rate_limit_key]['count'];
$attempts_left = max(0, $max_attempts - $attempts_used);
$locked_until  = 0;
if ($attempts_used >= $max_attempts) {
    $since = time() - (int)$_SESSION[$rate_limit_key]['last_attempt'];
    if ($since < $lockout_time) $locked_until = (int)ceil(($lockout_time - $since) / 60);
}

$admin_info = [
    'fullname'   => 'Administrator',
    'username'   => '',
    'role'       => '',
    'photo'      => null,
    'created_at' => null,
];

$ai = $conn->prepare("SELECT fullname, username, role, photo, created_at FROM admins WHERE id = ? LIMIT 1");
if ($ai) {
    $ai->bind_param('i', $admin_id);
    $ai->execute();
    $ai_res = $ai->get_result();
    if ($ai_res->num_rows === 1) {
        $admin_info = array_merge($admin_info, $ai_res->fetch_assoc());
    }
    $ai->close();
}

/* Initials from fullname */
$initials = '';
foreach (preg_split('/\s+/', trim((string)$admin_info['fullname'])) as $part) {
    if ($part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    if (mb_strlen($initials) >= 2) break;
}
$initials = $initials ?: 'A';

/* Photo URL with cache-buster */
$admin_photo_abs = !empty($admin_info['photo'])
    ? rtrim(ADMINS_PHOTO_DIR, '/\\') . DIRECTORY_SEPARATOR . basename((string)$admin_info['photo'])
    : '';
$admin_photo_url = ($admin_photo_abs !== '' && is_file($admin_photo_abs))
    ? 'profile.php?photo=1&v=' . filemtime($admin_photo_abs)
    : '';

/* Member since */
$member_since = !empty($admin_info['created_at'])
    ? date('F d, Y', strtotime((string)$admin_info['created_at']))
    : '—';

/* Days since account creation */
$days_member = 0;
if (!empty($admin_info['created_at'])) {
    try {
        $created = new DateTime((string)$admin_info['created_at']);
        $now     = new DateTime();
        $days_member = (int)$created->diff($now)->days;
    } catch (Throwable $e) {
        $days_member = 0;
    }
}

$theme = ($_COOKIE['admin_theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile • USAT Admin</title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root{
            --bg:#f4f6fb;--surface:#fff;--surface-2:#f8fafc;--text:#0f172a;
            --text-2:#64748b;--text-3:#94a3b8;--border:#e5e7eb;--border-strong:#d1d5db;
            --accent:#4f46e5;--accent-2:#6366f1;--accent-soft:#eef2ff;
            --green:#059669;--green-soft:#d1fae5;--red:#dc2626;--red-soft:#fee2e2;
            --amber:#d97706;--amber-soft:#fef3c7;--purple:#7c3aed;--purple-soft:#ede9fe;
            --shadow-xs:0 1px 2px rgba(15,23,42,0.04);
            --shadow-sm:0 1px 3px rgba(15,23,42,.06),0 1px 2px rgba(15,23,42,.04);
            --shadow-md:0 4px 12px rgba(15,23,42,.08);
            --shadow-lg:0 12px 32px rgba(15,23,42,.10);
            --radius:12px;--radius-lg:16px;--radius-xl:20px;--sidebar-w:264px;
        }
        [data-bs-theme="dark"]{
            --bg:#0b1220;--surface:#131c2e;--surface-2:#1a2439;--text:#f1f5f9;
            --text-2:#94a3b8;--text-3:#64748b;--border:#1f2a44;--border-strong:#2d3b57;
            --accent:#818cf8;--accent-2:#6366f1;--accent-soft:#1e2140;
            --green-soft:#052e24;--red-soft:#3f1517;--amber-soft:#3d2a08;--purple-soft:#2e1a54;
            --shadow-xs:0 1px 2px rgba(0,0,0,.3);
            --shadow-sm:0 1px 3px rgba(0,0,0,.35);
            --shadow-md:0 4px 12px rgba(0,0,0,.4);
            --shadow-lg:0 12px 32px rgba(0,0,0,.55);
        }
        *{margin:0;padding:0;box-sizing:border-box}
        html,body{height:100%}
        body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}

        /* ═══════════ Sidebar ═══════════ */
        .sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:transform .28s cubic-bezier(.4,0,.2,1)}
        .sidebar-brand{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem}
        .sidebar-brand img{width:38px;height:38px;border-radius:10px;object-fit:cover;box-shadow:0 0 0 3px var(--accent-soft)}
        .sidebar-brand .brand-text{display:flex;flex-direction:column;line-height:1.15}
        .sidebar-brand .brand-name{font-weight:700;font-size:.95rem}
        .sidebar-brand .brand-sub{font-size:.68rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;font-weight:600}
        .sidebar-nav{flex:1;padding:.75rem .75rem 1rem;overflow-y:auto}
        .sidebar-nav .nav-label{font-size:.65rem;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.8px;padding:.75rem .75rem .35rem}
        .sidebar-nav a{display:flex;align-items:center;gap:.75rem;padding:.6rem .75rem;border-radius:10px;color:var(--text-2);text-decoration:none;font-weight:500;font-size:.875rem;transition:background .15s,color .15s;margin-bottom:.1rem}
        .sidebar-nav a:hover{background:var(--surface-2);color:var(--text)}
        .sidebar-nav a.active{background:var(--accent);color:#fff;box-shadow:0 4px 12px -4px rgba(79,70,229,.5)}
        .sidebar-nav a i{font-size:1.05rem;width:20px;text-align:center;flex-shrink:0}
        .sidebar-footer{padding:.75rem;border-top:1px solid var(--border)}

        /* ═══════════ Main content ═══════════ */
        .main-content{margin-left:var(--sidebar-w);padding:1.5rem clamp(1rem,2.5vw,2rem) 2rem;min-height:100vh;width:calc(100% - var(--sidebar-w));max-width:none}

        .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap}
        .topbar h2{font-size:1.5rem;font-weight:700;letter-spacing:-.02em;margin:0}
        .topbar .sub{font-size:.88rem;color:var(--text-2);margin:.15rem 0 0}
        .menu-toggle{display:none;width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;align-items:center;justify-content:center}
        .breadcrumb-nav{display:flex;align-items:center;gap:.4rem;font-size:.78rem;color:var(--text-2);margin-bottom:.25rem}
        .breadcrumb-nav a{color:var(--accent);text-decoration:none;font-weight:500}
        .theme-btn{width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text-2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
        .theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

        .btn{font-weight:500;border-radius:10px;font-size:.85rem;transition:all .15s}
        .btn-outline-secondary{color:var(--text-2);border-color:var(--border);padding:.5rem 1rem}
        .btn-outline-secondary:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-strong)}

        /* ═══════════ Alerts ═══════════ */
        .cw-alert{display:flex;align-items:flex-start;gap:.7rem;padding:.9rem 1.15rem;border-radius:12px;font-size:.9rem;line-height:1.5;margin-bottom:1.25rem;border-left:4px solid;animation:cw-fade .25s ease}
        .cw-alert i{margin-top:2px;flex-shrink:0;font-size:1.1rem}
        .cw-alert-error{background:var(--red-soft);color:var(--red);border-color:var(--red)}
        .cw-alert-success{background:var(--green-soft);color:var(--green);border-color:var(--green)}
        @keyframes cw-fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

        .cw-attempts{display:flex;align-items:center;gap:.6rem;padding:.75rem 1rem;border-radius:12px;background:var(--amber-soft);color:var(--amber);font-size:.85rem;font-weight:600;margin-bottom:1.25rem;border-left:4px solid var(--amber)}

        /* ═══════════ Layout grid ═══════════ */
        .profile-grid{
            display:grid;
            grid-template-columns:minmax(0, 1fr) minmax(0, 1.15fr);
            gap:1.5rem;
            align-items:start;
        }
        @media (max-width:1200px){
            .profile-grid{grid-template-columns:1fr;}
        }

        /* ═══════════ Profile hero card ═══════════ */
        .pf-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);overflow:hidden}

        .pf-hero{
            padding:2.25rem 2rem 1.75rem;
            background:linear-gradient(135deg,#4f46e5 0%,#6366f1 45%,#7c3aed 100%);
            color:#fff;
            position:relative;
            overflow:hidden;
            text-align:center;
        }
        .pf-hero::before{
            content:'';position:absolute;inset:0;
            background:
                radial-gradient(circle at 15% 15%, rgba(255,255,255,.18) 0%, transparent 45%),
                radial-gradient(circle at 85% 85%, rgba(212,175,55,.18) 0%, transparent 50%),
                radial-gradient(circle at 50% 100%, rgba(255,255,255,.1) 0%, transparent 60%);
            pointer-events:none;
        }
        .pf-hero::after{
            content:'';position:absolute;right:-60px;top:-60px;
            width:240px;height:240px;border-radius:50%;
            background:rgba(255,255,255,.06);
        }

        .pf-photo-wrap{
            position:relative;display:inline-block;
            margin-bottom:1rem;
            z-index:2;
        }
        .pf-photo{
            width:132px;height:132px;border-radius:50%;object-fit:cover;
            border:5px solid rgba(255,255,255,.35);
            box-shadow:0 16px 40px rgba(0,0,0,.30);
            display:block;background:#fff;
            transition:transform .25s ease;
        }
        .pf-photo:hover{transform:scale(1.03)}
        .pf-photo-fallback{
            width:132px;height:132px;border-radius:50%;
            background:linear-gradient(135deg, rgba(255,255,255,.28), rgba(255,255,255,.15));
            border:5px solid rgba(255,255,255,.35);
            display:flex;align-items:center;justify-content:center;
            font-size:3rem;font-weight:800;letter-spacing:-.04em;
            color:#fff;box-shadow:0 16px 40px rgba(0,0,0,.30);
            backdrop-filter:blur(8px);
            transition:transform .25s ease;
        }
        .pf-photo-fallback:hover{transform:scale(1.03)}

        .pf-photo-btn{
            position:absolute;width:38px;height:38px;border-radius:50%;
            background:rgba(255,255,255,.97);color:var(--accent);
            border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;
            font-size:.95rem;transition:all .2s ease;z-index:3;
            box-shadow:0 6px 18px rgba(0,0,0,.20);
        }
        .pf-photo-btn:hover{background:#fff;transform:scale(1.12)}
        .pf-btn-upload{bottom:.35rem;right:.35rem}
        .pf-btn-delete{bottom:.35rem;left:.35rem;color:var(--red)}
        .pf-btn-delete:hover{background:#fff;color:var(--red)}

        .pf-hero-info{position:relative;z-index:2}
        .pf-hero-info h1{
            font-size:1.65rem;font-weight:700;
            letter-spacing:-.025em;margin:0 0 .55rem;
            word-break:break-word;color:#fff;
            display:flex;align-items:center;justify-content:center;gap:.6rem;flex-wrap:wrap;
        }
        .pf-role-badge{
            display:inline-flex;align-items:center;gap:.35rem;
            padding:.3rem .8rem;border-radius:999px;
            background:rgba(255,255,255,.22);font-size:.7rem;font-weight:700;
            letter-spacing:.6px;text-transform:uppercase;
            backdrop-filter:blur(6px);
            border:1px solid rgba(255,255,255,.2);
        }
        .pf-hero-meta{
            display:flex;gap:1rem;flex-wrap:wrap;justify-content:center;
            font-size:.85rem;opacity:.95;font-weight:500;
        }
        .pf-hero-meta span{display:inline-flex;align-items:center;gap:.35rem}
        .pf-hero-meta i{opacity:.85;font-size:.9rem}

        /* ═══════════ Stat strip ═══════════ */
        .pf-stats{
            display:grid;grid-template-columns:repeat(3,1fr);
            background:var(--surface);
            border-bottom:1px solid var(--border);
        }
        .pf-stat{
            padding:1.1rem 1rem;text-align:center;
            border-right:1px solid var(--border);
            transition:background .15s;
        }
        .pf-stat:last-child{border-right:none}
        .pf-stat:hover{background:var(--surface-2)}
        .pf-stat-val{
            font-size:1.35rem;font-weight:800;color:var(--text);
            letter-spacing:-.02em;line-height:1.1;
            font-variant-numeric:tabular-nums;
        }
        .pf-stat-lbl{
            font-size:.68rem;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.6px;
            margin-top:.3rem;font-weight:600;
            display:flex;align-items:center;justify-content:center;gap:.3rem;
        }
        .pf-stat-lbl i{color:var(--accent);font-size:.75rem}

        /* ═══════════ Info grid ═══════════ */
        .pf-info-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:0;
        }
        @media (max-width:640px){
            .pf-info-grid{grid-template-columns:1fr}
        }
        .pf-info-cell{
            padding:1rem 1.5rem;
            border-right:1px solid var(--border);
            border-bottom:1px solid var(--border);
        }
        .pf-info-cell:nth-child(even){border-right:none}
        .pf-info-cell:nth-last-child(-n+2){border-bottom:none}
        .pf-info-cell .lbl{
            font-size:.66rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.65px;
            margin-bottom:.35rem;
            display:flex;align-items:center;gap:.4rem;
        }
        .pf-info-cell .lbl i{color:var(--accent);font-size:.8rem}
        .pf-info-cell .val{
            font-weight:600;font-size:.95rem;color:var(--text);
            word-break:break-word;line-height:1.4;
        }

        .pf-photo-hint{
            padding:.85rem 1.5rem;font-size:.78rem;color:var(--text-2);
            background:var(--surface-2);border-top:1px solid var(--border);
            display:flex;align-items:center;gap:.5rem;
        }
        .pf-photo-hint i{color:var(--accent);font-size:.9rem}

        /* ═══════════ Password card ═══════════ */
        .cw-card{
            background:var(--surface);border:1px solid var(--border);
            border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);
            overflow:hidden;
        }
        .cw-card-head{
            display:flex;align-items:center;gap:1rem;
            padding:1.35rem 1.75rem;
            border-bottom:1px solid var(--border);
            background:linear-gradient(180deg,var(--surface),var(--surface-2));
        }
        .cw-card-head-icon{
            width:48px;height:48px;border-radius:13px;
            background:linear-gradient(135deg,var(--accent),var(--accent-2));
            color:#fff;display:flex;align-items:center;justify-content:center;
            font-size:1.25rem;flex-shrink:0;
            box-shadow:0 6px 16px rgba(79,70,229,.35);
        }
        .cw-card-head h3{font-size:1.1rem;font-weight:700;margin:0 0 .2rem}
        .cw-card-head p{font-size:.85rem;color:var(--text-2);margin:0}
        .cw-card-body{padding:1.75rem}

        .cw-field{margin-bottom:1.25rem}
        .cw-field-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.45rem}
        .cw-field label{
            display:block;font-size:.72rem;font-weight:700;
            color:var(--text-2);text-transform:uppercase;
            letter-spacing:.6px;margin-bottom:.45rem;
        }
        .cw-field-head label{margin-bottom:0}
        .cw-charcount{font-size:.72rem;color:var(--text-2);font-variant-numeric:tabular-nums;font-weight:500}

        .cw-input-wrap{position:relative}
        .cw-input-wrap input{
            width:100%;padding:.85rem 3rem .85rem 2.85rem;
            border:1.5px solid var(--border);border-radius:12px;
            font-size:.95rem;background:var(--surface-2);
            color:var(--text);font-family:inherit;
            transition:border-color .15s,box-shadow .15s,background .15s;
        }
        .cw-input-wrap input:focus{
            outline:none;border-color:var(--accent);
            box-shadow:0 0 0 4px color-mix(in srgb,var(--accent) 15%,transparent);
            background:var(--surface);
        }
        .cw-input-icon{
            position:absolute;left:1.05rem;top:50%;
            transform:translateY(-50%);color:var(--text-3);
            font-size:1.05rem;pointer-events:none;transition:color .15s;
        }
        .cw-input-wrap input:focus ~ .cw-input-icon{color:var(--accent)}
        .cw-eye{
            position:absolute;right:.55rem;top:50%;
            transform:translateY(-50%);width:36px;height:36px;
            border:none;background:transparent;color:var(--text-2);
            cursor:pointer;border-radius:9px;
            display:flex;align-items:center;justify-content:center;
            transition:background .15s,color .15s;
        }
        .cw-eye:hover{background:var(--surface-2);color:var(--accent)}

        .cw-hint{margin:.5rem 0 0;font-size:.8rem;color:var(--text-2);line-height:1.45}
        .cw-hint.is-valid{color:var(--green);font-weight:600}
        .cw-hint.is-invalid{color:var(--red);font-weight:600}
        .cw-hint i{margin-right:.3rem}

        .cw-divider{display:flex;align-items:center;gap:.85rem;margin:1.65rem 0 1.25rem}
        .cw-divider::before,.cw-divider::after{content:'';flex:1;height:1px;background:var(--border)}
        .cw-divider span{
            font-size:.7rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.7px;
            white-space:nowrap;display:inline-flex;align-items:center;gap:.4rem;
        }
        .cw-divider span i{font-size:.8rem;color:var(--accent)}

        .cw-strength{display:grid;grid-template-columns:repeat(5,1fr);gap:5px;margin-top:.65rem}
        .cw-strength-seg{height:5px;border-radius:3px;background:var(--border);transition:background .2s}
        .cw-strength-seg.active-weak{background:var(--red)}
        .cw-strength-seg.active-medium{background:var(--amber)}
        .cw-strength-seg.active-strong{background:var(--green)}

        .cw-reqs{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.7rem}
        .cw-req{
            display:inline-flex;align-items:center;gap:.35rem;
            padding:.25rem .7rem;border-radius:20px;
            font-size:.7rem;font-weight:600;
            background:var(--surface-2);color:var(--text-2);
            border:1px solid var(--border);transition:all .15s;
        }
        .cw-req i{font-size:.6rem;color:var(--text-3);opacity:.7}
        .cw-req.met{
            background:var(--green-soft);color:var(--green);
            border-color:color-mix(in srgb,var(--green) 30%,transparent);
        }
        .cw-req.met i{color:var(--green);opacity:1}

        .cw-check{
            display:flex;align-items:flex-start;gap:.7rem;
            padding:.95rem 1.1rem;border:1.5px solid var(--border);
            border-radius:12px;background:var(--surface-2);
            cursor:pointer;transition:border-color .15s,background .15s;
        }
        .cw-check:hover{border-color:var(--border-strong)}
        .cw-check input[type="checkbox"]{
            margin-top:.15rem;width:18px;height:18px;
            accent-color:var(--accent);flex-shrink:0;cursor:pointer;
        }
        .cw-check-txt{display:flex;flex-direction:column;gap:.2rem}
        .cw-check-title{font-size:.88rem;font-weight:600;color:var(--text)}
        .cw-check-sub{font-size:.78rem;color:var(--text-2);line-height:1.45}

        .cw-submit{
            width:100%;padding:1rem 1.5rem;border:none;border-radius:12px;
            background:linear-gradient(135deg,var(--accent),var(--accent-2));
            color:#fff;font-size:1rem;font-weight:600;font-family:inherit;
            cursor:pointer;display:inline-flex;align-items:center;justify-content:center;
            gap:.55rem;box-shadow:0 6px 18px rgba(79,70,229,.35);
            transition:transform .15s,box-shadow .15s,opacity .15s;
            margin-top:.9rem;letter-spacing:.2px;
        }
        .cw-submit:hover:not(:disabled){
            transform:translateY(-2px);
            box-shadow:0 10px 26px rgba(79,70,229,.45);
        }
        .cw-submit:disabled{opacity:.65;cursor:not-allowed}

        @media (max-width:1024px){
            .sidebar{transform:translateX(-100%)}
            .sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0;width:100%}
            .menu-toggle{display:flex}
        }
        @media (max-width:640px){
            .main-content{padding:1rem}
            .cw-card-body{padding:1.25rem}
            .cw-card-head{padding:1.1rem 1.25rem}
            .pf-hero{padding:1.75rem 1.25rem 1.5rem}
            .pf-photo,.pf-photo-fallback{width:108px;height:108px}
            .pf-photo-fallback{font-size:2.4rem}
            .pf-hero-info h1{font-size:1.3rem}
            .pf-hero-meta{font-size:.78rem;gap:.75rem}
            .pf-info-cell{padding:.85rem 1.1rem;border-right:none}
            .pf-info-cell:nth-last-child(-n+2){border-bottom:1px solid var(--border)}
            .pf-info-cell:last-child{border-bottom:none}
            .pf-stat-val{font-size:1.15rem}
        }
    </style>
</head>
<body>

<?php
/* ── Sidebar include: shared across all admin pages ── */
$__current = basename($_SERVER['PHP_SELF']);
$__super   = (($_SESSION['admin_role'] ?? '') === 'superadmin');

// Sub-pages that should keep "Students" highlighted
$__student_pages = ['student_profile.php','view_student.php','edit_student.php','export_students.php','sections_list.php','sf10.php'];
// Graduate and promote pages share the same highlight group
$__promote_pages = ['promote.php','graduates.php'];

$__act = $__current;
if (in_array($__current, $__student_pages, true)) $__act = 'student_profile.php';

$__a = fn($f) => $f === $__act ? ' class="active"' : '';

$__pending_count = (int)($conn->query("SELECT COUNT(*) c FROM enrollment_form WHERE status='Pending'")->fetch_assoc()['c'] ?? 0);
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="../assets/img/usat.jpg" alt="USAT" onerror="this.style.background='var(--accent)'">
        <div class="brand-text">
            <span class="brand-name">USAT Admin</span>
            <span class="brand-sub">Enrollment System</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Overview</div>
        <a href="dashboard.php"<?= $__a('dashboard.php') ?>><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php"<?= $__a('student_profile.php') ?>><i class="bi bi-people-fill"></i> Students</a>
        <a href="pending_enrollments.php"<?= $__a('pending_enrollments.php') ?>>
            <i class="bi bi-hourglass-split"></i> Pending
            <?php if ($__pending_count > 0): ?>
                <span style="margin-left:auto;background:#d97706;color:#fff;font-size:.68rem;padding:.15rem .5rem;border-radius:999px;font-weight:700;line-height:1">
                    <?= $__pending_count ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="promote.php"<?= $__a('promote.php') ?>><i class="bi bi-rocket-takeoff"></i> Promote</a>
        <a href="graduates.php"<?= $__a('graduates.php') ?>><i class="bi bi-mortarboard-fill"></i> Graduates</a>
        <a href="reports.php"<?= $__a('reports.php') ?>><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>

        <?php if ($__super): ?>
        <div class="nav-label" style="margin-top:.5rem">Administration</div>
        <a href="create_account.php"<?= $__a('create_account.php') ?>><i class="bi bi-person-plus"></i> Accounts</a>
        <a href="audit_log.php"<?= $__a('audit_log.php') ?>><i class="bi bi-shield-check"></i> Audit Log</a>
        <?php endif; ?>

        <div class="nav-label" style="margin-top:.5rem">Account</div>
        <a href="profile.php"<?= $__a('profile.php') ?>><i class="bi bi-person-gear"></i> My Profile</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php" class="btn btn-outline-danger btn-sm w-100">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
    </div>
</aside>
<div class="main-content" id="mainContent">

    <!-- ═══════════ Topbar ═══════════ -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <div class="breadcrumb-nav">
                    <a href="dashboard.php">Dashboard</a>
                    <i class="bi bi-chevron-right" style="font-size:.7rem;opacity:.6"></i>
                    <span>My Profile</span>
                </div>
                <h2>My Profile</h2>
                <p class="sub">Manage your account credentials and profile</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
            </a>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <!-- ═══════════ Alerts ═══════════ -->
    <?php if ($error !== ''): ?>
        <div class="cw-alert cw-alert-error" role="alert">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="cw-alert cw-alert-success" role="status">
            <i class="bi bi-check-circle-fill"></i>
            <span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <?php if ($attempts_used > 0 && $locked_until === 0): ?>
        <div class="cw-attempts">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= $attempts_left ?> attempt<?= $attempts_left === 1 ? '' : 's' ?> remaining before lockout.</span>
        </div>
    <?php endif; ?>

    <!-- ═══════════ Two-column layout ═══════════ -->
    <div class="profile-grid">

        <!-- ═══════════════════════════════════════════════════════
             LEFT — Profile card
             ═══════════════════════════════════════════════════════ -->
        <div class="pf-card">

            <!-- Hero -->
            <div class="pf-hero">
                <div class="pf-photo-wrap">
                    <?php if ($admin_photo_url): ?>
                        <img src="<?= htmlspecialchars($admin_photo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Profile photo" class="pf-photo">
                    <?php else: ?>
                        <div class="pf-photo-fallback"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <!-- Upload button -->
                    <form method="POST" enctype="multipart/form-data" id="uploadPhotoForm" style="display:contents">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="upload_photo" value="1">
                        <label class="pf-photo-btn pf-btn-upload" title="Change photo" for="adminPhotoInput">
                            <i class="bi bi-camera-fill"></i>
                        </label>
                        <input type="file" id="adminPhotoInput" name="admin_photo"
                               accept="image/jpeg,image/png" style="display:none"
                               onchange="if(this.files.length) document.getElementById('uploadPhotoForm').submit();">
                    </form>

                    <!-- Delete button -->
                    <?php if ($admin_photo_url): ?>
                    <form method="POST" id="deletePhotoForm" style="display:contents">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="delete_photo" value="1">
                        <button type="submit" class="pf-photo-btn pf-btn-delete" title="Remove photo"
                                onclick="return confirm('Remove your profile photo?');">
                            <i class="bi bi-trash3-fill"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="pf-hero-info">
                    <h1>
                        <?= htmlspecialchars((string)$admin_info['fullname'], ENT_QUOTES, 'UTF-8') ?>
                        <span class="pf-role-badge">
                            <i class="bi bi-shield-fill"></i>
                            <?= htmlspecialchars((string)$admin_info['role'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </h1>
                    <div class="pf-hero-meta">
                        <span><i class="bi bi-at"></i><?= htmlspecialchars((string)$admin_info['username'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span><i class="bi bi-calendar3"></i>Member since <?= htmlspecialchars($member_since, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
            </div>

            <!-- Stats strip -->
            <div class="pf-stats">
                <div class="pf-stat">
                    <div class="pf-stat-val"><?= number_format($days_member) ?></div>
                    <div class="pf-stat-lbl"><i class="bi bi-calendar-check"></i>Days Active</div>
                </div>
                <div class="pf-stat">
                    <div class="pf-stat-val" style="text-transform:capitalize"><?= htmlspecialchars((string)$admin_info['role'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="pf-stat-lbl"><i class="bi bi-shield-lock"></i>Access Level</div>
                </div>
                <div class="pf-stat">
                    <div class="pf-stat-val"><?= $admin_photo_url ? 'Active' : '—' ?></div>
                    <div class="pf-stat-lbl"><i class="bi bi-image"></i>Profile Photo</div>
                </div>
            </div>

            <!-- Info grid -->
            <div class="pf-info-grid">
                <div class="pf-info-cell">
                    <div class="lbl"><i class="bi bi-person-badge-fill"></i>Full Name</div>
                    <div class="val"><?= htmlspecialchars((string)$admin_info['fullname'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="pf-info-cell">
                    <div class="lbl"><i class="bi bi-at"></i>Username</div>
                    <div class="val">@<?= htmlspecialchars((string)$admin_info['username'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="pf-info-cell">
                    <div class="lbl"><i class="bi bi-shield-lock-fill"></i>Role</div>
                    <div class="val" style="text-transform:capitalize"><?= htmlspecialchars((string)$admin_info['role'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="pf-info-cell">
                    <div class="lbl"><i class="bi bi-calendar-check"></i>Account Created</div>
                    <div class="val"><?= htmlspecialchars($member_since, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>

            <div class="pf-photo-hint">
                <i class="bi bi-info-circle-fill"></i>
                <span>Photo: JPG or PNG · Max 2 MB · Min 100×100px</span>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════
             RIGHT — Password card
             ═══════════════════════════════════════════════════════ -->
        <div class="cw-card">
            <div class="cw-card-head">
                <div class="cw-card-head-icon"><i class="bi bi-shield-lock-fill"></i></div>
                <div>
                    <h3>Change Your Password</h3>
                    <p>Verify with your current password, then choose a new one.</p>
                </div>
            </div>

            <div class="cw-card-body">
                <form method="POST" id="profileForm" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="cw-field">
                        <label for="current_password">Current Password</label>
                        <div class="cw-input-wrap">
                            <input type="password"
                                   id="current_password"
                                   name="current_password"
                                   placeholder="Enter your current password"
                                   required
                                   autocomplete="current-password">
                            <i class="bi bi-shield-lock-fill cw-input-icon"></i>
                            <button type="button" class="cw-eye" data-toggle-pw="current_password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="cw-divider">
                        <span><i class="bi bi-arrow-repeat"></i> New Password</span>
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
                        <span><i class="bi bi-box-arrow-right"></i> Sessions</span>
                    </div>

                    <label class="cw-check" for="logout_others">
                        <input type="checkbox" name="logout_others" id="logout_others" value="1">
                        <span class="cw-check-txt">
                            <span class="cw-check-title">Sign out from all other devices</span>
                            <span class="cw-check-sub">Invalidates any "remember me" cookies on other browsers. You'll stay logged in here.</span>
                        </span>
                    </label>

                    <button type="submit" class="cw-submit" id="pfSubmit" <?= $locked_until > 0 ? 'disabled' : '' ?>>
                        <i class="bi <?= $locked_until > 0 ? 'bi-lock-fill' : 'bi-check2-circle' ?>"></i>
                        <span><?= $locked_until > 0 ? "Locked for {$locked_until} min" : 'Update Password' ?></span>
                    </button>
                </form>
            </div>
        </div>

    </div><!-- /profile-grid -->

</div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(2px);z-index:199"
     onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sb = document.getElementById('sidebar');
const ov = document.getElementById('sidebarOverlay');
document.getElementById('menuToggle')?.addEventListener('click', () => {
    sb.classList.toggle('open');
    ov.style.display = sb.classList.contains('open') ? 'block' : 'none';
});

const tb = document.getElementById('themeToggle');
const ti = document.getElementById('themeIcon');
const h  = document.documentElement;
function st(t){
    h.setAttribute('data-bs-theme', t);
    ti.className = 'bi bi-' + (t === 'dark' ? 'sun-fill' : 'moon-stars-fill');
    document.cookie = 'admin_theme=' + t + ';path=/;max-age=' + (60*60*24*365);
}
(function(){
    const m = document.cookie.match(/(?:^|; )admin_theme=([^;]+)/);
    st(m ? decodeURIComponent(m[1]) : 'light');
})();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

document.querySelectorAll('[data-toggle-pw]').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = document.getElementById(btn.getAttribute('data-toggle-pw'));
        if (!input) return;
        const icon = btn.querySelector('i');
        if (input.type === 'password') { input.type = 'text'; if (icon) icon.className = 'bi bi-eye-slash'; }
        else { input.type = 'password'; if (icon) icon.className = 'bi bi-eye'; }
    });
});

(function(){
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
function checkMatch(){
    if (!matchEl) return;
    const a = newPwEl.value, b = confPwEl.value;
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

(function(){
    const form = document.getElementById('profileForm');
    const btn  = document.getElementById('pfSubmit');
    if (!form || !btn) return;
    const btnText = btn.querySelector('span');
    const btnIcon = btn.querySelector('i');

    form.addEventListener('submit', function(e){
        const cur = document.getElementById('current_password').value;
        const np  = newPwEl.value;
        const cp  = confPwEl.value;

        if (!cur || !np || !cp) { e.preventDefault(); alert('Please fill in all fields.'); return; }
        if (np !== cp)          { e.preventDefault(); alert('New passwords do not match.'); return; }
        if (np.length < 8)      { e.preventDefault(); alert('Password must be at least 8 characters.'); return; }
        if (np === cur)         { e.preventDefault(); alert('New password must be different from your current one.'); return; }
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
</body>
</html>