<?php
/** @var array{name: string, activity_type: string|null, team_size: int|null, legal_status: string|null} $org */
/** @var string $redirect */
$activities = ['wordpress' => 'Agence WordPress', 'web' => 'Agence web', 'digital' => 'Agence digitale', 'marketing' => 'Marketing / communication', 'other' => 'Autre'];
$statuses = ['micro' => 'Micro-entreprise', 'ei' => 'Entreprise individuelle', 'eurl' => 'EURL', 'sarl' => 'SARL', 'sasu' => 'SASU', 'sas' => 'SAS', 'other' => 'Autre'];
?>
<form class="card form-card" data-api-form data-method="PATCH" data-action="/api/v1/organization" data-redirect="<?= attr($redirect) ?>" novalidate>
  <p class="flash flash--error form-summary" data-form-summary role="alert" hidden></p>
  <div class="form-grid">
    <div class="field">
      <label class="field__label" for="f-name">Nom de l'agence</label>
      <input class="input" id="f-name" name="name" value="<?= attr($org['name']) ?>" maxlength="150" required>
      <p class="field__error" data-error-for="name"></p>
    </div>
    <div class="field">
      <label class="field__label" for="f-activity_type">Activité principale</label>
      <select class="select" id="f-activity_type" name="activity_type">
        <option value="">Non précisé</option>
        <?php foreach ($activities as $value => $label): ?>
          <option value="<?= attr($value) ?>"<?php if ($org['activity_type'] === $value): ?> selected<?php endif; ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field__label" for="f-team_size">Taille de l'équipe (personnes)</label>
      <input class="input num" id="f-team_size" name="team_size" data-type="int" inputmode="numeric" value="<?= attr($org['team_size']) ?>">
      <p class="field__error" data-error-for="team_size"></p>
    </div>
    <div class="field">
      <label class="field__label" for="f-legal_status">Statut juridique</label>
      <select class="select" id="f-legal_status" name="legal_status">
        <option value="">Non précisé</option>
        <?php foreach ($statuses as $value => $label): ?>
          <option value="<?= attr($value) ?>"<?php if ($org['legal_status'] === $value): ?> selected<?php endif; ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-actions">
    <span class="text-small text-tertiary">Ces informations servent à adapter les recommandations.</span>
    <button class="btn btn--primary" type="submit">Enregistrer</button>
  </div>
</form>
