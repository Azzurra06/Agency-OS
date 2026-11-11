<?php
/** @var array{groups: array<string, list<array<string, mixed>>>, progress: array{done: int, total: int, pct: int}} $plan */
/** @var bool $isDemo */
use App\Support\Format;

$groupLabels = ['todo' => 'À faire', 'in_progress' => 'En cours', 'done' => 'Terminé'];
$progress = $plan['progress'];
?>
<header class="page-header">
  <div class="page-header__titles">
    <p class="text-small text-secondary"><a href="/app/strategie">Stratégie</a> / Plan d'action</p>
    <h1>Plan d'action</h1>
    <p class="page-header__sub">Les recommandations acceptées et vos actions libres.</p>
  </div>
</header>

<?php if ($isDemo): ?>
  <p class="alert alert--neutral"><?= ui_icon('flag', 'sm') ?><span>Démo : plan d'action fictif de l'agence Lumen.</span></p>
<?php endif; ?>

<section class="card" aria-labelledby="t-progress">
  <div class="card__header"><h2 id="t-progress">Progression</h2><span class="num"><?= e($progress['done'] . ' / ' . $progress['total'] . ' actions terminées') ?></span></div>
  <div class="progress progress--success" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= attr($progress['pct']) ?>" aria-label="Progression du plan"><span class="progress__bar" data-value="<?= attr($progress['pct']) ?>"></span></div>
</section>

<section class="card" aria-labelledby="t-new-action">
  <div class="card__header"><h2 id="t-new-action">Ajouter une action libre</h2></div>
  <form class="action-form" data-action-create novalidate>
    <div class="field"><label class="field__label" for="a-title">Action</label><input class="input" id="a-title" name="title" maxlength="160" required><p class="field__error" data-error-for="title"></p></div>
    <div class="field"><label class="field__label" for="a-due">Échéance</label><input class="input" id="a-due" name="due_date" type="date"><p class="field__error" data-error-for="due_date"></p></div>
    <button class="btn btn--secondary" type="submit"><?= ui_icon('plus', 'sm') ?>Ajouter</button>
  </form>
</section>

<?php foreach ($plan['groups'] as $status => $items): ?>
  <section class="card" aria-labelledby="t-<?= attr($status) ?>">
    <div class="card__header"><h2 id="t-<?= attr($status) ?>"><?= e($groupLabels[$status]) ?></h2><span class="badge"><?= e(count($items)) ?></span></div>
    <?php if ($items === []): ?><p class="text-small text-secondary">Aucune action.</p><?php endif; ?>
    <div class="action-list">
      <?php foreach ($items as $a): ?>
        <article class="action-item<?php if ($a['overdue']): ?> action-item--overdue<?php endif; ?>" id="action-<?= attr($a['public_id']) ?>" data-action="<?= attr($a['public_id']) ?>">
          <div class="action-item__head">
            <h3 class="action-item__title"><?= e($a['title']) ?></h3>
            <div class="cluster">
              <?php if ($a['goal_reached']): ?><span class="badge badge--success"><?= ui_icon('circle-check', 'sm') ?>Objectif atteint</span><?php endif; ?>
              <?php if ($a['overdue']): ?><span class="badge badge--danger">En retard</span><?php endif; ?>
              <?php if ($a['due_date'] !== null): ?><span class="text-small text-secondary"><?= ui_icon('calendar', 'sm') ?> <?= e(Format::date($a['due_date'])) ?></span><?php endif; ?>
            </div>
          </div>
          <?php if ($a['recommendation'] !== null): ?><p class="text-micro text-tertiary">Issue de la recommandation « <?= e($a['recommendation']['title']) ?> »<?= e($a['goal_reached'] ? ' : la condition n\'est plus remplie, vous pouvez clôturer l\'action.' : '') ?></p><?php endif; ?>
          <?php if ($a['tasks'] !== []): ?>
            <ul class="task-list">
              <?php foreach ($a['tasks'] as $t): ?>
                <li><label class="check-row"><input class="checkbox" type="checkbox" data-task="<?= attr($t['public_id']) ?>"<?php if ($t['is_done']): ?> checked<?php endif; ?>><span><?= e($t['label']) ?></span></label></li>
              <?php endforeach; ?>
            </ul>
            <p class="text-micro text-tertiary"><?= e($a['tasks_done'] . ' sur ' . count($a['tasks']) . ' sous-actions faites') ?></p>
          <?php endif; ?>
          <div class="cluster action-item__controls">
            <label class="visually-hidden" for="st-<?= attr($a['public_id']) ?>">Statut</label>
            <select class="input input--sm" id="st-<?= attr($a['public_id']) ?>" data-action-status>
              <?php foreach ($groupLabels as $value => $label): ?><option value="<?= attr($value) ?>"<?php if ($a['status'] === $value): ?> selected<?php endif; ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <label class="visually-hidden" for="due-<?= attr($a['public_id']) ?>">Échéance</label>
            <input class="input input--sm" id="due-<?= attr($a['public_id']) ?>" type="date" data-action-due value="<?= attr($a['due_date'] ?? '') ?>">
            <form class="task-add" data-task-add novalidate><label class="visually-hidden" for="nt-<?= attr($a['public_id']) ?>">Nouvelle sous-action</label><input class="input input--sm" id="nt-<?= attr($a['public_id']) ?>" name="label" maxlength="200" placeholder="Nouvelle sous-action"><button class="btn btn--ghost btn--sm" type="submit"><?= ui_icon('plus', 'sm') ?><span class="visually-hidden">Ajouter la sous-action</span></button></form>
            <button class="btn btn--ghost btn--sm" type="button" data-action-delete><?= ui_icon('x', 'sm') ?>Supprimer</button>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
