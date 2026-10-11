<?php
// admin/pending_enrollments.php — Review queue for student submissions.
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';

if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard.php');
    exit;
}

// ---- Time helper ----
function time_ago(string $dt): string {
    $t = strtotime($dt);
    if ($t === false) return '—';
    $diff = time() - $t;
    if ($diff < 60)         return 'Just now';
    if ($diff < 3600)       return floor($diff / 60) . ' min ago';
    if ($diff < 86400)      return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800)     return floor($diff / 86400) . ' day' . (floor($diff / 86400) === 1 ? '' : 's') . ' ago';
    return date('M d, Y', $t);
}

// ====================== REQUIRED DOCUMENTS ======================
// Every enrollee is expected to submit these. The counter on the
// pending table shows "X of N" where N is the count below.
$REQUIRED_DOCS = [
    'Good Moral Certificate',
    'Junior High School Certificate (Front)',
    'Junior High School Certificate (Back)',
    'NSO/PSA Birth Certificate (Original)',
    '2 pcs 2×2 picture',
];
$REQUIRED_DOCS_COUNT = count($REQUIRED_DOCS);

// ---- Filters ----
$sy_filter    = trim($_GET['school_year'] ?? '');
$grade_filter = trim($_GET['grade_level'] ?? '');
$search       = trim($_GET['search'] ?? '');

$where  = ["e.status = 'Pending'"];
$params = [];
$types  = '';

if ($sy_filter !== '')    { $where[] = "e.school_year = ?"; $params[] = $sy_filter;    $types .= 's'; }
if ($grade_filter !== '') { $where[] = "e.grade_level = ?"; $params[] = $grade_filter; $types .= 's'; }
if ($search !== '') {
    $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT e.enrollment_id, e.student_id, e.school_year, e.grade_level, e.term,
           e.track, e.strand, e.program, e.section, e.enrolled_at,
           s.student_id_number, s.lrn, s.first_name, s.middle_name, s.last_name,
           s.ext_name, s.sex, s.birth_date, s.email, s.phone,
           (SELECT COUNT(DISTINCT d.document_name) FROM entrance_documents d
             WHERE d.student_id = s.student_id
               AND d.submitted = 1
               AND d.file_path IS NOT NULL
               AND (
                   d.document_name = 'Good Moral Certificate'
                   OR d.document_name = 'Junior High School Certificate (Front)'
                   OR d.document_name = 'Junior High School Certificate (Back)'
                   OR d.document_name = 'NSO/PSA Birth Certificate (Original)'
                   OR d.document_name LIKE '2 pcs%2×2%'
                   OR d.document_name LIKE '2 pcs%2x2%'
               )
           ) AS docs_uploaded,
           {$REQUIRED_DOCS_COUNT} AS docs_total
    FROM enrollment_form e
    INNER JOIN students_info s ON s.student_id = e.student_id
    $where_sql
    ORDER BY e.enrolled_at ASC
";
$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---- Stats ----
$stat_total    = count($pending);
$stat_withdocs = 0;
$stat_nodocs   = 0;
$stat_overdue  = 0;
foreach ($pending as $p) {
    if ((int)$p['docs_uploaded'] > 0) $stat_withdocs++;
    else                              $stat_nodocs++;
    if ((time() - strtotime($p['enrolled_at'])) > 3 * 86400) $stat_overdue++;
}

// Dropdown data
$sy_list    = $conn->query("SELECT DISTINCT school_year FROM enrollment_form WHERE status='Pending' ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC) ?: [];
$grade_list = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form WHERE status='Pending' ORDER BY grade_level ASC")->fetch_all(MYSQLI_ASSOC) ?: [];

$theme = ($_COOKIE['admin_theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
$csrf_token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pending Enrollments • USAT Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{
    --bg:#f4f6fb;--surface:#fff;--surface-2:#f8fafc;--text:#0f172a;--text-2:#64748b;
    --text-3:#94a3b8;--border:#e5e7eb;--border-strong:#d1d5db;--accent:#4f46e5;
    --accent-2:#6366f1;--accent-soft:#eef2ff;--green:#059669;--green-soft:#d1fae5;
    --red:#dc2626;--red-soft:#fee2e2;--amber:#d97706;--amber-soft:#fef3c7;
    --purple:#7c3aed;--purple-soft:#ede9fe;--sidebar-w:264px;
    --shadow-sm:0 1px 3px rgba(15,23,42,.06),0 1px 2px rgba(15,23,42,.04);
    --shadow-md:0 4px 12px rgba(15,23,42,.08);
    --shadow-lg:0 12px 32px rgba(15,23,42,.10);
}
[data-bs-theme="dark"]{
    --bg:#0b1220;--surface:#131c2e;--surface-2:#1a2439;--text:#f1f5f9;--text-2:#94a3b8;
    --text-3:#64748b;--border:#1f2a44;--border-strong:#2d3b57;--accent:#818cf8;
    --accent-2:#6366f1;--accent-soft:#1e2140;--green-soft:#052e24;--red-soft:#3f1517;
    --amber-soft:#3d2a08;--purple-soft:#2e1a54;
    --shadow-sm:0 1px 3px rgba(0,0,0,.35);
    --shadow-md:0 4px 12px rgba(0,0,0,.4);
    --shadow-lg:0 12px 32px rgba(0,0,0,.55);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}

/* ===== Sidebar ===== */
.sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:transform .28s}
.sidebar-brand{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem}
.sidebar-brand img{width:38px;height:38px;border-radius:10px;object-fit:cover;box-shadow:0 0 0 3px var(--accent-soft)}
.sidebar-brand .brand-name{font-weight:700;font-size:.95rem}
.sidebar-brand .brand-sub{font-size:.68rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;font-weight:600}
.sidebar-nav{flex:1;padding:.75rem .75rem 1rem;overflow-y:auto}
.sidebar-nav .nav-label{font-size:.65rem;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.8px;padding:.75rem .75rem .35rem}
.sidebar-nav a{display:flex;align-items:center;gap:.75rem;padding:.6rem .75rem;border-radius:10px;color:var(--text-2);text-decoration:none;font-weight:500;font-size:.875rem;transition:background .15s,color .15s;margin-bottom:.1rem}
.sidebar-nav a:hover{background:var(--surface-2);color:var(--text)}
.sidebar-nav a.active{background:var(--accent);color:#fff}
.sidebar-nav a i{font-size:1.05rem;width:20px;text-align:center;flex-shrink:0}
.sidebar-nav a .badge-pill{margin-left:auto;background:var(--amber);color:#fff;font-size:.68rem;padding:.15rem .5rem;border-radius:999px;font-weight:700}
.sidebar-footer{padding:.75rem;border-top:1px solid var(--border)}

/* ===== Main ===== */
.main-content{margin-left:var(--sidebar-w);padding:1.5rem clamp(1rem,2.5vw,2rem) 2rem;min-height:100vh;max-width:1500px}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap}
.topbar h2{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;margin:0}
.topbar .sub{font-size:.85rem;color:var(--text-2);margin:0}
.menu-toggle{display:none;width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;align-items:center;justify-content:center}

/* ===== Stat tiles ===== */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
.stat-card{position:relative;background:var(--surface);border-radius:16px;border:1px solid var(--border);box-shadow:var(--shadow-sm);padding:1.1rem 1.25rem 1.1rem 1.4rem;display:flex;align-items:center;gap:1rem;overflow:hidden;transition:transform .2s, box-shadow .2s, border-color .2s}
.stat-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent)}
.stat-card.tone-green::before{background:var(--green)}
.stat-card.tone-amber::before{background:var(--amber)}
.stat-card.tone-red::before{background:var(--red)}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-lg);border-color:var(--border-strong)}
.stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;background:var(--accent-soft);color:var(--accent)}
.tone-green .stat-icon{background:var(--green-soft);color:var(--green)}
.tone-amber .stat-icon{background:var(--amber-soft);color:var(--amber)}
.tone-red .stat-icon{background:var(--red-soft);color:var(--red)}
.stat-info{min-width:0;flex:1}
.stat-value{font-size:1.55rem;font-weight:700;letter-spacing:-.02em;font-variant-numeric:tabular-nums;line-height:1.1;color:var(--text)}
.stat-label{font-size:.7rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;margin-top:.2rem;font-weight:600}

/* ===== Cards ===== */
.card{background:var(--surface);border-radius:16px;border:1px solid var(--border);box-shadow:var(--shadow-sm);margin-bottom:1.25rem;overflow:hidden}
.card-header{padding:.9rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.card-header h3{margin:0;font-size:.9rem;font-weight:600;display:flex;align-items:center;gap:.5rem}
.card-header h3 i{color:var(--accent)}
.card-body{padding:1.25rem}.card-body.no-padding{padding:0}

/* ===== Filter bar ===== */
.filter-bar{display:grid;grid-template-columns:minmax(200px,1.5fr) 1fr 1fr auto;gap:.75rem;align-items:end}
.filter-bar .field{display:flex;flex-direction:column;gap:.3rem;min-width:0}
.filter-bar label{font-size:.68rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px}
.filter-bar input,.filter-bar select{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:.5rem .8rem;font-size:.85rem;color:var(--text);font-family:inherit;width:100%}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--accent);background:var(--surface);outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%, transparent)}
.filter-bar .actions{display:flex;gap:.5rem}

/* ===== Quick filter chips ===== */
.quick-filters{display:flex;gap:.5rem;flex-wrap:wrap;padding:0 1.25rem 1rem}
.chip{display:inline-flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:999px;border:1px solid var(--border);background:var(--surface-2);color:var(--text-2);font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s;font-family:inherit}
.chip:hover{background:var(--surface);color:var(--text);border-color:var(--border-strong)}
.chip.active{background:var(--accent);color:#fff;border-color:var(--accent);box-shadow:0 4px 12px -4px color-mix(in srgb,var(--accent) 50%, transparent)}
.chip .count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:18px;padding:0 .35rem;border-radius:999px;background:var(--surface);color:var(--text-2);font-size:.68rem;font-weight:700;line-height:1}
.chip.active .count{background:rgba(255,255,255,.25);color:#fff}
.chip.chip-green.active{background:var(--green);border-color:var(--green)}
.chip.chip-amber.active{background:var(--amber);border-color:var(--amber)}
.chip.chip-red.active{background:var(--red);border-color:var(--red)}

/* ===== Buttons ===== */
.btn{font-weight:500;border-radius:9px;font-size:.82rem}
.btn-primary{background:var(--accent);border-color:var(--accent)}
.btn-primary:hover{background:var(--accent-2);border-color:var(--accent-2)}
.btn-outline-secondary{color:var(--text-2);border-color:var(--border)}
.btn-outline-secondary:hover{background:var(--surface-2);color:var(--text)}
.btn-success{background:var(--green);border-color:var(--green)}
.btn-success:hover{background:#047857;border-color:#047857}
.btn-outline-danger{color:var(--red);border-color:color-mix(in srgb,var(--red) 35%,transparent)}
.btn-outline-danger:hover{background:var(--red);color:#fff}
.btn-xs{padding:.3rem .7rem;font-size:.75rem;border-radius:8px}
.theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text-2);cursor:pointer;display:flex;align-items:center;justify-content:center}
.theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

/* ===== Table ===== */
.table-scroll{overflow-x:auto}
.table-admin{width:100%;border-collapse:separate;border-spacing:0}
.table-admin thead th{background:var(--surface-2);font-size:.68rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;padding:.7rem 1rem;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
.table-admin tbody td{padding:.85rem 1rem;border-bottom:1px solid var(--border);font-size:.85rem;vertical-align:middle;background:var(--surface)}
.table-admin tbody tr:hover td{background:color-mix(in srgb,var(--accent) 4%, transparent)}
.table-admin tbody tr:last-child td{border-bottom:none}
.table-admin tbody tr.row-ready td:first-child{box-shadow:inset 4px 0 0 var(--green)}
.table-admin tbody tr.row-nodocs td:first-child{box-shadow:inset 4px 0 0 var(--amber)}

/* ===== Avatar ===== */
.student-cell{display:flex;align-items:center;gap:.7rem;min-width:0}
.student-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;flex-shrink:0;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 15%, transparent)}
.student-info{min-width:0}
.student-name{font-weight:600;color:var(--text);font-size:.87rem;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.student-lrn{font-size:.72rem;color:var(--text-2);font-variant-numeric:tabular-nums}

.cell-strong{font-weight:600;color:var(--text)}
.cell-muted{color:var(--text-2);font-size:.8rem}
.cell-num{font-variant-numeric:tabular-nums;font-weight:600}
.cell-center{text-align:center}

/* ===== Pills ===== */
.pill{display:inline-flex;align-items:center;gap:.35rem;padding:.22rem .65rem;border-radius:999px;font-size:.7rem;font-weight:600;border:1px solid transparent;white-space:nowrap}
.pill.warning{background:var(--amber-soft);color:var(--amber);border-color:color-mix(in srgb,var(--amber) 22%,transparent)}
.pill.success{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb,var(--green) 22%,transparent)}
.pill.danger {background:var(--red-soft);color:var(--red);border-color:color-mix(in srgb,var(--red) 22%,transparent)}
.pill.info   {background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb,var(--accent) 22%,transparent)}
.pill.muted  {background:var(--surface-2);color:var(--text-2);border-color:var(--border)}

/* ===== Doc progress indicator ===== */
.doc-progress{display:flex;flex-direction:column;gap:.3rem;min-width:90px}
.doc-progress .doc-bar{width:100%;height:6px;border-radius:999px;background:var(--surface-2);overflow:hidden}
.doc-progress .doc-bar-fill{height:100%;border-radius:999px;transition:width .3s ease}
.doc-progress .doc-bar-fill.fill-full{background:var(--green)}
.doc-progress .doc-bar-fill.fill-partial{background:var(--accent)}
.doc-progress .doc-bar-fill.fill-empty{background:var(--amber)}
.doc-progress .doc-label{font-size:.7rem;font-weight:600;color:var(--text-2);font-variant-numeric:tabular-nums;display:flex;align-items:center;gap:.3rem}
.doc-progress .doc-label.ok{color:var(--green)}
.doc-progress .doc-label.warn{color:var(--amber)}

/* ===== Time cell ===== */
.time-cell{white-space:nowrap}
.time-cell .rel{font-size:.82rem;font-weight:600;color:var(--text)}
.time-cell .rel.overdue{color:var(--red)}
.time-cell .abs{font-size:.72rem;color:var(--text-2);font-variant-numeric:tabular-nums}

/* ===== Flash ===== */
.flash{display:flex;align-items:center;gap:.65rem;padding:.75rem 1rem;border-radius:12px;margin-bottom:1rem;font-size:.88rem;font-weight:500;border:1px solid transparent}
.flash.success{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb,var(--green) 25%,transparent)}
.flash.error{background:var(--red-soft);color:var(--red);border-color:color-mix(in srgb,var(--red) 25%,transparent)}
.flash .btn-close{margin-left:auto;opacity:.6}

/* ===== Empty ===== */
.empty{padding:4rem 1.5rem;text-align:center;color:var(--text-2)}
.empty .empty-icon{width:72px;height:72px;border-radius:50%;background:var(--green-soft);color:var(--green);display:inline-flex;align-items:center;justify-content:center;font-size:2rem;margin-bottom:1rem}
.empty .empty-title{font-weight:700;font-size:1.05rem;color:var(--text);margin-bottom:.35rem}
.empty .empty-sub{font-size:.88rem}

/* ===== Modals (base) ===== */
.modal-content{border-radius:16px;border:none}
.modal-header{padding:1rem 1.25rem;border-bottom:1px solid var(--border)}
.modal-body{padding:1.25rem}
.modal-footer{padding:.85rem 1.25rem;border-top:1px solid var(--border)}
.form-label{font-size:.78rem;font-weight:600;color:var(--text-2);margin-bottom:.35rem}
.form-control,.form-select{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:.5rem .8rem;font-size:.85rem;color:var(--text)}
.form-control:focus,.form-select:focus{border-color:var(--accent);background:var(--surface);outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%, transparent)}
.form-text{font-size:.72rem;color:var(--text-3)}

/* ============================================================
   Review Modal
   ============================================================ */
.review-modal .modal-content{border-radius:18px;overflow:hidden}
.review-modal .modal-dialog{max-width:720px}

.review-hero{padding:1.5rem 1.5rem 1.25rem;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;position:relative;overflow:hidden}
.review-hero::after{content:'';position:absolute;right:-40px;top:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.08)}
.review-hero-inner{position:relative;z-index:1;display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
.review-hero .close-btn{position:absolute;top:.75rem;right:.75rem;z-index:2;width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.2);border:none;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s}
.review-hero .close-btn:hover{background:rgba(255,255,255,.35)}
.review-avatar{width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.4);display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;flex-shrink:0;backdrop-filter:blur(4px)}
.review-hero-info{min-width:0;flex:1}
.review-hero-info h5{font-size:1.15rem;font-weight:700;margin:0 0 .35rem;letter-spacing:-.01em;line-height:1.2}
.review-hero-chips{display:flex;gap:.35rem;flex-wrap:wrap}
.review-hero-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .7rem;border-radius:999px;background:rgba(255,255,255,.2);font-size:.72rem;font-weight:600;backdrop-filter:blur(4px)}
.review-hero-chip i{font-size:.75rem;opacity:.9}

.review-section{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border)}
.review-section:last-of-type{border-bottom:none}
.review-section-head{display:flex;align-items:center;gap:.5rem;margin-bottom:.85rem;font-size:.72rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.8px}
.review-section-head i{width:24px;height:24px;border-radius:6px;background:var(--accent-soft);color:var(--accent);display:inline-flex;align-items:center;justify-content:center;font-size:.78rem}
.review-section.tone-green .review-section-head i{background:var(--green-soft);color:var(--green)}
.review-section.tone-amber .review-section-head i{background:var(--amber-soft);color:var(--amber)}
.review-section.tone-purple .review-section-head i{background:var(--purple-soft);color:var(--purple)}

.review-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.6rem 1.25rem}
.review-item{padding:.4rem 0;min-width:0}
.review-item .lbl{font-size:.68rem;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:.15rem}
.review-item .val{font-size:.9rem;color:var(--text);font-weight:600;word-break:break-word;line-height:1.3}
.review-item.full{grid-column:1/-1}
.review-item.empty .val{color:var(--text-3);font-weight:500;font-style:italic}

.review-doc-row{display:flex;align-items:center;gap:1rem;padding:.85rem 1rem;border-radius:12px;background:var(--surface-2);border:1px solid var(--border)}
.review-doc-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.review-doc-icon.complete{background:var(--green-soft);color:var(--green)}
.review-doc-icon.partial{background:var(--accent-soft);color:var(--accent)}
.review-doc-icon.empty{background:var(--amber-soft);color:var(--amber)}
.review-doc-body{flex:1;min-width:0}
.review-doc-count{font-size:.9rem;font-weight:700;color:var(--text);font-variant-numeric:tabular-nums}
.review-doc-label{font-size:.75rem;color:var(--text-2);font-weight:500;margin-top:.1rem}
.review-doc-bar{width:100%;height:8px;border-radius:999px;background:var(--surface);overflow:hidden;margin-top:.5rem;border:1px solid var(--border)}
.review-doc-bar-fill{height:100%;border-radius:999px;transition:width .3s}
.review-doc-bar-fill.fill-full{background:var(--green)}
.review-doc-bar-fill.fill-partial{background:var(--accent)}
.review-doc-bar-fill.fill-empty{background:var(--amber)}

.review-quick{display:flex;gap:.5rem;flex-wrap:wrap;padding:1rem 1.5rem;background:var(--surface-2);border-top:1px solid var(--border)}
.review-quick a{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .9rem;border-radius:10px;background:var(--surface);border:1px solid var(--border);color:var(--text);font-size:.78rem;font-weight:600;text-decoration:none;transition:all .15s}
.review-quick a:hover{border-color:var(--accent);color:var(--accent);transform:translateY(-1px);box-shadow:0 4px 12px -4px color-mix(in srgb,var(--accent) 40%, transparent)}

.review-footer{padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;border-top:1px solid var(--border);background:var(--surface)}

/* ============================================================
   Enhanced Approve Modal
   ============================================================ */
.approve-modal .modal-content{border-radius:18px;overflow:hidden}
.approve-modal .modal-dialog{max-width:560px}

.approve-hero{
    padding:1.5rem 1.5rem 1.25rem;
    background:linear-gradient(135deg,#059669,#047857);
    color:#fff;position:relative;overflow:hidden;
}
.approve-hero::after{
    content:'';position:absolute;right:-40px;top:-40px;
    width:180px;height:180px;border-radius:50%;
    background:rgba(255,255,255,.1);
}
.approve-hero-inner{position:relative;z-index:1;display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
.approve-hero .close-btn{
    position:absolute;top:.75rem;right:.75rem;z-index:2;
    width:32px;height:32px;border-radius:50%;
    background:rgba(255,255,255,.2);border:none;
    color:#fff;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    transition:background .15s;
}
.approve-hero .close-btn:hover{background:rgba(255,255,255,.35)}

.approve-hero-icon{
    width:56px;height:56px;border-radius:16px;
    background:rgba(255,255,255,.2);
    border:2px solid rgba(255,255,255,.35);
    display:flex;align-items:center;justify-content:center;
    font-size:1.5rem;flex-shrink:0;
    backdrop-filter:blur(4px);
}
.approve-hero-info{flex:1;min-width:0}
.approve-hero-info h5{
    font-size:1.1rem;font-weight:700;margin:0 0 .25rem;
    letter-spacing:-.01em;line-height:1.2;
}
.approve-hero-info p{
    margin:0;font-size:.82rem;opacity:.92;line-height:1.4;
}
.approve-hero-info .student-line{
    font-weight:700;font-size:.95rem;letter-spacing:-.01em;
    display:inline-flex;align-items:center;gap:.4rem;
    margin-bottom:.15rem;
}
.approve-hero-info .student-meta{
    font-size:.78rem;opacity:.9;
    display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;
}
.approve-hero-info .student-meta .sep{opacity:.5}

/* Approve body */
.approve-body{padding:1.5rem}
.approve-subtitle{
    font-size:.72rem;font-weight:700;
    color:var(--text-2);text-transform:uppercase;
    letter-spacing:.8px;margin-bottom:.85rem;
}
.approve-checklist{
    display:flex;flex-direction:column;gap:.7rem;
    margin-bottom:1.35rem;
}
.approve-check{
    display:flex;align-items:flex-start;gap:.75rem;
    padding:.75rem .9rem;border-radius:12px;
    background:var(--surface-2);border:1px solid var(--border);
    transition:all .15s;
}
.approve-check:hover{
    border-color:color-mix(in srgb,var(--green) 30%, transparent);
    background:color-mix(in srgb,var(--green) 5%, var(--surface-2));
}
.approve-check-icon{
    width:34px;height:34px;border-radius:10px;
    background:var(--green-soft);color:var(--green);
    display:flex;align-items:center;justify-content:center;
    font-size:.95rem;flex-shrink:0;
}
.approve-check-body{flex:1;min-width:0}
.approve-check-title{
    font-size:.85rem;font-weight:700;color:var(--text);
    line-height:1.25;margin-bottom:.15rem;
}
.approve-check-desc{
    font-size:.75rem;color:var(--text-2);line-height:1.45;
}

/* Email warning */
.approve-warn{
    display:flex;align-items:flex-start;gap:.6rem;
    padding:.85rem 1rem;border-radius:12px;
    background:var(--amber-soft);color:var(--amber);
    border:1px solid color-mix(in srgb,var(--amber) 30%, transparent);
    font-size:.82rem;line-height:1.5;
    margin-top:.5rem;
}
.approve-warn i{font-size:1.05rem;flex-shrink:0;margin-top:1px}
.approve-warn strong{font-weight:700}

/* Email preview */
.approve-email-box{
    padding:.85rem 1rem;border-radius:12px;
    background:var(--surface-2);border:1px solid var(--border);
    display:flex;align-items:center;gap:.7rem;
    margin-top:.5rem;
}
.approve-email-box .icon{
    width:36px;height:36px;border-radius:10px;
    background:var(--accent-soft);color:var(--accent);
    display:flex;align-items:center;justify-content:center;
    font-size:.95rem;flex-shrink:0;
}
.approve-email-box .body{flex:1;min-width:0}
.approve-email-box .label{
    font-size:.68rem;font-weight:700;color:var(--text-3);
    text-transform:uppercase;letter-spacing:.5px;
}
.approve-email-box .value{
    font-size:.88rem;font-weight:600;color:var(--text);
    word-break:break-all;line-height:1.3;margin-top:.1rem;
}
.approve-email-box .value.empty{
    color:var(--text-3);font-weight:500;font-style:italic;
}

/* Footer */
.approve-footer{
    padding:1rem 1.5rem;
    background:var(--surface-2);
    border-top:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;
    gap:.75rem;flex-wrap:wrap;
}
.approve-footer .btn{padding:.55rem 1.1rem;font-weight:600}
.approve-footer .btn-confirm{
    background:linear-gradient(135deg,#059669,#047857);
    border:none;color:#fff;
    box-shadow:0 4px 12px -4px rgba(5,150,105,.5);
    transition:all .15s;
}
.approve-footer .btn-confirm:hover{
    transform:translateY(-1px);
    box-shadow:0 8px 20px -4px rgba(5,150,105,.6);
    color:#fff;
}

@media (max-width:640px){
    .review-grid{grid-template-columns:1fr}
    .review-hero-inner{gap:.75rem}
    .review-avatar{width:52px;height:52px;font-size:1.15rem}
    .review-hero-info h5{font-size:1rem}
    .review-footer{flex-direction:column-reverse}
    .review-footer .btn{width:100%}
    .approve-hero-inner{gap:.75rem}
    .approve-hero-icon{width:46px;height:46px;font-size:1.25rem}
    .approve-hero-info h5{font-size:1rem}
    .approve-footer{flex-direction:column-reverse}
    .approve-footer .btn{width:100%}
}

/* ===== Responsive ===== */
@media (max-width:1024px){
    .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
    .main-content{margin-left:0}.menu-toggle{display:flex}
    .stat-grid{grid-template-columns:repeat(2,1fr)}
}
@media (max-width:768px){
    .filter-bar{grid-template-columns:1fr}
    .stat-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<?php
    $__current = basename($_SERVER['PHP_SELF']);
    $__super   = (($_SESSION['admin_role'] ?? '') === 'superadmin');
    $__student_pages = ['student_profile.php','view_student.php','edit_student.php','export_students.php','sections_list.php'];
    $__act = in_array($__current, $__student_pages, true) ? 'student_profile.php' : $__current;
    $__a   = fn($f) => $f === $__act ? ' class="active"' : '';
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="../assets/img/usat.jpg" alt="USAT" onerror="this.style.background='var(--accent)'">
        <div>
            <div class="brand-name">USAT Admin</div>
            <div class="brand-sub">Enrollment System</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Overview</div>
        <a href="dashboard.php"<?= $__a('dashboard.php') ?>><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php"<?= $__a('student_profile.php') ?>><i class="bi bi-people-fill"></i> Students</a>
        <a href="pending_enrollments.php" class="active">
            <i class="bi bi-hourglass-split"></i> Pending
            <?php if ($stat_total > 0): ?>
                <span class="badge-pill"><?= $stat_total ?></span>
            <?php endif; ?>
        </a>
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
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2>Pending Enrollments</h2>
                <p class="sub">Review and approve student submissions</p>
            </div>
        </div>
        <button class="theme-btn" id="themeToggle" title="Toggle theme">
            <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
        </button>
    </div>

    <!-- ================= Stat tiles ================= -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-inbox-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $stat_total ?></div>
                <div class="stat-label">Total Pending</div>
            </div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $stat_withdocs ?></div>
                <div class="stat-label">With Documents</div>
            </div>
        </div>
        <div class="stat-card tone-amber">
            <div class="stat-icon"><i class="bi bi-exclamation-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $stat_nodocs ?></div>
                <div class="stat-label">Awaiting Documents</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $stat_overdue ?></div>
                <div class="stat-label">Overdue (3+ days)</div>
            </div>
        </div>
    </div>

    <!-- ================= Filters ================= -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-funnel"></i> Filters</h3>
            <span class="pill info"><i class="bi bi-hourglass-split"></i> <?= $stat_total ?> pending</span>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar">
                <div class="field">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Name, LRN, or ID" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="field">
                    <label>School Year</label>
                    <select name="school_year">
                        <option value="">All Years</option>
                        <?php foreach ($sy_list as $sy): ?>
                            <option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $sy_filter === $sy['school_year'] ? 'selected' : '' ?>><?= htmlspecialchars($sy['school_year']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Grade Level</label>
                    <select name="grade_level">
                        <option value="">All</option>
                        <?php foreach ($grade_list as $g): ?>
                            <option value="<?= htmlspecialchars($g['grade_level']) ?>" <?= $grade_filter === $g['grade_level'] ? 'selected' : '' ?>><?= htmlspecialchars($g['grade_level']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="actions">
                    <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Apply</button>
                    <a href="pending_enrollments.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle me-1"></i> Reset</a>
                </div>
            </form>
        </div>

        <div class="quick-filters">
            <button type="button" class="chip active" data-filter="all">
                <i class="bi bi-list-ul"></i> All <span class="count"><?= $stat_total ?></span>
            </button>
            <button type="button" class="chip chip-green" data-filter="ready">
                <i class="bi bi-check2-circle"></i> Ready for Review <span class="count"><?= $stat_withdocs ?></span>
            </button>
            <button type="button" class="chip chip-amber" data-filter="nodocs">
                <i class="bi bi-exclamation-circle"></i> Missing Docs <span class="count"><?= $stat_nodocs ?></span>
            </button>
            <button type="button" class="chip chip-red" data-filter="overdue">
                <i class="bi bi-clock-history"></i> Overdue <span class="count"><?= $stat_overdue ?></span>
            </button>
        </div>
    </div>

    <!-- ================= Table ================= -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-inbox"></i> Submissions Awaiting Approval</h3>
            <span class="cell-muted" id="visibleCount"></span>
        </div>
        <div class="card-body no-padding">
            <?php if (empty($pending)): ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-check2-circle"></i></div>
                    <div class="empty-title">All caught up</div>
                    <div class="empty-sub">No pending enrollments to review right now.</div>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table-admin" id="pendingTable">
                        <thead>
                            <tr>
                                <th style="width:240px">Student</th>
                                <th>Program</th>
                                <th style="width:140px">Section</th>
                                <th style="width:160px">Submitted</th>
                                <th style="width:140px">Documents</th>
                                <th style="text-align:right;width:230px">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pending as $p):
                            $fullName   = trim(($p['last_name'] ?? '') . ', ' . ($p['first_name'] ?? '') . ' ' . ($p['middle_name'] ?? ''));
                            $initials   = strtoupper(substr($p['first_name'] ?? '?', 0, 1) . substr($p['last_name'] ?? '', 0, 1));
                            $docs_total = (int)$p['docs_total'];
                            $docs_up    = min((int)$p['docs_uploaded'], $docs_total);

                            if ($docs_total > 0) {
                                $pct = min(100, round(($docs_up / max(1, $docs_total)) * 100));
                            } else {
                                $pct = 0;
                            }
                            if ($docs_up === 0) {
                                $fill_class = 'fill-empty';
                                $label_class = 'warn';
                                $label_icon = 'bi-exclamation-circle';
                                $row_class = 'row-nodocs';
                            } elseif ($docs_total > 0 && $docs_up >= $docs_total) {
                                $fill_class = 'fill-full';
                                $label_class = 'ok';
                                $label_icon = 'bi-check2-circle';
                                $row_class = 'row-ready';
                            } else {
                                $fill_class = 'fill-partial';
                                $label_class = '';
                                $label_icon = 'bi-circle-half';
                                $row_class = 'row-ready';
                            }

                            $ts = strtotime($p['enrolled_at']);
                            $is_overdue = (time() - $ts) > 3 * 86400;
                            $rel = time_ago($p['enrolled_at']);
                            $abs = date('M d, Y · g:i A', $ts);

                            $data_filter = $docs_up > 0 ? 'ready' : 'nodocs';
                            if ($is_overdue) $data_filter .= ' overdue';
                        ?>
                            <tr class="<?= $row_class ?>" data-row-filter="<?= $data_filter ?>">
                                <td>
                                    <div class="student-cell">
                                        <div class="student-avatar"><?= htmlspecialchars($initials) ?></div>
                                        <div class="student-info">
                                            <div class="student-name"><?= htmlspecialchars($fullName) ?></div>
                                            <div class="student-lrn">
                                                <?= htmlspecialchars($p['student_id_number'] ?: '—') ?>
                                                · LRN <?= htmlspecialchars($p['lrn'] ?: '—') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="cell-strong"><?= htmlspecialchars($p['grade_level']) ?></div>
                                    <div class="cell-muted"><?= htmlspecialchars($p['strand'] ?: '—') ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($p['section'])): ?>
                                        <span class="pill info"><?= htmlspecialchars($p['section']) ?></span>
                                    <?php else: ?>
                                        <span class="cell-muted">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="time-cell">
                                    <div class="rel <?= $is_overdue ? 'overdue' : '' ?>" title="<?= htmlspecialchars($abs) ?>">
                                        <?php if ($is_overdue): ?><i class="bi bi-exclamation-triangle-fill me-1" style="font-size:.75rem"></i><?php endif; ?>
                                        <?= htmlspecialchars($rel) ?>
                                    </div>
                                    <div class="abs"><?= date('M d, Y', $ts) ?></div>
                                </td>
                                <td>
                                    <div class="doc-progress">
                                        <div class="doc-bar">
                                            <div class="doc-bar-fill <?= $fill_class ?>" style="width:<?= $pct ?>%"></div>
                                        </div>
                                        <div class="doc-label <?= $label_class ?>">
                                            <i class="bi <?= $label_icon ?>"></i>
                                            <?= $docs_up ?> of <?= $docs_total ?> uploaded
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align:right">
                                    <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                                        <button class="btn btn-outline-secondary btn-xs view-btn"
                                                data-id="<?= (int)$p['enrollment_id'] ?>"
                                                data-name="<?= htmlspecialchars($fullName) ?>"
                                                data-sid="<?= (int)$p['student_id'] ?>"
                                                data-idno="<?= htmlspecialchars($p['student_id_number']) ?>"
                                                data-lrn="<?= htmlspecialchars($p['lrn']) ?>"
                                                data-sex="<?= htmlspecialchars($p['sex']) ?>"
                                                data-birth="<?= htmlspecialchars($p['birth_date']) ?>"
                                                data-email="<?= htmlspecialchars($p['email'] ?? '') ?>"
                                                data-phone="<?= htmlspecialchars($p['phone'] ?? '') ?>"
                                                data-sy="<?= htmlspecialchars($p['school_year']) ?>"
                                                data-grade="<?= htmlspecialchars($p['grade_level']) ?>"
                                                data-term="<?= htmlspecialchars($p['term']) ?>"
                                                data-track="<?= htmlspecialchars($p['track']) ?>"
                                                data-strand="<?= htmlspecialchars($p['strand']) ?>"
                                                data-program="<?= htmlspecialchars($p['program']) ?>"
                                                data-section="<?= htmlspecialchars($p['section']) ?>"
                                                data-docsup="<?= $docs_up ?>"
                                                data-docstotal="<?= $docs_total ?>"
                                                title="Review details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="btn btn-success btn-xs approve-btn"
                                                data-id="<?= (int)$p['enrollment_id'] ?>"
                                                data-name="<?= htmlspecialchars($fullName) ?>"
                                                data-email="<?= htmlspecialchars($p['email'] ?? '') ?>"
                                                data-idno="<?= htmlspecialchars($p['student_id_number']) ?>"
                                                data-grade="<?= htmlspecialchars($p['grade_level']) ?>"
                                                data-strand="<?= htmlspecialchars($p['strand']) ?>"
                                                data-docsup="<?= $docs_up ?>"
                                                data-docstotal="<?= $docs_total ?>"
                                                title="Approve enrollment">
                                            <i class="bi bi-check-lg me-1"></i> Approve
                                        </button>
                                        <button class="btn btn-outline-danger btn-xs reject-btn"
                                                data-id="<?= (int)$p['enrollment_id'] ?>"
                                                data-name="<?= htmlspecialchars($fullName) ?>"
                                                title="Reject enrollment">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============ REVIEW MODAL ============ -->
<div class="modal fade review-modal" id="reviewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="review-hero">
                <button type="button" class="close-btn" data-bs-dismiss="modal" aria-label="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
                <div class="review-hero-inner">
                    <div class="review-avatar" id="reviewAvatar">—</div>
                    <div class="review-hero-info">
                        <h5 id="reviewName">—</h5>
                        <div class="review-hero-chips" id="reviewChips"></div>
                    </div>
                </div>
            </div>

            <div class="review-section">
                <div class="review-section-head">
                    <i class="bi bi-person-vcard"></i>
                    <span>Personal Information</span>
                </div>
                <div class="review-grid" id="reviewPersonal"></div>
            </div>

            <div class="review-section tone-purple">
                <div class="review-section-head">
                    <i class="bi bi-mortarboard"></i>
                    <span>Enrollment Details</span>
                </div>
                <div class="review-grid" id="reviewEnrollment"></div>
            </div>

            <div class="review-section tone-green">
                <div class="review-section-head">
                    <i class="bi bi-folder-check"></i>
                    <span>Documents</span>
                </div>
                <div id="reviewDocsBlock"></div>
            </div>

            <div class="review-quick">
                <a id="reviewViewStudent" href="#" target="_blank">
                    <i class="bi bi-person-badge"></i> Full Profile
                </a>
                <a id="reviewDocs" href="#" target="_blank">
                    <i class="bi bi-folder2-open"></i> View Documents
                </a>
            </div>

            <div class="review-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                    Close
                </button>
                <button class="btn btn-success btn-sm" id="reviewApproveBtn">
                    <i class="bi bi-check-lg me-1"></i> Approve Enrollment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============ ENHANCED APPROVE MODAL ============ -->
<div class="modal fade approve-modal" id="approveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="approve_enrollment.php" id="approveForm">

                <!-- Hero header -->
                <div class="approve-hero">
                    <button type="button" class="close-btn" data-bs-dismiss="modal" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                    <div class="approve-hero-inner">
                        <div class="approve-hero-icon">
                            <i class="bi bi-check2-circle"></i>
                        </div>
                        <div class="approve-hero-info">
                            <div class="student-line" id="approveStudentLine">
                                <i class="bi bi-person-fill"></i>
                                <span id="approveName">—</span>
                            </div>
                            <div class="student-meta" id="approveStudentMeta">
                                <span id="approveIdno">—</span>
                                <span class="sep">·</span>
                                <span id="approveGrade">—</span>
                                <span class="sep">·</span>
                                <span id="approveDocsInline">—</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Body -->
                <div class="approve-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="enrollment_id" id="approveId">
                    <input type="hidden" name="action" value="approve">

                    <div class="approve-subtitle">What happens next</div>

                    <div class="approve-checklist">
                        <div class="approve-check">
                            <div class="approve-check-icon">
                                <i class="bi bi-check2-all"></i>
                            </div>
                            <div class="approve-check-body">
                                <div class="approve-check-title">Set status to Active</div>
                                <div class="approve-check-desc">The enrollment will be officially activated and appear in the student masterlist.</div>
                            </div>
                        </div>

                        <div class="approve-check">
                            <div class="approve-check-icon">
                                <i class="bi bi-envelope-check"></i>
                            </div>
                            <div class="approve-check-body">
                                <div class="approve-check-title">Send confirmation email</div>
                                <div class="approve-check-desc">The student will receive an enrollment confirmation with their student ID and section.</div>
                            </div>
                        </div>

                        <div class="approve-check">
                            <div class="approve-check-icon">
                                <i class="bi bi-journal-text"></i>
                            </div>
                            <div class="approve-check-body">
                                <div class="approve-check-title">Record in audit trail</div>
                                <div class="approve-check-desc">The approval will be logged with your name and timestamp for accountability.</div>
                            </div>
                        </div>
                    </div>

                    <div class="approve-subtitle">Notification</div>

                    <div class="approve-email-box">
                        <div class="icon"><i class="bi bi-envelope-fill"></i></div>
                        <div class="body">
                            <div class="label">Student Email</div>
                            <div class="value" id="approveEmailDisplay">—</div>
                        </div>
                    </div>

                    <div class="approve-warn" id="approveEmailWarn" style="display:none">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div>
                            <strong>No email on file.</strong>
                            The approval will still succeed, but the student won't be notified. You may need to contact them directly.
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="approve-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button class="btn btn-confirm btn-sm">
                        <i class="bi bi-check-lg me-1"></i> Confirm Approval
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============ REJECT MODAL ============ -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="approve_enrollment.php">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-x-circle me-2"></i>Reject Enrollment</h6>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="enrollment_id" id="rejectId">
                    <input type="hidden" name="action" value="reject">

                    <p>Reject <strong id="rejectName"></strong>?</p>
                    <div class="mb-3">
                        <label class="form-label">Reason (sent to student)</label>
                        <textarea name="reason" class="form-control" rows="3" required maxlength="500"
                                  placeholder="e.g. Incomplete documents — please re-upload your PSA Birth Certificate."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg me-1"></i> Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:199"
     onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ---------- Sidebar ----------
const sb = document.getElementById('sidebar');
const ov = document.getElementById('sidebarOverlay');
document.getElementById('menuToggle')?.addEventListener('click', () => {
    sb.classList.toggle('open');
    ov.style.display = sb.classList.contains('open') ? 'block' : 'none';
});

// ---------- Theme ----------
const tb = document.getElementById('themeToggle');
const ti = document.getElementById('themeIcon');
const h  = document.documentElement;
function st(t){
    h.setAttribute('data-bs-theme', t);
    ti.className = 'bi bi-' + (t === 'dark' ? 'sun-fill' : 'moon-stars-fill');
    document.cookie = 'admin_theme=' + t + ';path=/;max-age=' + (60*60*24*365);
}
(function(){ const m = document.cookie.match(/(?:^|; )admin_theme=([^;]+)/); st(m ? decodeURIComponent(m[1]) : 'light'); })();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

// ---------- Quick filter chips ----------
const chips = document.querySelectorAll('.chip');
const rows  = document.querySelectorAll('#pendingTable tbody tr');
const visibleCountEl = document.getElementById('visibleCount');

function applyFilter(filter){
    let shown = 0;
    rows.forEach(row => {
        const tags = (row.dataset.rowFilter || '').split(' ');
        let show = false;
        if (filter === 'all')          show = true;
        else if (filter === 'ready')   show = tags.includes('ready');
        else if (filter === 'nodocs')  show = tags.includes('nodocs');
        else if (filter === 'overdue') show = tags.includes('overdue');
        row.style.display = show ? '' : 'none';
        if (show) shown++;
    });
    if (visibleCountEl) {
        visibleCountEl.textContent = (filter === 'all') ? '' : shown + ' shown';
    }
}

chips.forEach(chip => {
    chip.addEventListener('click', () => {
        chips.forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        applyFilter(chip.dataset.filter);
    });
});

// ---------- Helpers ----------
function esc(v){
    return String(v || '').replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));
}
function fmtDate(d){
    if (!d) return '';
    const dt = new Date(d);
    if (isNaN(dt.getTime())) return d;
    return dt.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
}
function infoItem(label, value){
    const empty = !value || value === '—';
    return `<div class="review-item${empty ? ' empty' : ''}">
        <div class="lbl">${label}</div>
        <div class="val">${empty ? 'Not provided' : esc(value)}</div>
    </div>`;
}
function chip(icon, text){
    return `<span class="review-hero-chip"><i class="bi ${icon}"></i>${esc(text)}</span>`;
}
function initials(name){
    if (!name) return '?';
    const parts = name.replace(',', '').split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    const first = parts[0][0] || '';
    const last = parts.length > 1 ? parts[parts.length - 1][0] : '';
    return (first + last).toUpperCase();
}

// ---------- Review modal ----------
document.querySelectorAll('.view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const d = btn.dataset;

        document.getElementById('reviewAvatar').textContent = initials(d.name);
        document.getElementById('reviewName').textContent = d.name || '—';

        const chips_html = [
            chip('bi-upc-scan', d.idno || 'No ID'),
            chip('bi-hash', 'LRN ' + (d.lrn || '—')),
            chip('bi-mortarboard', d.grade || '—')
        ].join('');
        document.getElementById('reviewChips').innerHTML = chips_html;

        document.getElementById('reviewPersonal').innerHTML =
            infoItem('Sex', d.sex) +
            infoItem('Birth Date', fmtDate(d.birth)) +
            infoItem('Email', d.email) +
            infoItem('Phone', d.phone);

        document.getElementById('reviewEnrollment').innerHTML =
            infoItem('School Year', d.sy) +
            infoItem('Grade Level', d.grade) +
            infoItem('Term', d.term) +
            infoItem('Track', d.track) +
            infoItem('Strand', d.strand) +
            infoItem('Program', d.program) +
            infoItem('Section', d.section || 'Unassigned') +
            infoItem('Enrollment ID', '#' + d.id);

        const up = parseInt(d.docsup, 10) || 0;
        const total = parseInt(d.docstotal, 10) || 0;
        const pct = total > 0 ? Math.min(100, Math.round((up / total) * 100)) : 0;

        let state, fillClass, icon, stateText;
        if (up === 0) {
            state = 'empty'; fillClass = 'fill-empty'; icon = 'bi-exclamation-circle';
            stateText = 'No documents uploaded yet';
        } else if (total > 0 && up >= total) {
            state = 'complete'; fillClass = 'fill-full'; icon = 'bi-check2-circle';
            stateText = 'All required documents received';
        } else {
            state = 'partial'; fillClass = 'fill-partial'; icon = 'bi-circle-half';
            stateText = 'Some documents still missing';
        }

        document.getElementById('reviewDocsBlock').innerHTML = `
            <div class="review-doc-row">
                <div class="review-doc-icon ${state}">
                    <i class="bi ${icon}"></i>
                </div>
                <div class="review-doc-body">
                    <div class="review-doc-count">${up} of ${total} uploaded</div>
                    <div class="review-doc-label">${stateText}</div>
                    <div class="review-doc-bar">
                        <div class="review-doc-bar-fill ${fillClass}" style="width:${pct}%"></div>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('reviewViewStudent').href = 'view_student.php?id=' + d.sid;
        document.getElementById('reviewDocs').href        = 'view_student.php?id=' + d.sid + '#documents';

        document.getElementById('reviewApproveBtn').onclick = () => {
            bootstrap.Modal.getInstance(document.getElementById('reviewModal')).hide();
            setTimeout(() => openApprove(d), 200);
        };

        new bootstrap.Modal(document.getElementById('reviewModal')).show();
    });
});

// ---------- Approve modal ----------
function openApprove(d){
    // d may come from a review button (dataset object) or an approve button.
    const id    = d.id    || d.dataset?.id    || '';
    const name  = d.name  || d.dataset?.name  || '—';
    const email = d.email || d.dataset?.email || '';
    const idno  = d.idno  || d.dataset?.idno  || '';
    const grade = d.grade || d.dataset?.grade || '';
    const strand= d.strand|| d.dataset?.strand|| '';
    const docsup= d.docsup|| d.dataset?.docsup|| '0';
    const docstotal = d.docstotal || d.dataset?.docstotal || '0';

    document.getElementById('approveId').value = id;
    document.getElementById('approveName').textContent = name;

    // Meta line
    document.getElementById('approveIdno').textContent  = idno || '—';
    document.getElementById('approveGrade').textContent = grade + (strand ? ' · ' + strand : '');
    document.getElementById('approveDocsInline').textContent = docsup + ' of ' + docstotal + ' docs';

    // Email display
    const emailEl = document.getElementById('approveEmailDisplay');
    if (email) {
        emailEl.textContent = email;
        emailEl.classList.remove('empty');
        document.getElementById('approveEmailWarn').style.display = 'none';
    } else {
        emailEl.textContent = 'No email on file';
        emailEl.classList.add('empty');
        document.getElementById('approveEmailWarn').style.display = 'flex';
    }

    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

document.querySelectorAll('.approve-btn').forEach(btn => {
    btn.addEventListener('click', () => openApprove(btn));
});

// ---------- Reject modal ----------
document.querySelectorAll('.reject-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('rejectId').value = btn.dataset.id;
        document.getElementById('rejectName').textContent = btn.dataset.name;
        new bootstrap.Modal(document.getElementById('rejectModal')).show();
    });
});
</script>
</body>
</html>