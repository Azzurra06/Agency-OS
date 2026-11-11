<?php
/** @var array{type: string, message: string}|null $flash */
?>
<?php if ($flash !== null): ?>
  <p class="flash flash--<?= attr(in_array($flash['type'], ['error', 'success', 'info'], true) ? $flash['type'] : 'info') ?>" role="<?= attr($flash['type'] === 'error' ? 'alert' : 'status') ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
