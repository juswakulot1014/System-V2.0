<?php
/**
 * shared/partials/enrollment_wizard_sidebar.php
 *
 * Vertical wizard progress for enrollment forms.
 *
 * Self-sufficient — works in both:
 *   - Public enrollment form (has $lang, $t, $initial_step)
 *   - Admin walkthrough page  (has $initial_step, no $lang/$t)
 *
 * Uses the SAME .wizard-step-indicator / data-step="N" contract as
 * the horizontal progress bar, so any wizard JS that binds those
 * elements drives this sidebar with zero changes.
 *
 * Optional in scope:
 *   $lang          'en' | 'fil'    (defaults to 'en')
 *   $t             translation array (defaults to [])
 *   $initial_step  1..5            (defaults to 1)
 */

/* ── Defensive: resolve once so array-key access never touches undefined vars ── */
$__ewz_lang = (isset($lang) && is_string($lang) && $lang !== '') ? $lang : 'en';

$__ewz_t = [];
if (isset($t) && is_array($t) && isset($t[$__ewz_lang]) && is_array($t[$__ewz_lang])) {
    $__ewz_t = $t[$__ewz_lang];
}

$__ewz_total   = 5;
$__ewz_current = max(1, min(5, (int)($initial_step ?? 1)));

$__ewz_labels = [
    1 => $__ewz_t['student']    ?? 'Student Info',
    2 => $__ewz_t['parents']    ?? 'Parents & Guardian',
    3 => $__ewz_t['address']    ?? 'Address',
    4 => $__ewz_t['education']  ?? 'Education',
    5 => $__ewz_t['enrollment'] ?? 'Enrollment',
];

$__ewz_hints = [
    1 => 'LRN, name, sex, birth date',
    2 => 'Parents & guardian details',
    3 => 'Home address',
    4 => 'Elementary, JHS, transferee',
    5 => 'Grade, strand, program, docs',
];

$__ewz_school = $__ewz_t['school'] ?? 'USAT College';
?>
<style>
.ewz-sidebar-wrap { position: relative; }
.ewz-sidebar {
    position: sticky; top: 20px;
    width: 100%; max-height: calc(100vh - 40px); overflow-y: auto;
    background: #fff; border-radius: 18px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 10px 30px -12px rgba(15, 23, 42, .15);
    padding: 1rem 0.85rem;
    display: flex; flex-direction: column; gap: 0.9rem;
    scrollbar-width: thin;
}
.ewz-sidebar::-webkit-scrollbar { width: 6px; }
.ewz-sidebar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
.ewz-brand {
    display: flex; align-items: center; gap: .65rem;
    padding: .35rem .5rem .85rem;
    border-bottom: 1px solid #e2e8f0;
}
.ewz-brand-logo {
    width: 38px; height: 38px; border-radius: 10px;
    background: linear-gradient(135deg, #1e88e5, #1565c0);
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: .9rem; flex-shrink: 0;
    box-shadow: 0 4px 12px -2px rgba(30,136,229,.4);
}
.ewz-brand-text { min-width: 0; }
.ewz-brand-title {
    font-size: .82rem; font-weight: 800; color: #0f172a;
    line-height: 1.15; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ewz-brand-sub {
    font-size: .64rem; font-weight: 700; color: #64748b;
    letter-spacing: .5px; text-transform: uppercase; margin-top: 2px;
}
.ewz-progress { padding: .35rem .5rem .75rem; border-bottom: 1px solid #e2e8f0; }
.ewz-progress-head {
    display: flex; align-items: baseline; justify-content: space-between; margin-bottom: .5rem;
}
.ewz-progress-label {
    font-size: .7rem; font-weight: 700; color: #334155;
    text-transform: uppercase; letter-spacing: .5px;
}
.ewz-progress-pct {
    font-size: 1rem; font-weight: 800; color: #1e88e5;
    font-variant-numeric: tabular-nums; line-height: 1;
}
.ewz-progress-track { height: 6px; border-radius: 999px; background: #e2e8f0; overflow: hidden; }
.ewz-progress-fill {
    height: 100%; width: 0%;
    background: linear-gradient(90deg, #1e88e5, #1565c0);
    border-radius: 999px;
    transition: width .45s cubic-bezier(.4, 0, .2, 1);
}
.ewz-steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .15rem; }
.ewz-sidebar .wizard-step-indicator {
    flex: none; display: flex; flex-direction: row;
    align-items: center; justify-content: flex-start;
    gap: .7rem; width: 100%; padding: .55rem .5rem;
    border-radius: 10px; text-align: left; font-family: inherit;
    background: transparent; border: none; cursor: pointer;
    transition: background .15s, color .15s; position: relative;
}
.ewz-sidebar .wizard-step-indicator:not(:disabled):hover { background: #f1f5f9; }
.ewz-sidebar .wizard-step-indicator:disabled { cursor: not-allowed; opacity: .55; }
.ewz-sidebar .wizard-step-indicator.active { background: #eff6ff; }
.ewz-sidebar .wizard-step-indicator.active::before {
    content: ''; position: absolute; left: 0; top: 8px; bottom: 8px;
    width: 3px; background: #1e88e5; border-radius: 0 3px 3px 0;
}
.ewz-sidebar .wizard-step-indicator .wizard-step-num {
    flex: none; width: 34px; height: 34px; font-size: .85rem; border-radius: 50%;
    background: #e2e8f0; color: #64748b;
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 700; box-shadow: none; transform: none !important;
    transition: background .2s, color .2s;
}
.ewz-sidebar .wizard-step-indicator.active .wizard-step-num {
    background: linear-gradient(135deg, #1e88e5, #1565c0); color: #fff;
    box-shadow: 0 4px 12px -4px rgba(30, 136, 229, .5);
}
.ewz-sidebar .wizard-step-indicator.completed .wizard-step-num {
    background: linear-gradient(135deg, #10b981, #047857); color: #fff;
    box-shadow: 0 4px 12px -4px rgba(16, 185, 129, .4);
}
.ewz-sidebar .wizard-step-indicator.invalid .wizard-step-num {
    background: linear-gradient(135deg, #ef4444, #b91c1c); color: #fff;
}
.ewz-step-body { min-width: 0; flex: 1; display: flex; flex-direction: column; gap: 1px; }
.ewz-step-label { font-size: .82rem; font-weight: 700; color: #1e293b; line-height: 1.25; text-align: left; }
.ewz-step-hint { font-size: .7rem; color: #64748b; line-height: 1.35; text-align: left; }
.ewz-sidebar .wizard-step-indicator.active .ewz-step-label { color: #1565c0; }
.ewz-sidebar .wizard-step-indicator.completed .ewz-step-label { color: #047857; }
.ewz-sidebar .wizard-step-indicator.invalid .ewz-step-label { color: #b91c1c; }
.ewz-step-connector {
    position: absolute; left: 22px; top: 40px; bottom: -8px;
    width: 2px; background: #e2e8f0; pointer-events: none;
}
.ewz-sidebar .wizard-step-indicator.completed .ewz-step-connector { background: #10b981; }

@media (min-width: 992px) {
    .form-container.ewz-layout {
        max-width: 1400px; display: grid;
        grid-template-columns: 280px minmax(0, 1fr);
        gap: 1.5rem; align-items: start;
    }
    .ewz-layout .wizard-progress-wrap { display: none; }
    .walkthrough-layout {
        display: grid;
        grid-template-columns: 280px minmax(0, 1fr);
        gap: 1.5rem; align-items: start;
    }
    .ewz-main, .walkthrough-content { min-width: 0; }
}
@media (max-width: 991.98px) {
    .ewz-sidebar-wrap { display: none; }
}
</style>

<aside class="ewz-sidebar-wrap" aria-label="Enrollment progress">
    <div class="ewz-sidebar">
        <div class="ewz-brand">
            <div class="ewz-brand-logo" aria-hidden="true">U</div>
            <div class="ewz-brand-text">
                <div class="ewz-brand-title"><?= htmlspecialchars($__ewz_school, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="ewz-brand-sub">Enrollment Walkthrough</div>
            </div>
        </div>
        <div class="ewz-progress">
            <div class="ewz-progress-head">
                <span class="ewz-progress-label" id="ewzProgressLabel">Step <?= $__ewz_current ?> of <?= $__ewz_total ?></span>
                <span class="ewz-progress-pct" id="ewzProgressPct">0%</span>
            </div>
            <div class="ewz-progress-track">
                <div class="ewz-progress-fill" id="ewzProgressFill"></div>
            </div>
        </div>
        <ol class="ewz-steps">
            <?php foreach ($__ewz_labels as $__n => $__lbl): ?>
                <li>
                    <button type="button"
                            class="wizard-step-indicator<?= $__n === $__ewz_current ? ' active' : '' ?>"
                            data-step="<?= $__n ?>"
                            <?= $__n === $__ewz_current ? 'aria-current="step"' : '' ?>
                            aria-label="Step <?= $__n ?>: <?= htmlspecialchars($__lbl, ENT_QUOTES, 'UTF-8') ?>">
                        <span class="wizard-step-num" aria-hidden="true"><?= $__n ?></span>
                        <span class="ewz-step-body">
                            <span class="ewz-step-label"><?= htmlspecialchars($__lbl, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="ewz-step-hint"><?= htmlspecialchars($__ewz_hints[$__n], ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <?php if ($__n < $__ewz_total): ?>
                            <span class="ewz-step-connector" aria-hidden="true"></span>
                        <?php endif; ?>
                    </button>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</aside>

<script>
(function () {
    'use strict';
    const sidebar = document.querySelector('.ewz-sidebar');
    if (!sidebar) return;
    const items   = sidebar.querySelectorAll('.wizard-step-indicator');
    const fill    = document.getElementById('ewzProgressFill');
    const pctEl   = document.getElementById('ewzProgressPct');
    const labelEl = document.getElementById('ewzProgressLabel');
    const total   = items.length;
    if (!total || !fill || !pctEl || !labelEl) return;
    function refresh() {
        let done = 0, activeStep = 1;
        items.forEach(function (el) {
            if (el.classList.contains('completed')) done++;
            if (el.classList.contains('active'))    activeStep = parseInt(el.dataset.step, 10) || 1;
        });
        const pct = Math.round((done / total) * 100);
        fill.style.width  = pct + '%';
        pctEl.textContent = pct + '%';
        labelEl.textContent = 'Step ' + activeStep + ' of ' + total;
    }
    const obs = new MutationObserver(refresh);
    items.forEach(function (el) { obs.observe(el, { attributes: true, attributeFilter: ['class'] }); });
    refresh();
})();
</script>