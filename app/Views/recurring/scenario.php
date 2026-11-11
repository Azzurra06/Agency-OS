<?php
/** @var array<string, mixed> $view */
/** @var bool $isDemo */
use App\Support\Format;

$s = $view['scenario'];
$p = $view['projection'];
$isDraft = $s['status'] === 'draft';
$months = $p['months'] ?? [];
$last = $months === [] ? null : $months[count($months) - 1];
$frozenDate = $s['finalized_at'] === null ? null : Format::date(substr((string) $s['finalized_at'], 0, 10));
$status = match ($s['status']) {
    'finalized' => 'Version figée le ' . $frozenDate . ' · v' . $s['version'],
    'superseded' => 'Version figée le ' . $frozenDate . ' · v' . $s['version'] . ' · remplacée',
    'archived' => 'Version figée le ' . $frozenDate . ' · v' . $s['version'] . ' · archivée',
    default => 'Brouillon · v' . $s['version'],
};
$chart = ['labels' => array_map(static fn (array $m): string => 'M' . $m['month'], $months), 'mrr' => array_map(static fn (array $m): float => (float) $m['mrr'], $months)];
?>
<div data-scenario data-id="<?= attr($s['public_id']) ?>" data-editable="<?= attr($isDraft ? '1' : '0') ?>">
<header class="page-header">
  <div class="page-header__titles">
    <p class="text-small text-secondary"><a href="/app/recurrent">Revenus récurrents</a> / Scénario</p>
    <h1><?= e($s['name']) ?></h1>
    <p class="page-header__sub">Projection simulée : elle n'est jamais additionnée à votre MRR réel.</p>
  </div>
  <div class="cluster">
    <?php if ($isDraft): ?>
      <button class="btn btn--ghost" type="button" data-scenario-delete><?= ui_icon('x', 'sm') ?>Supprimer le brouillon</button>
      <button class="btn btn--primary" type="button" data-scenario-finalize><?= ui_icon('lock', 'sm') ?>Finaliser</button>
    <?php else: ?>
      <?php if ($s['status'] === 'finalized'): ?><button class="btn btn--ghost" type="button" data-scenario-archive><?= ui_icon('archive', 'sm') ?>Archiver</button><?php endif; ?>
      <button class="btn btn--primary" type="button" data-scenario-new-version><?= ui_icon('copy', 'sm') ?>Dupliquer pour modifier</button>
    <?php endif; ?>
  </div>
</header>

<div class="status-strip">
  <?php if ($isDraft): ?><span class="badge badge--outline"><?= e($status) ?></span><?php else: ?><?= ui_nature('frozen', $status) ?><?php endif; ?>
  <?= ui_nature('simulated') ?>
  <span>MRR réel aujourd'hui (contrats signés) : <strong class="num"><?= e(Format::money($view['contracted_mrr'])) ?></strong>, affiché à titre de repère, jamais additionné.</span>
</div>

<?php if ($isDemo): ?>
  <p class="alert alert--neutral"><?= ui_icon('flag', 'sm') ?><span>Valeurs fictives de démonstration. N'indiquez pas les chiffres réels de votre entreprise.</span></p>
<?php endif; ?>

<form class="card stack stack--lg" data-scenario-form novalidate>
  <fieldset class="sim-fieldset"<?php if (!$isDraft): ?> disabled<?php endif; ?>>
    <div class="two-col">
      <div class="field"><label class="field__label" for="s-name">Nom du scénario</label><input class="input" id="s-name" name="name" maxlength="150" value="<?= attr($s['name']) ?>"></div>
      <div class="field"><span class="field__label">Horizon</span>
        <div class="segmented" role="radiogroup" aria-label="Horizon de projection">
          <label><input type="radio" name="horizon" value="6"<?php if ($s['horizon'] === 6): ?> checked<?php endif; ?>>6 mois</label>
          <label><input type="radio" name="horizon" value="12"<?php if ($s['horizon'] === 12): ?> checked<?php endif; ?>>12 mois</label>
        </div>
      </div>
    </div>
    <div class="table-wrap">
      <table class="table entries-table">
        <thead><tr>
          <th scope="col">Formule</th>
          <th scope="col">Clients au départ</th>
          <th scope="col">Nouveaux par mois</th>
          <th scope="col"><span class="compare-head">Résiliations par mois<?= ui_tip('tip-churn', 'Résiliations', 'Taux de résiliation mensuel', 'Part des clients qui résilient chaque mois. 3 % par mois signifie qu\'environ un tiers des clients partent en un an.') ?></span></th>
        </tr></thead>
        <tbody>
          <?php foreach ($view['entries'] as $e): if ($e['offer'] === null) { continue; } ?>
            <tr data-entry="<?= attr($e['offer']['public_id']) ?>">
              <th scope="row"><?= e($e['offer']['name']) ?><br><span class="text-micro text-tertiary"><?= e($e['offer']['active_contracts'] . ' contrats signés aujourd\'hui') ?></span></th>
              <td><input class="input num" aria-label="Clients au départ" data-field="starting_clients" inputmode="decimal" value="<?= attr(Format::number($e['starting_clients'])) ?>"></td>
              <td><input class="input num" aria-label="Nouveaux clients par mois" data-field="new_clients_per_month" inputmode="decimal" value="<?= attr(Format::number($e['new_clients_per_month'])) ?>"></td>
              <td><div class="input-affix"><input class="input num" aria-label="Résiliations par mois" data-field="monthly_churn_pct" inputmode="decimal" value="<?= attr(Format::number($e['monthly_churn_pct'])) ?>"><span class="input-affix__unit">%</span></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </fieldset>
  <p class="alert alert--danger" data-form-summary hidden></p>
  <?php if ($isDraft): ?>
    <div class="cluster cluster--between">
      <span class="text-small text-secondary" data-save-status>La projection se met à jour pendant la saisie, sans rien enregistrer.</span>
      <button class="btn btn--secondary" type="button" data-scenario-save><?= ui_icon('save', 'sm') ?>Enregistrer le scénario</button>
    </div>
  <?php endif; ?>
</form>

<div class="kpi-grid">
  <article class="card kpi kpi--simulated"><div class="kpi__top"><h2 class="kpi__label">MRR simulé en fin d'horizon</h2><?= ui_nature('simulated') ?></div><p class="kpi__value"><span data-p="final_mrr"><?= e($last === null ? '—' : Format::number($last['mrr'], 0)) ?></span><span class="kpi__unit">€ / mois</span></p><div class="kpi__meta"><span>≈ <span data-p="final_clients"><?= e($last['clients_rounded'] ?? '—') ?></span> clients au mois <span data-p="horizon"><?= e($s['horizon']) ?></span></span></div></article>
  <article class="card kpi kpi--simulated"><div class="kpi__top"><h2 class="kpi__label">Cumul simulé</h2><?= ui_nature('simulated') ?></div><p class="kpi__value"><span data-p="cumulative"><?= e($p['cumulative'] === null ? '—' : Format::number($p['cumulative'], 0)) ?></span><span class="kpi__unit">€</span></p><div class="kpi__meta"><span>dont 6 premiers mois : <span class="num" data-p="cumulative_6"><?= e($p['cumulative_6'] === null ? '—' : Format::money($p['cumulative_6'], 0)) ?></span></span></div></article>
  <article class="card kpi kpi--simulated"><div class="kpi__top"><h2 class="kpi__label">Capacité en fin d'horizon</h2><?= ui_nature('simulated') ?></div><p class="kpi__value"><span data-p="final_capacity"><?= e(($last['capacity_use_pct'] ?? null) === null ? '—' : Format::percent($last['capacity_use_pct'])) ?></span></p><div class="kpi__meta"><span><span class="num" data-p="final_hours"><?= e($last === null ? '—' : Format::hours($last['hours'], 1)) ?></span> par mois</span></div></article>
</div>
<p class="alert alert--warning" data-p-capacity<?php if (!($p['capacity_exceeded'] ?? false)): ?> hidden<?php endif; ?>><?= ui_icon('triangle-alert', 'sm') ?><span>La projection dépasse votre capacité de production mensuelle : prévoyez un recrutement ou de la sous-traitance.</span></p>

<section class="card" aria-labelledby="t-chart">
  <div class="card__header"><div class="card__title"><h2 id="t-chart">MRR simulé, mois par mois</h2><?= ui_tip('tip-chart', 'Projection', 'Lire la projection', 'Courbe en pointillés : valeurs simulées. Nₜ = Nₜ₋₁ × (1 − résiliations) + nouveaux clients ; MRR = clients × prix mensuel normalisé.') ?></div><?= ui_nature('simulated') ?></div>
  <div class="chart-box"><canvas id="chart-projection" role="img" aria-label="<?= attr('MRR simulé : ' . implode(', ', array_map(static fn (array $m): string => 'mois ' . $m['month'] . ' ' . Format::money($m['mrr'], 0), $months))) ?>"></canvas></div>
  <script type="application/json" id="data-projection"><?= json_script($chart) ?></script>
  <div class="table-wrap">
    <table class="table projection-table">
      <thead><tr><th scope="col">Mois</th><th scope="col">Clients (≈)</th><th scope="col">MRR simulé</th><th scope="col">Heures nécessaires</th><th scope="col">Capacité</th></tr></thead>
      <tbody data-p-rows>
        <?php foreach ($months as $m): ?>
          <tr><td><?= e($m['month']) ?></td><td class="num"><?= e($m['clients_rounded']) ?></td><td class="num"><?= e(Format::money($m['mrr'])) ?></td><td class="num"><?= e(Format::hours($m['hours'], 1)) ?></td><td class="num"><?= e($m['capacity_use_pct'] === null ? '—' : Format::percent($m['capacity_use_pct'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if (count($view['versions']) > 1 || !$isDraft): ?>
  <section class="card" aria-labelledby="t-versions">
    <div class="card__header"><h2 id="t-versions">Historique des versions</h2></div>
    <ul class="version-list">
      <?php foreach ($view['versions'] as $v): ?>
        <li><a href="/app/recurrent/scenarios/<?= attr($v['public_id']) ?>"<?php if ($v['public_id'] === $s['public_id']): ?> aria-current="page"<?php endif; ?>>v<?= e($v['version']) ?></a>
          <span class="text-small text-secondary"><?= e(match ($v['status']) { 'draft' => 'Brouillon', 'finalized' => 'Figée le ' . Format::date(substr((string) $v['finalized_at'], 0, 10)), 'superseded' => 'Remplacée', default => 'Archivée' }) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>
</div>
