<?php
/**
 * shared/lib/dir_view.php
 * Table ⇄ Cards toggle. Cookie per key: view_{key} = table|cards.
 */
declare(strict_types=1);

if (!function_exists('dir_view')) {
    function dir_view(string $key, string $default = 'table'): string {
        if (!in_array($default, ['table', 'cards'], true)) $default = 'table';
        $cookie = 'view_' . preg_replace('/[^a-z0-9_]/i', '_', $key);
        $mode   = $_COOKIE[$cookie] ?? $default;
        return in_array($mode, ['table', 'cards'], true) ? $mode : $default;
    }
}

if (!function_exists('dir_view_class')) {
    function dir_view_class(string $view): string {
        return 'dir-view-wrap dir-view-' . $view;
    }
}

if (!function_exists('dir_view_toggle')) {
    function dir_view_toggle(string $view): string {
        $t = $view === 'table';
        $c = $view === 'cards';
        ob_start(); ?>
        <div class="view-toggle" role="group" aria-label="View options">
            <button type="button" class="vt-btn <?= $t ? 'active' : '' ?>"
                    data-dir-view="table" title="Table view" aria-label="Table view"
                    aria-pressed="<?= $t ? 'true' : 'false' ?>">
                <i class="bi bi-table"></i>
            </button>
            <button type="button" class="vt-btn <?= $c ? 'active' : '' ?>"
                    data-dir-view="cards" title="Card view" aria-label="Card view"
                    aria-pressed="<?= $c ? 'true' : 'false' ?>">
                <i class="bi bi-grid-3x3-gap-fill"></i>
            </button>
        </div>
        <?php return ob_get_clean();
    }
}