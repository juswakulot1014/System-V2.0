<?php
/** @var array $doc */
?>
<div class="footer">
  <table style="border:0; width:100%; font-size:8pt;">
    <tr>
      <td style="text-align:left;">
        <?php if ($doc['control_no'] !== null): ?>
          Doc. No.: <?= doc_e($doc['control_no']) ?> &nbsp;|&nbsp;
        <?php endif; ?>
        Generated: <?= doc_e($doc['generated_at']->format('Y-m-d H:i')) ?>
      </td>
      <td style="text-align:right;">
        <?= doc_e($doc['title']) ?>
      </td>
    </tr>
  </table>
</div>