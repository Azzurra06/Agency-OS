<?php
/** @var \App\Domain\Diagnostic\Questionnaire $questionnaire */
?>
<article class="stack stack--lg">
  <header class="stack stack--sm">
    <h1>Méthodologie</h1>
    <p class="text-secondary">Comment Agency OS calcule vos indicateurs, votre score de maturité et ses recommandations. Tous les calculs sont faits par le serveur, en décimal exact (sans arrondi intermédiaire), et arrondis une seule fois à l'affichage. Un terme vous échappe ? Consultez le <a href="/glossaire">glossaire</a>.</p>
    <nav class="chips" aria-label="Sommaire">
      <a class="chip" href="#profil">Profil financier</a><a class="chip" href="#cout-horaire">Coût horaire</a><a class="chip" href="#pricing">Pricing</a>
      <a class="chip" href="#recurrent">Revenus récurrents</a><a class="chip" href="#diagnostic">Diagnostic</a><a class="chip" href="#recommandations">Recommandations</a><a class="chip" href="#natures">Natures des données</a>
    </nav>
  </header>

  <section class="card stack" id="profil" aria-labelledby="m-profil">
    <h2 id="m-profil">Profil financier et résultat estimé</h2>
    <p>Vos chiffres portent sur une <strong>période de référence</strong> : le dernier exercice clos (par défaut) ou les 12 derniers mois. Si la période ne dure pas 12 mois, les montants sont annualisés avec un facteur k = 12 ÷ nombre de mois.</p>
    <pre class="formula">CA annualisé           = CA × k
Charges annualisées    = (charges fixes + personnel + charges variables) × k + complément de rémunération du dirigeant
Résultat estimé        = CA annualisé − charges annualisées</pre>
    <p class="text-small text-secondary">Le résultat estimé est une estimation de gestion : il ne remplace ni votre bilan ni l'avis de votre expert-comptable. Il inclut la rémunération du dirigeant à son coût complet ; il exclut l'impôt sur les sociétés, les amortissements et les éléments exceptionnels.</p>
    <p class="text-small"><strong>Exemple :</strong> CA 180 000 €, charges fixes 30 000 €, personnel 60 000 €, charges variables 15 600 €, rémunération du dirigeant 50 000 € (non incluse dans le personnel) → résultat estimé <strong>24 400 €</strong>, soit 13,6 % du CA.</p>
  </section>

  <section class="card stack" id="cout-horaire" aria-labelledby="m-hc">
    <h2 id="m-hc">Coût horaire complet</h2>
    <pre class="formula">Coûts de structure  S  = (charges fixes + personnel) × k + complément du dirigeant
Heures facturables  H  = personnes qui produisent × heures annuelles × taux de facturabilité
Coût horaire        CH = S ÷ H</pre>
    <p>Les charges variables sont exclues du coût horaire pour éviter un double comptage : les frais propres à un projet sont saisis dans la simulation, les charges variables générales sont appliquées en pourcentage du prix. Hypothèses par défaut, signalées comme telles : 1 607 heures par personne et par an, 65 % de temps facturable.</p>
    <p class="text-small"><strong>Exemple :</strong> S = 140 000 €, 2 personnes × 1 607 h × 65 % = 2 089,10 h → <strong>67,01 €/h</strong>.</p>
  </section>

  <section class="card stack" id="pricing" aria-labelledby="m-pricing">
    <h2 id="m-pricing">Pricing d'une prestation</h2>
    <pre class="formula">Heures            T  = Σ heures × (1 + aléas)
Coût direct       C₀ = T × CH + frais directs
Prix plancher     Pp = C₀ ÷ (1 − tv)                  (résultat nul)
Prix recommandé   Pr = C₀ ÷ (1 − marge cible − tv)    (marge sur prix de vente atteinte exactement)
Résultat au prix P   = P × (1 − tv) − C₀</pre>
    <p><strong>Marge sur prix de vente</strong> = résultat ÷ prix. <strong>Majoration sur coût</strong> = résultat ÷ coût de revient. Ce sont deux notions différentes : 30 % de marge sur prix de vente ne s'obtient pas en multipliant le coût par 1,3.</p>
    <p class="text-small"><strong>Exemple :</strong> 40 h à 60 €/h, 300 € de frais, tv = 2 %, marge cible 30 % → prix plancher <strong>2 755,10 €</strong>, prix recommandé <strong>3 970,59 €</strong>. Au prix de 3 200 €, la marge sur prix de vente est de 13,6 % ; avec 8 ventes par an, le manque à gagner est de 6 164,71 €.</p>
    <p class="text-small text-secondary">Un brouillon est recalculé en direct et n'a aucun effet sur la stratégie. Une simulation finalisée fige ses hypothèses et ses résultats ; elle devient la référence de la prestation.</p>
  </section>

  <section class="card stack" id="recurrent" aria-labelledby="m-rec">
    <h2 id="m-rec">Revenus récurrents</h2>
    <pre class="formula">Prix mensuel       pₘ = prix (mensuel) ou prix ÷ 12 (annuel)
Coût par contrat   cₘ = heures par mois × CH + coûts externes + tv × pₘ
MRR contractualisé    = Σ pₘ × contrats signés      ARR = MRR × 12
Projection         Nₜ = Nₜ₋₁ × (1 − résiliation) + nouveaux clients</pre>
    <p>Le MRR contractualisé ne compte que les contrats réellement signés. Les scénarios de projection sont des simulations : ils ne sont <strong>jamais additionnés</strong> au réel. La couverture du CA par les contrats est affichée à côté de la part récurrente que vous déclarez ; elle ne la remplace que si vous le demandez.</p>
  </section>

  <section class="card stack" id="diagnostic" aria-labelledby="m-diag">
    <h2 id="m-diag">Diagnostic de maturité (questionnaire <?= e($questionnaire->version) ?>)</h2>
    <p>30 questions réparties en six dimensions de poids égal. Chaque réponse vaut de 0 à 3 points.</p>
    <pre class="formula">Score d'une dimension = 100 × points obtenus ÷ (3 × réponses exploitables)
Dimension calculable  si au moins 3 réponses exploitables ET au moins 60 % des questions
Score global          = moyenne des six dimensions, affiché seulement si les six sont calculables
Fiabilité             = réponses exploitables ÷ questions applicables</pre>
    <p>Niveaux : Fragile (0 à 39), En construction (40 à 59), Structuré (60 à 79), Avancé (80 à 100). Un diagnostic terminé avec des réponses manquantes est un <strong>diagnostic partiel</strong> : les dimensions insuffisantes ne sont ni notées ni placées à 0.</p>
    <h3>« Je ne sais pas » : une règle par question</h3>
    <p class="text-small">Pour une question <strong>évaluable</strong>, ne pas savoir est un manque de maturité : la réponse compte 0 point. Pour une question <strong>inconnue</strong>, l'information peut légitimement manquer : la réponse est exclue du calcul et réduit la fiabilité.</p>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Dimension</th><th scope="col">Question</th><th scope="col">« Je ne sais pas »</th></tr></thead>
        <tbody>
          <?php foreach ($questionnaire->dimensions as $dimension): foreach ($dimension['questions'] as $question): ?>
            <tr><td><?= e($dimension['label']) ?></td><td><?= e($question['text']) ?></td><td><?= e($question['unknown_policy'] === 'evaluable' ? 'Évaluable (0 point)' : 'Inconnue (exclue)') ?></td></tr>
          <?php endforeach; endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-small text-secondary">Autoévaluation indicative : ce n'est ni une certification ni un diagnostic financier officiel.</p>
  </section>

  <section class="card stack" id="recommandations" aria-labelledby="m-reco">
    <h2 id="m-reco">Recommandations</h2>
    <p>Un moteur de règles déterministe (pas d'intelligence artificielle) compare vos données à des seuils publiés : prix sous le prix plancher ou à plus de 10 % sous le prix recommandé, formule récurrente déficitaire ou à moins de 20 % de marge, part récurrente inférieure à 20 %, objectif supérieur de plus de 30 % au CA, dimension du diagnostic sous 40/100, etc. Il n'utilise que des données réelles ou figées, jamais un brouillon.</p>
    <pre class="formula">Priorité = base de sévérité (info 20 · moyenne 45 · haute 70 · critique 90)
         + bonus de dimension = arrondi(10 × (1 − score de la dimension ÷ 100)), si elle est calculable
         (plafonnée à 100)</pre>
    <p>Chaque recommandation indique les données sur lesquelles elle repose, les valeurs exactes qui l'ont déclenchée et les seuils appliqués. Une donnée incomplète ou ancienne est signalée et n'augmente jamais la priorité. Quand la condition disparaît, la recommandation est résolue automatiquement.</p>
  </section>

  <section class="card stack" id="natures" aria-labelledby="m-nat">
    <h2 id="m-nat">Natures des données</h2>
    <ul class="nature-list">
      <li><?= ui_nature('declared') ?> une valeur que vous avez saisie.</li>
      <li><?= ui_nature('assumption') ?> une valeur par défaut ou une hypothèse, à remplacer par vos vrais chiffres.</li>
      <li><?= ui_nature('calculated') ?> un résultat calculé à partir de vos données.</li>
      <li><?= ui_nature('simulated') ?> une projection ou un scénario, jamais mélangé au réel.</li>
      <li><?= ui_nature('frozen') ?> une version validée, conservée telle quelle avec sa date.</li>
      <li><?= ui_nature('demo') ?> une donnée fictive de démonstration.</li>
    </ul>
  </section>
</article>
