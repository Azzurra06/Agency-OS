# Agency OS

SaaS de pilotage stratégique et financier pour petites agences web et digitales.

> « Pilotez la rentabilité de votre agence, structurez vos offres et prenez les bonnes décisions pour développer votre activité. »

**MVP portfolio** : données fictives uniquement, sur `agency-os.azzurra-pro.com`. Le SaaS commercial sera déployé plus tard sur une infrastructure dédiée (voir [docs/06-roadmap.md](docs/06-roadmap.md)).

## Avancement

| Étape | Document | Statut |
|---|---|---|
| 1 — Cadrage fonctionnel | [docs/01-cadrage.md](docs/01-cadrage.md) | Validé |
| 2 — Architecture | [docs/02-architecture.md](docs/02-architecture.md) · [docs/03-formules-et-scoring.md](docs/03-formules-et-scoring.md) | Validé |
| Architecture détaillée | [docs/05-architecture-detaillee.md](docs/05-architecture-detaillee.md) | Validé |
| Sécurité et confidentialité | [docs/04-securite.md](docs/04-securite.md) | Validé ; S2 (OpenSSL) pour le portfolio |
| Roadmap portfolio / SaaS commercial | [docs/06-roadmap.md](docs/06-roadmap.md) | Adoptée |
| 3 — UX/UI | [docs/07-ux-ui.md](docs/07-ux-ui.md) · [prototype/](prototype/README.md) | Validé |
| 4 — Lot 0 : socle | code `app/`, `tests/` | Validé |
| 4 — Lot 1 : démo, authentification, vue d'ensemble, profil, onboarding | code `app/`, `tests/` | Validé |
| 4 — Lot 2 : diagnostic | code `app/`, `tests/` · [docs/08-questionnaire-v1.md](docs/08-questionnaire-v1.md) | Validé |
| 4 — Lot 3 : pricing | code `app/`, `tests/` | Validé |
| 4 — Lot 4 : revenus récurrents | code `app/`, `tests/` | Validé |
| 4 — Lot 5 : stratégie | code `app/`, `tests/` | Validé |
| **4 — Lot 6 : historique, méthodologie, RGPD, pages légales** | code `app/`, `tests/` | Livré |
| **5 — Tests et déploiement** | [docs/09-deploiement.md](docs/09-deploiement.md) · https://agency-os.azzurra-pro.com | En ligne |

## Stack

HTML, CSS et JavaScript natifs, Chart.js (local) · PHP 8.2+ (8.3 visé), BCMath · MySQL/MariaDB (PDO, requêtes préparées) · hébergement mutualisé Hostinger. Dépendances de production : **PHPMailer** (SMTP, inactif sur le portfolio) et **Chart.js**. Dépendances de développement : PHPUnit, PHPStan.

## Organisation du code

```
app/                    code PHP (hors du répertoire public)
  Boot.php, App.php     création de l'application et conteneur de services
  bootstrap.php         autoloader et durcissement PHP à l'exécution
  Http/                 noyau, routeur, requête/réponse, middlewares (CSRF, authentification), contrôleurs
  Security/             sessions, CSRF, limitation des tentatives, journal de sécurité, mots de passe, permissions
  Security/Crypto/      chiffrement des champs (OpenSSL AES-256-GCM / sodium), trousseau de clés versionnées
  Infrastructure/       connexion PDO, migrations, dépôts restreints à l'organisation
  Support/              Decimal (BCMath), validation, configuration, journal, échappement, vues
  Mail/                 emails (capture par défaut, SMTP prêt mais désactivé en portfolio)
  Cron/                 exécuteur des tâches planifiées (heure de Paris, anti-doublon)
  Backup/               interface de stockage des sauvegardes (stockage externe non souscrit)
  Views/                vues PHP (toute sortie échappée)
bin/                    migrate, cron, check-requirements, generate-key
config/                 .env.example (le .env réel et les clés ne sont jamais dans Git)
database/migrations/    migrations SQL versionnées
public_html/            seul répertoire servi : index.php, .htaccess, assets
storage/                journaux, sessions, emails capturés, sauvegardes (non public)
tests/                  Unit, Functional (base réelle), Security
tools/check-forbidden.php  contrôle des motifs interdits
prototype/              prototype statique du design system (phase 3)
```

## Développement local

```bash
composer install
cp config/.env.example config/.env      # puis renseigner APP_KEY, DB_*, SESSION_SECURE=false en local
php bin/generate-key.php --app-key      # valeur à copier dans APP_KEY
php bin/generate-key.php --data-key     # crée config/keys/data.k1 (à sauvegarder hors ligne)
php bin/migrate.php
php bin/check-requirements.php          # contrôle de l'environnement réel
php -S localhost:8000 -t public_html public_html/index.php
```

## Vérifications

```bash
php tools/check-forbidden.php           # motifs interdits
vendor/bin/phpstan analyse              # analyse statique, niveau max
vendor/bin/phpunit                      # 156 tests : unitaires, fonctionnels (MySQL/MariaDB), sécurité
```

Les mêmes vérifications s'exécutent sur GitHub Actions à chaque push (MariaDB 10.11 et MySQL 8.0), avec un audit des dépendances et une recherche de secrets.
