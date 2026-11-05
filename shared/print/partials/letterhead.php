<?php
/** @var array $ident */
$logoSrc = school_logo_data_uri();
?>
<table width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%; border:0; margin-bottom:4pt;">
  <tr>
    <td width="90" align="left" valign="middle" style="width:90px; text-align:left; vertical-align:middle;">
      <?php if ($logoSrc): ?>
        <img src="<?= $logoSrc ?>" alt="Logo" width="80" height="80" style="width:80px; height:80px; border-radius:50%;">
      <?php endif; ?>
    </td>
    <td align="center" valign="middle" style="text-align:center; vertical-align:middle; line-height:1.2;">
      <div style="font-size:10pt; letter-spacing:.5pt;"><?= doc_e($ident['country']) ?></div>
      <div style="font-size:11pt; font-weight:bold; letter-spacing:.5pt;"><?= doc_e($ident['department']) ?></div>
      <?php if (!empty($ident['region'])): ?>
        <div style="font-size:10pt;"><?= doc_e($ident['region']) ?></div>
      <?php endif; ?>
      <?php if (!empty($ident['division'])): ?>
        <div style="font-size:10pt;"><?= doc_e($ident['division']) ?></div>
      <?php endif; ?>
      <div style="font-size:14pt; font-weight:bold; letter-spacing:.05em; margin-top:4pt; color:#1e3a8a;">
        <?= doc_e($ident['school']) ?>
      </div>
      <div style="font-size:9.5pt; font-style:italic; color:#4b5563;">
        <?= doc_e($ident['address']) ?>
      </div>
    </td>
    <td width="90" align="right" valign="middle" style="width:90px; text-align:right; vertical-align:middle;">&nbsp;</td>
  </tr>
</table>
<div style="border-top: 2pt solid #1e3a8a; margin: 8pt 0 2pt; height:0; line-height:0; font-size:0;">&nbsp;</div>
<div style="border-top: 1pt solid #1e3a8a; margin: 0 0 14pt; height:0; line-height:0; font-size:0;">&nbsp;</div>