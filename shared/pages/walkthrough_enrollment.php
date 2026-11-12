<?php
declare(strict_types=1);

/**
 * shared/pages/walkthrough_enrollment.php
 *
 * Walk-in / assisted enrollment wizard for principal (superadmin) and registrar.
 * Reuses the 5-step flow of the public enrollment form, but runs inside the
 * admin shell and supports auto-approval + auto-section assignment.
 */

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';

$__nav_section = 'enrollment';

/* ── Role gate ── */
if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$csrf_token = csrf_token();

$errors = $_SESSION['walkthrough_errors'] ?? [];
$old    = $_SESSION['walkthrough_old']    ?? [];
unset($_SESSION['walkthrough_errors'], $_SESSION['walkthrough_old']);

$success_msg = $_SESSION['walkthrough_success'] ?? null;
unset($_SESSION['walkthrough_success']);

/* ── Shared constants (mirror process handler) ── */
$STRAND_PROGRAMS = [
    'Automotive and Small Engine Technologies' => [
        'Driving and Automotive Servicing',
        'Automotive Servicing (Electrical Repair)',
        'Automotive Servicing (Engine and Chassis Repairs)',
    ],
    'Construction and Building Technologies' => [
        'Carpentry', 'Manual Metal Arc Welding',
    ],
    'ICT Support and Computer Programming Technologies' => [
        'Computer Programming (Java)',
        'Computer Programming (.NET)',
        'Computer System Servicing',
    ],
    'Industrial Technologies' => [
        'Electronics Product Assembly and Servicing',
    ],
    'Agri-Fishery Business and Food Innovation' => [
        'Agricultural Crops Production',
    ],
    'Hospitality and Tourism' => [
        'Food and Beverage Operation',
        'Hotel Operation (Housekeeping)',
    ],
];
$SY_OPTIONS = [];
$__cy = (int)date('Y');
for ($y = $__cy - 1; $y <= $__cy + 2; $y++) $SY_OPTIONS[] = $y . '-' . ($y + 1);

$doc_options = [
    'Good Moral Certificate'                 => 'good_moral',
    'Junior High School Certificate (Front)' => 'jhs_certificate_front',
    'Junior High School Certificate (Back)'  => 'jhs_certificate_back',
    'NSO/PSA Birth Certificate (Original)'   => 'birth_certificate',
    '2 pcs 2×2 picture'                      => 'pictures_2x2',
];

/* ── Determine which step to show ── */
$initial_step = 1;
if (!empty($errors)) {
    $step_map = [
        'lrn' => 1, 'last_name' => 1, 'first_name' => 1, 'sex' => 1, 'birth_date' => 1,
        'email' => 1, 'phone' => 1,
        'is_4ps' => 2, 'father_contact' => 2, 'mother_contact' => 2, 'guardian_contact' => 2,
        'school_year' => 5, 'grade_level' => 5, 'term' => 5, 'strand' => 5, 'program' => 5,
        'entrance_data' => 5, 'upload' => 5,
    ];
    $first = PHP_INT_MAX;
    foreach ($errors as $field => $_) {
        $s = $step_map[$field] ?? 1;
        if ($s < $first) $first = $s;
    }
    if ($first !== PHP_INT_MAX) $initial_step = $first;
}

$v = static function (string $k, string $default = '') use ($old): string {
    return htmlspecialchars((string)($old[$k] ?? $default), ENT_QUOTES, 'UTF-8');
};
$err = static function (string $k) use ($errors): string {
    return isset($errors[$k]) ? '<span class="wt-error">' . htmlspecialchars((string)$errors[$k], ENT_QUOTES, 'UTF-8') . '</span>' : '';
};
$is = static function (string $k, string $value) use ($old): string {
    return ((string)($old[$k] ?? '') === $value) ? ' selected' : '';
};

$cur_strand  = (string)($old['strand'] ?? '');
$cur_program = (string)($old['program'] ?? '');
$cur_sy      = (string)($old['school_year'] ?? ($SY_OPTIONS[1] ?? ''));

$edu_history = [];
if (!empty($old['edu_level']) && is_array($old['edu_level'])) {
    foreach ($old['edu_level'] as $i => $lvl) {
        $edu_history[] = [
            'level' => $lvl,
            'school_name' => $old['school_name'][$i] ?? '',
            'school_address' => $old['school_address'][$i] ?? '',
            'year_completed' => $old['year_completed'][$i] ?? '',
        ];
    }
}
if (empty($edu_history)) {
    $edu_history[] = ['level' => 'Elementary', 'school_name' => '', 'school_address' => '', 'year_completed' => ''];
}

$page_title = 'Walkthrough Enrollment • USAT Admin';

$page_css = <<<'CSS'
/* ════════════════════════════════════════════════════════════════
   Walkthrough Enrollment — "Registrar Studio" theme
   ════════════════════════════════════════════════════════════════ */

/* ── Per-step color theming ── */
.wt-step { --step-accent: #4f46e5; --step-soft: #eef2ff; --step-ring: rgba(79,70,229,.18); }
.wt-step[data-step="1"] { --step-accent: #4f46e5; --step-soft: #eef2ff; --step-ring: rgba(79,70,229,.18); }
.wt-step[data-step="2"] { --step-accent: #7c3aed; --step-soft: #f5f3ff; --step-ring: rgba(124,58,237,.18); }
.wt-step[data-step="3"] { --step-accent: #0891b2; --step-soft: #ecfeff; --step-ring: rgba(8,145,178,.18); }
.wt-step[data-step="4"] { --step-accent: #059669; --step-soft: #ecfdf5; --step-ring: rgba(5,150,105,.18); }
.wt-step[data-step="5"] { --step-accent: #ea580c; --step-soft: #fff7ed; --step-ring: rgba(234,88,12,.18); }

/* ── Page header ── */
.wt-crumbs { display: flex; align-items: center; gap: .35rem; font-size: .76rem; color: var(--text-2); margin-bottom: .3rem; font-weight: 500; }
.wt-crumbs a { color: var(--text-2); text-decoration: none; transition: color .15s; }
.wt-crumbs a:hover { color: var(--accent); }
.wt-crumbs i { font-size: .62rem; opacity: .5; }
.wt-crumbs .current { color: var(--text); font-weight: 600; }
.wt-actions { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }

/* ── Flash messages ── */
.wt-flash {
    display: flex; align-items: flex-start; gap: .85rem;
    padding: 1rem 1.15rem; border-radius: 14px;
    margin-bottom: 1.35rem; font-weight: 500;
    animation: wtSlideIn .35s cubic-bezier(.4, 0, .2, 1);
}
@keyframes wtSlideIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: none; } }
.wt-flash-icon {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.05rem; flex-shrink: 0;
}
.wt-flash-body { flex: 1; min-width: 0; }
.wt-flash-title { font-weight: 700; font-size: .88rem; margin-bottom: .15rem; }
.wt-flash-text { font-size: .82rem; line-height: 1.5; opacity: .92; }
.wt-flash.success { background: linear-gradient(135deg, #f0fdf4, #dcfce7); color: #065f46; border: 1px solid rgba(5,150,105,.28); }
.wt-flash.success .wt-flash-icon { background: #10b981; color: #fff; }
.wt-flash.error { background: linear-gradient(135deg, #fef2f2, #fee2e2); color: #991b1b; border: 1px solid rgba(220,38,38,.28); }
.wt-flash.error .wt-flash-icon { background: #dc2626; color: #fff; }
.wt-flash .close-btn {
    background: none; border: none; color: inherit; opacity: .55;
    cursor: pointer; padding: .25rem; border-radius: 6px; transition: opacity .15s, background .15s;
    flex-shrink: 0;
}
.wt-flash .close-btn:hover { opacity: 1; background: rgba(0,0,0,.06); }

/* ── Cards ── */
.wt-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(15,23,42,.04), 0 4px 12px -2px rgba(15,23,42,.03);
    overflow: hidden;
    margin-bottom: 1.25rem;
    transition: box-shadow .2s, border-color .2s;
}
.wt-card:hover { box-shadow: 0 2px 6px rgba(15,23,42,.05), 0 8px 24px -4px rgba(15,23,42,.06); }
.wt-card-head {
    display: flex; align-items: center; gap: 1rem;
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, var(--step-soft) 0%, var(--surface) 90%);
    border-bottom: 1px solid var(--border);
}
.wt-ch-icon {
    width: 46px; height: 46px; border-radius: 13px;
    background: var(--surface);
    color: var(--step-accent);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem; flex-shrink: 0;
    box-shadow: 0 0 0 1px var(--step-ring) inset, 0 4px 10px -2px var(--step-ring);
}
.wt-ch-text { min-width: 0; flex: 1; }
.wt-ch-badge {
    display: inline-block;
    padding: .2rem .65rem;
    border-radius: 999px;
    background: var(--surface);
    color: var(--step-accent);
    font-size: .66rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .7px;
    margin-bottom: .35rem;
    border: 1px solid var(--step-ring);
}
.wt-ch-text h3 {
    margin: 0 0 .2rem;
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--text);
    letter-spacing: -.01em;
    display: flex; align-items: center; gap: .5rem;
}
.wt-ch-sub { margin: 0; font-size: .78rem; color: var(--text-2); }
.wt-card-body { padding: 1.5rem; }

/* ── Step transition ── */
.wt-step { display: none; }
.wt-step.active { display: block; animation: wtFade .3s cubic-bezier(.4, 0, .2, 1); }
@keyframes wtFade { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

/* ── Grid rows ── */
.wt-row { display: grid; grid-template-columns: repeat(12, 1fr); gap: .9rem; }
.wt-col-3 { grid-column: span 3; }
.wt-col-4 { grid-column: span 4; }
.wt-col-6 { grid-column: span 6; }
.wt-col-12 { grid-column: span 12; }
@media (max-width: 720px) {
    .wt-col-3, .wt-col-4, .wt-col-6 { grid-column: span 12; }
}

/* ── Group sections inside cards ── */
.wt-group + .wt-group { margin-top: 1.75rem; }
.wt-group-head {
    display: flex; align-items: center; gap: .6rem;
    padding-bottom: .7rem;
    margin-bottom: 1rem;
    border-bottom: 1px dashed var(--border);
}
.wt-group-head .wt-gh-icon {
    width: 26px; height: 26px; border-radius: 7px;
    background: var(--step-soft);
    color: var(--step-accent);
    display: flex; align-items: center; justify-content: center;
    font-size: .78rem;
    flex-shrink: 0;
}
.wt-group-head h4 {
    margin: 0;
    font-size: .78rem;
    font-weight: 800;
    color: var(--text);
    text-transform: uppercase;
    letter-spacing: .6px;
}
.wt-group-head .wt-gh-hint {
    margin-left: auto;
    font-size: .72rem;
    color: var(--text-2);
    font-weight: 500;
}

/* ── Fields ── */
.wt-field { display: flex; flex-direction: column; gap: .35rem; }
.wt-field > label {
    font-size: .72rem;
    font-weight: 700;
    color: var(--text-2);
    text-transform: uppercase;
    letter-spacing: .55px;
    display: flex; align-items: center; gap: .35rem;
}
.wt-field > label .req {
    color: var(--step-accent);
    font-weight: 800;
    margin-left: .1rem;
}
.wt-field > label .opt {
    font-size: .62rem;
    font-weight: 600;
    color: var(--text-3);
    background: var(--surface-2);
    padding: .08rem .4rem;
    border-radius: 4px;
    text-transform: none;
    letter-spacing: .1px;
    margin-left: auto;
}
.wt-field input,
.wt-field select,
.wt-field textarea {
    width: 100%;
    padding: .65rem .85rem;
    background: var(--surface-2);
    border: 1.5px solid var(--border);
    border-radius: 9px;
    font-size: .88rem;
    color: var(--text);
    font-family: inherit;
    transition: border-color .15s, box-shadow .15s, background .15s;
}
.wt-field input:hover,
.wt-field select:hover,
.wt-field textarea:hover { border-color: var(--border-strong, #cbd5e1); }
.wt-field input:focus,
.wt-field select:focus,
.wt-field textarea:focus {
    outline: none;
    border-color: var(--step-accent);
    background: var(--surface);
    box-shadow: 0 0 0 4px var(--step-ring);
}
.wt-field input[readonly],
.wt-field input[readonly]:focus {
    background: var(--surface);
    border-style: dashed;
    color: var(--text-2);
    box-shadow: none;
}
.wt-field textarea { resize: vertical; min-height: 60px; }
.wt-field select {
    appearance: none;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' d='M1 1.5l5 5 5-5'/></svg>");
    background-repeat: no-repeat;
    background-position: right .85rem center;
    background-size: 12px 8px;
    padding-right: 2.4rem;
    cursor: pointer;
}
.wt-field.has-error input,
.wt-field.has-error select,
.wt-field.has-error textarea {
    border-color: var(--red);
    background: var(--red-soft);
}
.wt-field.has-error > label { color: var(--red); }
.wt-field.has-error input:focus,
.wt-field.has-error select:focus,
.wt-field.has-error textarea:focus {
    box-shadow: 0 0 0 4px rgba(220,38,38,.18);
}

/* ── Error text ── */
.wt-error {
    display: flex; align-items: center; gap: .35rem;
    font-size: .74rem; font-weight: 600; color: var(--red);
    margin-top: .05rem;
    animation: wtErrorIn .2s ease;
}
.wt-error::before {
    content: '\F33A';
    font-family: 'bootstrap-icons';
    font-size: .8rem;
    flex-shrink: 0;
}
@keyframes wtErrorIn { from { opacity: 0; transform: translateX(-4px); } to { opacity: 1; transform: none; } }

/* ── Wizard nav (sticky) ── */
.wt-nav {
    position: sticky;
    bottom: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem;
    margin-top: 1.35rem;
    padding: .85rem 1.1rem;
    background: color-mix(in srgb, var(--surface) 92%, transparent);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: 0 8px 24px -8px rgba(15,23,42,.15), 0 2px 6px -1px rgba(15,23,42,.06);
    z-index: 20;
    flex-wrap: wrap;
}
.wt-nav .spacer { flex: 1; }
.wt-nav .btn {
    padding: .7rem 1.5rem;
    border-radius: 10px;
    font-weight: 700;
    font-size: .88rem;
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    transition: transform .15s, box-shadow .15s, background .15s;
    border: none;
    cursor: pointer;
}
.wt-nav .btn-back {
    background: var(--surface-2);
    color: var(--text);
    border: 1px solid var(--border);
}
.wt-nav .btn-back:hover { background: var(--surface); border-color: var(--border-strong, #cbd5e1); transform: translateY(-1px); }
.wt-nav .btn-next {
    background: var(--step-accent);
    color: #fff;
    box-shadow: 0 4px 12px -2px var(--step-ring);
}
.wt-nav .btn-next:hover { filter: brightness(1.08); transform: translateY(-1px); box-shadow: 0 6px 18px -2px var(--step-ring); }
.wt-nav .btn-submit {
    background: linear-gradient(135deg, #10b981, #047857);
    color: #fff;
    box-shadow: 0 4px 12px -2px rgba(16,185,129,.4);
}
.wt-nav .btn-submit:hover { filter: brightness(1.08); transform: translateY(-1px); box-shadow: 0 8px 20px -4px rgba(16,185,129,.55); }
.wt-nav .btn.placeholder { visibility: hidden; pointer-events: none; }
.wt-nav-hint {
    font-size: .74rem;
    color: var(--text-2);
    font-weight: 500;
    display: flex; align-items: center; gap: .35rem;
}
.wt-nav-hint strong { color: var(--text); font-weight: 700; }

/* ── Document cards ── */
.wt-doc-grid { display: flex; flex-direction: column; gap: .75rem; }
.wt-doc-card {
    position: relative;
    background: var(--surface);
    border: 1.5px dashed var(--border);
    border-radius: 12px;
    padding: .9rem 1.1rem;
    transition: border-color .2s, background .2s, box-shadow .2s;
}
.wt-doc-card:hover { border-color: var(--step-accent); background: color-mix(in srgb, var(--step-soft) 40%, var(--surface)); }
.wt-doc-card.is-checked { border-style: solid; border-color: var(--step-accent); background: var(--step-soft); }
.wt-doc-card.has-file { border-color: var(--green); background: var(--green-soft); }
.wt-doc-card.has-file .wt-doc-icon { background: var(--green); color: #fff; }

.wt-doc-head {
    display: flex; align-items: flex-start; gap: .8rem;
    cursor: pointer;
}
.wt-doc-head input[type="checkbox"] {
    margin-top: .25rem;
    width: 18px; height: 18px;
    accent-color: var(--step-accent);
    cursor: pointer;
    flex-shrink: 0;
}
.wt-doc-icon {
    width: 34px; height: 34px; border-radius: 9px;
    background: var(--surface-2);
    color: var(--step-accent);
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem; flex-shrink: 0;
    transition: background .2s, color .2s;
}
.wt-doc-body { flex: 1; min-width: 0; }
.wt-doc-name { font-size: .88rem; font-weight: 700; color: var(--text); line-height: 1.35; margin-bottom: .15rem; }
.wt-doc-hint { font-size: .74rem; color: var(--text-2); line-height: 1.4; }
.wt-doc-status {
    display: inline-flex; align-items: center; gap: .3rem;
    font-size: .68rem; font-weight: 700;
    padding: .18rem .55rem; border-radius: 999px;
    text-transform: uppercase; letter-spacing: .4px;
    background: var(--surface-2); color: var(--text-2);
    flex-shrink: 0;
}
.wt-doc-card.is-checked .wt-doc-status { background: var(--step-accent); color: #fff; }
.wt-doc-card.has-file .wt-doc-status { background: var(--green); color: #fff; }

.wt-doc-file {
    display: flex; align-items: center; gap: .6rem;
    margin-top: .7rem;
    padding-top: .7rem;
    border-top: 1px dashed var(--border);
    flex-wrap: wrap;
}
.wt-doc-file input[type="file"] {
    flex: 1; min-width: 180px;
    font-size: .78rem;
    padding: .35rem .5rem;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 7px;
    color: var(--text-2);
}
.wt-doc-file input[type="file"]::file-selector-button {
    padding: .3rem .7rem;
    border-radius: 6px;
    border: none;
    background: var(--surface-2);
    color: var(--text);
    font-weight: 600;
    font-size: .74rem;
    cursor: pointer;
    margin-right: .5rem;
    transition: background .15s;
}
.wt-doc-file input[type="file"]::file-selector-button:hover { background: var(--border); }
.wt-doc-file-meta { font-size: .72rem; color: var(--text-2); font-weight: 500; }
.wt-doc-file-meta.has-file { color: var(--green); font-weight: 700; }

/* ── Auto-approve box ── */
.wt-approve-box {
    margin-top: 1.35rem;
    padding: 1.15rem 1.25rem;
    border-radius: 14px;
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border: 1.5px solid rgba(5,150,105,.32);
    display: flex; align-items: flex-start; gap: .95rem;
    transition: box-shadow .2s, border-color .2s;
}
.wt-approve-box:hover { border-color: rgba(5,150,105,.55); box-shadow: 0 6px 18px -6px rgba(5,150,105,.25); }
.wt-approve-icon {
    width: 42px; height: 42px; border-radius: 50%;
    background: #10b981; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem; flex-shrink: 0;
    box-shadow: 0 4px 12px -2px rgba(16,185,129,.5);
}
.wt-approve-body { flex: 1; min-width: 0; }
.wt-approve-head {
    display: flex; align-items: flex-start; gap: .7rem;
    margin-bottom: .5rem;
}
.wt-approve-head input[type="checkbox"] {
    width: 20px; height: 20px;
    accent-color: #059669;
    margin-top: 2px;
    cursor: pointer;
    flex-shrink: 0;
}
.wt-approve-title {
    font-size: .95rem; font-weight: 800; color: #065f46;
    line-height: 1.3; cursor: pointer;
}
.wt-approve-sub { font-size: .8rem; color: #047857; line-height: 1.5; margin-bottom: .7rem; }
.wt-approve-checks { display: flex; flex-direction: column; gap: .3rem; }
.wt-approve-checks span {
    display: flex; align-items: center; gap: .45rem;
    font-size: .78rem; color: #065f46; font-weight: 500;
}
.wt-approve-checks i { color: #059669; font-size: .9rem; flex-shrink: 0; }

/* ── Responsive tweaks ── */
@media (max-width: 640px) {
    .wt-card-head { padding: 1rem 1.1rem; gap: .75rem; }
    .wt-ch-icon { width: 38px; height: 38px; font-size: 1rem; }
    .wt-ch-text h3 { font-size: .95rem; }
    .wt-card-body { padding: 1.15rem; }
    .wt-nav { padding: .7rem .85rem; bottom: 8px; }
    .wt-nav .btn { padding: .6rem 1.15rem; font-size: .82rem; }
    .wt-nav-hint { display: none; }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: .001ms !important;
        transition-duration: .001ms !important;
    }
}
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

<div class="main-content" id="mainContent">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu"><i class="bi bi-list fs-5"></i></button>
            <div>
                <div class="wt-crumbs">
                    <a href="<?= URL_PREFIX ?>/dashboard.php">Dashboard</a>
                    <i class="bi bi-chevron-right"></i>
                    <a href="<?= URL_PREFIX ?>/pending_enrollments.php">Enrollment</a>
                    <i class="bi bi-chevron-right"></i>
                    <span class="current">Walkthrough</span>
                </div>
                <h2>Walkthrough Enrollment</h2>
                <p class="sub">Walk-in / assisted enrollment · registrar &amp; principal</p>
            </div>
        </div>
        <div class="wt-actions">
            <a href="<?= URL_PREFIX ?>/pending_enrollments.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-hourglass-split me-1"></i> Pending Queue
            </a>
            <a href="<?= URL_PREFIX ?>/student_profile.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-people me-1"></i> All Students
            </a>
            <button class="theme-btn" id="themeToggle" title="Toggle theme"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="wt-flash success" role="status">
            <div class="wt-flash-icon"><i class="bi bi-check-lg"></i></div>
            <div class="wt-flash-body">
                <div class="wt-flash-title">Enrollment submitted</div>
                <div class="wt-flash-text"><?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <button type="button" class="close-btn" onclick="this.closest('.wt-flash').remove()"><i class="bi bi-x-lg"></i></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="wt-flash error" role="alert">
            <div class="wt-flash-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div class="wt-flash-body">
                <div class="wt-flash-title">Please fix the highlighted fields</div>
                <div class="wt-flash-text">Review the highlighted fields below before submitting.</div>
            </div>
            <button type="button" class="close-btn" onclick="this.closest('.wt-flash').remove()"><i class="bi bi-x-lg"></i></button>
        </div>
    <?php endif; ?>

    <div class="walkthrough-layout">

        <?php require __DIR__ . '/../partials/enrollment_wizard_sidebar.php'; ?>

        <div class="walkthrough-content">
            <form method="POST" action="<?= URL_PREFIX ?>/walkthrough_process.php" enctype="multipart/form-data" novalidate id="wtForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                <!-- ═══════════ STEP 1 — STUDENT INFO ═══════════ -->
                <div class="wt-step<?= $initial_step === 1 ? ' active' : '' ?>" data-step="1">
                    <div class="wt-card">
                        <div class="wt-card-head">
                            <div class="wt-ch-icon"><i class="bi bi-person-vcard"></i></div>
                            <div class="wt-ch-text">
                                <span class="wt-ch-badge">Step 1 of 5</span>
                                <h3>Student Information</h3>
                                <p class="wt-ch-sub">LRN, name, sex, birth date, contact details</p>
                            </div>
                        </div>
                        <div class="wt-card-body">

                            <div class="wt-group">
                                <div class="wt-group-head">
                                    <div class="wt-gh-icon"><i class="bi bi-person-badge"></i></div>
                                    <h4>Identity</h4>
                                    <span class="wt-gh-hint">Fields marked * are required</span>
                                </div>
                                <div class="wt-row">
                                    <div class="wt-col-4 wt-field<?= isset($errors['lrn']) ? ' has-error' : '' ?>">
                                        <label for="lrn">LRN <span class="req">*</span></label>
                                        <input type="text" id="lrn" name="lrn" maxlength="12" inputmode="numeric" required value="<?= $v('lrn') ?>" placeholder="12-digit LRN">
                                        <?= $err('lrn') ?>
                                    </div>
                                    <div class="wt-col-4 wt-field<?= isset($errors['last_name']) ? ' has-error' : '' ?>">
                                        <label for="last_name">Last Name <span class="req">*</span></label>
                                        <input type="text" id="last_name" name="last_name" maxlength="100" required value="<?= $v('last_name') ?>">
                                        <?= $err('last_name') ?>
                                    </div>
                                    <div class="wt-col-4 wt-field<?= isset($errors['first_name']) ? ' has-error' : '' ?>">
                                        <label for="first_name">First Name <span class="req">*</span></label>
                                        <input type="text" id="first_name" name="first_name" maxlength="100" required value="<?= $v('first_name') ?>">
                                        <?= $err('first_name') ?>
                                    </div>
                                    <div class="wt-col-4 wt-field">
                                        <label for="middle_name">Middle Name <span class="opt">Optional</span></label>
                                        <input type="text" id="middle_name" name="middle_name" maxlength="100" value="<?= $v('middle_name') ?>">
                                    </div>
                                    <div class="wt-col-4 wt-field">
                                        <label for="nick_name">Nickname <span class="opt">Optional</span></label>
                                        <input type="text" id="nick_name" name="nick_name" maxlength="50" value="<?= $v('nick_name') ?>">
                                    </div>
                                    <div class="wt-col-4 wt-field">
                                        <label for="ext_name">Extension <span class="opt">Optional</span></label>
                                        <input type="text" id="ext_name" name="ext_name" maxlength="20" value="<?= $v('ext_name') ?>" placeholder="Jr., Sr., III…">
                                    </div>
                                    <div class="wt-col-4 wt-field<?= isset($errors['sex']) ? ' has-error' : '' ?>">
                                        <label for="sex">Sex <span class="req">*</span></label>
                                        <select id="sex" name="sex" required>
                                            <option value="">— Select —</option>
                                            <option value="Male"<?= $is('sex', 'Male') ?>>Male</option>
                                            <option value="Female"<?= $is('sex', 'Female') ?>>Female</option>
                                        </select>
                                        <?= $err('sex') ?>
                                    </div>
                                    <div class="wt-col-4 wt-field<?= isset($errors['birth_date']) ? ' has-error' : '' ?>">
                                        <label for="birth_date">Birth Date <span class="req">*</span></label>
                                        <input type="date" id="birth_date" name="birth_date" required value="<?= $v('birth_date') ?>">
                                        <?= $err('birth_date') ?>
                                    </div>
                                    <div class="wt-col-4 wt-field">
                                        <label for="age">Age <span class="opt">Auto-calculated</span></label>
                                        <input type="number" id="age" name="age" readonly value="<?= $v('age') ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="wt-group">
                                <div class="wt-group-head">
                                    <div class="wt-gh-icon"><i class="bi bi-telephone"></i></div>
                                    <h4>Contact</h4>
                                </div>
                                <div class="wt-row">
                                    <div class="wt-col-6 wt-field">
                                        <label for="email">Email <span class="opt">Optional</span></label>
                                        <input type="email" id="email" name="email" maxlength="150" value="<?= $v('email') ?>" placeholder="student@example.com">
                                        <?= $err('email') ?>
                                    </div>
                                    <div class="wt-col-6 wt-field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
                                        <label for="phone">Phone <span class="opt">Optional</span></label>
                                        <input type="tel" id="phone" name="phone" maxlength="11" inputmode="numeric" value="<?= $v('phone') ?>" placeholder="09XXXXXXXXX">
                                        <?= $err('phone') ?>
                                    </div>
                                </div>
                            </div>

                            <div class="wt-group">
                                <div class="wt-group-head">
                                    <div class="wt-gh-icon"><i class="bi bi-info-circle"></i></div>
                                    <h4>Background</h4>
                                </div>
                                <div class="wt-row">
                                    <div class="wt-col-4 wt-field">
                                        <label for="religion">Religion</label>
                                        <input type="text" id="religion" name="religion" maxlength="100" value="<?= $v('religion') ?>">
                                    </div>
                                    <div class="wt-col-4 wt-field">
                                        <label for="nationality">Nationality</label>
                                        <input type="text" id="nationality" name="nationality" maxlength="50" value="<?= $v('nationality', 'Filipino') ?>">
                                    </div>
                                    <div class="wt-col-4 wt-field">
                                        <label for="civil_status">Civil Status</label>
                                        <select id="civil_status" name="civil_status">
                                            <option value="">— Select —</option>
                                            <?php foreach (['Single','Married','Widowed','Separated','Annulled'] as $opt): ?>
                                                <option value="<?= $opt ?>"<?= $is('civil_status', $opt) ?>><?= $opt ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="wt-col-12 wt-field">
                                        <label for="special_skills">Special Skills</label>
                                        <textarea id="special_skills" name="special_skills" rows="2" maxlength="500" placeholder="e.g. programming, carpentry, cooking…"><?= $v('special_skills') ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="wt-nav">
                        <span class="btn btn-back placeholder"><i class="bi bi-arrow-left"></i> Previous</span>
                        <span class="spacer"></span>
                        <span class="wt-nav-hint"><i class="bi bi-info-circle"></i> Next: <strong>Parents &amp; Guardian</strong></span>
                        <button type="button" class="btn btn-next" data-next="2">Next <i class="bi bi-arrow-right"></i></button>
                    </div>
                </div>

                <!-- ═══════════ STEP 2 — PARENTS & GUARDIAN ═══════════ -->
                <div class="wt-step<?= $initial_step === 2 ? ' active' : '' ?>" data-step="2">
                    <div class="wt-card">
                        <div class="wt-card-head">
                            <div class="wt-ch-icon"><i class="bi bi-people"></i></div>
                            <div class="wt-ch-text">
                                <span class="wt-ch-badge">Step 2 of 5</span>
                                <h3>Parents &amp; Guardian</h3>
                                <p class="wt-ch-sub">Family background and emergency contacts</p>
                            </div>
                        </div>
                        <div class="wt-card-body">

                            <div class="wt-group">
                                <div class="wt-group-head">
                                    <div class="wt-gh-icon"><i class="bi bi-house-heart"></i></div>
                                    <h4>Parents</h4>
                                </div>
                                <div class="wt-row">
                                    <div class="wt-col-6 wt-field">
                                        <label for="father_name">Father Name <span class="opt">Optional</span></label>
                                        <input type="text" id="father_name" name="father_name" maxlength="150" value="<?= $v('father_name') ?>">
                                    </div>
                                    <div class="wt-col-6 wt-field">
                                        <label for="father_occupation">Father Occupation <span class="opt">Optional</span></label>
                                        <input type="text" id="father_occupation" name="father_occupation" maxlength="100" value="<?= $v('father_occupation') ?>">
                                    </div>
                                    <div class="wt-col-6 wt-field<?= isset($errors['father_contact']) ? ' has-error' : '' ?>">
                                        <label for="father_contact">Father Contact <span class="opt">Optional</span></label>
                                        <input type="tel" id="father_contact" name="father_contact" maxlength="11" placeholder="09XXXXXXXXX" value="<?= $v('father_contact') ?>">
                                        <?= $err('father_contact') ?>
                                    </div>
                                    <div class="wt-col-6 wt-field">
                                        <label for="mother_maiden_name">Mother (Maiden) <span class="opt">Optional</span></label>
                                        <input type="text" id="mother_maiden_name" name="mother_maiden_name" maxlength="150" value="<?= $v('mother_maiden_name') ?>">
                                    </div>
                                    <div class="wt-col-6 wt-field">
                                        <label for="mother_occupation">Mother Occupation <span class="opt">Optional</span></label>
                                        <input type="text" id="mother_occupation" name="mother_occupation" maxlength="100" value="<?= $v('mother_occupation') ?>">
                                    </div>
                                    <div class="wt-col-6 wt-field<?= isset($errors['mother_contact']) ? ' has-error' : '' ?>">
                                        <label for="mother_contact">Mother Contact <span class="opt">Optional</span></label>
                                        <input type="tel" id="mother_contact" name="mother_contact" maxlength="11" placeholder="09XXXXXXXXX" value="<?= $v('mother_contact') ?>">
                                        <?= $err('mother_contact') ?>
                                    </div>
                                    <div class="wt-col-6 wt-field">
                                        <label for="ave_family_income">Family Income <span class="opt">Optional</span></label>
                                        <input type="number" step="0.01" id="ave_family_income" name="ave_family_income" value="<?= $v('ave_family_income') ?>" placeholder="0.00">
                                    </div>
                                    <div class="wt-col-6 wt-field<?= isset($errors['is_4ps']) ? ' has-error' : '' ?>">
                                        <label for="is_4ps">CCT / 4Ps Recipient <span class="req">*</span></label>
                                        <select id="is_4ps" name="is_4ps" required>
                                            <option value="">— Select —</option>
                                            <option value="1"<?= $is('is_4ps', '1') ?>>YES</option>
                                            <option value="0"<?= $is('is_4ps', '0') ?>>NO</option>
                                        </select>
                                        <?= $err('is_4ps') ?>
                                    </div>
                                </div>
                            </div>

                            <div class="wt-group">
                                <div class="wt-group-head">
                                    <div class="wt-gh-icon"><i class="bi bi-shield-check"></i></div>
                                    <h4>Guardian</h4>
                                    <span class="wt-gh-hint">Emergency contact</span>
                                </div>
                                <div class="wt-row">
                                    <div class="wt-col-4 wt-field">
                                        <label for="guardian_fullname">Guardian Name <span class="opt">Optional</span></label>
                                        <input type="text" id="guardian_fullname" name="guardian_fullname" maxlength="150" value="<?= $v('guardian_fullname') ?>">
                                    </div>
                                    <div class="wt-col-4 wt-field">
                                        <label for="guardian_relation">Relationship <span class="opt">Optional</span></label>
                                        <input type="text" id="guardian_relation" name="guardian_relation" maxlength="60" value="<?= $v('guardian_relation') ?>" placeholder="e.g. Aunt, Grandparent">
                                    </div>
                                    <div class="wt-col-4 wt-field<?= isset($errors['guardian_contact']) ? ' has-error' : '' ?>">
                                        <label for="guardian_contact">Guardian Contact <span class="opt">Optional</span></label>
                                        <input type="tel" id="guardian_contact" name="guardian_contact" maxlength="11" placeholder="09XXXXXXXXX" value="<?= $v('guardian_contact') ?>">
                                        <?= $err('guardian_contact') ?>
                                    </div>
                                    <div class="wt-col-12 wt-field">
                                        <label for="household_id">Household ID <span class="opt">Optional</span></label>
                                        <input type="text" id="household_id" name="household_id" maxlength="50" value="<?= $v('household_id') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="wt-nav">
                        <button type="button" class="btn btn-back" data-back="1"><i class="bi bi-arrow-left"></i> Previous</button>
                        <span class="spacer"></span>
                        <span class="wt-nav-hint"><i class="bi bi-info-circle"></i> Next: <strong>Home Address</strong></span>
                        <button type="button" class="btn btn-next" data-next="3">Next <i class="bi bi-arrow-right"></i></button>
                    </div>
                </div>

                <!-- ═══════════ STEP 3 — ADDRESS ═══════════ -->
                <div class="wt-step<?= $initial_step === 3 ? ' active' : '' ?>" data-step="3">
                    <div class="wt-card">
                        <div class="wt-card-head">
                            <div class="wt-ch-icon"><i class="bi bi-geo-alt"></i></div>
                            <div class="wt-ch-text">
                                <span class="wt-ch-badge">Step 3 of 5</span>
                                <h3>Complete Home Address</h3>
                                <p class="wt-ch-sub">Where the student currently resides</p>
                            </div>
                        </div>
                        <div class="wt-card-body">
                            <div class="wt-row">
                                <div class="wt-col-6 wt-field">
                                    <label for="purok_street">Purok / Street</label>
                                    <input type="text" id="purok_street" name="purok_street" maxlength="200" value="<?= $v('purok_street') ?>">
                                </div>
                                <div class="wt-col-6 wt-field">
                                    <label for="barangay">Barangay</label>
                                    <input type="text" id="barangay" name="barangay" maxlength="100" value="<?= $v('barangay') ?>">
                                </div>
                                <div class="wt-col-6 wt-field">
                                    <label for="town_city">Town / City</label>
                                    <input type="text" id="town_city" name="town_city" maxlength="100" value="<?= $v('town_city') ?>">
                                </div>
                                <div class="wt-col-6 wt-field">
                                    <label for="province">Province</label>
                                    <input type="text" id="province" name="province" maxlength="100" value="<?= $v('province') ?>">
                                </div>
                                <div class="wt-col-6 wt-field">
                                    <label for="region">Region</label>
                                    <input type="text" id="region" name="region" maxlength="100" value="<?= $v('region') ?>">
                                </div>
                                <div class="wt-col-3 wt-field">
                                    <label for="district">District</label>
                                    <input type="text" id="district" name="district" maxlength="100" value="<?= $v('district') ?>">
                                </div>
                                <div class="wt-col-3 wt-field">
                                    <label for="postal_code">Postal Code</label>
                                    <input type="text" id="postal_code" name="postal_code" maxlength="10" value="<?= $v('postal_code') ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="wt-nav">
                        <button type="button" class="btn btn-back" data-back="2"><i class="bi bi-arrow-left"></i> Previous</button>
                        <span class="spacer"></span>
                        <span class="wt-nav-hint"><i class="bi bi-info-circle"></i> Next: <strong>Educational History</strong></span>
                        <button type="button" class="btn btn-next" data-next="4">Next <i class="bi bi-arrow-right"></i></button>
                    </div>
                </div>

                <!-- ═══════════ STEP 4 — EDUCATION ═══════════ -->
                <div class="wt-step<?= $initial_step === 4 ? ' active' : '' ?>" data-step="4">
                    <div class="wt-card">
                        <div class="wt-card-head">
                            <div class="wt-ch-icon"><i class="bi bi-graduation-cap"></i></div>
                            <div class="wt-ch-text">
                                <span class="wt-ch-badge">Step 4 of 5</span>
                                <h3>Educational History</h3>
                                <p class="wt-ch-sub">Elementary, Junior High, and transferee records</p>
                            </div>
                        </div>
                        <div class="wt-card-body">
                            <div id="wt-edu-container">
                                <?php foreach ($edu_history as $idx => $edu): ?>
                                    <div class="wt-row wt-edu-entry" style="margin-bottom:.85rem;">
                                        <div class="wt-col-3 wt-field">
                                            <label>Level</label>
                                            <select name="edu_level[]">
                                                <option value="Elementary"<?= $edu['level'] === 'Elementary' ? ' selected' : '' ?>>Elementary</option>
                                                <option value="JHS"<?= $edu['level'] === 'JHS' ? ' selected' : '' ?>>Junior High School</option>
                                                <option value="Transferred"<?= $edu['level'] === 'Transferred' ? ' selected' : '' ?>>Transferred</option>
                                            </select>
                                        </div>
                                        <div class="wt-col-3 wt-field">
                                            <label>School Name</label>
                                            <input type="text" name="school_name[]" maxlength="200" value="<?= htmlspecialchars($edu['school_name'], ENT_QUOTES, 'UTF-8') ?>">
                                        </div>
                                        <div class="wt-col-3 wt-field">
                                            <label>School Address</label>
                                            <input type="text" name="school_address[]" maxlength="300" value="<?= htmlspecialchars($edu['school_address'], ENT_QUOTES, 'UTF-8') ?>">
                                        </div>
                                        <div class="wt-col-3 wt-field">
                                            <label>Year Completed</label>
                                            <input type="text" name="year_completed[]" maxlength="20" inputmode="numeric" value="<?= htmlspecialchars($edu['year_completed'], ENT_QUOTES, 'UTF-8') ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="wtAddEduBtn" style="margin-top:.6rem;">
                                <i class="bi bi-plus-lg me-1"></i> Add School
                            </button>
                        </div>
                    </div>

                    <div class="wt-nav">
                        <button type="button" class="btn btn-back" data-back="3"><i class="bi bi-arrow-left"></i> Previous</button>
                        <span class="spacer"></span>
                        <span class="wt-nav-hint"><i class="bi bi-info-circle"></i> Next: <strong>Enrollment &amp; Documents</strong></span>
                        <button type="button" class="btn btn-next" data-next="5">Next <i class="bi bi-arrow-right"></i></button>
                    </div>
                </div>

                <!-- ═══════════ STEP 5 — ENROLLMENT ═══════════ -->
                <div class="wt-step<?= $initial_step === 5 ? ' active' : '' ?>" data-step="5">
                    <div class="wt-card">
                        <div class="wt-card-head">
                            <div class="wt-ch-icon"><i class="bi bi-clipboard-list"></i></div>
                            <div class="wt-ch-text">
                                <span class="wt-ch-badge">Step 5 of 5</span>
                                <h3>Enrollment Details</h3>
                                <p class="wt-ch-sub">Grade level, strand, program, and section</p>
                            </div>
                        </div>
                        <div class="wt-card-body">
                            <div class="wt-row">
                                <div class="wt-col-3 wt-field<?= isset($errors['school_year']) ? ' has-error' : '' ?>">
                                    <label for="school_year">School Year <span class="req">*</span></label>
                                    <select id="school_year" name="school_year" required>
                                        <?php foreach ($SY_OPTIONS as $opt): ?>
                                            <option value="<?= $opt ?>"<?= $cur_sy === $opt ? ' selected' : '' ?>><?= $opt ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?= $err('school_year') ?>
                                </div>
                                <div class="wt-col-3 wt-field<?= isset($errors['grade_level']) ? ' has-error' : '' ?>">
                                    <label for="grade_level">Grade Level <span class="req">*</span></label>
                                    <select id="grade_level" name="grade_level" required>
                                        <option value="">— Select —</option>
                                        <option value="Grade 11"<?= $is('grade_level', 'Grade 11') ?>>Grade 11</option>
                                        <option value="Grade 12"<?= $is('grade_level', 'Grade 12') ?>>Grade 12</option>
                                    </select>
                                    <?= $err('grade_level') ?>
                                </div>
                                <div class="wt-col-3 wt-field<?= isset($errors['term']) ? ' has-error' : '' ?>">
                                    <label for="term">Term <span class="req">*</span></label>
                                    <select id="term" name="term" required>
                                        <option value="">— Select —</option>
                                        <option value="1st Term"<?= $is('term', '1st Term') ?>>1st Term</option>
                                        <option value="2nd Term"<?= $is('term', '2nd Term') ?>>2nd Term</option>
                                        <option value="3rd Term"<?= $is('term', '3rd Term') ?>>3rd Term</option>
                                    </select>
                                    <?= $err('term') ?>
                                </div>
                                <div class="wt-col-3 wt-field">
                                    <label for="track">Track <span class="opt">Auto</span></label>
                                    <input type="text" id="track" name="track" value="TECHPRO ELECTIVES" readonly>
                                </div>
                                <div class="wt-col-6 wt-field<?= isset($errors['strand']) ? ' has-error' : '' ?>">
                                    <label for="strand">Strand <span class="req">*</span></label>
                                    <select id="strand" name="strand" required>
                                        <option value="">— Select —</option>
                                        <?php foreach (array_keys($STRAND_PROGRAMS) as $s): ?>
                                            <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>"<?= $cur_strand === $s ? ' selected' : '' ?>><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?= $err('strand') ?>
                                </div>
                                <div class="wt-col-6 wt-field<?= isset($errors['program']) ? ' has-error' : '' ?>">
                                    <label for="program">Program <span class="req">*</span></label>
                                    <select id="program" name="program" required>
                                        <option value="">— Select —</option>
                                        <?php if ($cur_strand !== '' && isset($STRAND_PROGRAMS[$cur_strand])): ?>
                                            <?php foreach ($STRAND_PROGRAMS[$cur_strand] as $p): ?>
                                                <option value="<?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?>"<?= $cur_program === $p ? ' selected' : '' ?>><?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <?= $err('program') ?>
                                </div>
                                <div class="wt-col-6 wt-field">
                                    <label for="section">Section <span class="opt">Auto-assigned if blank</span></label>
                                    <input type="text" id="section" name="section" maxlength="50" value="<?= $v('section') ?>" placeholder="Leave blank to auto-assign">
                                </div>
                                <div class="wt-col-6 wt-field">
                                    <label>&nbsp;</label>
                                    <label style="display:flex; align-items:center; gap:.6rem; text-transform:none; letter-spacing:0; font-size:.85rem; font-weight:600; color:var(--text); cursor:pointer; padding:.65rem .85rem; background:var(--surface-2); border:1.5px solid var(--border); border-radius:9px;">
                                        <input type="checkbox" name="is_transferred" id="is_transferred" value="1" style="width:16px;height:16px;accent-color:var(--step-accent);"<?= !empty($old['is_transferred']) ? ' checked' : '' ?>>
                                        Transferred IN from another school
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="wt-card">
                        <div class="wt-card-head">
                            <div class="wt-ch-icon"><i class="bi bi-folder-open"></i></div>
                            <div class="wt-ch-text">
                                <h3>Entrance Documents</h3>
                                <p class="wt-ch-sub">Tick to mark presented · upload a copy of each</p>
                            </div>
                        </div>
                        <div class="wt-card-body">
                            <div class="wt-doc-grid">
                                <?php foreach ($doc_options as $doc_name => $doc_key):
                                    $checked = in_array($doc_name, (array)($old['entrance_data'] ?? []), true);
                                ?>
                                    <div class="wt-doc-card<?= $checked ? ' is-checked' : '' ?>" data-doc-key="<?= $doc_key ?>">
                                        <label class="wt-doc-head" for="doc_<?= $doc_key ?>">
                                            <input type="checkbox" name="entrance_data[]" value="<?= htmlspecialchars($doc_name, ENT_QUOTES, 'UTF-8') ?>" id="doc_<?= $doc_key ?>"<?= $checked ? ' checked' : '' ?>>
                                            <div class="wt-doc-icon"><i class="bi bi-file-earmark-text"></i></div>
                                            <div class="wt-doc-body">
                                                <div class="wt-doc-name"><?= htmlspecialchars($doc_name, ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="wt-doc-hint">Tick if presented · upload a scanned copy</div>
                                            </div>
                                            <span class="wt-doc-status"><?= $checked ? 'Presented' : 'Not yet' ?></span>
                                        </label>
                                        <div class="wt-doc-file">
                                            <input type="file" name="entrance_files[<?= $doc_key ?>]" id="file_<?= $doc_key ?>" accept=".pdf,.jpg,.jpeg,.png">
                                            <span class="wt-doc-file-meta" data-filename>PDF, JPG, or PNG · max 5 MB</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?= $err('entrance_data') ?>
                            <?= $err('upload') ?>
                        </div>
                    </div>

                    <div class="wt-approve-box">
                        <div class="wt-approve-icon"><i class="bi bi-lightning-charge-fill"></i></div>
                        <div class="wt-approve-body">
                            <div class="wt-approve-head">
                                <input type="checkbox" name="auto_approve" id="auto_approve" value="1"<?= !empty($old['auto_approve']) ? ' checked' : '' ?>>
                                <label for="auto_approve" class="wt-approve-title">Auto-approve this enrollment</label>
                            </div>
                            <div class="wt-approve-sub">
                                Skip the pending queue and set the enrollment to <strong>Active</strong> immediately. Leave unchecked to route through standard review.
                            </div>
                            <div class="wt-approve-checks">
                                <span><i class="bi bi-check2-circle"></i> Student appears in the directory right away</span>
                                <span><i class="bi bi-check2-circle"></i> Section is assigned automatically if left blank</span>
                                <span><i class="bi bi-check2-circle"></i> Confirmation email sent if an address is provided</span>
                            </div>
                        </div>
                    </div>

                    <div class="wt-nav">
                        <button type="button" class="btn btn-back" data-back="4"><i class="bi bi-arrow-left"></i> Previous</button>
                        <span class="spacer"></span>
                        <button type="submit" class="btn btn-submit" id="wtSubmitBtn">
                            <i class="bi bi-check2-circle"></i> Submit Enrollment
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const WT_STRAND_PROGRAMS = <?= json_encode($STRAND_PROGRAMS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

(function () {
    'use strict';
    const form = document.getElementById('wtForm');
    if (!form) return;

    const TOTAL_STEPS = 5;
    const steps     = form.querySelectorAll('.wt-step');
    const indicators = document.querySelectorAll('.wizard-step-indicator');
    let currentStep = <?= (int)$initial_step ?>;
    const visited   = new Set([currentStep]);
    const passed    = new Set();

    function clearErrorsIn(step) {
        const node = form.querySelector('[data-step="' + step + '"]');
        if (node) node.querySelectorAll('.wt-field.has-error').forEach(el => el.classList.remove('has-error'));
    }

    function digitsOnly(s) { return String(s || '').replace(/\D/g, ''); }

    function getStepErrors(step) {
        clearErrorsIn(step);
        const missing = [];

        function markError(id, msg) {
            const el = document.getElementById(id);
            if (el) {
                const field = el.closest('.wt-field');
                if (field) field.classList.add('has-error');
            }
            missing.push(msg);
        }

        if (step === 1) {
            const lrn = document.getElementById('lrn');
            if (lrn) {
                const d = digitsOnly(lrn.value);
                if (d.length === 0) markError('lrn', 'LRN is required');
                else if (d.length !== 12) markError('lrn', 'LRN must be exactly 12 digits');
                else lrn.value = d;
            }
            ['last_name', 'first_name', 'sex', 'birth_date'].forEach(id => {
                const el = document.getElementById(id);
                if (el && !String(el.value || '').trim()) markError(id, id.replace('_', ' ') + ' is required');
            });
        }
        if (step === 2) {
            const el = document.getElementById('is_4ps');
            if (el && String(el.value) === '') markError('is_4ps', 'Please answer the CCT/4Ps question');
        }
        if (step === 4) {
            // optional step
        }
        if (step === 5) {
            ['school_year', 'grade_level', 'term', 'strand', 'program'].forEach(id => {
                const el = document.getElementById(id);
                if (el && !String(el.value || '').trim()) markError(id, id.replace('_', ' ') + ' is required');
            });
            const checked = form.querySelectorAll('input[name="entrance_data[]"]:checked');
            if (checked.length === 0) missing.push('Select at least one entrance document');
        }

        return missing;
    }

    function refreshIndicators() {
        indicators.forEach(el => {
            const s = parseInt(el.dataset.step, 10);
            el.classList.remove('active', 'completed', 'invalid');
            el.removeAttribute('aria-current');
            if (s === currentStep) {
                el.classList.add('active');
                el.setAttribute('aria-current', 'step');
            } else if (passed.has(s)) {
                const errs = getStepErrors(s);
                el.classList.add(errs.length === 0 ? 'completed' : 'invalid');
            }
            el.disabled = !(s <= currentStep || visited.has(s));
        });
    }

    function showStep(n) {
        if (n < 1 || n > TOTAL_STEPS) return;
        currentStep = n;
        visited.add(n);
        steps.forEach(el => el.classList.toggle('active', parseInt(el.dataset.step, 10) === n));
        refreshIndicators();
        // Focus first input on the new step for keyboard users
        const active = form.querySelector('.wt-step.active');
        const firstField = active ? active.querySelector('input:not([type=hidden]):not([readonly]), select, textarea') : null;
        if (firstField) setTimeout(() => firstField.focus({ preventScroll: true }), 150);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    form.addEventListener('click', e => {
        const next = e.target.closest('.btn-next');
        const back = e.target.closest('.btn-back');
        if (next) {
            const target = parseInt(next.dataset.next, 10);
            const errs = getStepErrors(target - 1);
            if (errs.length) {
                alert('Please fix:\n\n• ' + errs.join('\n• '));
                return;
            }
            passed.add(target - 1);
            showStep(target);
        }
        if (back && !back.classList.contains('placeholder')) {
            showStep(parseInt(back.dataset.back, 10));
        }
    });

    indicators.forEach(el => {
        el.addEventListener('click', () => {
            const s = parseInt(el.dataset.step, 10);
            if (s <= currentStep || visited.has(s)) showStep(s);
        });
    });

    form.addEventListener('submit', e => {
        let firstBad = null, firstErrs = null;
        for (let s = 1; s <= TOTAL_STEPS; s++) {
            const errs = getStepErrors(s);
            if (errs.length && firstBad === null) { firstBad = s; firstErrs = errs; }
        }
        if (firstBad !== null) {
            e.preventDefault();
            showStep(firstBad);
            alert('Please fix:\n\n• ' + firstErrs.join('\n• '));
            return;
        }
        const btn = document.getElementById('wtSubmitBtn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting…'; }
    });

    // Strand → Program cascade
    const strandEl = document.getElementById('strand');
    const programEl = document.getElementById('program');
    if (strandEl && programEl) {
        strandEl.addEventListener('change', () => {
            const s = strandEl.value;
            programEl.innerHTML = '<option value="">— Select —</option>';
            if (WT_STRAND_PROGRAMS[s]) {
                WT_STRAND_PROGRAMS[s].forEach(p => {
                    const o = document.createElement('option');
                    o.value = p; o.textContent = p;
                    programEl.appendChild(o);
                });
            }
        });
    }

    // Age auto-calc
    const bd = document.getElementById('birth_date');
    const ageEl = document.getElementById('age');
    if (bd && ageEl) {
        const calc = () => {
            if (!bd.value) return;
            const b = new Date(bd.value), t = new Date();
            let a = t.getFullYear() - b.getFullYear();
            if (t.getMonth() < b.getMonth() || (t.getMonth() === b.getMonth() && t.getDate() < b.getDate())) a--;
            ageEl.value = a;
        };
        bd.addEventListener('change', calc);
        calc();
    }

    // Add education row
    const addEdu = document.getElementById('wtAddEduBtn');
    if (addEdu) {
        addEdu.addEventListener('click', () => {
            const c = document.getElementById('wt-edu-container');
            const div = document.createElement('div');
            div.className = 'wt-row wt-edu-entry';
            div.style.marginBottom = '.85rem';
            div.innerHTML = `
                <div class="wt-col-3 wt-field"><label>Level</label>
                    <select name="edu_level[]">
                        <option value="Elementary">Elementary</option>
                        <option value="JHS">Junior High School</option>
                        <option value="Transferred">Transferred</option>
                    </select></div>
                <div class="wt-col-3 wt-field"><label>School Name</label><input type="text" name="school_name[]" maxlength="200"></div>
                <div class="wt-col-3 wt-field"><label>School Address</label><input type="text" name="school_address[]" maxlength="300"></div>
                <div class="wt-col-3 wt-field"><label>Year Completed</label><input type="text" name="year_completed[]" maxlength="20" inputmode="numeric"></div>`;
            c.appendChild(div);
            div.querySelector('input')?.focus();
        });
    }

    // Doc card: checkbox highlight + file name display
    document.querySelectorAll('.wt-doc-card').forEach(card => {
        const cb = card.querySelector('input[type="checkbox"]');
        const status = card.querySelector('.wt-doc-status');
        if (cb) {
            cb.addEventListener('change', () => {
                card.classList.toggle('is-checked', cb.checked);
                if (status) status.textContent = cb.checked ? 'Presented' : 'Not yet';
            });
        }
        const fileInp = card.querySelector('input[type="file"]');
        const meta = card.querySelector('[data-filename]');
        if (fileInp && meta) {
            fileInp.addEventListener('change', () => {
                const hasFile = fileInp.files && fileInp.files.length > 0;
                card.classList.toggle('has-file', hasFile);
                if (hasFile) {
                    meta.textContent = '✓ ' + fileInp.files[0].name;
                    meta.classList.add('has-file');
                } else {
                    meta.textContent = 'PDF, JPG, or PNG · max 5 MB';
                    meta.classList.remove('has-file');
                }
            });
        }
    });

    showStep(currentStep);
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>