<?php
/** @var list<array{public_id: string, name: string, category: string, current_price: string|null, annual_volume: int|null, is_active: bool}> $services */
/** @var bool $isDemo */
$categories = ['showcase' => 'Site vitrine', 'ecommerce' => 'Site e-commerce', 'redesign' => 'Refonte', 'seo' => 'SEO', 'custom' => 'Sur mesure', 'other' => 'Autre'];
?>
<section class="card stack" aria-labelledby="t-services">
  <div class="card__title"><h2 id="t-services">Prestations ponctuelles</h2><?= ui_tip('tip-services', 'Prestations', 'Catalogue des prestations', 'Le prix pratiqué est la référence du « prix actuel » comparé au prix recommandé du simulateur. Le volume annuel permet de chiffrer un manque à gagner. La maintenance se gère dans le module Revenus récurrents.') ?></div>
  <?php if ($isDemo): ?><p class="text-small text-secondary">Prestations fictives de l'agence « Lumen ».</p><?php endif; ?>

  <?php if ($services === []): ?>
    <div class="empty"><span class="empty__icon"><?= ui_icon('tag') ?></span><h3>Aucune prestation</h3><p class="empty__text text-small">Ajoutez les prestations que vous vendez, avec leur prix habituel.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <caption class="visually-hidden">Prestations</caption>
        <thead><tr><th scope="col">Prestation</th><th scope="col">Type</th><th scope="col" class="num">Prix pratiqué HT</th><th scope="col" class="num">Ventes / an</th><th scope="col">Active</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
          <?php foreach ($services as $s): ?>
            <tr class="service-row" data-service-row data-id="<?= attr($s['public_id']) ?>">
              <td><input class="input" name="name" value="<?= attr($s['name']) ?>" aria-label="Nom de la prestation" maxlength="150"></td>
              <td><select class="select" name="category" aria-label="Type">
                <?php foreach ($categories as $value => $label): ?><option value="<?= attr($value) ?>"<?php if ($s['category'] === $value): ?> selected<?php endif; ?>><?= e($label) ?></option><?php endforeach; ?>
              </select></td>
              <td><div class="input-affix"><input class="input num" name="current_price" data-type="decimal" inputmode="decimal" value="<?= attr($s['current_price']) ?>" aria-label="Prix pratiqué de <?= attr($s['name']) ?>"><span class="input-affix__unit">€</span></div></td>
              <td><input class="input num" name="annual_volume" data-type="int" inputmode="numeric" value="<?= attr($s['annual_volume']) ?>" aria-label="Ventes par an de <?= attr($s['name']) ?>"></td>
              <td><label class="check"><input type="checkbox" name="is_active" data-type="bool"<?php if ($s['is_active']): ?> checked<?php endif; ?>><span class="visually-hidden">Active</span></label></td>
              <td class="cluster">
                <button class="btn btn--secondary btn--sm" type="button" data-service-save>Enregistrer</button>
                <button class="btn btn--ghost btn--sm btn--icon" type="button" data-service-delete data-name="<?= attr($s['name']) ?>" aria-label="Supprimer <?= attr($s['name']) ?>"><?= ui_icon('trash-2', 'sm') ?></button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <form class="form-grid form-grid--3" data-api-form data-method="POST" data-action="/api/v1/services" data-reload novalidate>
    <p class="flash flash--error form-summary" data-form-summary role="alert" hidden></p>
    <div class="field">
      <label class="field__label" for="new-name">Nouvelle prestation</label>
      <input class="input" id="new-name" name="name" maxlength="150" placeholder="Ex. Site vitrine WordPress">
      <p class="field__error" data-error-for="name"></p>
    </div>
    <div class="field">
      <label class="field__label" for="new-category">Type</label>
      <select class="select" id="new-category" name="category">
        <?php foreach ($categories as $value => $label): ?><option value="<?= attr($value) ?>"><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field__label" for="new-price">Prix pratiqué HT</label>
      <div class="input-affix"><input class="input num" id="new-price" name="current_price" data-type="decimal" inputmode="decimal"><span class="input-affix__unit">€</span></div>
      <p class="field__error" data-error-for="current_price"></p>
    </div>
    <div><button class="btn btn--secondary" type="submit"><?= ui_icon('plus', 'sm') ?>Ajouter</button></div>
  </form>
</section>
