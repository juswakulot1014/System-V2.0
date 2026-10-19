<?php
/**
 * shared/partials/head.php
 *
 * Outputs <!DOCTYPE html> … <body> plus all shared CSS and Bootstrap JS.
 * Bootstrap JS is loaded here (before any page-specific script) so pages
 * can call `new bootstrap.Modal(...)` from their inline scripts.
 *
 * Optional variables in scope when included:
 *   $page_title  string   — for <title>.
 *   $page_head   string   — extra tags before </head>.
 *   $page_css    string   — inline <style> block for page-specific rules.
 */

$__theme = (isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark')
    ? 'dark'
    : 'light';

$__title      = isset($page_title) && $page_title !== '' ? $page_title : 'USAT Admin';
$__extra_head = $page_head ?? '';
$__extra_css  = $page_css  ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $__theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($__title, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <style>
        :root {
            --bg: #f4f6fb; --surface: #ffffff; --surface-2: #f8fafc;
            --text: #0f172a; --text-2: #64748b; --text-3: #94a3b8;
            --border: #e5e7eb; --border-strong: #d1d5db;
            --accent: #4f46e5; --accent-2: #6366f1; --accent-soft: #eef2ff;
            --green: #059669; --green-soft: #d1fae5;
            --red: #dc2626; --red-soft: #fee2e2;
            --amber: #d97706; --amber-soft: #fef3c7;
            --purple: #7c3aed; --purple-soft: #ede9fe;
            --blue: #2563eb; --pink: #ec4899;
            --shadow-xs: 0 1px 2px rgba(15,23,42,0.04);
            --shadow-sm: 0 1px 3px rgba(15,23,42,0.06), 0 1px 2px rgba(15,23,42,0.04);
            --shadow-md: 0 4px 12px rgba(15,23,42,0.06);
            --shadow-lg: 0 12px 32px rgba(15,23,42,0.10);
            --radius-sm: 8px; --radius: 12px; --radius-lg: 16px; --radius-xl: 20px;
            --sidebar-w: 264px;
        }

        [data-bs-theme="dark"] {
            --bg: #0b1220; --surface: #131c2e; --surface-2: #1a2439;
            --text: #f1f5f9; --text-2: #94a3b8; --text-3: #64748b;
            --border: #1f2a44; --border-strong: #2d3b57;
            --accent: #818cf8; --accent-2: #6366f1; --accent-soft: #1e2140;
            --green-soft: #052e24; --red-soft: #3f1517; --amber-soft: #3d2a08; --purple-soft: #2e1a54;
            --shadow-xs: 0 1px 2px rgba(0,0,0,0.3);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.35);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 12px 32px rgba(0,0,0,0.55);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg); color: var(--text); min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .sidebar {
            position: fixed; left: 0; top: 0; bottom: 0; width: var(--sidebar-w);
            background: var(--surface); border-right: 1px solid var(--border);
            z-index: 200; display: flex; flex-direction: column;
            transition: transform .28s cubic-bezier(.4,0,.2,1);
        }
        .sidebar-brand {
            padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: .75rem;
        }
        .sidebar-brand img {
            width: 38px; height: 38px; border-radius: 10px; object-fit: cover;
            box-shadow: 0 0 0 3px var(--accent-soft);
        }
        .sidebar-brand .brand-text { display: flex; flex-direction: column; line-height: 1.15; }
        .sidebar-brand .brand-name { font-weight: 700; font-size: .95rem; color: var(--text); }
        .sidebar-brand .brand-sub {
            font-size: .68rem; color: var(--text-2); text-transform: uppercase;
            letter-spacing: .6px; font-weight: 600;
        }
        .sidebar-nav { flex: 1; padding: .75rem .75rem 1rem; overflow-y: auto; }
        .sidebar-nav .nav-label {
            font-size: .65rem; font-weight: 700; color: var(--text-3);
            text-transform: uppercase; letter-spacing: .8px;
            padding: .75rem .75rem .35rem;
        }
        .sidebar-nav a {
            display: flex; align-items: center; gap: .75rem;
            padding: .6rem .75rem; border-radius: 10px;
            color: var(--text-2); text-decoration: none;
            font-weight: 500; font-size: .875rem;
            transition: background .15s, color .15s;
            margin-bottom: .1rem;
        }
        .sidebar-nav a:hover { background: var(--surface-2); color: var(--text); }
        .sidebar-nav a.active {
            background: var(--accent); color: #fff;
            box-shadow: 0 4px 12px -4px rgba(79,70,229,.5);
        }
        .sidebar-nav a.active:hover { background: var(--accent); }
        .sidebar-nav a i { font-size: 1.05rem; width: 20px; text-align: center; flex-shrink: 0; }
        .sidebar-footer { padding: .75rem; border-top: 1px solid var(--border); }

        .main-content {
            margin-left: var(--sidebar-w);
            padding: 1.5rem clamp(1rem,2.5vw,2rem) 2rem;
            min-height: 100vh; max-width: 1600px;
        }
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 1.5rem; gap: 1rem; flex-wrap: wrap;
        }
        .topbar h2 { font-size: 1.35rem; font-weight: 700; letter-spacing: -.02em; margin: 0; }
        .topbar .sub { font-size: .85rem; color: var(--text-2); margin: 0; }
        .menu-toggle {
            display: none; width: 40px; height: 40px; border-radius: 10px;
            border: 1px solid var(--border); background: var(--surface);
            color: var(--text); cursor: pointer;
            align-items: center; justify-content: center;
            transition: background .15s;
        }
        .menu-toggle:hover { background: var(--surface-2); }

        .stat-grid {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem; margin-bottom: 1.5rem;
        }
        .stat-card {
            position: relative; background: var(--surface);
            border-radius: var(--radius-lg); border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            padding: 1.25rem 1.25rem 1.25rem 1.5rem;
            display: flex; align-items: center; gap: 1rem;
            transition: transform .2s, box-shadow .2s, border-color .2s;
            overflow: hidden;
        }
        .stat-card::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
            background: var(--accent);
        }
        .stat-card.tone-blue::before { background: var(--accent); }
        .stat-card.tone-green::before { background: var(--green); }
        .stat-card.tone-purple::before { background: var(--purple); }
        .stat-card.tone-amber::before { background: var(--amber); }
        .stat-card.tone-red::before { background: var(--red); }
        .stat-card:hover {
            transform: translateY(-3px); box-shadow: var(--shadow-lg);
            border-color: var(--border-strong);
        }
        .stat-icon {
            width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem; flex-shrink: 0;
            background: var(--accent-soft); color: var(--accent);
        }
        .tone-green  .stat-icon { background: var(--green-soft); color: var(--green); }
        .tone-purple .stat-icon { background: var(--purple-soft); color: var(--purple); }
        .tone-amber  .stat-icon { background: var(--amber-soft); color: var(--amber); }
        .tone-red    .stat-icon { background: var(--red-soft); color: var(--red); }
        .stat-info { min-width: 0; flex: 1; }
        .stat-info .stat-value {
            font-size: 1.65rem; font-weight: 700; line-height: 1.1;
            letter-spacing: -.02em; font-variant-numeric: tabular-nums;
        }
        .stat-info .stat-label {
            font-size: .7rem; color: var(--text-2); text-transform: uppercase;
            letter-spacing: .6px; margin-top: .25rem; font-weight: 600;
        }
        .stat-info .stat-hint {
            font-size: .72rem; color: var(--text-3); margin-top: .35rem; font-weight: 500;
        }

        .card {
            background: var(--surface); border-radius: var(--radius-lg);
            border: 1px solid var(--border); box-shadow: var(--shadow-sm);
            margin-bottom: 1.25rem; overflow: hidden;
        }
        .card-header {
            padding: .9rem 1.25rem; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap; background: var(--surface);
        }
        .card-header h3 {
            margin: 0; font-size: .9rem; font-weight: 600;
            display: flex; align-items: center; gap: .5rem; color: var(--text);
        }
        .card-header h3 i { color: var(--accent); font-size: 1rem; }
        .card-header .hint { font-size: .75rem; color: var(--text-2); font-weight: 500; }
        .card-body { padding: 1.25rem; }
        .card-body.no-padding { padding: 0; }

        .table-scroll {
            overflow: auto; max-height: 600px;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
        }
        .table-admin { width: 100%; border-collapse: separate; border-spacing: 0; }
        .table-admin thead th {
            background: var(--surface-2);
            font-size: .68rem; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: .6px;
            padding: .7rem 1rem; text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap; position: sticky; top: 0; z-index: 2;
        }
        .table-admin tbody td {
            padding: .7rem 1rem; border-bottom: 1px solid var(--border);
            font-size: .85rem; vertical-align: middle;
            transition: background .12s;
        }
        .table-admin tbody tr:hover td {
            background: color-mix(in srgb, var(--accent) 4%, transparent);
        }
        .table-admin tbody tr:last-child td { border-bottom: none; }

        .cell-id { font-variant-numeric: tabular-nums; font-weight: 600; font-size: .82rem; color: var(--text); }
        .cell-strong { font-weight: 600; color: var(--text); }
        .cell-muted { color: var(--text-2); font-size: .82rem; }
        .cell-accent { color: var(--accent); font-weight: 600; }
        .cell-num { color: var(--text-3); font-size: .78rem; font-variant-numeric: tabular-nums; }
        .cell-center { text-align: center; }
        .cell-male { color: var(--blue); font-weight: 600; font-variant-numeric: tabular-nums; }
        .cell-female { color: var(--pink); font-weight: 600; font-variant-numeric: tabular-nums; }

        .pill {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .25rem .65rem; border-radius: 999px;
            font-size: .72rem; font-weight: 600;
            border: 1px solid transparent;
        }
        .pill::before {
            content: ''; width: 6px; height: 6px; border-radius: 50%;
            background: currentColor; opacity: .9;
        }
        .pill.success { background: var(--green-soft); color: var(--green); }
        .pill.warning { background: var(--amber-soft); color: var(--amber); }
        .pill.danger  { background: var(--red-soft);   color: var(--red); }
        .pill.info    { background: var(--accent-soft); color: var(--accent); }
        .pill.purple  { background: var(--purple-soft); color: var(--purple); }
        .pill.muted   { background: var(--surface-2); color: var(--text-2); }

        .btn { font-weight: 500; border-radius: 9px; font-size: .82rem; transition: all .15s; }
        .btn-primary { background: var(--accent); border-color: var(--accent); }
        .btn-primary:hover { background: var(--accent-2); border-color: var(--accent-2); }
        .btn-outline-primary { color: var(--accent); border-color: var(--border-strong); }
        .btn-outline-primary:hover { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn-outline-secondary { color: var(--text-2); border-color: var(--border); }
        .btn-outline-secondary:hover { background: var(--surface-2); color: var(--text); border-color: var(--border-strong); }
        .btn-outline-success { color: var(--green); border-color: color-mix(in srgb, var(--green) 35%, transparent); }
        .btn-outline-success:hover { background: var(--green); color: #fff; border-color: var(--green); }
        .btn-outline-warning { color: var(--amber); border-color: color-mix(in srgb, var(--amber) 40%, transparent); }
        .btn-outline-warning:hover { background: var(--amber); color: #fff; border-color: var(--amber); }
        .btn-outline-danger { color: var(--red); border-color: color-mix(in srgb, var(--red) 35%, transparent); }
        .btn-outline-danger:hover { background: var(--red); color: #fff; border-color: var(--red); }
        .btn-success { background: var(--green); border-color: var(--green); }
        .btn-success:hover { background: #047857; border-color: #047857; }
        .btn-icon {
            width: 32px; height: 32px; padding: 0;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 9px;
        }
        .btn-xs { padding: .25rem .6rem; font-size: .72rem; border-radius: 8px; }
        .theme-btn {
            width: 38px; height: 38px; border-radius: 10px;
            border: 1px solid var(--border); background: var(--surface);
            color: var(--text-2); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all .15s;
        }
        .theme-btn:hover { background: var(--accent); color: #fff; border-color: var(--accent); }

        .form-label {
            display: block; font-size: .7rem; font-weight: 700;
            color: var(--text-2); text-transform: uppercase;
            letter-spacing: .5px; margin-bottom: .35rem;
        }
        .form-control, .form-select {
            background: var(--surface-2); border: 1px solid var(--border);
            border-radius: 10px; padding: .5rem .8rem; font-size: .85rem;
            color: var(--text); font-family: inherit;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--accent); background: var(--surface);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
            outline: none;
        }
        .form-text { font-size: .72rem; color: var(--text-3); }

        .filter-bar {
            display: grid; gap: .75rem; align-items: end; width: 100%;
        }
        .filter-bar .field {
            display: flex; flex-direction: column; gap: .3rem; min-width: 0;
        }
        .filter-bar label {
            font-size: .68rem; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: .5px;
        }
        .filter-bar input, .filter-bar select {
            background: var(--surface-2); border: 1px solid var(--border);
            border-radius: 10px; padding: .5rem .8rem; font-size: .85rem;
            color: var(--text); font-family: inherit; width: 100%;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .filter-bar input::placeholder { color: var(--text-3); }
        .filter-bar input:focus, .filter-bar select:focus {
            border-color: var(--accent); background: var(--surface);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
            outline: none;
        }
        .filter-bar .actions { display: flex; gap: .5rem; flex-wrap: nowrap; }

        .empty { padding: 3rem 1.5rem; text-align: center; color: var(--text-2); }
        .empty .empty-icon {
            width: 64px; height: 64px; border-radius: 50%;
            background: var(--surface-2); color: var(--text-3);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.6rem; margin-bottom: 1rem;
        }
        .empty .empty-title { font-weight: 600; color: var(--text); margin-bottom: .25rem; }
        .empty .empty-sub { font-size: .85rem; color: var(--text-2); }

        .flash {
            display: flex; align-items: center; gap: .65rem;
            padding: .75rem 1rem; border-radius: var(--radius);
            margin-bottom: 1rem; font-size: .88rem; font-weight: 500;
            border: 1px solid transparent;
        }
        .flash.success { background: var(--green-soft); color: var(--green); border-color: color-mix(in srgb, var(--green) 25%, transparent); }
        .flash.error   { background: var(--red-soft);   color: var(--red);   border-color: color-mix(in srgb, var(--red) 25%, transparent); }
        .flash.info    { background: var(--accent-soft); color: var(--accent); border-color: color-mix(in srgb, var(--accent) 25%, transparent); }
        .flash .btn-close { margin-left: auto; opacity: .6; }

        @media (min-width: 1440px) {
            .stat-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        }
        @media (max-width: 1100px) {
            .filter-bar { grid-template-columns: 1fr 1fr; }
            .filter-bar .actions { grid-column: span 2; justify-content: flex-end; }
        }
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .menu-toggle { display: flex; }
        }
        @media (max-width: 640px) {
            .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
            .stat-card { padding: 1rem 1rem 1rem 1.1rem; gap: .75rem; }
            .stat-icon { width: 38px; height: 38px; font-size: 1rem; }
            .stat-info .stat-value { font-size: 1.35rem; }
            .filter-bar { grid-template-columns: 1fr; }
            .filter-bar .actions { grid-column: span 1; }
            .card-body { padding: 1rem; }
        }

        <?= $__extra_css ?>
    </style>
    <?= $__extra_head ?>
</head>
<body>