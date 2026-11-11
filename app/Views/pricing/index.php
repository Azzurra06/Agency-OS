<?php
/** @var list<array<string, mixed>> $simulations */
/** @var list<array{public_id: string, name: string, current_price: string|null}> $services */
/** @var string $filter */
/** @var bool $isDemo */
use App\Support\Format;

$filters = ['toutes' => 'Toutes', 'actives' => 'Actives', 'brouillons' => 'Brouillons', 'archivees' => 'Remplacées et archivées'];
$visible = array_values(array_filter($simulations, static fn (array $s): bool => match ($filter) {
    'actives' => $s['status'] === 'finalized',
    'brouillons' => $s['status'] === 'draft',
    'archivees' => in_array($s['status'], ['superseded', 'archived'], true),
    default => true,
}));
$statusLabel = static fn (array $s): string => match ($s['status']) {
    'draft' => 'Brouillon · v' . $s['version'],
    'finalized' => 'Active · v' . $s['version'] . ' · figée le ' . Format::date(substr((string) $s['finalized_at'], 0, 10)),
    'superseded' => 'Remplacée · v' . $s['version'],
    default => 'Archivée · v' . $s['version'],
};
?>
<header class="page-header">
  <div class="page-header__titles">
    <div class="card__title"><h1>Pricing</h1><?= ui_tip('tip-pricing', 'Pricing', 'Simulateur de prix', 'Trouvez le bon prix d\'une prestation : le minimum pour ne pas perdre d\'argent (prix plancher) et le prix qui vous laisse la marge visée (prix recommandé), à partir de votre coût horaire, du temps estimé et des frais du projet. Un brouillon est sans conséquence : seule la simulation finalisée sert de référence.', [], 'start', '40 h à 60 €/h + 300 € de frais, marge visée 30 % → plancher 2 755 €, recommandé 3 971 €.', '/app/glossaire#prix-recommande') ?></div>
    <p class="page-header__sub">Vérifiez que vos prix couvrent vos coûts et votre marge cible.</p>
  </div>
</header>

<?php if ($isDemo): ?>
  <p class="alert alert--neutral"><?= ui_icon('flag', 'sm') ?><span>Valeurs fictives de démonstration. N'indiquez pas les chiffres réels de votre entreprise.</span></p>
<?php endif; ?>

<section class="card" aria-labelledby="t-new">
  <div class="card__header"><h2 id="t-new">Nouvelle simulation</h2></div>
  <form class="cluster new-simulation" data-pricing-create>
    <label class="visually-hidden" for="new-service">Prestation</label>
    <select class="input" id="new-service" name="service">
      <?php foreach ($services as $service): ?>
        <option value="<?= attr($service['public_id']) ?>"><?= e($service['name'] . ($service['current_price'] === null ? '' : ' · ' . Format::money($service['current_price'], 0))) ?></option>
      <?php endforeach; ?>
      <option value="">Sur mesure (sans prestation du catalogue)</option>
    </select>
    <button class="btn btn--primary" type="submit"><?= ui_icon('plus', 'sm') ?>Créer la simulation</button>
  </form>
</section>

<section class="card" aria-labelledby="t-list">
  <div class="card__header">
    <h2 id="t-list">Simulations</h2>
    <nav class="chips" aria-label="Filtrer les simulations">
      <?php foreach ($filters as $key => $label): ?>
        <a class="chip" href="/app/pricing<?= attr($key === 'toutes' ? '' : '?filtre=' . $key) ?>"<?php if ($filter === $key): ?> aria-current="true"<?php endif; ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <?php if ($simulations === []): ?>
    <div class="empty">
      <span class="empty__icon"><?= ui_icon('tag') ?></span>
      <h3>Créez votre première simulation à partir d'une prestation</h3>
      <p class="empty__text text-small">Le simulateur reprend votre coût horaire et vos charges variables depuis le profil, et le prix actuel depuis le catalogue.</p>
    </div>
  <?php elseif ($visible === []): ?>
    <p class="text-small text-secondary">Aucune simulation dans ce filtre.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Simulation</th><th scope="col">Statut</th><th scope="col">Prix actuel</th><th scope="col">Prix recommandé</th><th scope="col">Écart</th></tr></thead>
        <tbody>
          <?php foreach ($visible as $s): ?>
            <tr>
              <td><a href="/app/pricing/<?= attr($s['public_id']) ?>"><?= e($s['name']) ?></a><?php if ($s['service'] === null): ?> <span class="badge">Sur mesure</span><?php endif; ?></td>
              <td><?php if ($s['status'] === 'draft'): ?><span class="badge badge--outline"><?= e($statusLabel($s)) ?></span><?php else: ?><?= ui_nature('frozen', $statusLabel($s)) ?><?php endif; ?></td>
              <td class="num"><?= e($s['current_price'] === null ? '—' : Format::money($s['current_price'])) ?></td>
              <td class="num"><?= e($s['recommended_price'] === null ? ($s['error'] === null ? '—' : 'Non calculable') : Format::money($s['recommended_price'])) ?></td>
              <td class="num"><?php if ($s['gap_pct'] !== null): ?><span class="badge badge--<?= attr(str_starts_with((string) $s['gap_pct'], '-') ? 'warning' : 'success') ?>"><?= e(Format::percent($s['gap_pct'])) ?></span><?php else: ?>—<?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
