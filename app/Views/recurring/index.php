<?php
/** @var array<string, mixed> $summary */
/** @var list<array<string, mixed>> $offers */
/** @var list<array<string, mixed>> $scenarios */
/** @var bool $isDemo */
use App\Support\Format;

$period = static fn (string $p): string => $p === 'annual' ? '/ an' : '/ mois';
$statusLabel = static fn (array $s): string => match ($s['status']) {
    'draft' => 'Brouillon · v' . $s['version'],
    'finalized' => 'Figé le ' . Format::date(substr((string) $s['finalized_at'], 0, 10)) . ' · v' . $s['version'],
    'superseded' => 'Remplacé · v' . $s['version'],
    default => 'Archivé · v' . $s['version'],
};
?>
<header class="page-header">
  <div class="page-header__titles">
    <div class="card__title"><h1>Revenus récurrents</h1><?= ui_term('revenus-recurrents', 'Ici, la zone « Réel » ne compte que les contrats signés ; la zone « Prévisionnel » contient des simulations, jamais ajoutées au réel.') ?></div>
    <p class="page-header__sub">Vos contrats de maintenance, d'hébergement ou de SEO, et leur potentiel.</p>
  </div>
  <a class="btn btn--secondary" href="/app/recurrent/formules/nouvelle"><?= ui_icon('plus', 'sm') ?>Nouvelle formule</a>
</header>

<?php if ($isDemo): ?>
  <p class="alert alert--neutral"><?= ui_icon('flag', 'sm') ?><span>Valeurs fictives de démonstration. N'indiquez pas les chiffres réels de votre entreprise.</span></p>
<?php endif; ?>

<section class="zone zone--real" aria-labelledby="t-real">
  <div class="zone__head">
    <h2 id="t-real">Réel : contrats signés</h2>
    <?= ui_nature('calculated', 'Calculé à partir des contrats signés') ?>
    <?php if ($summary['contracts_updated_at'] !== null): ?><span class="text-small text-secondary">Contrats mis à jour le <?= e(Format::date(substr((string) $summary['contracts_updated_at'], 0, 10))) ?></span><?php endif; ?>
  </div>
  <div class="kpi-grid">
    <article class="card kpi">
      <div class="kpi__top"><div class="card__title"><h3 class="kpi__label">MRR contractualisé</h3><?= ui_term('mrr', 'C\'est un indicateur de suivi, pas un encaissement.') ?></div></div>
      <p class="kpi__value"><?= e(Format::number($summary['mrr'], 0)) ?><span class="kpi__unit">€ / mois</span></p>
      <div class="kpi__meta"><span>ARR <span class="num"><?= e(Format::money($summary['arr'], 0)) ?></span></span></div>
    </article>
    <article class="card kpi">
      <div class="kpi__top"><div class="card__title"><h3 class="kpi__label">Couverture du CA</h3><?= ui_term('couverture-ca', 'Elle est affichée à côté de la part récurrente que vous avez déclarée, sans la remplacer.') ?></div></div>
      <?php if ($summary['coverage_pct'] === null): ?>
        <p class="kpi__value">Non calculable</p>
        <div class="kpi__meta"><span>Renseignez le chiffre d'affaires de la période.</span><a href="/app/parametres/finances">Compléter</a></div>
      <?php else: ?>
        <p class="kpi__value"><?= e(Format::percent($summary['coverage_pct'])) ?></p>
        <div class="kpi__meta"><span>Part déclarée : <?= e($summary['declared_share_pct'] === null ? 'non renseignée' : Format::percent($summary['declared_share_pct'])) ?> <?= ui_nature('declared') ?></span></div>
      <?php endif; ?>
    </article>
    <article class="card kpi">
      <div class="kpi__top"><div class="card__title"><h3 class="kpi__label">Capacité utilisée</h3><?= ui_term('capacite-utilisee') ?></div></div>
      <p class="kpi__value"><?= e($summary['capacity_use_pct'] === null ? '—' : Format::percent($summary['capacity_use_pct'])) ?></p>
      <div class="kpi__meta"><span><span class="num"><?= e(Format::hours($summary['hours_needed'], 1)) ?></span> par mois<?= e($summary['monthly_capacity'] === null ? '' : ' sur ' . Format::hours($summary['monthly_capacity'], 1)) ?></span></div>
    </article>
  </div>

  <?php if ($summary['gap_alert']): ?>
    <div class="alert alert--warning"><?= ui_icon('triangle-alert', 'sm') ?>
      <div class="stack stack--sm">
        <span>La couverture calculée (<?= e(Format::percent($summary['coverage_pct'])) ?>) s'écarte de <?= e(Format::number($summary['gap_points'], 1)) ?> points de la part déclarée (<?= e(Format::percent($summary['declared_share_pct'])) ?>). Vérifiez vos contrats, ou mettez à jour la part déclarée si elle est obsolète.</span>
        <div><button class="btn btn--secondary btn--sm" type="button" data-accept-share>Remplacer la part déclarée par <?= e(Format::percent($summary['coverage_pct'])) ?></button></div>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($summary['unprofitable'] !== []): ?>
    <p class="alert alert--danger"><?= ui_icon('triangle-alert', 'sm') ?><span>Formule(s) déficitaire(s) par contrat : <?= e(implode(', ', $summary['unprofitable'])) ?>. Revoyez leur prix ou le temps inclus.</span></p>
  <?php endif; ?>

  <?php if ($summary['breakdown'] !== [] && $summary['mrr'] !== '0.00'): ?>
    <div class="card">
      <div class="card__header"><h3>Répartition du MRR par formule</h3></div>
      <div class="stacked-bar" role="img" aria-label="<?= attr(implode(', ', array_map(static fn (array $b): string => $b['name'] . ' ' . Format::percent($b['share_pct'] ?? '0'), $summary['breakdown']))) ?>">
        <?php foreach ($summary['breakdown'] as $i => $b): if ($b['share_pct'] === null || $b['share_pct'] === '0.00') { continue; } ?>
          <span class="stacked-bar__seg stacked-bar__seg--<?= attr($i % 4) ?>" data-width="<?= attr($b['share_pct']) ?>"></span>
        <?php endforeach; ?>
      </div>
      <ul class="legend">
        <?php foreach ($summary['breakdown'] as $i => $b): ?>
          <li><span class="legend__dot stacked-bar__seg--<?= attr($i % 4) ?>"></span><?= e($b['name']) ?> · <span class="num"><?= e(Format::money($b['mrr'])) ?></span> (<?= e($b['contracts']) ?> contrats)</li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card__header"><h3>Formules</h3></div>
    <?php if ($offers === []): ?>
      <div class="empty"><span class="empty__icon"><?= ui_icon('repeat') ?></span><h4>Aucune formule récurrente</h4><p class="empty__text text-small">Créez votre offre de maintenance, d'hébergement ou de SEO pour suivre votre MRR.</p><a class="btn btn--primary" href="/app/recurrent/formules/nouvelle">Créer une formule</a></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th scope="col">Formule</th><th scope="col">Prix</th><th scope="col">Résultat par contrat</th><th scope="col">Contrats signés</th><th scope="col">MRR</th></tr></thead>
          <tbody>
            <?php foreach ($offers as $o): $econ = $o['economics']; ?>
              <tr data-offer-row="<?= attr($o['public_id']) ?>">
                <td><a href="/app/recurrent/formules/<?= attr($o['public_id']) ?>"><?= e($o['name']) ?></a><?php if (!$o['is_active']): ?> <span class="badge">Inactive</span><?php endif; ?></td>
                <td class="num"><?= e(Format::money($o['price']) . ' ' . $period($o['billing_period'])) ?></td>
                <td class="num"><?php if ($econ['monthly_result'] === null): ?>Non calculable<?php else: ?><span class="<?= attr($econ['unprofitable'] ? 'text-danger' : '') ?>"><?= e(Format::money($econ['monthly_result']) . ' / mois · ' . Format::percent($econ['margin_on_price_pct'])) ?></span><?php endif; ?></td>
                <td>
                  <div class="contracts-edit">
                    <label class="visually-hidden" for="c-<?= attr($o['public_id']) ?>">Contrats signés : <?= e($o['name']) ?></label>
                    <input class="input num" id="c-<?= attr($o['public_id']) ?>" inputmode="numeric" value="<?= attr($o['active_contracts']) ?>" data-contracts-input>
                    <button class="btn btn--ghost btn--sm" type="button" data-contracts-save><?= ui_icon('save', 'sm') ?><span class="visually-hidden">Enregistrer</span></button>
                  </div>
                  <span class="text-micro text-tertiary" data-contracts-date><?= e($o['contracts_updated_at'] === null ? '' : 'mis à jour le ' . Format::date(substr((string) $o['contracts_updated_at'], 0, 10))) ?></span>
                </td>
                <td class="num"><?= e(Format::money($o['mrr'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="zone zone--forecast" aria-labelledby="t-forecast">
  <div class="zone__head">
    <h2 id="t-forecast">Prévisionnel : scénarios</h2>
    <?= ui_nature('simulated') ?>
    <span class="text-small text-secondary">Jamais additionné au réel.</span>
    <?php if ($offers !== []): ?><button class="btn btn--secondary btn--sm zone__action" type="button" data-scenario-create><?= ui_icon('plus', 'sm') ?>Nouveau scénario</button><?php endif; ?>
  </div>
  <?php if ($scenarios === []): ?>
    <p class="text-small text-secondary">Aucun scénario. Un scénario projette vos formules sur 6 ou 12 mois à partir de vos contrats actuels, avec vos hypothèses de nouveaux clients et de résiliations.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Scénario</th><th scope="col">Statut</th><th scope="col">MRR simulé en fin d'horizon</th><th scope="col">Cumul simulé</th></tr></thead>
        <tbody>
          <?php foreach ($scenarios as $s): ?>
            <tr>
              <td><a href="/app/recurrent/scenarios/<?= attr($s['public_id']) ?>"><?= e($s['name']) ?></a></td>
              <td><?php if ($s['status'] === 'draft'): ?><span class="badge badge--outline"><?= e($statusLabel($s)) ?></span><?php else: ?><?= ui_nature('frozen', $statusLabel($s)) ?><?php endif; ?></td>
              <td class="num"><?= e($s['final_mrr'] === null ? '—' : Format::money($s['final_mrr']) . ' (mois ' . $s['horizon'] . ')') ?></td>
              <td class="num"><?= e($s['cumulative'] === null ? '—' : Format::money($s['cumulative'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
