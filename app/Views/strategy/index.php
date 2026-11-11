<?php
/** @var array{items: list<array<string, mixed>>, counts: array<string, int>} $list */
/** @var string $tabKey */
/** @var array<string, string> $tabs */
/** @var string|null $module */
/** @var list<int> $snoozeDays */
/** @var bool $isDemo */
use App\Domain\Strategy\RuleCatalog;
use App\Support\Format;

$tabLabels = ['nouvelles' => 'Nouvelles', 'acceptees' => 'Acceptées', 'reportees' => 'Reportées', 'ignorees' => 'Ignorées', 'resolues' => 'Résolues'];
$modules = ['pricing' => 'Pricing', 'recurring' => 'Récurrent', 'diagnostic' => 'Diagnostic', 'strategy' => 'Stratégie'];
$severityBadge = ['critical' => 'danger', 'high' => 'warning', 'medium' => 'accent', 'info' => 'outline'];
$difficulty = ['low' => 'Facile', 'medium' => 'Moyenne', 'high' => 'Difficile'];
$missingLabels = ['annual_volume' => 'volume annuel de la prestation', 'diagnostic_partial' => 'diagnostic partiel'];
$staleLabels = ['financial_profile' => 'profil financier', 'pricing_simulation' => 'simulation de prix', 'recurring_contracts' => 'contrats récurrents', 'diagnostic' => 'diagnostic'];
$query = static fn (string $tab, ?string $mod): string => '/app/strategie?onglet=' . $tab . ($mod === null ? '' : '&module=' . $mod);
?>
<header class="page-header">
  <div class="page-header__titles">
    <div class="card__title"><h1>Stratégie</h1><?= ui_tip('tip-strat', 'Stratégie', 'D\'où viennent ces recommandations ?', 'Un moteur de règles déterministe analyse votre profil, vos prix figés, vos formules récurrentes et votre dernier diagnostic. Chaque recommandation indique ses sources, les valeurs qui l\'ont déclenchée et les seuils appliqués. Les brouillons n\'en déclenchent jamais.') ?></div>
    <p class="page-header__sub">Recommandations priorisées et plan d'action.</p>
  </div>
  <div class="cluster">
    <button class="btn btn--ghost" type="button" data-reco-evaluate><?= ui_icon('rotate-ccw', 'sm') ?>Réévaluer</button>
    <a class="btn btn--primary" href="/app/strategie/plan"><?= ui_icon('flag', 'sm') ?>Plan d'action</a>
  </div>
</header>

<?php if ($isDemo): ?>
  <p class="alert alert--neutral"><?= ui_icon('flag', 'sm') ?><span>Démo : recommandations calculées sur les valeurs fictives de l'agence Lumen.</span></p>
<?php endif; ?>

<nav class="settings-tabs" aria-label="Statut des recommandations">
  <?php foreach ($tabs as $key => $status): ?>
    <a href="<?= attr($query($key, $module)) ?>"<?php if ($tabKey === $key): ?> aria-current="page"<?php endif; ?>><?= e($tabLabels[$key]) ?> <span class="badge"><?= e($list['counts'][$status] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>
<nav class="chips" aria-label="Filtrer par module">
  <a class="chip" href="<?= attr($query($tabKey, null)) ?>"<?php if ($module === null): ?> aria-current="true"<?php endif; ?>>Tous les modules</a>
  <?php foreach ($modules as $key => $label): ?>
    <a class="chip" href="<?= attr($query($tabKey, $key)) ?>"<?php if ($module === $key): ?> aria-current="true"<?php endif; ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($list['items'] === []): ?>
  <section class="card"><div class="empty"><span class="empty__icon"><?= ui_icon('circle-check') ?></span><h2>Aucune recommandation ici</h2><p class="empty__text text-small"><?= e($tabKey === 'nouvelles' ? 'Rien de nouveau : complétez votre profil, finalisez vos simulations de prix et faites le diagnostic pour des recommandations plus précises.' : 'Cette liste est vide.') ?></p></div></section>
<?php endif; ?>

<div class="reco-list">
  <?php foreach ($list['items'] as $r): ?>
    <article class="card reco reco--<?= attr($r['severity']) ?>" data-reco="<?= attr($r['public_id']) ?>">
      <div class="reco__head">
        <div class="stack stack--sm">
          <div class="cluster">
            <span class="badge badge--<?= attr($severityBadge[$r['severity']] ?? 'outline') ?>">Priorité <?= e($r['priority_label']) ?> · <?= e($r['priority']) ?></span>
            <?php if ($r['reappeared']): ?><span class="badge badge--warning">Réapparue</span><?php endif; ?>
            <?php if ($r['is_demo']): ?><?= ui_nature('demo') ?><?php endif; ?>
            <span class="text-micro text-tertiary">Difficulté : <?= e($difficulty[$r['difficulty']] ?? '—') ?> · <?= e(implode(', ', array_map(static fn (string $m): string => $modules[$m] ?? $m, $r['modules']))) ?></span>
          </div>
          <h2 class="reco__title"><?= e($r['title']) ?></h2>
          <p class="text-small text-secondary reco__sources"><strong>Repose sur :</strong> <?= e($r['sources'] === [] ? 'absence de données' : implode(' · ', $r['sources'])) ?></p>
        </div>
        <?php if ($r['impact_kind'] === 'quantified' && $r['impact_amount'] !== null): ?>
          <div class="reco__impact"><span class="text-micro text-tertiary">Impact estimé</span><span class="num"><?= e(trim(Format::money($r['impact_amount'], 0) . ' ' . RuleCatalog::impactUnit($r['rule_code']))) ?></span><?= ui_nature('calculated') ?></div>
        <?php endif; ?>
      </div>
      <?php if ($r['missing'] !== [] || $r['stale'] !== []): ?>
        <p class="alert alert--warning"><?= ui_icon('triangle-alert', 'sm') ?><span>
          <?php if ($r['missing'] !== []): ?>Données incomplètes : <?= e(implode(', ', array_map(static fn (string $m): string => $missingLabels[$m] ?? $m, $r['missing']))) ?>. <?php endif; ?>
          <?php if ($r['stale'] !== []): ?>Repose sur des données anciennes : <?= e(implode(', ', array_map(static fn (string $m): string => $staleLabels[$m] ?? $m, $r['stale']))) ?>. Mettez-les à jour pour fiabiliser la recommandation.<?php endif; ?>
        </span></p>
      <?php endif; ?>
      <p><?= e($r['explanation']) ?></p>
      <p class="text-small"><strong>Impact :</strong> <?= e($r['impact_text']) ?></p>
      <?php if ($r['actions'] !== []): ?>
        <div class="stack stack--sm"><p class="text-small"><strong>Actions concrètes</strong></p><ul class="reco__actions"><?php foreach ($r['actions'] as $action): ?><li><?= e($action) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <details class="reco__details">
        <summary>Voir le détail du calcul</summary>
        <dl class="kv">
          <?php foreach ($r['values'] as $k => $val): if ($val === null || $val === '') { continue; } ?><dt><?= e(RuleCatalog::valueLabel((string) $k)) ?></dt><dd class="num"><?= e($val) ?></dd><?php endforeach; ?>
          <?php foreach ($r['thresholds'] as $k => $val): ?><dt><?= e(RuleCatalog::valueLabel((string) $k)) ?></dt><dd class="num"><?= e($val) ?></dd><?php endforeach; ?>
          <dt>Règle</dt><dd><?= e($r['rule_code']) ?></dd>
          <dt>Dernière évaluation</dt><dd><?= e(Format::date(substr((string) $r['last_evaluated_at'], 0, 10))) ?></dd>
        </dl>
      </details>
      <div class="cluster reco__buttons">
        <?php if (in_array($r['status'], ['new', 'snoozed', 'dismissed'], true)): ?>
          <button class="btn btn--primary btn--sm" type="button" data-reco-accept><?= ui_icon('plus', 'sm') ?>Ajouter au plan d'action</button>
        <?php endif; ?>
        <?php if ($r['status'] === 'new'): ?>
          <label class="visually-hidden" for="snooze-<?= attr($r['public_id']) ?>">Reporter de</label>
          <select class="input input--sm" id="snooze-<?= attr($r['public_id']) ?>" data-reco-snooze-days><?php foreach ($snoozeDays as $d): ?><option value="<?= attr($d) ?>"><?= e($d . ' jours') ?></option><?php endforeach; ?></select>
          <button class="btn btn--ghost btn--sm" type="button" data-reco-snooze><?= ui_icon('clock', 'sm') ?>Reporter</button>
          <button class="btn btn--ghost btn--sm" type="button" data-reco-dismiss><?= ui_icon('x', 'sm') ?>Ignorer</button>
        <?php endif; ?>
        <?php if (in_array($r['status'], ['snoozed', 'dismissed', 'accepted'], true)): ?>
          <button class="btn btn--ghost btn--sm" type="button" data-reco-reopen><?= ui_icon('rotate-ccw', 'sm') ?>Remettre dans les nouvelles</button>
        <?php endif; ?>
        <?php if ($r['action'] !== null): ?><a class="btn btn--ghost btn--sm" href="/app/strategie/plan#action-<?= attr($r['action']) ?>">Voir dans le plan</a><?php endif; ?>
        <?php if ($r['status'] === 'snoozed' && $r['snoozed_until'] !== null): ?><span class="text-small text-secondary">Reportée jusqu'au <?= e(Format::date($r['snoozed_until'])) ?></span><?php endif; ?>
        <?php if ($r['status'] === 'auto_resolved'): ?><span class="badge badge--success">Résolue automatiquement : la condition n'est plus remplie</span><?php endif; ?>
        <?php if ($r['status'] === 'done'): ?><span class="badge badge--success">Action terminée</span><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
