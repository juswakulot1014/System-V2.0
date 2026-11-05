<?php
/** @var array $doc */
if (empty($doc['signatories'])) return;

$cols  = count($doc['signatories']);
$width = (int) floor(100 / max(1, $cols));
?>
<div class="signatures">
  <table>
    <tr>
      <?php foreach ($doc['signatories'] as $sig):
          $sigName = trim((string)($sig['name'] ?? ''));
      ?>
        <td style="width:<?= $width ?>%; padding: 0 20pt;">
          <div class="sig-item">
            <div style="height: 36pt;"></div> <!-- Space for actual signature -->
            <div class="sig-line"><?= $sigName !== '' ? doc_e($sigName) : '&nbsp;' ?></div>
            <div class="sig-label"><?= doc_e($sig['position'] ?? '') ?></div>
            <?php if (!empty($sig['label'])): ?>
              <div class="sig-role"><?= doc_e($sig['label']) ?></div>
            <?php endif; ?>
          </div>
        </td>
      <?php endforeach; ?>
    </tr>
  </table>
</div>