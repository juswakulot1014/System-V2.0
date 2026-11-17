<?php
/**
 * shared/lib/view_toggle.php
 * Grid + 5 list-view styles for stat-grids. Cookie per key.
 */
declare(strict_types=1);

if (!function_exists('view_modes')) {
    function view_modes(): array {
        return [
            'grid'          => ['icon' => 'bi-grid-3x3-gap-fill', 'title' => 'Grid view'],
            'list-compact'  => ['icon' => 'bi-list',              'title' => 'Compact list'],
            'list'          => ['icon' => 'bi-list-ul',           'title' => 'Standard list'],
            'list-detailed' => ['icon' => 'bi-card-list',         'title' => 'Detailed list'],
            'list-accent'   => ['icon' => 'bi-list-stars',        'title' => 'Accent list'],
            'list-two-col'  => ['icon' => 'bi-grid-1x2-fill',     'title' => 'Two-column list'],
        ];
    }
}

if (!function_exists('view_mode')) {
    function view_mode(string $key, string $default = 'grid'): string {
        $modes = view_modes();
        if (!isset($modes[$default])) $default = 'grid';
        $cookie = 'view_' . preg_replace('/[^a-z0-9_]/i', '_', $key);
        $mode   = $_COOKIE[$cookie] ?? $default;
        return isset($modes[$mode]) ? $mode : $default;
    }
}

if (!function_exists('view_container_class')) {
    function view_container_class(string $key, string $default = 'grid'): string {
        return 'view-container view-mode-' . view_mode($key, $default);
    }
}

if (!function_exists('view_toggle')) {
    function view_toggle(string $key, string $default = 'grid', string $label = ''): string {
        $mode  = view_mode($key, $default);
        $modes = view_modes();
        $k     = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        $l     = $label !== ''
               ? '<span class="vt-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
               : '';
        $aria  = $label !== ''
               ? htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ' view options'
               : 'View options';
        ob_start(); ?>
        <div class="view-toggle-wrap" role="group" aria-label="<?= $aria ?>">
            <?= $l ?>
            <div class="view-toggle" data-view-key="<?= $k ?>">
                <?php foreach ($modes as $m => $meta): $active = ($m === $mode); ?>
                <button type="button"
                        class="vt-btn <?= $active ? 'active' : '' ?>"
                        data-view="<?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?>"
                        title="<?= htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8') ?>"
                        aria-label="<?= htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8') ?>"
                        aria-pressed="<?= $active ? 'true' : 'false' ?>">
                    <i class="bi <?= htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php return ob_get_clean();
    }
}