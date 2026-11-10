# Agency OS — Architecture de sécurité et de confidentialité

> Statut : **proposition, en attente de validation** (prérequis au développement)
> Principes : *Security by Design* et *Privacy by Design*. Référentiels : **OWASP ASVS 5.0, niveau 2 visé** (chapitres applicables) et **OWASP Top 10** (dernière édition).
> Ce document complète et **remplace** [02-architecture.md §4](02-architecture.md) une fois validé. Aucune application n'est invulnérable : ce document vise à réduire les risques, à en limiter l'impact et à les rendre détectables.

---

## 1. Ce que l'hébergement permet réellement

Vérifications faites le 10/11/2026 via l'API Hostinger sur le compte `u700770059` et le site `agency-os.azzurra-pro.com`.

### 1.1 Constats vérifiés

| Élément | Constat | Conséquence |
|---|---|---|
| Offre | **Web Premium** (`hostinger_premium`), active | Mutualisé : pas d'accès root, ni de pare-feu ou de serveur web configurables |
| Compte Unix | **Partagé** avec les autres sites du compte (au moins 6 sites `*.azzurra-pro.com`, dont plusieurs WordPress, et 19 bases de données) | ⚠️ **Risque principal**, voir §2.3 et l'arbitrage **D1** |
| PHP | 8.3.36 ; versions disponibles jusqu'à 8.5 | Conforme |
| Extensions | actives : `bcmath`, `intl`, `mbstring`, `pdo_mysql`, `openssl`, `curl` ; **`sodium` disponible mais désactivée** | Argon2id et chiffrement libsodium possibles après activation (**D3**) |
| Fonctions désactivées | `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`… | PHP ne peut pas lancer `mysqldump` ; en revanche, une **tâche cron peut exécuter une commande shell** (§8) |
| Options PHP par défaut | `display_errors=Off` ✅ ; `expose_php=On` ❌ ; `allow_url_fopen=On` ❌ ; `session.cookie_secure/httponly=Off` ❌ ; `samesite=None` ❌ ; `use_strict_mode=Off` ❌ ; `log_errors=Off` ❌ ; `post_max_size=256M` ❌ | Durcissement **par le code** à l'exécution (les options de session sont modifiables par `ini_set`) **et** dans hPanel (§6.3) |
| SSL | **Actif**, certificat permanent (`hssl`), **redirection HTTPS activée** | HTTPS obligatoire, déjà en place ; HSTS à ajouter (§5) |
| CDN Hostinger | **Activé** sur le sous-domaine | ⚠️ Peut masquer l'IP réelle des visiteurs (ce qui fausse la limitation des tentatives et les journaux) et mettre en cache des réponses ; voir **D2** |
| Cache serveur | Désactivé ✅ | — |
| Cron | Disponible, aucune tâche configurée | Purge de la démo, instantanés, sauvegardes, alertes |
| MySQL | **Un seul utilisateur par base**, avec **tous les privilèges** sur sa base (`DROP`, `ALTER`, `TRIGGER`, `EVENT`…) ; 3 Go par base ; accès distant seulement s'il est explicitement autorisé | Moindre privilège limité (**D10**) |

### 1.2 Points non vérifiables par l'API, à contrôler dans hPanel avant de figer les choix

| Point | Où le vérifier | Pourquoi |
|---|---|---|
| Sauvegardes automatiques : fréquence, rétention, restauration | hPanel → Fichiers → Sauvegardes | Il faut savoir si l'offre Premium sauvegarde chaque semaine ou chaque jour. Notre politique de sauvegarde (§8) ne repose **pas** uniquement sur ces sauvegardes |
| Accès SSH | hPanel → Avancé → Accès SSH | Migrations, tests de restauration ; sinon, alternative par phpMyAdmin |
| Privilèges MySQL modifiables, ou deuxième utilisateur possible | hPanel → Bases de données | Moindre privilège |
| Localisation du centre de données (`srv936`) | hPanel → Hébergement → Détails | RGPD : hébergement dans l'UE |
| Version de MySQL ou MariaDB | phpMyAdmin | Prise en charge des contraintes `CHECK`, des clés étrangères composites et du JSON |
| Email du domaine (SPF, DKIM, DMARC) | hPanel → Emails, zone DNS | Délivrabilité des emails de réinitialisation et de vérification |
| Prise en compte des en-têtes `.htaccess` (`Header`) par LiteSpeed | Test automatique au déploiement (§11) | HSTS, CSP |

---

## 2. Modèle de menaces

### 2.1 Données à protéger

| Donnée | Sensibilité | Remarque |
|---|---|---|
| Rémunération du dirigeant, CA, charges, coûts de personnel, résultat | **Très haute** | Secret des affaires. Pour un entrepreneur individuel, la rémunération est une **donnée personnelle** |
| Prix, marges, offres, nombre de contrats, coût horaire | Haute | Avantage concurrentiel |
| Email, nom, adresses IP, journaux de connexion | Données personnelles | RGPD |
| Mots de passe, jetons de session et de réinitialisation, clés | Critique | Ne doivent jamais être lisibles en clair |
| Diagnostic, plan d'action | Moyenne | Stratégie interne |

### 2.2 Acteurs et principales menaces

| Acteur | Menace | Parades principales |
|---|---|---|
| Visiteur anonyme | force brute, création de comptes abusive, abus de la démo, injections | limitation des tentatives, vérification de l'email, quotas de démo, requêtes préparées |
| Utilisateur authentifié malveillant | **IDOR/BOLA** (accès aux données d'une autre agence), mass assignment, XSS stocké | isolation à trois niveaux (§4), liste blanche des champs, échappement et CSP |
| Site tiers | CSRF, clickjacking | jetons CSRF, vérification de `Origin`, `SameSite`, `frame-ancestors 'none'` |
| Attaquant réseau | vol de session | HTTPS et HSTS, cookies `Secure` |
| **Autre site compromis du même compte Hostinger** (plugin WordPress vulnérable, par exemple) | lecture de `config/.env`, des clés et des sauvegardes, puis accès à la base | **D1**, §2.3 |
| Erreur de l'opérateur | suppression accidentelle, migration ratée | sauvegardes chiffrées, sauvegarde avant chaque migration, délai avant suppression d'un compte |

### 2.3 Limite structurelle du mutualisé : le compte Unix partagé

Tous les sites du compte `u700770059` s'exécutent **sous le même utilisateur système**. Les permissions de fichiers (600, 700) protègent contre les **autres clients** de Hostinger, qui sont isolés par CageFS/CloudLinux. Elles ne protègent **pas** contre un autre de tes propres sites qui serait compromis : celui-ci pourrait lire la configuration d'Agency OS, ses clés et sa base.

Aucune mesure applicative ne peut compenser complètement ce risque. Le chiffrement applicatif ne le résout pas non plus, puisque la clé est lisible par le même utilisateur. **Arbitrage D1** (§13).

---

## 3. Authentification et sessions

### 3.1 Mots de passe

- `password_hash()` avec **Argon2id** si `PASSWORD_ARGON2ID` est disponible (fourni par l'extension sodium depuis PHP 7.4, d'où **D3**), avec `memory_cost` = 64 Mo, `time_cost` = 3 et `threads` = 1. Ces valeurs seront calibrées pour rester sous environ 250 ms sur le serveur.
- Sinon, **bcrypt avec un coût de 12**. `password_needs_rehash()` à chaque connexion migre automatiquement vers Argon2id dès qu'il devient disponible.
- Politique : **12 caractères minimum**, 128 au maximum, tous les caractères admis, pas de règles de composition imposées (recommandation ASVS et NIST). Refus des mots de passe trop courants (liste locale d'environ 100 000 mots de passe, **sans appel à un service tiers**) et de ceux qui contiennent l'email.
- Un mot de passe n'est **jamais** envoyé par email, journalisé ni renvoyé par l'API.

### 3.2 Inscription et vérification de l'email (anti-énumération)

- L'inscription renvoie **toujours** le même message : « Vérifiez votre boîte de réception ».
- Si l'adresse est déjà utilisée, le titulaire reçoit un email « Vous avez déjà un compte » ; personne d'autre ne l'apprend.
- Le compte n'est utilisable **qu'après vérification** de l'email : lien à usage unique, valable 24 h (**D9**).
- Limitation : 5 inscriptions par IP et par heure.

### 3.3 Connexion et limitation des tentatives

- Message unique « Identifiants invalides », et **temps de réponse constant** : un hachage factice est vérifié même si l'email est inconnu.
- Limites (table `rate_limit_hits`, clés hachées) :
  - **5 échecs par couple email + IP** sur 15 minutes, puis blocage de 15 minutes ;
  - **20 échecs par email**, toutes IP confondues, sur 1 heure, puis blocage de 1 heure et email d'alerte au titulaire ;
  - **50 échecs par IP** sur 1 heure, puis blocage de 1 heure ;
  - le blocage s'applique **même si le mot de passe est correct**, pour éviter qu'un attaquant ne s'en serve comme oracle.
- La fiabilité de ces limites suppose l'**IP réelle** du visiteur : voir **D2** (CDN).

### 3.4 Sessions

| Mesure | Valeur |
|---|---|
| Cookie | nom `__Host-aos_sid` (le préfixe `__Host-` impose `Secure`, `Path=/` et l'absence de `Domain`) ; `HttpOnly` ; `Secure` ; `SameSite=Lax` |
| Configuration | `session.use_strict_mode=1`, `use_only_cookies=1`, `use_trans_sid=0`, `sid_length=48`, `sid_bits_per_character=6`, appliqués par le code (`ini_set`) avant `session_start()` ; ne dépend pas des valeurs par défaut de Hostinger |
| Stockage | `storage/sessions/` (droits 700, hors `public_html`), avec ramasse-miettes géré par l'application. Cela évite le répertoire de sessions commun aux autres sites du compte et ses réglages différents |
| Régénération | `session_regenerate_id(true)` à la connexion, à la déconnexion, à toute **élévation de privilège** (réauthentification) et au changement de mot de passe |
| Expiration | **60 min d'inactivité** (avertissement 5 min avant, avec un bouton « Rester connecté ») ; **12 h au maximum** depuis la connexion |
| Déconnexion | destruction côté serveur, suppression du cookie, nouveau jeton CSRF |
| Invalidation globale | `users.session_epoch` est incrémenté lors d'un changement de mot de passe, d'une réinitialisation ou d'une action « Se déconnecter de tous les appareils ». Toute session portant un ancien numéro est refusée |
| Réauthentification | mot de passe redemandé (validité de 10 minutes) pour changer d'email ou de mot de passe, exporter les données ou supprimer le compte |

### 3.5 Réinitialisation du mot de passe

1. `POST /auth/password/forgot` renvoie **toujours** la même réponse. Limites : 3 demandes par email et par heure, 10 par IP et par heure.
2. Jeton de **32 octets aléatoires** (`random_bytes`), dont seul le **SHA-256 est stocké**. **30 minutes** de validité, **usage unique**. Une nouvelle demande invalide les jetons précédents.
3. Le lien est construit à partir de `APP_URL` (configuration), **jamais** à partir de l'en-tête `Host`, pour éviter l'empoisonnement de lien.
4. La page de réinitialisation échange immédiatement le jeton contre un état de session, puis **redirige vers une URL sans jeton**. En-têtes `Referrer-Policy: no-referrer` et `Cache-Control: no-store`.
5. Après la réinitialisation : toutes les sessions sont invalidées, le jeton est consommé et un email de confirmation est envoyé (« Votre mot de passe a été modifié. Si ce n'est pas vous… »).

### 3.6 MFA : architecture prête, non développée au MVP

- Table `user_mfa_methods` (type `totp`, secret **chiffré**, date d'activation) et table `user_recovery_codes` (codes **hachés**). Les deux sont créées vides dès le MVP.
- Machine à états de la connexion : `password_verified` → `mfa_required` → `authenticated`. Le middleware `Authenticate` exige déjà le niveau `authenticated`. Ajouter TOTP plus tard reviendra à insérer l'étape intermédiaire, sans refondre les sessions.

---

## 4. Autorisations et isolation entre agences (exigence critique)

L'isolation repose sur **trois niveaux indépendants**. Une erreur à un niveau est rattrapée par les autres.

### Niveau 1 : le contexte vient exclusivement du serveur

- `OrganizationContext` lit `user_id` et `active_organization_id` **dans la session** et vérifie l'appartenance (`organization_members`) **à chaque requête**.
- Aucun `organization_id` n'est accepté depuis le navigateur. Les routes n'en contiennent pas, et le `Validator` **rejette** tout champ non déclaré, `organization_id` compris (422 `unknown_field`).

### Niveau 2 : des dépôts restreints par construction

- Chaque dépôt métier hérite de `OrgScopedRepository`, dont toutes les méthodes exigent un objet `OrgId` (et non un entier libre) et ajoutent `AND organization_id = :org` à chaque `SELECT`, `UPDATE` et `DELETE`.
- Aucune méthode du type `findById($id)` sans organisation n'existe hors des scripts d'administration en ligne de commande.
- Un identifiant appartenant à une autre organisation donne un **404** (même réponse qu'un identifiant inexistant). Côté serveur, l'événement `authz.foreign_resource` est **journalisé** pour détecter les tentatives de sondage.

### Niveau 3 : l'intégrité garantie par la base de données

- **Clés étrangères composites** `(parent_id, organization_id)` vers `(id, organization_id)` sur toutes les tables enfants. Exemples : `pricing_scenarios` → `pricing_simulations`, `pricing_simulations.service_id` → `services`.
- Il devient **impossible** pour la base elle-même de rattacher un objet à une ressource d'une autre organisation, même si le code avait un défaut.

### Identifiants publics non devinables (D7)

- Les URL et l'API utilisent un `public_id` aléatoire (128 bits, encodé en base32, par exemple `01JC…`), et non l'identifiant auto-incrémenté.
- C'est une défense en profondeur : elle ne remplace pas les contrôles. Elle empêche l'énumération et ne révèle pas le volume d'activité.

### Moindre privilège et politiques

- Une `Policy` par ressource ; **refus par défaut**. Chaque route déclare la permission requise (`pricing.write`…) et le routeur refuse de démarrer si une route n'en déclare pas.
- Rôles : `owner` au MVP ; `admin` et `member` sont prévus avec une matrice de permissions.
- **Utilisateurs de démo** : pas de changement d'email ni de mot de passe, pas d'export, pas d'envoi d'email, pas d'invitation.
- Les scripts d'administration (CLI) ne sont **pas** exposés au web.

### Tests d'accès croisé (automatisés, bloquants)

- Le routeur expose la liste de ses routes. Pour **chaque route**, un test vérifie : sans authentification → 401 ; avec un utilisateur d'une organisation B sur une ressource de A → **404**, sans aucune modification en base (contrôle par empreinte des tables) ; utilisateur de démo sur une route interdite → 403.
- Une route ajoutée sans test d'isolation fait échouer la suite (test de couverture des routes).

---

## 5. Protection contre les attaques web

| Menace | Mesures |
|---|---|
| **Injection SQL** | PDO, `ATTR_EMULATE_PREPARES=false`, `ERRMODE_EXCEPTION`, `utf8mb4` ; requêtes préparées **exclusivement** ; tris et filtres dynamiques par **liste blanche**. Un contrôle automatique (CI) interdit les concaténations dans `->query()` et `->prepare()` |
| **XSS** | Échappement **contextuel** par des fonctions dédiées : `e()` pour le HTML, `attr()` pour les attributs, `json_script()` (`JSON_HEX_TAG\|AMP\|APOS\|QUOT`) pour les données injectées dans `<script type="application/json">`. En JavaScript, **uniquement** `textContent` et des nœuds construits par le DOM ; `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write` et `eval` sont **interdits** et un contrôle automatique bloque leur usage. **Trusted Types** sont imposés (`require-trusted-types-for 'script'`) sur les navigateurs qui les prennent en charge |
| **CSP** | `default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'; require-trusted-types-for 'script'; upgrade-insecure-requests`. Aucun script ni style en ligne ; tout est servi localement (Chart.js, polices, icônes). Rapport des violations via `report-to` vers un endpoint interne limité en débit |
| **CSRF** | Jeton synchronisé par session, envoyé dans l'en-tête `X-CSRF-Token` et vérifié par `hash_equals` pour toute méthode autre que `GET`/`HEAD` ; **vérification de `Origin`** (ou de `Referer` à défaut) avec rejet si elle ne correspond pas à `APP_URL` ; `SameSite=Lax`. Les requêtes `GET` ne modifient jamais de données |
| **Clickjacking** | `frame-ancestors 'none'` et `X-Frame-Options: DENY` |
| **Vol de session** | HTTPS seul et HSTS, cookie `__Host-` `Secure` `HttpOnly`, régénération de l'identifiant, expiration (§3.4), aucun identifiant de session dans les URL |
| **Force brute** | §3.3 ; même mécanisme pour la réinitialisation, l'inscription, la démo et les exports |
| **Mass assignment** | Un **DTO d'entrée par endpoint**, avec une liste explicite des champs modifiables. Tout champ inconnu donne une erreur 422. Le statut, les champs figés, `organization_id`, `public_id` et `is_demo` ne sont jamais modifiables par l'API |
| **Fuite d'informations** | Message générique côté utilisateur ; détail technique uniquement dans les journaux, avec un **identifiant d'incident** affiché (« Erreur, référence 7F3K… ») ; `display_errors=Off` ; `expose_php=Off` ; pas de version dans les réponses ; 404 identique pour « inexistant » et « interdit » |
| **Validation des entrées** | Types stricts, longueurs, bornes, énumérations, dates ISO, décimaux en chaînes (`^-?\d{1,8}(\.\d{1,2})?$`), au plus 50 lignes par scénario, JSON de profondeur 16 au maximum, UTF-8 valide obligatoire, normalisation de l'email |
| **Taille des requêtes** | 64 Ko pour le JSON de l'API, sinon **413** ; pas d'envoi de fichiers au MVP ; `post_max_size` abaissé à 2 Mo dans hPanel |
| **Fréquence des requêtes** | Par session et par IP : aperçus 120 par minute, écritures 60 par minute, exports 5 par heure, connexion selon §3.3, rapports CSP 30 par minute. Au-delà : **429** avec `Retry-After` |
| **Redirection ouverte** | Le paramètre `next` n'accepte qu'un chemin relatif interne figurant sur une liste blanche |
| **En-tête `Host` falsifié** | Requête refusée si `Host` ne correspond pas à `APP_URL` ; les URL absolues proviennent toujours de la configuration |

### En-têtes HTTP (envoyés par PHP, et par `.htaccess` pour les fichiers statiques)

```
Strict-Transport-Security: max-age=31536000; includeSubDomains     (montée progressive : 1 jour, puis 1 mois, puis 1 an ; pas de preload)
Content-Security-Policy: (voir ci-dessus)
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin                   (no-referrer sur les pages contenant un jeton)
X-Frame-Options: DENY
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()
Cache-Control: no-store                                             (toutes les pages authentifiées et toutes les réponses de l'API)
```

`includeSubDomains` sur `agency-os.azzurra-pro.com` ne couvre que ses éventuels sous-domaines (`staging.agency-os…`) : il n'a **aucun effet** sur tes autres sites.

---

## 6. Serveur, fichiers et base de données

### 6.1 Répartition des fichiers et droits

```
domains/agency-os.azzurra-pro.com/          750
├── app/  database/  bin/                   750 / fichiers 640   (+ .htaccess "Require all denied" par précaution)
├── config/                                 700
│   ├── .env                                600   identifiants de la base, APP_KEY, SMTP
│   ├── keys/                               700   data-key.v1 (600), backup-public.key (644)
│   └── mysql-backup.cnf                    600   identifiants pour mysqldump (lus par le cron, pas par la ligne de commande)
├── storage/                                700   sessions/, logs/, backups/, cache/
└── public_html/                            755   seul dossier servi : index.php, assets/, .htaccess
```

- `public_html/.htaccess` :
  - `Options -Indexes` ;
  - refus de `^\.` (`.git`, `.env`…) et des extensions `sql|log|bak|env|ini|sh|md|json|lock|dist|cnf|key` (sauf les JSON des assets s'il y en a) ;
  - seuls `index.php` et `assets/` sont accessibles.
- Un **script de vérification après déploiement** (§11) contrôle par HTTP que `/.env`, `/.git/HEAD`, `/config/.env`, `/storage/logs/` et `/database/…sql` répondent 403 ou 404.

### 6.2 Secrets

- Aucun secret dans Git : `config/.env`, `config/keys/*`, `config/*.cnf` et `storage/*` figurent dans `.gitignore`. **Analyse des secrets** (gitleaks) en CI, et secret scanning GitHub une fois l'accès au dépôt rétabli.
- Aucun secret dans le JavaScript ou le HTML. Le seul « secret » côté client est le jeton CSRF, propre à chaque session.
- `APP_KEY` (signatures internes) et la clé de chiffrement des données sont **distinctes** et versionnées (`v1`, `v2`…) pour permettre une rotation.
- Identifiants MySQL : mot de passe aléatoire de 32 caractères, stocké uniquement dans `config/.env` ; **aucun accès distant autorisé** pour cette base. La connexion se fait sur `127.0.0.1` ou `localhost`.

### 6.3 Durcissement de PHP dans hPanel (pour ce site uniquement)

| Option | Par défaut | Cible | Remarque |
|---|---|---|---|
| `expose_php` | On | **Off** | |
| `allow_url_fopen` | On | **Off** | l'application n'ouvre aucune URL distante (`curl` reste disponible si besoin) |
| `log_errors` | Off | **On** | avec `error_log` vers `storage/logs/php-error.log` (défini par le code) |
| `display_errors` | Off | Off | aussi forcé par le code |
| `post_max_size` / `upload_max_filesize` | 256M | **2M** / **1M** | |
| `max_execution_time` | 300 | **30** (web) | les scripts CLI fixent leur propre limite |
| `session.*` | non sécurisées | sécurisées | forcées par le code (§3.4) et alignées dans hPanel |
| `open_basedir` | vide | `…/domains/agency-os.azzurra-pro.com:/tmp` | à tester ; défense partielle contre la lecture d'autres fichiers par PHP (n'empêche pas un autre site compromis de lire Agency OS) |
| Extension `sodium` | désactivée | **activée** | **D3** |

L'application vérifie ces valeurs au démarrage en production (`bin/check-requirements.php` et une vérification d'exécution qui journalise un avertissement en cas d'écart).

### 6.4 MySQL et moindre privilège

- **Contrainte constatée** : un seul utilisateur par base, avec tous les privilèges sur cette base. La séparation idéale (un utilisateur limité à `SELECT`/`INSERT`/`UPDATE`/`DELETE` pour l'application, un autre pour les migrations) est à vérifier dans hPanel (**D10**).
- **Mesures compensatoires** :
  - aucune requête DDL dans le code web (le routeur web ne charge jamais le `Migrator`) ;
  - migrations uniquement en ligne de commande, précédées d'une sauvegarde ;
  - base **dédiée** à Agency OS, sans partage avec un autre site ;
  - aucun accès distant ;
  - mot de passe propre à cette base.

### 6.5 Mises à jour

- Les versions correctives de PHP sont appliquées par Hostinger. Le passage à une nouvelle version mineure se fait manuellement, après test en préproduction. PHP 8.3 bénéficie des correctifs de sécurité jusqu'à fin 2027.
- Dépendances : **aucune en production**, à part Chart.js, embarqué localement avec une version figée et une **empreinte SHA-384 vérifiée par les tests**. En développement : PHPUnit et PHPStan, contrôlés par `composer audit` en CI.
- Revue trimestrielle documentée : versions de PHP, de Chart.js et des dépendances de développement, avis de sécurité.

---

## 7. Protection des données financières

### 7.1 Exposition et transport

- Les données financières ne sortent **que** par les endpoints authentifiés, limités à l'organisation (§4). Les réponses de l'API sont construites par des **sérialiseurs explicites** : jamais d'objet ou de ligne de base renvoyé tel quel.
- **Aucun montant dans les URL** : les calculs et les filtres passent par le corps des requêtes `POST`, et les URL ne contiennent que des `public_id`. Les journaux d'accès du serveur et les en-têtes `Referer` ne contiennent donc jamais de chiffres.
- `Cache-Control: no-store` sur toute réponse authentifiée. Voir aussi **D2** (CDN).

### 7.2 Journaux techniques

- Le `Logger` n'accepte qu'une **liste blanche de clés de contexte** (`event`, `user_public_id`, `org_public_id`, `route`, `status`, `error_ref`…).
- Tout champ dont le nom évoque un montant, un taux, un mot de passe, un jeton ou un cookie est **masqué** par défaut (`[redacted]`). Les corps des requêtes ne sont **jamais** journalisés.
- Des tests vérifient le masquage, notamment qu'un montant présent dans une exception n'apparaît pas dans le journal.

### 7.3 Navigateur

- **Aucune donnée financière** dans `localStorage`, `sessionStorage`, IndexedDB ou les cookies. Seules des préférences d'interface y sont stockées (barre latérale repliée, onglet actif).
- Les données initiales injectées dans la page vivent uniquement dans le DOM et disparaissent avec lui. `Cache-Control: no-store` empêche aussi leur réaffichage par le bouton « Précédent » après une déconnexion.

### 7.4 Exports

- `GET /account/export` exige une **réauthentification**. L'export est généré **à la volée** et diffusé en flux, **jamais écrit sur le disque**.
- En-têtes : `Content-Disposition: attachment`, `no-store`.
- Limite : 5 exports par heure. Chaque export est journalisé (événement seulement, sans contenu) et donne lieu à un email de notification au titulaire.

### 7.5 Chiffrement applicatif au repos (D4)

**Ce qu'il protège** : une fuite de la **base seule**. Par exemple une injection SQL en lecture, un export via phpMyAdmin, une sauvegarde SQL dérobée, ou un accès distant à MySQL mal configuré.

**Ce qu'il ne protège pas** : une compromission du serveur ou du compte Unix (la clé est lisible par le même utilisateur, voir §2.3), ni les **sauvegardes complètes Hostinger**, qui contiennent à la fois les fichiers (donc la clé) et la base.

**Mécanisme** : uniquement des primitives reconnues, **aucun chiffrement maison** :
- **libsodium** : `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt`, avec un nonce aléatoire de 24 octets ;
- **données associées (AAD)** = `table|colonne|organization_id|id de ligne|version de clé`. Un chiffré copié vers une autre ligne ou une autre organisation **ne se déchiffre pas** ;
- format stocké : `v1:` + base64(nonce ‖ chiffré) dans une colonne `VARBINARY`/`TEXT` ;
- clé de 256 bits dans `config/keys/data-key.v1` : **hors de la base**, hors du docroot, droits 600, absente de Git ;
- rotation : un script en ligne de commande rechiffre les données vers `v2` ; les anciennes versions restent déchiffrables tant que la rotation n'est pas terminée.

**Périmètre proposé au MVP (option B)** : les champs **les plus sensibles** :
- `financial_profiles` : CA, charges, coûts de personnel, rémunération du dirigeant (coût complet et part incluse), objectif ;
- `financial_profile_changes` : valeurs avant et après ;
- `kpi_snapshots` : colonnes monétaires et `context`.

Les prix, offres et simulations restent en clair : ils sont moins sensibles et plus nombreux. L'architecture (convertisseurs de colonnes déclarés dans les dépôts) permet d'étendre le chiffrement à ces données plus tard (option C).

**Coût** : ces champs ne sont plus agrégeables en SQL. Ce n'est pas un problème ici, puisque tous les calculs se font déjà en PHP via BCMath. Si la clé est perdue, les données sont **irrécupérables** : la clé est donc incluse dans la procédure de sauvegarde **hors ligne** (§8).

### 7.6 Suppression des données

| Situation | Traitement |
|---|---|
| Suppression d'un objet (simulation, offre…) | Confirmation explicite, puis suppression définitive en cascade |
| **Suppression du compte** (D8) | Réauthentification, puis compte **verrouillé immédiatement** (plus d'accès, sessions invalidées) et **suppression définitive programmée à J+7**. Un lien d'annulation est envoyé par email. À J+7, le cron supprime l'organisation (cascade sur toutes les tables), les adhésions et l'utilisateur, puis vérifie par comptage qu'il ne reste aucune ligne. Un événement anonymisé `account.purged` est conservé, sans email ni données |
| Sauvegardes | Les données supprimées disparaissent des sauvegardes **à l'expiration de la rétention** (30 jours pour nos sauvegardes chiffrées ; durée des sauvegardes Hostinger à confirmer). Ce délai est **indiqué dans la politique de confidentialité**. Une restauration ultérieure est suivie de la **réapplication des suppressions** : un registre des suppressions minimal (`public_id` de l'organisation et date, sans données) est conservé pendant la durée de rétention pour cela |

---

## 8. Sauvegardes et restauration

### 8.1 Architecture à trois niveaux

| Niveau | Contenu | Fréquence | Rétention | Chiffrement |
|---|---|---|---|---|
| **N1 : Hostinger** | fichiers et bases du compte | selon l'offre (**à confirmer** dans hPanel) | selon l'offre | géré par Hostinger (non maîtrisé) |
| **N2 : sauvegardes applicatives** | base Agency OS | **quotidienne** (cron à 03:00) | **7 quotidiennes et 4 hebdomadaires** (environ 30 jours) | **oui, par clé publique** |
| **N3 : copie hors site** | les fichiers N2 chiffrés | hebdomadaire | 3 mois | déjà chiffrés |

**N2 en détail** :
- Le cron exécute `bin/backup-db.sh` (le shell est autorisé pour le cron ; PHP n'en a pas besoin) :
  - `mysqldump --single-transaction --routines --triggers --defaults-extra-file=config/mysql-backup.cnf` ;
  - compression par `gzip` ;
  - chiffrement par `php bin/seal-backup.php` avec libsodium : `crypto_box_seal` d'une clé aléatoire, puis `crypto_secretstream_xchacha20poly1305` sur le flux.
- **Le serveur ne détient que la clé publique.** La clé privée de déchiffrement est conservée **hors ligne** par toi (gestionnaire de mots de passe et copie papier ou clé USB). Même en cas de compromission du serveur, les sauvegardes passées restent illisibles.
- Le fichier en clair n'est jamais conservé : il est chiffré puis supprimé dans la même tâche, et le script refuse de continuer si le chiffrement échoue.
- Un manifeste accompagne chaque sauvegarde : date, taille, SHA-256, version du schéma et nombre de lignes par table (sans données).
- Des contrôles automatiques valident la sauvegarde : présence du marqueur `-- Dump completed`, taille dans la plage attendue, rotation. **En cas d'échec, un email d'alerte est envoyé.**

**N3** : arbitrage **D5**. Une copie stockée sur le même serveur ne protège pas contre la perte du compte ou du serveur.

### 8.2 Restauration documentée (`docs/runbooks/restauration.md`, phase 5)

1. Télécharger le fichier chiffré et son manifeste.
2. Déchiffrer **en local** avec la clé privée (`php bin/unseal-backup.php`, qui fonctionne aussi hors du serveur).
3. Vérifier le SHA-256 et la version du schéma.
4. Importer dans une **base de restauration distincte**, jamais directement en production.
5. Lancer `bin/verify-restore.php` : intégrité des clés étrangères, comptages comparés au manifeste, déchiffrement d'un échantillon de champs chiffrés (ce qui vérifie aussi la clé de données).
6. Réappliquer le registre des suppressions.
7. Mettre l'application en maintenance, basculer, puis contrôler.
8. Rédiger un compte rendu.

### 8.3 Tests de restauration

- **Mensuels**, en suivant la procédure complète sur la base de test `…_aos_restore`, avec un compte rendu daté (durée, écarts).
- **Avant chaque mise en production importante.**

### 8.4 Protection contre la suppression accidentelle et la corruption

- Transactions sur toute écriture qui touche plusieurs tables ; clés étrangères et contraintes `CHECK` (si la version de MySQL les applique, **à vérifier**) ; enregistrements figés en lecture seule (§02).
- `bin/migrate.php` **refuse de s'exécuter** sans une sauvegarde N2 de moins d'une heure, et chaque migration est encadrée par une transaction lorsque c'est possible.
- Délai de 7 jours avant la suppression d'un compte (§7.6) et confirmations explicites dans l'interface.
- Aucun accès à phpMyAdmin dans le fonctionnement courant ; les modifications manuelles en production sont interdites hors procédure.

---

## 9. Confidentialité et RGPD

### 9.1 Rôles (à faire valider juridiquement avant toute commercialisation)

- **Responsable de traitement** : l'éditrice d'Agency OS, pour les comptes, la sécurité et le service. Ses coordonnées figureront dans les mentions légales.
- Lors d'une commercialisation, les données métier saisies par une agence pourront relever de la **sous-traitance** (article 28) : un contrat de sous-traitance sera alors à prévoir.
- **Sous-traitants** : Hostinger (hébergement, CDN si conservé, email) ; éventuellement le fournisseur de stockage hors site (**D5**). **Aucun autre service tiers** : pas d'outil d'analyse d'audience, pas de polices ni de scripts externes, pas de pixel de suivi.
- Seuls des cookies **strictement nécessaires** sont utilisés (session et préférences sans données personnelles). **Aucun bandeau de consentement n'est requis** (exemption CNIL), et le principe est rappelé dans la politique.

### 9.2 Minimisation

- **Obligatoires** : email, mot de passe, nom de l'agence.
- **Facultatifs** : prénom, nom.
- **Non collectés** : téléphone, adresse, date de naissance, SIRET, données de clients finaux (les contrats ne sont que des **nombres**, sans noms de clients).
- Les données financières sont toutes facultatives et appartiennent à l'agence.

### 9.3 Durées de conservation

| Donnée | Durée |
|---|---|
| Compte et données de l'agence | tant que le compte est actif ; **inactif depuis 24 mois** : email d'avertissement, puis suppression à 25 mois |
| Compte supprimé | purge à J+7 ; présence résiduelle dans les sauvegardes ≤ 30 jours (N2) et 3 mois (N3), durée Hostinger à préciser |
| Journal de sécurité | **6 mois**, puis suppression automatique |
| Journal des erreurs techniques | 30 jours |
| Données de limitation des tentatives | 24 h |
| Jetons de réinitialisation et de vérification | supprimés 24 h après expiration ou utilisation |
| Démo | 24 h |
| Registre des suppressions (sans données) | 3 mois (durée maximale des sauvegardes) |

### 9.4 Droits des personnes, intégrés au produit

| Droit | Mise en œuvre |
|---|---|
| Accès et portabilité | **Export JSON complet** (désormais obligatoire au MVP, et non plus « souhaitable ») |
| Rectification | Modification dans Paramètres (profil, agence, finances) |
| Effacement | Suppression du compte (§7.6) |
| Autres demandes (opposition, limitation, questions) | Adresse de contact dédiée, traitement sous **un mois**, registre interne des demandes |

### 9.5 Documents à produire (phase 5, avant la mise en ligne)

- Politique de confidentialité.
- Mentions légales (éditeur, hébergeur Hostinger).
- Conditions d'utilisation.
- **Registre des traitements** (interne).
- Procédure de violation de données (notification à la CNIL sous 72 h si nécessaire).
- Fiche « sécurité » publique résumant les mesures.

Une analyse d'impact (AIPD) ne semble pas obligatoire (pas de données sensibles au sens de l'article 9, pas de traitement à grande échelle). Ce point est à réévaluer lors d'une commercialisation.

---

## 10. Journalisation, détection et incidents

### 10.1 Journal de sécurité (table `security_events`, insertion seule)

| Champ | Contenu |
|---|---|
| `event` | `auth.login_succeeded`, `auth.login_failed`, `auth.locked`, `auth.logout`, `auth.password_changed`, `auth.password_reset_requested`, `auth.password_reset_completed`, `auth.email_verified`, `auth.sessions_revoked`, `authz.denied`, `authz.foreign_resource`, `csrf.rejected`, `ratelimit.exceeded`, `account.export`, `account.deletion_requested`, `account.deletion_cancelled`, `account.purged`, `finance.profile_changed` (sans valeurs), `data.deleted` (type et `public_id`), `demo.created`, `demo.purged`, `backup.failed`, `config.drift` |
| Acteur | `user_id` (si connu) ; `organization_id` (si connu) |
| Réseau | `ip` (VARBINARY 16) ; `user_agent_hash` |
| Divers | `request_id` ; `created_at` |
| `context` | JSON, **liste blanche** : jamais de mot de passe, de jeton, de cookie, de montant ni d'email en clair pour les échecs (hachage HMAC de l'email) |

L'utilisateur voit ses **10 dernières connexions** (date, appareil approximatif) dans Paramètres → Sécurité.

### 10.2 Détection (cron toutes les 15 minutes) et alertes par email à l'administratrice

- Plus de 30 échecs de connexion par heure sur l'ensemble du service, ou plus de 10 sur un même compte.
- Plus de 5 événements `authz.foreign_resource` en 10 minutes pour un même utilisateur, ce qui signale un sondage IDOR. L'utilisateur est alors **suspendu automatiquement** en attendant une revue.
- Pic de rejets CSRF ou de violations CSP.
- Échec de sauvegarde ou de purge ; cron absent depuis plus de 26 h (« battement » enregistré à chaque exécution).
- Écart de configuration détecté au démarrage (§6.3).

### 10.3 Procédure d'incident (`docs/runbooks/incident.md`)

1. **Détecter et qualifier** : gravité, données concernées.
2. **Contenir** :
   - **mode maintenance** (fichier `storage/maintenance.flag`, vérifié par le contrôleur frontal) ;
   - révocation de toutes les sessions (époque globale) ;
   - rotation des secrets : mot de passe MySQL, `APP_KEY`, clé de données en `v(n+1)` avec rechiffrement.
3. **Analyser** : journaux de sécurité, journaux d'accès Hostinger, intégrité des fichiers (empreintes de déploiement comparées au contenu réel).
4. **Corriger, puis restaurer** si nécessaire (§8.2).
5. **Notifier** : la CNIL sous 72 h en cas de violation présentant un risque ; les personnes concernées en cas de risque élevé.
6. **Retour d'expérience** écrit et mise à jour du registre des risques.

---

## 11. Sécurité du mode démonstration

| Exigence | Mise en œuvre |
|---|---|
| Isolation | Une organisation et un utilisateur `is_demo` par visiteur, avec les **mêmes contrôles** (§4) ; tests d'accès croisé entre deux démos |
| Aucune donnée réelle | Jeu de données **entièrement fictif** et relu (agence « Lumen », personnes et chiffres inventés), versionné dans le dépôt. Bandeau permanent : « Données fictives : n'enregistrez pas d'informations réelles » |
| Expiration | 24 h ; compte à rebours visible ; accès refusé au-delà |
| Suppression | Cron toutes les heures, purge de secours à chaque création, cascade dans une transaction, vérification par comptage, événement `demo.purged` |
| Ressources | 5 démos par IP et par heure ; 500 démos actives au maximum ; **quotas par démo** (20 simulations, 10 offres, 5 scénarios, 50 actions) ; les démos sont exclues des instantanés quotidiens et des alertes |
| Droits réduits | Pas d'email, pas d'export, pas de changement d'identifiants, pas d'invitation |

---

## 12. Tests de sécurité et processus

### 12.1 Tests automatisés (bloquants en CI)

| Suite | Contenu |
|---|---|
| `tests/Security/AccessControlTest` | Matrice générée à partir des routes : anonyme → 401 ; autre organisation → 404 sans modification ; démo sur une route interdite → 403 ; couverture de 100 % des routes |
| `CsrfTest` | Chaque route d'écriture : sans jeton, avec un jeton faux ou expiré, avec une mauvaise `Origin` → 403 |
| `MassAssignmentTest` | `organization_id`, `status`, `is_demo` et les champs figés injectés → 422, base inchangée |
| `InjectionTest` | Corpus SQLi et XSS dans chaque paramètre : jamais d'erreur 500, aucune donnée étrangère, rendu échappé (analyse du HTML produit) |
| `SessionTest` | Attributs du cookie, régénération de l'identifiant, expiration d'inactivité et absolue, déconnexion côté serveur, invalidation par l'époque de session |
| `BruteForceTest` | Seuils de §3.3, réponse identique pour un email inconnu, temps de réponse constant (tolérance) |
| `PasswordResetTest` | Usage unique, expiration, invalidation des jetons précédents, `Host` falsifié ignoré, sessions révoquées |
| `HeadersTest` | En-têtes de §5 présents sur les pages, l'API et les erreurs |
| `LoggingTest` | Aucun mot de passe, jeton ou montant dans les journaux (injection volontaire, puis recherche) |
| `CryptoTest` | Aller-retour du chiffrement, échec avec une mauvaise AAD (ligne ou organisation échangée), rotation de version, sauvegarde scellée et descellée |
| `DemoTest` | Isolation, quotas, purge complète table par table |
| `DataDeletionTest` | Purge à J+7 : plus aucune ligne liée, événement anonymisé conservé |

**Analyse statique** :
- **PHPStan** au niveau maximal (outil de développement, justifié par la détection de défauts de type à coût nul en production) ;
- contrôles personnalisés en CI : SQL concaténé, `innerHTML` et équivalents, `eval`, `var_dump`/`print_r` ;
- **gitleaks** pour les secrets ;
- `composer audit` pour les dépendances de développement ;
- contrôle de l'empreinte de Chart.js.

### 12.2 Avant chaque mise en production

1. **Revue de sécurité** du code modifié, avec la checklist ASVS correspondante (`docs/security/asvs-checklist.md`).
2. **Scan dynamique** OWASP ZAP (*baseline*, puis *scan actif* authentifié) **sur la préproduction**, jamais sur la production (**D11**).
3. `bin/security-smoke.php https://…` : HTTPS et HSTS, en-têtes, fichiers sensibles inaccessibles, cookies, 404 sur un `public_id` étranger, 413 et 429.
4. `bin/check-requirements.php` : options PHP, extensions, droits des fichiers, absence d'accès distant à MySQL (contrôle manuel dans hPanel), cron actifs.
5. **Test de restauration** récent (moins de 30 jours).
6. **Registre des risques** à jour (`docs/security/risk-register.md`). **Aucun risque critique ou élevé ouvert.**
7. Checklist de mise en production signée (phase 5).

### 12.3 Intégration dans chaque phase

| Phase | Sécurité intégrée |
|---|---|
| 3 — UX/UI | Écrans de réauthentification, de suppression différée, d'activité récente, de verrouillage et d'expiration de session ; messages génériques ; aucune donnée sensible dans les URL ; motifs d'interface compatibles avec la CSP (aucun style en ligne) |
| 4 — Développement | « Définition de fini » de chaque module : politiques déclarées, tests d'isolation et de CSRF, DTO en liste blanche, journalisation, revue de la checklist. **Le socle sécurité est développé en premier** (sessions, CSRF, contexte d'organisation, dépôts restreints, en-têtes, journal, limitation des tentatives) |
| 5 — Tests et déploiement | §12.2, documents RGPD, procédures d'incident et de restauration, configuration de hPanel vérifiée |

---

## 13. Arbitrages à valider

| # | Sujet | Options | Recommandation |
|---|---|---|---|
| **D1** | **Compte Unix partagé** avec tes autres sites (dont plusieurs WordPress) | (a) rester sur ce compte en durcissant les autres sites ; (b) **compte ou offre Hostinger dédié** à Agency OS ; (c) VPS (plus de contrôle, mais l'administration système est à ta charge) | **(a) pour le MVP de portfolio**, qui ne contient que des données de démo et de test, avec revue et mise à jour des sites WordPress du compte et suppression des sites inutilisés. **(b) obligatoire avant d'accueillir des données réelles de clients.** Je l'inscris comme condition de commercialisation dans le registre des risques |
| **D2** | **CDN Hostinger activé** sur le sous-domaine | (a) le désactiver ; (b) le garder et faire confiance à un en-tête d'IP transmis par le CDN | **(a)**. Fiabilité de l'IP pour la limitation des tentatives et les journaux, aucun risque de mise en cache de pages authentifiées ; le gain de performance est négligeable pour une application authentifiée et légère. Je peux le désactiver via l'API avec ton accord |
| **D3** | **Extension `sodium`** | activer, ou rester sur OpenSSL (AES-256-GCM) et bcrypt | **Activer** : Argon2id, chiffrement des données et scellement des sauvegardes avec une API difficile à mal utiliser. Je peux l'activer via l'API avec ton accord |
| **D4** | **Chiffrement applicatif au repos** | (A) aucun ; (B) champs les plus sensibles (profil financier, journal, instantanés) ; (C) toutes les données financières | **(B)**, en assumant ses limites (§7.5) : protection contre une fuite de la base seule, pas contre une compromission du compte |
| **D5** | **Copie de sauvegarde hors site (N3)** | (a) téléchargement manuel hebdomadaire par toi ; (b) envoi automatique vers un stockage objet européen (nouveau sous-traitant, quelques centimes par mois) ; (c) aucune | **(b)** pour la fiabilité, sinon **(a)** pour le MVP. Les fichiers sont chiffrés avant envoi dans les deux cas |
| **D6** | **Envoi d'emails** (vérification, réinitialisation, alertes) | (a) `mail()` de PHP via le serveur Hostinger, avec SPF, DKIM et DMARC du domaine ; (b) SMTP authentifié via une bibliothèque (PHPMailer, nouvelle dépendance) | **(a)**, sans dépendance supplémentaire, après vérification de SPF, DKIM et DMARC. Passage à (b) si la délivrabilité est insuffisante |
| **D7** | **Identifiants publics aléatoires** | aléatoires, ou séquentiels avec contrôles seuls | **Aléatoires** (défense en profondeur, pas d'énumération) |
| **D8** | **Suppression du compte** | immédiate, ou différée de 7 jours avec annulation | **Différée de 7 jours**, avec verrouillage immédiat (protection contre les erreurs, compatible RGPD si l'utilisateur en est informé) |
| **D9** | **Vérification de l'email obligatoire** avant le premier accès | oui ou non | **Oui** : anti-énumération, adresse valide pour la réinitialisation. La démo reste accessible sans compte |
| **D10** | **Moindre privilège MySQL** | séparer un utilisateur applicatif et un utilisateur de migration si hPanel le permet ; sinon mesures compensatoires (§6.4) | **Vérifier dans hPanel**, puis appliquer la meilleure option disponible |
| **D11** | **Préproduction** | sous-domaine `staging.agency-os.azzurra-pro.com`, base séparée, données fictives, protégé par authentification HTTP | **Oui** : indispensable pour les scans dynamiques et les tests de restauration sans toucher à la production |

### Changements induits dans les documents précédents (appliqués après validation)

| Document | Avant | Après |
|---|---|---|
| 01 §4 | Mot de passe oublié « souhaitable », export JSON « souhaitable » | **Indispensables** (sécurité, RGPD) |
| 02 §4 | bcrypt ou Argon2id, 10 caractères minimum, inactivité de 2 h, cookie générique | Argon2id via sodium, **12 caractères**, **60 min**, cookie `__Host-` et stockage dédié des sessions |
| 02 §5 | Identifiants auto-incrémentés exposés ; clés étrangères simples | `public_id` aléatoires ; **clés étrangères composites** avec l'organisation ; tables `security_events`, `user_mfa_methods`, `user_recovery_codes`, `email_verifications`, `deletion_registry` ; colonnes chiffrées (D4) |
| 02 §9 | Démo sans quotas par organisation | Quotas par démo, droits réduits |
| 02 §11 | Deux tâches cron | Purge de la démo (toutes les heures), instantanés et maintenance (chaque jour), sauvegarde (chaque jour), détection (toutes les 15 min), purge des journaux (chaque jour) |
