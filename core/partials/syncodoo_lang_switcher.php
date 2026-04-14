<?php
$activeLang = $activeLang ?? 'fr';
$baseUrl = $baseUrl ?? '';
?>
<div style="text-align:right;margin:4px 0 6px 0;font-size:0.88em">
    <span style="color:#6c757d;margin-right:4px"><?php echo syncodoo_tr('SyncodooLabelLanguage'); ?></span>
    <a href="<?php echo htmlspecialchars($baseUrl.'&set_lang=fr'); ?>" style="padding:2px 10px;text-decoration:none;border-radius:3px 0 0 3px;border:1px solid #ccc;<?php echo $activeLang === 'fr' ? 'background:#1f8f43;color:#fff;font-weight:700;border-color:#1f8f43' : 'background:#f8f9fa;color:#495057'; ?>">FR</a>
    <a href="<?php echo htmlspecialchars($baseUrl.'&set_lang=nl'); ?>" style="padding:2px 10px;text-decoration:none;border:1px solid #ccc;border-left:none;<?php echo $activeLang === 'nl' ? 'background:#1f8f43;color:#fff;font-weight:700;border-color:#1f8f43' : 'background:#f8f9fa;color:#495057'; ?>">NL</a>
    <a href="<?php echo htmlspecialchars($baseUrl.'&set_lang=en'); ?>" style="padding:2px 10px;text-decoration:none;border-radius:0 3px 3px 0;border:1px solid #ccc;border-left:none;<?php echo $activeLang === 'en' ? 'background:#1f8f43;color:#fff;font-weight:700;border-color:#1f8f43' : 'background:#f8f9fa;color:#495057'; ?>">EN</a>
</div>
