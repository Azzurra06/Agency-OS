<?php
/** @var array<string, mixed>|null $offer */
/** @var bool $isDemo */
use App\Support\Format;

$isNew = $offer === null;
$v = $offer ?? ['public_id' => '', 'name' => '', 'kind' => 'maintenance', 'billing_period' => 'monthly', 'price' => null, 'hours_per_month' => '0.00',
    'external_costs_per_month' => '0.00', 'active_contracts' => 0, 'is_active' => true, 'items' => [], 'economics' => null];
$econ = $v['economics'];
$kinds = ['maintenance' => 'Maintenance', 'seo' => 'SEO', 'hosting' => 'Hébergement', 'other' => 'Autre'];
$num = static fn (?string $x): string => $x === null ? '' : Format::number($x);
?>
<div data-offer-editor data-id="<?= attr($v['public_id']) ?>">
<header class="page-header">
  <div class="page-header__titles">
    <p class="text-small text-secondary"><a href="/app/recurrent">Revenus récurrents</a> / Formule</p>
    <h1><?= e($isNew ? 'Nouvelle formule récurrente' : $v['name']) ?></h1>
    <p class="page-header__sub">Une formule est une donnée réelle : ses changements ne modifient jamais un scénario déjà figé.</p>
  </div>
  <?php if (!$isNew): ?><button class="btn btn--ghost" type="button" data-offer-delete data-name="<?= attr($v['name']) ?>"><?= ui_icon('x', 'sm') ?>Supprimer la formule</button><?php endif; ?>
</header>

<?php if ($isDemo): ?>
  <p class="alert alert--neutral"><?= ui_icon('flag', 'sm') ?><span>Valeurs fictives de démonstration. N'indiquez pas les chiffres réels de votre entreprise.</span></p>
<?php endif; ?>

<div class="sim-grid">
  <form class="card stack stack--lg" data-offer-form novalidate>
    <div class="two-col">
      <div class="field"><label class="field__label" for="o-name">Nom de la formule</label><input class="input" id="o-name" name="name" maxlength="100" required value="<?= attr($v['name']) ?>"><p class="field__error" data-error-for="name"></p></div>
      <div class="field"><label class="field__label" for="o-kind">Type</label><select class="input" id="o-kind" name="kind"><?php foreach ($kinds as $k => $label): ?><option value="<?= attr($k) ?>"<?php if ($v['kind'] === $k): ?> selected<?php endif; ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="field">
        <span class="field__label">Facturation</span>
        <div class="segmented" role="radiogroup" aria-label="Périodicité de facturation">
          <label><input type="radio" name="billing_period" value="monthly"<?php if ($v['billing_period'] === 'monthly'): ?> checked<?php endif; ?>>Mensuelle</label>
          <label><input type="radio" name="billing_period" value="annual"<?php if ($v['billing_period'] === 'annual'): ?> checked<?php endif; ?>>Annuelle</label>
        </div>
      </div>
      <div class="field"><label class="field__label" for="o-price">Prix HT par période</label><div class="input-affix"><input class="input num" id="o-price" name="price" data-type="decimal" inputmode="decimal" value="<?= attr($num($v['price'])) ?>"><span class="input-affix__unit">€</span></div><p class="field__error" data-error-for="price"></p></div>
      <div class="field"><div class="field__label"><label for="o-hours">Temps par contrat et par mois</label><?= ui_tip('tip-oh', 'Temps', 'Temps par contrat', 'Temps moyen passé chaque mois sur un contrat (mises à jour, sauvegardes, support). Il est valorisé au coût horaire complet du profil.') ?></div><div class="input-affix"><input class="input num" id="o-hours" name="hours_per_month" data-type="decimal" inputmode="decimal" value="<?= attr($num($v['hours_per_month'])) ?>"><span class="input-affix__unit">h</span></div><p class="field__error" data-error-for="hours_per_month"></p></div>
      <div class="field"><div class="field__label"><label for="o-ext">Coûts externes par contrat et par mois</label><?= ui_tip('tip-oe', 'Coûts externes', 'Coûts externes', 'Licences, outils de sauvegarde, hébergement payé à un tiers… par contrat et par mois.') ?></div><div class="input-affix"><input class="input num" id="o-ext" name="external_costs_per_month" data-type="decimal" inputmode="decimal" value="<?= attr($num($v['external_costs_per_month'])) ?>"><span class="input-affix__unit">€</span></div><p class="field__error" data-error-for="external_costs_per_month"></p></div>
      <div class="field"><div class="field__label"><label for="o-contracts">Contrats signés</label><?= ui_nature('declared') ?></div><input class="input num" id="o-contracts" name="active_contracts" data-type="int" inputmode="numeric" value="<?= attr($v['active_contracts']) ?>"><p class="field__help">Uniquement les contrats réellement signés : ils forment le MRR contractualisé.</p><p class="field__error" data-error-for="active_contracts"></p></div>
      <div class="field"><span class="field__label">Statut</span><label class="checkbox"><input type="checkbox" name="is_active" data-type="bool"<?php if ($v['is_active']): ?> checked<?php endif; ?>>Formule proposée actuellement</label></div>
    </div>
    <div class="field">
      <label class="field__label" for="o-items">Prestations incluses (une par ligne)</label>
      <textarea class="input" id="o-items" rows="4" data-items><?= e(implode("\n", $v['items'])) ?></textarea>
    </div>
    <p class="alert alert--danger" data-form-summary hidden></p>
    <div class="cluster cluster--between">
      <a class="btn btn--ghost" href="/app/recurrent">Annuler</a>
      <button class="btn btn--primary" type="submit"><?= ui_icon('save', 'sm') ?><?= e($isNew ? 'Créer la formule' : 'Enregistrer') ?></button>
    </div>
  </form>

  <aside class="card sim-results" aria-labelledby="t-econ">
    <div class="card__header"><div class="card__title"><h2 id="t-econ">Économie d'un contrat</h2><?= ui_tip('tip-econ', 'Économie', 'Économie d\'un contrat, par mois', 'Coût mensuel = temps × coût horaire complet + coûts externes + charges variables générales × prix mensuel. Recalculé pendant la saisie, rien n\'est enregistré.') ?><?= ui_nature('calculated') ?></div><span class="inline-status" data-calc-status></span></div>
    <div class="result-list" aria-live="polite" aria-atomic="true">
      <div class="result-line"><span class="result-line__label">Prix mensuel normalisé</span><span class="result-line__value" data-e="monthly_price"><?= e($econ === null ? '—' : Format::money($econ['monthly_price'])) ?></span></div>
      <div class="result-line"><span class="result-line__label">Coût mensuel</span><span class="result-line__value" data-e="monthly_cost"><?= e($econ['monthly_cost'] ?? null ? Format::money($econ['monthly_cost']) : '—') ?></span></div>
      <div class="result-line result-line--key"><span class="result-line__label">Résultat par contrat</span><span class="result-line__value" data-e="monthly_result"><?= e($econ['monthly_result'] ?? null ? Format::money($econ['monthly_result']) : '—') ?></span></div>
      <div class="result-line"><span class="result-line__label">Marge sur prix de vente</span><span class="result-line__value" data-e="margin_on_price_pct"><?= e($econ['margin_on_price_pct'] ?? null ? Format::percent($econ['margin_on_price_pct']) : '—') ?></span></div>
      <div class="result-line"><span class="result-line__label">Prix plancher mensuel<?= ui_tip('tip-ofloor', 'Prix plancher', 'Prix plancher mensuel', '(Temps × coût horaire + coûts externes) ÷ (1 − taux de charges variables générales) : en dessous, chaque contrat fait perdre de l\'argent.') ?></span><span class="result-line__value" data-e="floor_price"><?= e($econ['floor_price'] ?? null ? Format::money($econ['floor_price']) : '—') ?></span></div>
    </div>
    <p class="text-small text-secondary" data-e-basis><?= e($econ === null || $econ['hourly_cost'] === null ? 'Coût horaire du profil non calculable : seul le prix mensuel est affiché.' : 'Avec un coût horaire de ' . Format::money($econ['hourly_cost']) . '/h et ' . Format::percent($econ['variable_rate_pct'] ?? '0', 2) . ' de charges variables générales (profil).') ?></p>
    <p class="alert alert--danger" data-e-unprofitable<?php if (!($econ['unprofitable'] ?? false)): ?> hidden<?php endif; ?>><?= ui_icon('triangle-alert', 'sm') ?><span>Cette formule est déficitaire : chaque contrat coûte plus qu'il ne rapporte.</span></p>
    <p class="text-small text-tertiary">Le MRR est un indicateur normalisé : une formule annuelle est encaissée en une fois.</p>
  </aside>
</div>
</div>
