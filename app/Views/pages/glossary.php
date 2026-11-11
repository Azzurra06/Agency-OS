<?php
/** @var array<string, array<string, array{term: string, aka?: string, category: string, definition: string, example: string, formula?: string, see?: string}>> $groups */
/** @var array<string, string> $categories */
/** @var bool $inApp */
$inApp ??= false;
$methodology = '/methodologie';
?>
<article class="stack stack--lg glossary">
  <header class="stack stack--sm">
    <h1>Glossaire</h1>
    <p class="text-secondary">Les termes utilisés dans Agency OS, expliqués simplement et avec un exemple. Les calculs détaillés sont dans la <a href="<?= attr($methodology) ?>">méthodologie</a>.</p>
    <nav class="chips" aria-label="Rubriques du glossaire">
      <?php foreach ($categories as $key => $label): ?>
        <a class="chip" href="#rubrique-<?= attr($key) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="field glossary__search">
      <label class="visually-hidden" for="glossary-filter">Rechercher un terme</label>
      <input class="input" id="glossary-filter" type="search" placeholder="Rechercher un terme (MRR, marge, coût horaire…)" autocomplete="off" data-glossary-filter>
      <p class="text-small text-secondary" data-glossary-empty hidden>Aucun terme ne correspond à cette recherche.</p>
    </div>
  </header>

  <?php foreach ($groups as $category => $terms): if ($terms === []) { continue; } ?>
  <section class="card stack" id="rubrique-<?= attr($category) ?>" aria-labelledby="g-<?= attr($category) ?>" data-glossary-group>
    <h2 id="g-<?= attr($category) ?>"><?= e($categories[$category] ?? $category) ?></h2>
    <dl class="glossary__list">
      <?php foreach ($terms as $slug => $term): ?>
      <div class="glossary__item" id="<?= attr($slug) ?>" data-glossary-item data-search="<?= attr(mb_strtolower($term['term'] . ' ' . ($term['aka'] ?? '') . ' ' . $term['definition'])) ?>">
        <dt class="glossary__term"><?= e($term['term']) ?><?php if (isset($term['aka'])): ?> <span class="glossary__aka">· <?= e($term['aka']) ?></span><?php endif; ?></dt>
        <dd class="glossary__body">
          <p><?= e($term['definition']) ?></p>
          <p class="glossary__example"><strong>Exemple :</strong> <?= e($term['example']) ?></p>
          <?php if (isset($term['formula'])): ?><pre class="formula"><?= e($term['formula']) ?></pre><?php endif; ?>
          <?php if (isset($term['see'], $term['formula'])): ?><p class="text-small"><a href="<?= attr($methodology . '#' . $term['see']) ?>">Détail du calcul dans la méthodologie</a></p><?php endif; ?>
        </dd>
      </div>
      <?php endforeach; ?>
    </dl>
  </section>
  <?php endforeach; ?>
</article>
