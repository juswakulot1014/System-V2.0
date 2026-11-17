<?php
/**
 * shared/lib/card_helper.php
 * render_dir_card() — generic card for table ⇄ cards directories.
 */
declare(strict_types=1);

if (!function_exists('render_dir_card')) {
    function render_dir_card(array $c): string {
        $title    = (string)($c['title']    ?? '—');
        $initials = (string)($c['initials'] ?? '');
        $meta     = (array) ($c['meta']     ?? []);
        $badge    = $c['badge']             ?? null;
        $tags     = (array) ($c['tags']     ?? []);
        $footL    = (string)($c['footer_left']  ?? '');
        $footR    = $c['footer_right']      ?? null;
        $action   = $c['action']            ?? null;

        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        ob_start(); ?>
        <div class="student-card">
            <div class="student-card-header">
                <?php if ($initials !== ''): ?>
                    <div class="student-avatar-mini"><?= $e($initials) ?></div>
                <?php endif; ?>
                <div class="student-card-info">
                    <div class="student-card-name"><?= $e($title) ?></div>
                    <?php if (!empty($meta)): ?>
                        <div class="student-card-meta">
                            <?php
                            $parts = [];
                            foreach ($meta as $m) {
                                if ($m === null || $m === '') continue;
                                $parts[] = '<span>' . $e($m) . '</span>';
                            }
                            echo implode('<span class="sep">·</span>', $parts);
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (is_array($badge) && !empty($badge['label'])): ?>
                    <span class="pill <?= $e($badge['class'] ?? 'muted') ?>" style="font-size:.66rem;"><?= $e($badge['label']) ?></span>
                <?php endif; ?>
            </div>

            <?php if (!empty($tags)): ?>
                <div class="student-card-tags">
                    <?php foreach ($tags as $t): ?>
                        <span class="s-tag <?= $e($t['class'] ?? '') ?>">
                            <?php if (!empty($t['icon'])): ?><i class="bi <?= $e($t['icon']) ?>"></i><?php endif; ?>
                            <?= $e($t['label'] ?? '') ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($footL !== '' || $footR !== null || $action !== null): ?>
                <div class="student-card-footer">
                    <span class="student-card-strand"><?= $e($footL) ?></span>
                    <?php if (is_array($action)): ?>
                        <a href="<?= $e($action['href'] ?? '#') ?>" class="btn btn-sm btn-outline-primary student-card-actions">
                            <?php if (!empty($action['icon'])): ?><i class="bi <?= $e($action['icon']) ?>"></i> <?php endif; ?>
                            <?= $e($action['label'] ?? 'View') ?>
                        </a>
                    <?php elseif (is_array($footR)): ?>
                        <span class="pill <?= $e($footR['class'] ?? 'muted') ?>" style="font-size:.66rem;"><?= $e($footR['label'] ?? '') ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }
}