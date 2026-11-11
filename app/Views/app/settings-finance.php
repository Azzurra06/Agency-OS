<?php
/** @var array<string, mixed> $derived */
use App\Support\Format;

include __DIR__ . '/settings-tabs.php';
/** @var array{value: string|null} $result */
$result = $derived['estimated_result'];
/** @var array{value: string|null, nature: string, assumptions: list<string>} $hourly */
$hourly = $derived['hourly_cost'];
/** @var array{value: string|null} $tv */
$tv = $derived['general_variable_rate'];
/** @var array{label: string}|null $period */
$period = $derived['period'];
?>
<section class="card stack" aria-labelledby="t-derived">
  <div class="cluster cluster--between">
    <div class="card__title"><h2 id="t-derived">Ce que nous calculons</h2><?= ui_tip('tip-derived', 'Calculs', 'Valeurs calculées', 'Recalculées à chaque modification à partir de vos données déclarées. Les valeurs par défaut acceptées sont signalées comme hypothèses.') ?></div>
    <span class="text-small text-secondary"><?= e($period['label'] ?? 'Période non renseignée') ?></span>
  </div>
  <div class="summary-grid">
    <div class="summary-item"><span class="text-small text-secondary">Résultat estimé</span><span class="num"><?= e(Format::money($result['value'], 0)) ?></span><?= ui_nature('calculated') ?></div>
    <div class="summary-item"><span class="text-small text-secondary">Coût horaire complet</span><span class="num"><?= e(Format::money($hourly['value'])) ?></span><?= ui_nature($hourly['nature'], $hourly['nature'] === 'assumption' ? 'Saisi manuellement' : ($hourly['assumptions'] === [] ? 'Calculé' : 'Calculé avec hypothèses')) ?></div>
    <div class="summary-item"><span class="text-small text-secondary">Charges variables générales</span><span class="num"><?= e(Format::percent($tv['value'], 2)) ?></span><span class="text-small text-tertiary">appliquées à chaque prix</span></div>
  </div>
</section>
<?php
$formAction = '/api/v1/financial-profile';
$formRedirect = '';
$submitLabel = 'Enregistrer';
include __DIR__ . '/../partials/finance-form.php';
