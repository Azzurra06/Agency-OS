<?php
/** @var array<string, string|bool|int|null> $values */
/** @var bool $isDemo */
/** @var string $formAction */
/** @var string $formRedirect */
/** @var string $submitLabel */
use App\Support\FinanceFields;

$v = static fn (string $k): string => is_scalar($values[$k] ?? null) ? (string) $values[$k] : '';
$amounts = FinanceFields::amounts();
$amountField = static function (string $name) use ($amounts, $v): void {
    $f = $amounts[$name];
    ?>
    <div class="field">
      <div class="field__label"><label for="f-<?= attr($name) ?>"><?= e($f['label']) ?></label><?php if (isset($f['term'])): ?><?= ui_term($f['term'], $f['note'] ?? null) ?><?php elseif (isset($f['tip'])): ?><?= ui_tip('tip-' . str_replace('_', '-', $name), $f['label'], $f['tip'][0], $f['tip'][1], [], 'start', $f['tip'][2] ?? null) ?><?php endif; ?></div>
      <div class="input-affix"><input class="input num" id="f-<?= attr($name) ?>" name="<?= attr($name) ?>" data-type="decimal" inputmode="decimal" value="<?= attr($v($name)) ?>" aria-describedby="h-<?= attr($name) ?> err-<?= attr($name) ?>"><span class="input-affix__unit"><?= e($f['unit']) ?></span></div>
      <p class="field__help" id="h-<?= attr($name) ?>"><?= e($f['help']) ?></p>
      <p class="field__error" id="err-<?= attr($name) ?>" data-error-for="<?= attr($name) ?>"></p>
    </div>
    <?php
};
?>
<form class="card form-card" data-api-form data-method="PUT" data-action="<?= attr($formAction) ?>" data-redirect="<?= attr($formRedirect) ?>" novalidate>
  <p class="flash flash--error form-summary" data-form-summary role="alert" hidden></p>
  <?php if ($isDemo): ?>
    <p class="alert alert--neutral"><?= ui_icon('info', 'sm') ?><span>Valeurs fictives de démonstration. N'indiquez pas les chiffres réels de votre entreprise.</span></p>
  <?php endif; ?>

  <section class="form-section" aria-labelledby="s-period">
    <div class="form-section__head"><h2 id="s-period">Période de référence</h2><?= ui_term('periode-reference', 'Si elle ne dure pas 12 mois, les montants sont ramenés à un an pour les calculs.') ?></div>
    <div class="form-grid form-grid--3">
      <div class="field">
        <label class="field__label" for="f-period_type">Type</label>
        <select class="select" id="f-period_type" name="period_type">
          <option value="closed_fiscal_year"<?php if ($v('period_type') !== 'trailing_12_months'): ?> selected<?php endif; ?>>Dernier exercice clos</option>
          <option value="trailing_12_months"<?php if ($v('period_type') === 'trailing_12_months'): ?> selected<?php endif; ?>>12 derniers mois</option>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="f-period_start">Début</label>
        <input class="input" type="date" id="f-period_start" name="period_start" value="<?= attr($v('period_start')) ?>">
        <p class="field__error" data-error-for="period_start"></p>
      </div>
      <div class="field">
        <label class="field__label" for="f-period_end">Fin</label>
        <input class="input" type="date" id="f-period_end" name="period_end" value="<?= attr($v('period_end')) ?>">
        <p class="field__error" data-error-for="period_end"></p>
      </div>
    </div>
  </section>

  <section class="form-section" aria-labelledby="s-amounts">
    <div class="form-section__head"><h2 id="s-amounts">Chiffre d'affaires et charges</h2></div>
    <div class="form-grid">
      <?php foreach (['revenue', 'fixed_costs', 'personnel_costs', 'variable_costs_attributable', 'variable_costs_general'] as $name) { $amountField($name); } ?>
    </div>
  </section>

  <section class="form-section" aria-labelledby="s-owner">
    <div class="form-section__head"><h2 id="s-owner">Rémunération du dirigeant</h2></div>
    <div class="form-grid">
      <?php foreach (['owner_comp_total_cost', 'owner_comp_included_amount'] as $name) { $amountField($name); } ?>
    </div>
  </section>

  <section class="form-section" aria-labelledby="s-production">
    <div class="form-section__head"><h2 id="s-production">Capacité de production</h2><?= ui_term('heures-facturables', 'Elles servent à calculer votre coût horaire complet.', formula: true) ?></div>
    <div class="form-grid form-grid--3">
      <div class="field">
        <div class="field__label"><label for="f-production_fte">ETP de production</label><?= ui_term('etp', 'Ne comptez que les personnes qui produisent pour les clients.') ?></div>
        <input class="input num" id="f-production_fte" name="production_fte" data-type="decimal" inputmode="decimal" value="<?= attr($v('production_fte')) ?>">
        <p class="field__help">Personnes qui produisent des heures facturables, dirigeant compris s'il produit.</p>
        <p class="field__error" data-error-for="production_fte"></p>
      </div>
      <div class="field">
        <label class="field__label" for="f-annual_hours_per_fte">Heures travaillées par an et par ETP</label>
        <div class="input-affix"><input class="input num" id="f-annual_hours_per_fte" name="annual_hours_per_fte" data-type="decimal" inputmode="decimal" value="<?= attr($v('annual_hours_per_fte')) ?>" placeholder="1607"><span class="input-affix__unit">h</span></div>
        <label class="check"><input type="checkbox" name="annual_hours_is_assumption" data-type="bool"<?php if (($values['annual_hours_is_assumption'] ?? false) === true): ?> checked<?php endif; ?>>Valeur supposée (hypothèse)</label>
        <p class="field__error" data-error-for="annual_hours_per_fte"></p>
      </div>
      <div class="field">
        <div class="field__label"><label for="f-billable_rate">Taux de facturabilité</label><?= ui_term('taux-facturabilite', 'Sans donnée précise, 65 % est une hypothèse prudente.') ?></div>
        <div class="input-affix"><input class="input num" id="f-billable_rate" name="billable_rate" data-type="decimal" inputmode="decimal" value="<?= attr($v('billable_rate')) ?>" placeholder="65"><span class="input-affix__unit">%</span></div>
        <label class="check"><input type="checkbox" name="billable_rate_is_assumption" data-type="bool"<?php if (($values['billable_rate_is_assumption'] ?? false) === true): ?> checked<?php endif; ?>>Valeur supposée (hypothèse)</label>
        <p class="field__error" data-error-for="billable_rate"></p>
      </div>
    </div>
    <details class="advanced">
      <summary>Forcer un coût horaire</summary>
      <div class="field">
        <label class="field__label" for="f-hourly_cost_override">Coût horaire personnalisé (remplace le calcul)</label>
        <div class="input-affix"><input class="input num" id="f-hourly_cost_override" name="hourly_cost_override" data-type="decimal" inputmode="decimal" value="<?= attr($v('hourly_cost_override')) ?>"><span class="input-affix__unit">€/h</span></div>
        <p class="field__help">Laissez vide pour utiliser le coût calculé à partir de vos charges.</p>
        <p class="field__error" data-error-for="hourly_cost_override"></p>
      </div>
    </details>
  </section>

  <section class="form-section" aria-labelledby="s-goals">
    <div class="form-section__head"><h2 id="s-goals">Objectifs</h2></div>
    <div class="form-grid form-grid--3">
      <?php $amountField('revenue_target'); ?>
      <div class="field">
        <label class="field__label" for="f-revenue_target_year">Exercice visé</label>
        <input class="input num" id="f-revenue_target_year" name="revenue_target_year" data-type="int" inputmode="numeric" value="<?= attr($v('revenue_target_year')) ?>">
        <p class="field__error" data-error-for="revenue_target_year"></p>
      </div>
      <div class="field">
        <div class="field__label"><label for="f-recurring_share_declared">Part récurrente du CA (estimation)</label><?= ui_term('part-recurrente', 'Elle reste la référence : la couverture calculée est affichée à côté, sans la remplacer.') ?></div>
        <div class="input-affix"><input class="input num" id="f-recurring_share_declared" name="recurring_share_declared" data-type="decimal" inputmode="decimal" value="<?= attr($v('recurring_share_declared')) ?>"><span class="input-affix__unit">%</span></div>
        <p class="field__error" data-error-for="recurring_share_declared"></p>
      </div>
    </div>
  </section>

  <div class="form-actions">
    <span class="text-small text-tertiary">Montants HT. Les montants sensibles sont chiffrés en base.</span>
    <button class="btn btn--primary" type="submit"><?= e($submitLabel) ?></button>
  </div>
</form>
