<?php
/** @var array<string, mixed> $view */
/** @var string|null $scenarioId */
/** @var bool $isDemo */
use App\Application\PricingService;
use App\Support\Format;

$sim = $view['simulation'];
$scenarios = $view['scenarios'];
$isDraft = $sim['status'] === 'draft';
$current = $scenarios[0];
foreach ($scenarios as $s) {
    if ($s['public_id'] === $scenarioId || ($scenarioId === null && $s['is_reference'])) {
        $current = $s;
        break;
    }
}
$form = $current['form'];
$computed = $current['computed'];
$results = $computed['results'] ?? null;
$atPrice = $results['at_price'] ?? null;
$hc = $computed['inputs']['hourly_cost'] ?? ['value' => null, 'nature' => 'calculated', 'assumptions' => [], 'source' => 'profile'];
$tvInfo = $computed['inputs']['variable_rate_pct'] ?? ['value' => null, 'available' => false, 'period' => null];
$money = static fn (?string $v): string => $v === null ? '—' : Format::money($v);
$pct = static fn (?string $v): string => $v === null ? '—' : Format::percent($v);
$frozenDate = $sim['finalized_at'] === null ? null : Format::date(substr((string) $sim['finalized_at'], 0, 10));
$statusLabel = match ($sim['status']) {
    'finalized' => 'Version figée le ' . $frozenDate . ' · v' . $sim['version'] . ($sim['service'] !== null ? ' · active pour ' . $sim['service']['name'] : ''),
    'superseded' => 'Version figée le ' . $frozenDate . ' · v' . $sim['version'] . ' · remplacée par une version plus récente',
    'archived' => 'Version figée le ' . $frozenDate . ' · v' . $sim['version'] . ' · archivée',
    default => 'Brouillon · v' . $sim['version'],
};
$editable = $isDraft;
$drift = $view['drift'];
$base = '/app/pricing/' . $sim['public_id'];
?>
<div class="page--with-summary-inner" data-pricing data-simulation="<?= attr($sim['public_id']) ?>" data-scenario="<?= attr($current['public_id']) ?>" data-editable="<?= attr($editable ? '1' : '0') ?>">
<header class="page-header">
  <div class="page-header__titles">
    <p class="text-small text-secondary"><a href="/app/pricing">Pricing</a> / <?= e($sim['service']['name'] ?? 'Sur mesure') ?></p>
    <h1><?= e($sim['name']) ?></h1>
    <p class="page-header__sub">Votre prix couvre-t-il vos coûts et votre marge cible ?</p>
  </div>
  <div class="cluster">
    <?php if ($isDraft): ?>
      <button class="btn btn--ghost" type="button" data-pricing-delete><?= ui_icon('x', 'sm') ?>Supprimer le brouillon</button>
      <button class="btn btn--primary" type="button" data-pricing-finalize><?= ui_icon('lock', 'sm') ?>Finaliser la simulation</button>
    <?php else: ?>
      <?php if ($sim['status'] === 'finalized'): ?><button class="btn btn--ghost" type="button" data-pricing-archive><?= ui_icon('archive', 'sm') ?>Archiver</button><?php endif; ?>
      <button class="btn btn--primary" type="button" data-pricing-new-version><?= ui_icon('copy', 'sm') ?>Nouvelle version</button>
    <?php endif; ?>
  </div>
</header>

<div class="status-strip">
  <?php if ($isDraft): ?>
    <span class="badge badge--outline"><?= e($statusLabel) ?></span>
    <span><strong>Sans effet sur la stratégie</strong> tant qu'il n'est pas finalisé. Les résultats sont recalculés en direct, rien n'est enregistré sans votre accord.</span>
  <?php else: ?>
    <?= ui_nature('frozen', $statusLabel) ?>
    <span><?= e($sim['status'] === 'finalized' ? 'Référence utilisée par la stratégie pour cette prestation.' : 'Consultable, mais plus utilisée par la stratégie.') ?></span>
  <?php endif; ?>
</div>

<?php if (is_array($drift) && ($drift['hourly_cost'] || $drift['variable_rate'])): ?>
  <p class="alert alert--warning"><?= ui_icon('triangle-alert', 'sm') ?><span><strong>Hypothèses modifiées depuis la validation du <?= e($frozenDate) ?>.</strong>
    <?= e(implode(' ', array_filter([$drift['hourly_cost'] ? 'Le coût horaire de votre profil a changé.' : null, $drift['variable_rate'] ? 'Le taux de charges variables générales a changé.' : null]))) ?>
    Les valeurs figées ci-dessous restent celles validées ; créez une nouvelle version pour les mettre à jour.</span></p>
<?php endif; ?>

<?php if ($isDemo): ?>
  <p class="alert alert--neutral"><?= ui_icon('flag', 'sm') ?><span>Valeurs fictives de démonstration. N'indiquez pas les chiffres réels de votre entreprise.</span></p>
<?php endif; ?>

<div class="chips scenario-chips" role="navigation" aria-label="Scénarios">
  <?php foreach ($scenarios as $s): ?>
    <a class="chip" href="<?= attr($base . '?scenario=' . $s['public_id']) ?>"<?php if ($s['public_id'] === $current['public_id']): ?> aria-current="true"<?php endif; ?>>
      <?php if ($s['is_reference']): ?><?= ui_icon('star', 'sm') ?><?php endif; ?><?= e($s['label'] . ($s['is_reference'] ? ' · référence' : '')) ?>
    </a>
  <?php endforeach; ?>
  <?php if ($isDraft && count($scenarios) < PricingService::MAX_SCENARIOS): ?>
    <button class="chip chip--add" type="button" data-pricing-add-scenario data-copy-of="<?= attr($current['public_id']) ?>"><?= ui_icon('plus', 'sm') ?>Dupliquer ce scénario</button>
  <?php endif; ?>
  <?php if ($isDraft && !$current['is_reference']): ?>
    <button class="btn btn--ghost btn--sm" type="button" data-pricing-reference="<?= attr($current['public_id']) ?>"><?= ui_icon('star', 'sm') ?>Définir comme référence</button>
  <?php endif; ?>
  <?php if ($isDraft && count($scenarios) > 1): ?>
    <button class="btn btn--ghost btn--sm" type="button" data-pricing-delete-scenario="<?= attr($current['public_id']) ?>"><?= ui_icon('x', 'sm') ?>Supprimer ce scénario</button>
  <?php endif; ?>
</div>

<div class="sim-grid">
  <form class="card" aria-labelledby="t-params" data-pricing-form novalidate>
    <h2 id="t-params" class="visually-hidden">Paramètres : <?= e($current['label']) ?></h2>
    <fieldset class="sim-fieldset"<?php if (!$editable): ?> disabled<?php endif; ?>>
      <div class="form-section">
        <div class="field">
          <label class="field__label" for="p-label">Nom du scénario</label>
          <input class="input" id="p-label" name="label" maxlength="60" value="<?= attr($form['label']) ?>">
        </div>
      </div>

      <div class="form-section">
        <div class="form-section__head">
          <div class="card__title"><h3>Coût horaire</h3><?= ui_tip('tip-hc', 'Coût horaire', 'Coût horaire complet', 'Coût d\'une heure facturable : (charges fixes + personnel + rémunération du dirigeant) ÷ heures facturables annuelles. « Suivre le profil » utilise la valeur calculée ; « Personnalisé » la remplace pour ce scénario uniquement.') ?></div>
          <span data-r-hc-nature><?= ui_nature($hc['nature'] === 'assumption' ? 'assumption' : 'calculated', $hc['source'] === 'custom' ? 'Saisi pour ce scénario' : ($hc['assumptions'] === [] ? 'Calculé' : 'Calculé avec hypothèses')) ?></span>
        </div>
        <div class="segmented" role="radiogroup" aria-label="Origine du coût horaire">
          <label><input type="radio" name="hourly_cost_mode" value="profile"<?php if ($form['hourly_cost_mode'] === 'profile'): ?> checked<?php endif; ?>>Suivre le profil</label>
          <label><input type="radio" name="hourly_cost_mode" value="custom"<?php if ($form['hourly_cost_mode'] === 'custom'): ?> checked<?php endif; ?>>Personnalisé</label>
        </div>
        <div class="field" data-custom-hc<?php if ($form['hourly_cost_mode'] !== 'custom'): ?> hidden<?php endif; ?>>
          <label class="field__label" for="p-hc">Coût horaire personnalisé</label>
          <div class="input-affix"><input class="input num" id="p-hc" name="hourly_cost_custom" data-type="decimal" inputmode="decimal" value="<?= attr($form['hourly_cost_custom'] === null ? '' : Format::number($form['hourly_cost_custom'])) ?>"><span class="input-affix__unit">€/h</span></div>
          <p class="field__error" data-error-for="hourly_cost_custom"></p>
        </div>
        <p class="field__help">Valeur utilisée : <strong class="num" data-r="hourly_cost"><?= e($hc['value'] === null ? 'non calculable' : Format::money($hc['value']) . '/h') ?></strong><?php if ($hc['source'] === 'profile' && $hc['value'] === null): ?> · <a href="/app/parametres/finances">Compléter le profil</a><?php endif; ?></p>
      </div>

      <div class="form-section">
        <div class="form-section__head">
          <div class="card__title"><h3>Temps estimé</h3><?= ui_tip('tip-time', 'Temps estimé', 'Temps estimé', 'Heures nécessaires par phase du projet. Le modèle indicatif est un point de départ entièrement modifiable, jamais une référence de marché.') ?></div>
          <?php if ($editable): ?><button class="btn btn--ghost btn--sm" type="button" data-pricing-template="<?= attr($sim['service']['category'] ?? 'custom') ?>"><?= ui_icon('file-text', 'sm') ?>Préremplir avec un modèle indicatif</button><?php endif; ?>
        </div>
        <table class="line-table"><caption class="visually-hidden">Lignes de temps</caption><tbody data-lines="time">
          <?php foreach ($form['lines'] as $line): if ($line['kind'] !== 'time') { continue; } ?>
            <tr data-line="time"><td><input class="input" aria-label="Phase" maxlength="120" data-field="label" value="<?= attr($line['label']) ?>"></td><td><div class="input-affix"><input class="input num" aria-label="Heures" inputmode="decimal" data-field="hours" value="<?= attr(Format::number($line['hours'])) ?>"><span class="input-affix__unit">h</span></div></td><td><?php if ($editable): ?><button class="btn btn--ghost btn--icon btn--sm" type="button" data-line-remove aria-label="Supprimer la ligne"><?= ui_icon('x', 'sm') ?></button><?php endif; ?></td></tr>
          <?php endforeach; ?>
        </tbody></table>
        <div class="line-total"><?php if ($editable): ?><button class="btn btn--ghost btn--sm" type="button" data-line-add="time"><?= ui_icon('plus', 'sm') ?>Ajouter une phase</button><?php else: ?><span></span><?php endif; ?><span>Total <span class="num" data-r="total_hours"><?= e($results === null ? '—' : Format::hours($results['total_hours'], 2)) ?></span></span></div>
        <p class="alert alert--neutral" data-template-warning hidden><?= ui_icon('info', 'sm') ?><span>Ordre de grandeur pour démarrer, à adapter à votre méthode. Ce n'est pas une référence de marché.</span></p>
      </div>

      <div class="form-section">
        <div class="form-section__head"><div class="card__title"><h3>Frais directs du projet</h3><?= ui_tip('tip-direct', 'Frais directs', 'Frais directs', 'Dépenses propres à ce projet : licences, thème, sous-traitance… Elles s\'ajoutent au coût du temps. Les charges générales non attribuables sont traitées à part, en pourcentage du prix.') ?></div></div>
        <table class="line-table"><caption class="visually-hidden">Frais directs</caption><tbody data-lines="direct_cost">
          <?php foreach ($form['lines'] as $line): if ($line['kind'] !== 'direct_cost') { continue; } ?>
            <tr data-line="direct_cost"><td><input class="input" aria-label="Libellé du frais" maxlength="120" data-field="label" value="<?= attr($line['label']) ?>"></td><td><div class="input-affix"><input class="input num" aria-label="Montant" inputmode="decimal" data-field="amount" value="<?= attr(Format::number($line['amount'])) ?>"><span class="input-affix__unit">€</span></div></td><td><?php if ($editable): ?><button class="btn btn--ghost btn--icon btn--sm" type="button" data-line-remove aria-label="Supprimer la ligne"><?= ui_icon('x', 'sm') ?></button><?php endif; ?></td></tr>
          <?php endforeach; ?>
        </tbody></table>
        <div class="line-total"><?php if ($editable): ?><button class="btn btn--ghost btn--sm" type="button" data-line-add="direct_cost"><?= ui_icon('plus', 'sm') ?>Ajouter un frais</button><?php else: ?><span></span><?php endif; ?><span>Total <span class="num" data-r="direct_costs"><?= e($results === null ? '—' : Format::money($results['direct_costs'])) ?></span></span></div>
      </div>

      <div class="form-section">
        <div class="two-col">
          <div class="field">
            <div class="field__label"><label for="p-margin">Marge cible sur prix de vente</label><?= ui_tip('tip-target-margin', 'Marge cible', 'Marge cible sur prix de vente', 'Part du prix de vente que vous souhaitez conserver après tous les coûts. Attention : 30 % de marge sur prix ≠ coût × 1,3 (qui ne donne que 23 % de marge).') ?><?= ui_nature('assumption') ?></div>
            <div class="input-affix"><input class="input num" id="p-margin" name="target_margin_pct" data-type="decimal" inputmode="decimal" value="<?= attr(Format::number($form['target_margin_pct'])) ?>"><span class="input-affix__unit">%</span></div>
            <p class="field__error" data-error-for="target_margin_pct"></p>
          </div>
          <div class="field">
            <div class="field__label"><label for="p-cont">Provision pour aléas</label><?= ui_tip('tip-cont', 'Aléas', 'Provision pour aléas', 'Pourcentage d\'heures ajouté pour les imprévus (allers-retours, corrections). 10 % sur 40 h donne 44 h.') ?></div>
            <div class="input-affix"><input class="input num" id="p-cont" name="contingency_pct" data-type="decimal" inputmode="decimal" value="<?= attr(Format::number($form['contingency_pct'])) ?>"><span class="input-affix__unit">%</span></div>
            <p class="field__error" data-error-for="contingency_pct"></p>
          </div>
          <div class="field">
            <div class="field__label"><label for="p-price">Prix testé</label><?php if ($form['proposed_price'] === null && ($sim['service']['current_price'] ?? null) !== null): ?><?= ui_nature('declared', 'Prix actuel du catalogue') ?><?php endif; ?></div>
            <div class="input-affix"><input class="input num" id="p-price" name="proposed_price" data-type="decimal" inputmode="decimal" placeholder="<?= attr(($sim['service']['current_price'] ?? null) === null ? 'Facultatif' : Format::number($sim['service']['current_price'])) ?>" value="<?= attr($form['proposed_price'] === null ? '' : Format::number($form['proposed_price'])) ?>"><span class="input-affix__unit">€</span></div>
            <p class="field__help"><?= e(($sim['service']['current_price'] ?? null) === null ? 'Laissez vide pour ne calculer que les prix plancher et recommandé.' : 'Laissez vide pour tester le prix actuel du catalogue.') ?></p>
            <p class="field__error" data-error-for="proposed_price"></p>
          </div>
          <div class="field">
            <span class="field__label">Charges variables générales<?= ui_tip('tip-cvg', 'Charges variables générales', 'Charges variables générales', 'Charges proportionnelles au chiffre d\'affaires mais non attribuables à un projet (frais bancaires, commissions…). Issues de votre profil, elles sont appliquées en pourcentage du prix.') ?></span>
            <p class="num"><span data-r="variable_rate"><?= e($tvInfo['value'] === null ? '0,00 %' : Format::percent($tvInfo['value'], 2)) ?></span> du prix <?= ui_nature('calculated') ?></p>
            <p class="field__help"><?= e($tvInfo['period'] === null ? 'Issu du profil financier.' : 'Issu du profil : ' . $tvInfo['period'] . '.') ?></p>
          </div>
        </div>
      </div>
    </fieldset>
    <p class="alert alert--danger" data-form-summary hidden></p>
    <?php if ($editable): ?>
      <div class="cluster cluster--between sim-actions">
        <span class="text-small text-secondary" data-save-status>Modifications non enregistrées : l'aperçu est calculé sans rien enregistrer.</span>
        <button class="btn btn--secondary" type="button" data-pricing-save><?= ui_icon('save', 'sm') ?>Enregistrer le scénario</button>
      </div>
    <?php endif; ?>
  </form>

  <aside class="card sim-results" aria-labelledby="t-results">
    <div class="card__header">
      <div class="card__title"><h2 id="t-results">Résultats</h2><?= ui_tip('tip-results', 'Résultats', 'Comment ces résultats sont-ils obtenus ?', $editable ? 'Ils sont recalculés par le serveur à chaque modification (rien n\'est enregistré). Ce brouillon n\'a aucun effet sur la stratégie : seule la simulation finalisée sert de référence.' : 'Résultats figés à la finalisation, avec les hypothèses de ce jour-là. Ils ne changent plus, même si votre profil évolue.') ?><?= ui_nature($editable ? 'simulated' : 'frozen') ?></div>
      <?php if ($editable): ?><span class="inline-status inline-status--ok" data-calc-status><?= ui_icon('check', 'sm') ?>À jour</span><?php endif; ?>
    </div>
    <p class="alert alert--danger" data-r-error<?php if (($computed['error'] ?? null) === null): ?> hidden<?php endif; ?>><?= ui_icon('triangle-alert', 'sm') ?><span data-r-error-text><?= e(($computed['error'] ?? null) === null ? '' : PricingService::errorMessage((string) $computed['error'])) ?></span></p>
    <div class="result-list" aria-live="polite" aria-atomic="true">
      <div class="result-line"><span class="result-line__label">Coût direct et de structure</span><span class="result-line__value" data-r="base_cost"><?= e($money($results['base_cost'] ?? null)) ?></span></div>
      <div class="result-line"><span class="result-line__label">Prix plancher<?= ui_tip('tip-floor', 'Prix plancher', 'Prix plancher', 'Prix en dessous duquel la prestation fait perdre de l\'argent : coût direct et de structure ÷ (1 − taux de charges variables générales).') ?></span><span class="result-line__value" data-r="floor_price"><?= e($money($results['floor_price'] ?? null)) ?></span></div>
      <div class="result-line result-line--key"><span class="result-line__label"><?= ui_icon('star', 'sm') ?>Prix recommandé<?= ui_tip('tip-reco', 'Prix recommandé', 'Prix recommandé', 'Le prix qui atteint exactement votre marge cible : coût direct et de structure ÷ (1 − marge cible − taux de charges variables générales).') ?></span><span class="result-line__value" data-r="recommended_price"><?= e($money($results['recommended_price'] ?? null)) ?></span></div>
      <p class="text-small text-tertiary" data-r-rounded-line<?php if ($current['rounded_price'] === null): ?> hidden<?php endif; ?>>Arrondi commercial proposé : <span class="num" data-r="rounded_price"><?= e($current['rounded_price'] === null ? '' : Format::money($current['rounded_price'], 0)) ?></span></p>
    </div>
    <hr class="divider">
    <div class="stack stack--sm" data-r-at-price<?php if ($atPrice === null): ?> hidden<?php endif; ?>>
      <p class="eyebrow">Au prix testé de <span class="num" data-r="price"><?= e($money($atPrice['price'] ?? null)) ?></span></p>
      <div class="result-list">
        <div class="result-line"><span class="result-line__label">Coût de revient complet</span><span class="result-line__value" data-r="full_cost"><?= e($money($atPrice['full_cost'] ?? null)) ?></span></div>
        <div class="result-line"><span class="result-line__label">Résultat</span><span class="result-line__value" data-r="result"><?= e($money($atPrice['result'] ?? null)) ?></span></div>
        <div class="result-line"><span class="result-line__label">Marge sur prix de vente<?= ui_tip('tip-margin', 'Marge sur prix de vente', 'Marge sur prix de vente', 'Résultat ÷ prix de vente (« taux de marque »). À ne pas confondre avec la majoration sur coût.') ?></span><span class="result-line__value" data-r="margin_on_price_pct"><?= e($pct($atPrice['margin_on_price_pct'] ?? null)) ?></span></div>
        <div class="result-line"><span class="result-line__label">Majoration sur coût<?= ui_tip('tip-markup', 'Majoration sur coût', 'Majoration sur coût', 'Résultat ÷ coût de revient. Une majoration de 50 % donne une marge sur prix de vente de 33,3 %.') ?></span><span class="result-line__value" data-r="markup_on_cost_pct"><?= e($pct($atPrice['markup_on_cost_pct'] ?? null)) ?></span></div>
        <div class="result-line" data-r-shortfall-line<?php if (($atPrice['annual_shortfall'] ?? null) === null): ?> hidden<?php endif; ?>><span class="result-line__label">Manque à gagner annuel<?= ui_tip('tip-shortfall', 'Manque à gagner', 'Manque à gagner annuel', '(Prix recommandé − prix testé) × volume annuel déclaré dans le catalogue. Ordre de grandeur, pas une prévision.') ?></span><span class="result-line__value" data-r="annual_shortfall"><?= e($money($atPrice['annual_shortfall'] ?? null)) ?></span></div>
      </div>
    </div>
    <div class="stack stack--sm" data-r-alerts>
      <?php foreach ($computed['alerts'] ?? [] as $alert): ?>
        <p class="alert alert--<?= attr($alert['level'] === 'danger' ? 'danger' : ($alert['level'] === 'warning' ? 'warning' : 'info')) ?>"><?= ui_icon($alert['level'] === 'info' ? 'info' : 'triangle-alert', 'sm') ?><span><?= e(PricingService::alertMessage($alert['code'], $atPrice['gap_to_recommended_pct'] ?? null)) ?></span></p>
      <?php endforeach; ?>
    </div>
  </aside>
</div>

<?php if (count($scenarios) > 1): ?>
  <section class="card" aria-labelledby="t-compare">
    <div class="card__header"><div class="card__title"><h2 id="t-compare">Comparaison des scénarios</h2><?= ui_tip('tip-compare', 'Comparaison', 'Comparer les scénarios', 'Valeurs enregistrées de chaque scénario. Le scénario de référence (étoile) est celui retenu à la finalisation.') ?></div></div>
    <div class="table-wrap">
      <table class="table compare-table">
        <thead><tr><th scope="col"><span class="visually-hidden">Indicateur</span></th><?php foreach ($scenarios as $s): ?><th scope="col"><span class="compare-head"><?php if ($s['is_reference']): ?><?= ui_icon('star', 'sm') ?><?php endif; ?><?= e($s['label']) ?></span></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ([
              ['Heures (aléas compris)', static fn (array $c): string => isset($c['results']['total_hours']) ? Format::hours($c['results']['total_hours'], 2) : '—'],
              ['Coût horaire', static fn (array $c): string => ($c['inputs']['hourly_cost']['value'] ?? null) === null ? '—' : Format::money($c['inputs']['hourly_cost']['value']) . '/h'],
              ['Marge cible', static fn (array $c): string => Format::percent($c['inputs']['target_margin_pct'] ?? null)],
              ['Prix plancher', static fn (array $c): string => $money($c['results']['floor_price'] ?? null)],
              ['Prix recommandé', static fn (array $c): string => $money($c['results']['recommended_price'] ?? null)],
              ['Prix testé', static fn (array $c): string => $money($c['results']['at_price']['price'] ?? null)],
              ['Résultat au prix testé', static fn (array $c): string => $money($c['results']['at_price']['result'] ?? null)],
              ['Marge sur prix de vente', static fn (array $c): string => $pct($c['results']['at_price']['margin_on_price_pct'] ?? null)],
          ] as [$label, $fn]): ?>
            <tr><th scope="row"><?= e($label) ?></th><?php foreach ($scenarios as $s): ?><td class="num"><?= e(($s['computed']['error'] ?? null) !== null ? 'Non calculable' : $fn($s['computed'])) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<?php if (count($view['versions']) > 1 || !$isDraft): ?>
  <section class="card" aria-labelledby="t-versions">
    <div class="card__header"><h2 id="t-versions">Historique des versions</h2></div>
    <ul class="version-list">
      <?php foreach ($view['versions'] as $v): ?>
        <li>
          <a href="/app/pricing/<?= attr($v['public_id']) ?>"<?php if ($v['public_id'] === $sim['public_id']): ?> aria-current="page"<?php endif; ?>>v<?= e($v['version']) ?></a>
          <span class="text-small text-secondary"><?= e(match ($v['status']) {
              'draft' => 'Brouillon',
              'finalized' => 'Active · figée le ' . Format::date(substr((string) $v['finalized_at'], 0, 10)),
              'superseded' => 'Remplacée · figée le ' . Format::date(substr((string) $v['finalized_at'], 0, 10)),
              default => 'Archivée · figée le ' . Format::date(substr((string) $v['finalized_at'], 0, 10)),
          }) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<div class="mobile-summary" aria-hidden="true">
  <span>Prix recommandé<br><span class="num" data-r="recommended_price"><?= e($money($results['recommended_price'] ?? null)) ?></span></span>
  <span class="text-right">Marge au prix testé<br><span class="num" data-r="margin_on_price_pct"><?= e($pct($atPrice['margin_on_price_pct'] ?? null)) ?></span></span>
</div>

<template data-line-template="time"><tr data-line="time"><td><input class="input" aria-label="Phase" maxlength="120" data-field="label"></td><td><div class="input-affix"><input class="input num" aria-label="Heures" inputmode="decimal" data-field="hours"><span class="input-affix__unit">h</span></div></td><td><button class="btn btn--ghost btn--icon btn--sm" type="button" data-line-remove aria-label="Supprimer la ligne"><?= ui_icon('x', 'sm') ?></button></td></tr></template>
<template data-line-template="direct_cost"><tr data-line="direct_cost"><td><input class="input" aria-label="Libellé du frais" maxlength="120" data-field="label"></td><td><div class="input-affix"><input class="input num" aria-label="Montant" inputmode="decimal" data-field="amount"><span class="input-affix__unit">€</span></div></td><td><button class="btn btn--ghost btn--icon btn--sm" type="button" data-line-remove aria-label="Supprimer la ligne"><?= ui_icon('x', 'sm') ?></button></td></tr></template>
</div>
