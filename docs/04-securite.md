# Agency OS — Architecture de sécurité et de confidentialité

> Statut : **arbitrages D1 à D11 validés le 10/11/2026**, avec des ajustements (§13). **Aucune modification d'infrastructure n'a été faite** (Hostinger, CDN, extensions PHP, DNS) : les actions prévues sont listées en §16 et attendent ton accord explicite.
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
| **Portée réelle des réglages PHP** (constat du 10/11/2026, voir §17) | Extensions et options PHP **partagées par tous les sites du compte qui utilisent la même version de PHP**, et non réglées site par site. Sites en 8.3 : `agency-os`, `azzurra-pro.com`, `apercu`, `amani-events`. Sites en 8.2 : `v2`, `bell-extension`, `apaisio`, `boxe`, `wedizz` | **Aucun réglage PHP ne peut être isolé à Agency OS tant qu'il partage sa version avec d'autres sites.** Le durcissement passe donc **par le code** (§6.3) |
| Fonctions désactivées | `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`… | PHP ne peut pas lancer `mysqldump` ; en revanche, une **tâche cron peut exécuter une commande shell** (§8) |
| Options PHP par défaut | `display_errors=Off` ✅ ; `expose_php=On` ❌ ; `allow_url_fopen=On` ❌ ; `session.cookie_secure/httponly=Off` ❌ ; `samesite=None` ❌ ; `use_strict_mode=Off` ❌ ; `log_errors=Off` ❌ ; `post_max_size=256M` ❌ | Durcissement **par le code** à l'exécution (les options de session sont modifiables par `ini_set`) **et** dans hPanel (§6.3) |
| SSL | **Actif**, certificat permanent (`hssl`), **redirection HTTPS activée** | HTTPS obligatoire, déjà en place ; HSTS à ajouter (§5) |
| CDN Hostinger | Réglage **« activé »** dans les paramètres du site, **mais pas dans le chemin effectif** : le DNS de `agency-os` pointe **directement vers le serveur** (A `89.116.147.139`, AAAA `2a02:4780:27:1036:…:12`), alors que `azzurra-pro.com` et `www` passent par `*.cdn.hstgr.net` ; aucun en-tête de CDN dans les réponses | Aujourd'hui, l'IP vue par PHP est celle du visiteur, et aucune mise en cache par le CDN n'a été constatée. Le statut peut toutefois changer si le DNS est modifié : voir **D2** et la surveillance automatique (§12.2) |
| Cache serveur | Désactivé ✅ | — |
| Cron | Disponible, aucune tâche configurée | Purge de la démo, instantanés, sauvegardes, alertes |
| MySQL | **Un seul utilisateur par base**, avec **tous les privilèges** sur sa base (`DROP`, `ALTER`, `TRIGGER`, `EVENT`…) ; 3 Go par base ; accès distant seulement s'il est explicitement autorisé | Moindre privilège limité (**D10**) |
| En-têtes HTTP observés (site vide, 10/11/2026) | `x-powered-by: PHP/8.3.36` (version exposée) ; `server: LiteSpeed` ; `content-security-policy: upgrade-insecure-requests` **ajouté par Hostinger** ; pas de HSTS | Notre CSP s'ajoutera à celle de Hostinger : le navigateur applique les deux, ce qui reste compatible. `expose_php=Off` supprimera `x-powered-by`. HSTS est à ajouter |
| Email de `azzurra-pro.com` | SPF `v=spf1 include:_spf.mail.hostinger.com ~all` ✅ ; DKIM Hostinger (`hostingermail-a/b/c._domainkey`) ✅ ; **DMARC `p=none`** sans adresse de rapport ⚠️ ; MX Hostinger ✅ | L'envoi par SMTP Hostinger sera aligné SPF et DKIM. Avec `p=none`, **n'importe qui peut usurper l'adresse de l'expéditeur** sans être rejeté ; il faut durcir progressivement (§16, action A6) |

### 1.2 Points non vérifiables par l'API, à contrôler dans hPanel avant de figer les choix

| Point | Où le vérifier | Pourquoi |
|---|---|---|
| Sauvegardes automatiques : fréquence, rétention, restauration | hPanel → Fichiers → Sauvegardes | Il faut savoir si l'offre Premium sauvegarde chaque semaine ou chaque jour. Notre politique de sauvegarde (§8) ne repose **pas** uniquement sur ces sauvegardes |
| Accès SSH | hPanel → Avancé → Accès SSH | Migrations, tests de restauration ; sinon, alternative par phpMyAdmin |
| Privilèges MySQL modifiables, ou deuxième utilisateur possible | hPanel → Bases de données | Moindre privilège |
| Localisation du centre de données (`srv936`) | hPanel → Hébergement → Détails | RGPD : hébergement dans l'UE |
| Version de MySQL ou MariaDB | phpMyAdmin | Prise en charge des contraintes `CHECK`, des clés étrangères composites et du JSON |
| Boîte d'envoi SMTP dédiée (`no-reply@…`) | hPanel → Emails | Le DNS email est vérifié (§1.1). Reste à créer la boîte d'envoi (avec ton accord) et à durcir DMARC |
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

Aucune mesure applicative ne peut compenser complètement ce risque. Le chiffrement applicatif ne le résout pas non plus, puisque la clé est lisible par le même utilisateur.

**Décision D1** :
- sur cet hébergement, **uniquement des données fictives** (portfolio, démo, comptes de test) ;
- **avant toute collecte de données financières réelles**, trois conditions : un hébergement **suffisamment isolé**, une **revue de sécurité** et une **vérification de la configuration de production réelle**.

Un changement d'hébergement n'est **pas** en soi une garantie : chaque mesure de ce document devra être revérifiée sur le nouvel environnement. Le passage aux données réelles est verrouillé techniquement par le drapeau `APP_REAL_DATA_ALLOWED=false` (§13, D1).

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
- **Aucune fonctionnalité privée** n'est accessible avant la vérification de l'email (**D9**). Seules sont autorisées les pages « Vérifiez votre email », « Renvoyer le lien » et « Se déconnecter ».
- Jeton de vérification : 32 octets aléatoires, **SHA-256 stocké**, valable **24 h**, **usage unique** ; toute nouvelle demande invalide les jetons précédents.
- **Renvoi limité** : 1 demande toutes les 2 minutes, 5 par jour et par compte, 10 par IP et par heure. La réponse reste générique.
- Limitation des inscriptions : 5 par IP et par heure.
- Un compte non vérifié au bout de 7 jours est supprimé automatiquement (minimisation).

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

### 6.3 Durcissement de PHP : par le code, sans toucher à hPanel

Constat (§1.1) : les réglages de hPanel s'appliquent à **tous les sites d'une même version de PHP**. Pour le MVP portfolio, le durcissement est donc fait **exclusivement par l'application**. Il ne touche qu'Agency OS et sera transférable tel quel vers le futur hébergement.

| Réglage | Mise en œuvre dans le code | Limite |
|---|---|---|
| `display_errors=0`, `log_errors=1`, `error_log` vers `storage/logs/` | `ini_set()` dans `bootstrap.php`, avant tout traitement | Une erreur de syntaxe dans `bootstrap.php` lui-même échapperait à ce réglage, mais `display_errors` vaut déjà Off par défaut sur le serveur ✅ |
| Version de PHP exposée (`X-Powered-By`) | `header_remove('X-Powered-By')` sur chaque réponse PHP | `expose_php` ne peut pas être modifié à l'exécution ; l'en-tête est retiré, ce qui donne le même résultat pour les réponses PHP |
| `session.*` (`cookie_secure`, `httponly`, `samesite`, `use_strict_mode`, `use_only_cookies`, `sid_length`, `save_path`, `gc_maxlifetime`) | `ini_set()` et `session_set_cookie_params()` avant `session_start()` (§3.4) | Aucune |
| `allow_url_fopen` | Non modifiable à l'exécution. **Règle de code** : aucun accès réseau par `fopen` ou `file_get_contents` ; un contrôle automatique en CI interdit les URL dans ces fonctions. Le seul client HTTP est `curl`, enveloppé dans un service avec une liste d'hôtes autorisés (vide au MVP) | Le réglage du serveur reste On |
| Taille des requêtes | Vérification de `Content-Length` et lecture plafonnée de `php://input` (64 Ko pour l'API) ; refus avec **413** | `post_max_size` reste à sa valeur de serveur |
| Durée d'exécution | `set_time_limit(30)` pour le web ; les scripts en ligne de commande fixent leur propre limite | — |
| `open_basedir` | Non appliqué (réglage partagé) | Défense en profondeur absente ; risque R1, accepté pour le portfolio |

`bin/check-requirements.php` vérifie ces valeurs **effectives** au démarrage, avec `ini_get` et l'absence de l'en-tête.

### 6.4 MySQL et moindre privilège

- **Contrainte constatée** : un seul utilisateur par base, avec tous les privilèges sur cette base. La séparation idéale (un utilisateur limité à `SELECT`/`INSERT`/`UPDATE`/`DELETE` pour l'application, un autre pour les migrations) est à vérifier dans hPanel (**D10**).
- **Décision D10** :
  1. vérifier dans hPanel si les privilèges de l'utilisateur peuvent être réduits, ou si un second utilisateur peut être créé (action **A4**, §16) ;
  2. si oui : un utilisateur applicatif limité à `SELECT`, `INSERT`, `UPDATE`, `DELETE` et `LOCK TABLES` (plus `CREATE TEMPORARY TABLES` si nécessaire), et un utilisateur de migration utilisé uniquement en ligne de commande ;
  3. sinon : **risque résiduel R4 documenté** (§15).
- **Mesures compensatoires** (dans tous les cas) :
  - aucune requête DDL dans le code web (le routeur web ne charge jamais le `Migrator`) ;
  - migrations uniquement en ligne de commande, précédées d'une sauvegarde ;
  - base **dédiée** à Agency OS, sans partage avec un autre site ;
  - aucun accès distant ;
  - mot de passe propre à cette base.

### 6.5 Mises à jour

- Les versions correctives de PHP sont appliquées par Hostinger. Le passage à une nouvelle version mineure se fait manuellement, après test en préproduction. PHP 8.3 bénéficie des correctifs de sécurité jusqu'à fin 2027.
- Dépendances en production, limitées à **deux** :
  - **Chart.js** (front), embarqué localement, version figée, empreinte SHA-384 vérifiée par les tests ;
  - **PHPMailer** (back, **D6**), exception justifiée : SMTP authentifié, TLS, encodage MIME correct, bibliothèque largement auditée. Elle est installée avec `composer install --no-dev` **sur la machine de construction**, et non sur Hostinger. Version figée par `composer.lock` (empreintes vérifiées par Composer). Le dossier `vendor/` est déployé **hors de `public_html`**.
- En développement : PHPUnit et PHPStan.
- **`composer audit` en CI** sur toutes les dépendances : une vulnérabilité connue de gravité élevée ou critique **bloque** la livraison.
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

### 7.5 Chiffrement applicatif au repos (D4, validé)

**Ce qu'il protège** : une fuite de la **base seule**. Par exemple une injection SQL en lecture, un export via phpMyAdmin, une sauvegarde SQL dérobée, ou un accès distant à MySQL mal configuré.

**Ce qu'il ne protège pas, et ne sera jamais présenté comme protégeant** :
- une **compromission complète du serveur** ou du compte Unix : la clé est lisible par le même utilisateur que l'application (§2.3) ;
- les **sauvegardes complètes Hostinger**, qui contiennent à la fois les fichiers (donc les clés) et la base.

La politique de confidentialité et la fiche sécurité publique emploieront une formulation exacte : « chiffrement des données financières en base, avec des clés stockées séparément de la base ».

**Champs chiffrés**

| Table | Champs |
|---|---|
| `financial_profiles` | CA, charges fixes, coûts de personnel, charges variables (attribuables et générales), rémunération du dirigeant (coût complet et part incluse), coût horaire forcé, objectif de CA, part récurrente déclarée |
| `financial_profile_changes` | valeurs avant et après |
| `kpi_snapshots` | toutes les valeurs financières (CA, charges, résultat, MRR, coût horaire) et `context` |
| `recommendations` | `evidence` (contient des valeurs financières) et `impact_amount` |
| Données figées des simulations et des scénarios (`frozen_context`, `frozen_results`) | elles contiennent le coût horaire et des données dérivées du profil |

Restent en clair (moins sensibles, et nécessaires aux contraintes de base) : identifiants, statuts, dates, libellés, prix et temps des brouillons. Ce périmètre pourra être élargi sans refonte.

**Service unique `Security\FieldCrypto`**
- Aucun autre code n'appelle libsodium pour les données.
- Les dépôts déclarent leurs colonnes chiffrées (`'revenue' => Encrypted::decimal()`). La conversion se fait **uniquement** dans la couche d'accès aux données, de façon transparente pour les services.
- Algorithme : `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt` ; nonce aléatoire de 24 octets par valeur.
- **Données associées (AAD)** = `table|colonne|organization_id|public_id de la ligne|version de clé`. Un chiffré déplacé vers une autre ligne ou une autre organisation **échoue au déchiffrement**, ce qui est journalisé comme un incident d'intégrité.
- Format stocké : `k<version>:` + base64(nonce ‖ chiffré ‖ tag).
- Un échec de déchiffrement n'est **jamais** remplacé silencieusement par 0 ou NULL : il lève une exception et produit une erreur 500 avec une référence d'incident.

**Gestion des clés (`Security\Keyring`)**
- Fichiers `config/keys/data.k1`, `data.k2`… : 32 octets aléatoires (`sodium_crypto_aead_xchacha20poly1305_ietf_keygen`), droits 600, **hors de `public_html` et hors de la base**, absents de Git et des sauvegardes N2 et N3.
- `config/keys/keyring.json` indique la **version active**, utilisée pour chiffrer, et les **versions acceptées**, utilisées pour déchiffrer.
- **Sauvegarde des clés** : séparée des sauvegardes de données. Copie hors ligne chiffrée par toi (gestionnaire de mots de passe), à chaque création de clé. Sans la clé, les sauvegardes de données sont inutilisables : la procédure de restauration (§8.2) inclut la restauration des clés.
- **Rotation** :
  1. génération de `k(n+1)`, qui devient la version active ;
  2. `bin/rotate-keys.php` rechiffre les données par lots et reprend là où il s'est arrêté ;
  3. vérification qu'aucune valeur `k(n)` ne subsiste ;
  4. retrait de `k(n)` des versions acceptées **après** la fin de la rétention des sauvegardes qui l'utilisent (sinon ces sauvegardes deviendraient illisibles).

  Rotation **annuelle**, ou **immédiate** en cas de suspicion de compromission.
- `APP_KEY` (signatures HMAC des jetons internes et de l'email haché du journal) est **distincte** des clés de données et suit sa propre rotation.

**Tests** : aller-retour par type ; échec si l'AAD est modifiée ; lecture d'une donnée `k1` après passage à `k2` ; reprise de la rotation après interruption ; **test de restauration** (sauvegarde N2 et clés restaurées, puis déchiffrement d'un échantillon : §8.2, étape 5) ; aucune valeur en clair dans la base après écriture (lecture SQL directe).

### 7.6 Suppression des données

| Situation | Traitement |
|---|---|
| Suppression d'un objet (simulation, offre…) | Confirmation explicite, puis suppression définitive en cascade |
| **Suppression du compte** (D8) | 1. **Vérification d'identité** : saisie du mot de passe (réauthentification), puis confirmation par un lien envoyé à l'adresse du compte (à usage unique, valable 1 h).<br>2. **Désactivation immédiate** : aucun accès, sessions invalidées, données plus servies par l'API.<br>3. **Période d'annulation de 7 jours** : l'annulation exige à nouveau une vérification d'identité (mot de passe et lien email).<br>4. À J+7, le cron **supprime les données actives** : organisation (cascade sur toutes les tables), adhésions, utilisateur. Il vérifie par comptage qu'il ne reste aucune ligne et enregistre le `public_id` dans `deletion_registry`. Un événement anonymisé `account.purged` est conservé, sans email ni données |
| Obligations de conservation | **MVP** : aucune obligation identifiée (service gratuit, sans facturation). **En cas de commercialisation** : les pièces comptables (factures) sont à conserver **10 ans** (article L123-22 du Code de commerce). Elles seraient gérées dans un module de facturation distinct, avec le **minimum** de données (identité de facturation, montants facturés par Agency OS), **jamais** les données financières de l'agence. Journal de sécurité : 6 mois (intérêt légitime, preuve en cas d'incident). Ces durées figureront dans la politique de confidentialité |
| Sauvegardes | Les données supprimées disparaissent des sauvegardes **à l'expiration de la rétention** (30 jours pour nos sauvegardes chiffrées ; durée des sauvegardes Hostinger à confirmer). Ce délai est **indiqué dans la politique de confidentialité**. Une restauration ultérieure est suivie de la **réapplication des suppressions** : un registre des suppressions minimal (`public_id` de l'organisation et date, sans données) est conservé pendant la durée de rétention pour cela |

---

## 8. Sauvegardes et restauration

### 8.1 Architecture à trois niveaux

| Niveau | Contenu | Fréquence | Rétention | Chiffrement |
|---|---|---|---|---|
| **N1 : Hostinger** | fichiers et bases du compte | selon l'offre (**à confirmer** dans hPanel) | selon l'offre | géré par Hostinger (non maîtrisé) |
| **N2 : sauvegardes applicatives** | base Agency OS | **quotidienne** (cron à 03:00) | **7 quotidiennes et 4 hebdomadaires** (environ 30 jours) | **oui, par clé publique** |
| **N3 : stockage externe européen** (D5) | les fichiers N2 chiffrés | **quotidienne** (après N2) | 30 quotidiennes et 12 hebdomadaires (environ 3 mois) | déjà chiffrés avant envoi, plus le chiffrement côté fournisseur |

**N2 en détail** :
- Le cron exécute `bin/backup-db.sh` (le shell est autorisé pour le cron ; PHP n'en a pas besoin) :
  - `mysqldump --single-transaction --routines --triggers --defaults-extra-file=config/mysql-backup.cnf` ;
  - compression par `gzip` ;
  - chiffrement par `php bin/seal-backup.php` avec libsodium : `crypto_box_seal` d'une clé aléatoire, puis `crypto_secretstream_xchacha20poly1305` sur le flux.
- **Le serveur ne détient que la clé publique.** La clé privée de déchiffrement est conservée **hors ligne** par toi (gestionnaire de mots de passe et copie papier ou clé USB). Même en cas de compromission du serveur, les sauvegardes passées restent illisibles.
- Le fichier en clair n'est jamais conservé : il est chiffré puis supprimé dans la même tâche, et le script refuse de continuer si le chiffrement échoue.
- Un manifeste accompagne chaque sauvegarde : date, taille, SHA-256, version du schéma et nombre de lignes par table (sans données).
- Des contrôles automatiques valident la sauvegarde : présence du marqueur `-- Dump completed`, taille dans la plage attendue, rotation. **En cas d'échec, un email d'alerte est envoyé.**

**N3 en détail (D5, validé)**
- **Stockage objet compatible S3, hébergé dans l'UE**, avec **verrouillage d'objets** (*Object Lock*, mode « conformité ») : une sauvegarde envoyée ne peut être ni modifiée ni supprimée avant la fin de sa rétention, **même avec des identifiants volés**.
- Identifiants **en écriture seule** (droit d'envoyer, pas de supprimer ni de lister) stockés dans `config/.env`. Un serveur compromis ne peut donc pas effacer l'historique.
- Fournisseur à choisir parmi les offres européennes qui proposent le verrouillage d'objets, par exemple Scaleway (Paris), OVHcloud (France) ou Backblaze B2 (région UE). Le choix est soumis à ton accord, car c'est un nouveau sous-traitant RGPD (action **A7**, §16).
- Envoi par `bin/offsite-upload.php`, qui utilise `curl`, disponible. La signature des requêtes S3 (AWS Signature V4, standard ouvert fondé sur HMAC-SHA256) est implémentée dans une petite classe **testée contre les vecteurs de test officiels**. C'est une signature de protocole, et non un chiffrement maison. L'alternative, le SDK AWS, est jugée trop lourde.
- Contrôle quotidien : présence de l'objet du jour, taille et SHA-256 identiques au manifeste. Alerte en cas d'écart.
- **Solution provisoire, seulement tant que l'application ne contient que des données de démonstration** : téléchargement manuel hebdomadaire des fichiers N2 chiffrés, consigné dans `docs/runbooks/sauvegardes.md`. Elle n'est **pas** acceptable pour des données réelles.

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
- **Sous-traitants** : Hostinger (hébergement, email SMTP ; CDN seulement s'il est un jour placé dans le chemin de l'application) et le **fournisseur de stockage externe européen** (D5, à choisir). **Aucun autre service tiers** : pas d'outil d'analyse d'audience, pas de polices ni de scripts externes, pas de pixel de suivi.
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
3. `bin/security-smoke.php https://…` : HTTPS et HSTS, en-têtes, `Cache-Control: no-store` sur les réponses authentifiées, détection d'un CDN inattendu (résolution DNS et en-têtes de cache), fichiers sensibles inaccessibles, cookies, 404 sur un `public_id` étranger, 413 et 429.
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

### 12.4 Préproduction (D11, renforcée)

| Exigence | Mise en œuvre |
|---|---|
| Sous-domaine protégé | `staging.agency-os.azzurra-pro.com` (création soumise à ton accord, action **A8**), avec une **authentification HTTP Basic** devant tout le site. Le fichier `.htpasswd` est placé **hors** de `public_html`, avec un mot de passe aléatoire propre à la préproduction. Deuxième barrière : les comptes applicatifs habituels |
| Base distincte | Base MySQL et utilisateur **distincts**, avec un mot de passe différent de la production |
| Identifiants distincts | `config/.env`, `APP_KEY`, clés de données, clé publique de sauvegarde et identifiants SMTP **propres** à la préproduction. Aucun secret partagé avec la production, et aucune copie de la base de production |
| Données fictives uniquement | Jeux de données générés (`bin/seed-demo.php`) ; `APP_ENV=staging` ; un contrôle au démarrage refuse de fonctionner si `APP_REAL_DATA_ALLOWED=true` |
| Pas d'indexation | En-tête `X-Robots-Tag: noindex, nofollow, noarchive` sur toutes les réponses, `robots.txt` en `Disallow: /`, et l'authentification HTTP empêche de toute façon l'exploration |
| Pas d'emails réels | Transport d'email `capture` : les messages sont écrits dans `storage/mail-capture/` (droits 700, purgés au bout de 7 jours) et **jamais envoyés**. Une liste blanche facultative (`MAIL_ALLOWLIST=@azzurra-pro.com`) permet de tester la délivrabilité vers tes propres adresses ; toute autre adresse est bloquée et journalisée |
| Fichiers sensibles | Mêmes règles `.htaccess` qu'en production ; `bin/security-smoke.php` est exécuté **aussi** sur la préproduction |
| Bandeau | « Préproduction : données fictives » sur toutes les pages |

## 13. Arbitrages validés (10/11/2026)

| # | Décision | Mise en œuvre |
|---|---|---|
| **D1** | Hébergement partagé **validé pour le portfolio, avec des données fictives uniquement**. Avant toute donnée financière réelle : hébergement suffisamment isolé, **revue de sécurité**, **vérification de la configuration de production**. Un changement d'hébergement ne vaut pas garantie | Drapeau `APP_REAL_DATA_ALLOWED=false` : l'inscription publique est **désactivée** en production. Seuls la démo et des comptes de test créés en ligne de commande existent, avec la mention « données fictives » sur l'écran d'inscription et dans les CGU. Lever ce drapeau suppose la checklist « données réelles » (§14.3) |
| **D2** | CDN : **vérifier avant de décider**, ne rien désactiver automatiquement. Exclure du cache les pages authentifiées, l'API privée et les contenus financiers ; s'il n'est pas possible de le garantir, désactiver le CDN pour l'application | **Constat du 10/11** : le CDN n'est pas dans le chemin effectif (§1.1). L'application envoie **de toute façon** `Cache-Control: private, no-store` et `Vary: Cookie` sur toute réponse authentifiée, sur toute réponse de l'API et sur les erreurs. Seuls `assets/*` (fichiers versionnés par empreinte) sont publics et mis en cache. **Protocole de vérification** (en préproduction, si le CDN est un jour actif) : comparer deux requêtes successives sur une page authentifiée et sur `/api/v1/dashboard` avec deux comptes différents ; examiner l'en-tête de statut du cache ; vérifier que `no-store` est respecté et que l'IP du visiteur est transmise et exploitable. Si l'un de ces points échoue : désactivation du CDN pour ce sous-domaine (avec ton accord). Le smoke test (§12.2) alerte si le DNS ou les en-têtes révèlent un CDN actif |
| **D3** | Sodium validé, **disponibilité effective à vérifier** avant utilisation | Activation soumise à ton accord (action **A1**). Puis `bin/check-requirements.php` vérifie que l'extension est chargée, que `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt`, `sodium_crypto_box_seal` et `crypto_secretstream` existent, et que `PASSWORD_ARGON2ID` est défini et fonctionne réellement (hachage et vérification de test). En production, l'application **refuse de démarrer** si un de ces points manque. Pas de bascule silencieuse : bcrypt n'est utilisé que si Argon2id est absent, et cela est signalé |
| **D4** | Chiffrement des champs financiers sensibles, y compris dans les journaux et les instantanés ; service centralisé ; clés hors du répertoire public, versionnées, avec rotation et tests de restauration ; jamais présenté comme une protection contre la compromission du serveur | §7.5 |
| **D5** | Stockage externe européen chiffré, avec rétention et tests de restauration ; le téléchargement manuel n'est que provisoire | §8.1 (N3) ; choix du fournisseur : action **A7** |
| **D6** | **SMTP authentifié avec PHPMailer**, au lieu de `mail()` ; vérifier SPF, DKIM et DMARC | §6.5 (dépendance) ; `Mail\Mailer` est une façade unique (transports `smtp`, `capture`, `null`). SMTP Hostinger en **TLS obligatoire** (port 465 en TLS implicite, ou 587 avec STARTTLS), vérification du certificat activée, délai d'attente de 10 s. Boîte d'envoi dédiée (action **A5**). Les emails ne contiennent **jamais** de mot de passe ni de donnée financière, seulement des liens à usage unique. Constat DNS : SPF et DKIM sont en place, **DMARC est à durcir** (action **A6**) |
| **D7** | Identifiants non prédictibles pour les ressources exposées ; **jamais considérés comme une mesure d'isolation** | §4 : `public_id` de 128 bits, en plus des trois niveaux de contrôle. Les tests d'accès croisé utilisent des `public_id` **valides** d'une autre organisation : ils prouvent que l'isolation ne repose pas sur le secret de l'identifiant |
| **D8** | Désactivation immédiate, annulation possible pendant 7 jours après vérification d'identité, puis suppression des données actives ; traitement des sauvegardes et obligations de conservation documentés | §7.6 |
| **D9** | Vérification de l'email obligatoire avant toute fonctionnalité privée ; jetons temporaires à usage unique ; renvois limités | §3.2 |
| **D10** | Vérifier les privilèges dans hPanel et appliquer le moindre privilège ; sinon, documenter le risque résiduel | §6.4 ; action **A4** ; risque **R4** |
| **D11** | Préproduction : sous-domaine protégé, base et identifiants distincts, données fictives, pas d'indexation, pas d'emails réels, fichiers sensibles inaccessibles | §12.4 ; action **A8** |

---

## 14. Dépendances techniques

### 14.1 Prérequis de l'hébergement

| Prérequis | État au 10/11/2026 | Bloque |
|---|---|---|
| PHP ≥ 8.2 | ✅ 8.3.36 | — |
| `bcmath`, `intl`, `mbstring`, `pdo_mysql`, `openssl`, `curl` | ✅ actifs | — |
| `sodium` | ⏳ disponible, **désactivée** | Argon2id, chiffrement des champs, scellement des sauvegardes (A1) |
| Cron (ligne PHP toutes les 15 min, ligne shell quotidienne) | ✅ disponible, rien de configuré | Démo, instantanés, sauvegardes, alertes (A3) |
| SSH | ❓ à vérifier | Migrations, tests de restauration (sinon phpMyAdmin, ce qui est moins sûr et plus lent) |
| Version de MySQL ou MariaDB | ❓ à vérifier | Colonne générée UNIQUE (une simulation active par prestation), `CHECK`, JSON |
| Privilèges MySQL ajustables | ❓ à vérifier (A4) | Moindre privilège (sinon R4) |
| Prise en compte des en-têtes de `.htaccess` par LiteSpeed | ❓ test au déploiement | En-têtes des fichiers statiques. Les en-têtes des pages et de l'API sont envoyés par PHP de toute façon |
| `mysqldump` disponible pour le cron shell | ❓ à vérifier au premier déploiement | Sauvegardes N2 (alternative : export par PDO en ligne de commande, plus lent) |
| Connexion HTTPS sortante (`curl`) | ✅ extension présente ; ❓ pare-feu sortant à tester | Envoi externe N3 |
| Boîte email SMTP | ⏳ à créer (A5) | Vérification de l'email, réinitialisation, alertes |
| Sous-domaine de préproduction | ⏳ à créer (A8) | Scans dynamiques, tests de restauration |
| Centre de données dans l'UE | ❓ à vérifier | RGPD |

### 14.2 Dépendances logicielles

| Composant | Usage | Environnement | Contrôle |
|---|---|---|---|
| Chart.js (version figée) | graphiques | production (front) | empreinte SHA-384 en test ; revue trimestrielle des avis de sécurité |
| PHPMailer (version figée) | SMTP | production (back), hors `public_html` | `composer.lock`, `composer audit` bloquant |
| PHPUnit, PHPStan | tests, analyse statique | développement, CI | `composer audit` |
| gitleaks, OWASP ZAP | secrets, scan dynamique | CI, poste de test | — |
| **Accès en écriture au dépôt GitHub** | CI, revue, historique | — | ⚠️ **actuellement refusé** (push en erreur 403) : la CI ne peut pas être mise en place tant que l'accès n'est pas rétabli |

### 14.3 Checklist « passage aux données réelles » (condition pour lever `APP_REAL_DATA_ALLOWED`)

1. Hébergement isolé choisi et configuré : aucun autre site sur le même utilisateur système.
2. Toutes les vérifications de §14.1 refaites **sur ce nouvel environnement**.
3. Revue de sécurité complète (§12.2), avec scan dynamique authentifié et **aucun risque critique ou élevé ouvert**.
4. Stockage externe N3 opérationnel (le téléchargement manuel n'est plus admis) et **test de restauration réussi**, clés comprises.
5. DMARC durci (au minimum `p=quarantine`) et délivrabilité testée.
6. Documents RGPD publiés ; registre des traitements à jour ; sous-traitants identifiés.
7. Rotation de tous les secrets générés pendant la phase de portfolio.

---

## 15. Risques résiduels

Le registre détaillé sera tenu dans `docs/security/risk-register.md`. Les risques sont évalués **avec** les mesures de ce document.

| # | Risque | Vraisemblance | Impact | Niveau | Traitement |
|---|---|---|---|---|---|
| R1 | Un autre site du compte (WordPress) est compromis et lit les fichiers, les clés et la base d'Agency OS | moyenne | élevé avec des données réelles ; **faible avec des données fictives** | **Accepté pour le portfolio** (D1) | Données fictives uniquement ; mise à jour et réduction des sites WordPress du compte (recommandé) ; hébergement isolé obligatoire avant les données réelles |
| R2 | Compromission du serveur : le chiffrement au repos est contourné (clé sur le même système) | faible à moyenne | élevé | Accepté et **documenté** | Limite exposée explicitement (§7.5) ; sauvegardes scellées par clé publique (les sauvegardes **passées** restent protégées) ; détection et rotation (§10) |
| R3 | Les sauvegardes complètes Hostinger contiennent les clés **et** la base | faible | élevé | Accepté | Hors de notre contrôle ; Hostinger est sous-traitant ; à réévaluer avec le futur hébergement |
| R4 | Utilisateur MySQL avec tous les droits (si A4 ne permet pas de restreindre) : une injection SQL pourrait détruire des données | faible (requêtes préparées exclusivement, contrôles en CI) | élevé | Réduit, **résiduel à confirmer** après A4 | Pas de DDL dans le code web ; sauvegardes N2 et N3 immuables ; tests d'injection |
| R5 | Le CDN est activé ultérieurement par une modification du DNS : mise en cache ou IP masquée | faible | moyen | Réduit | `no-store` et `private` systématiques ; smoke test et alerte en cas de changement ; protocole D2 |
| R6 | Usurpation de l'adresse d'envoi tant que DMARC est en `p=none` (hameçonnage se faisant passer pour Agency OS) | moyenne | moyen | **À traiter** (A6) | Durcissement progressif de DMARC, avec rapports agrégés |
| R7 | Panne ou absence du cron : instantanés, purges et sauvegardes non réalisés | moyenne | moyen | Réduit | Battement et alerte (moins de 26 h), purge de secours de la démo, jours manquants signalés, contrôle des sauvegardes |
| R8 | Perte des clés de données ou de la clé privée de sauvegarde | faible | **très élevé** (données ou sauvegardes illisibles) | Réduit | Copies hors ligne, testées lors des restaurations mensuelles |
| R9 | Vulnérabilité dans PHPMailer, Chart.js ou PHP | moyenne dans le temps | variable | Réduit | Surface minimale ; `composer audit` bloquant ; revue trimestrielle ; mises à jour PHP |
| R10 | Dépôt GitHub inaccessible : pas de CI, donc pas de contrôles automatiques avant livraison | **actuelle** | moyen | **À traiter** | Rétablir l'accès ; en attendant, mêmes contrôles exécutés localement avant chaque livraison |
| R11 | Erreur d'autorisation non couverte (nouvelle route) | faible | élevé | Réduit | Les trois niveaux d'isolation ; un test de couverture des routes bloque toute route sans test d'isolation |
| R12 | Exfiltration lente par un utilisateur authentifié (son **propre** espace) | — | — | Hors périmètre | Il s'agit de ses propres données |

---

## 16. Actions d'infrastructure : état au 10/11/2026

Décision du 10/11/2026 : **MVP portfolio** (données fictives uniquement) d'un côté, **futur SaaS commercial** sur une autre infrastructure de l'autre. Aucune modification de la configuration globale de l'hébergement, ni des autres sites, bases ou emails.

| # | Action | Décision | État |
|---|---|---|---|
| A1 | Sodium | À faire maintenant, **uniquement sans impact sur les autres sites** | ⚠️ **Annulée après constat d'impact** (§17). Sodium est **désactivée**, comme à l'origine. Solutions possibles en §17.3, **en attente de ta décision** |
| A2 | Réglages PHP de sécurité | À faire maintenant, **limités à Agency OS** | ⚠️ **Annulée pour la même raison.** Remplacée par un durcissement **par le code** (§6.3), sans impact externe |
| A4 | Base MySQL dédiée | À faire maintenant | ✅ **Faite** : `u700770059_agencyos`, utilisateur `u700770059_agencyos`, rattachée à `agency-os.azzurra-pro.com`. Aucune autre base modifiée. Le mot de passe initial est **considéré comme exposé** (il figure dans l'historique de la session) : **il sera changé au premier déploiement**, et le nouveau sera écrit directement dans `config/.env` sur le serveur, sans transiter ailleurs. Privilèges constatés : tous les droits sur cette base uniquement (**R4**, accepté pour des données fictives) |
| A3 | Cron | À préparer dans le code, **sans programmation sur Hostinger** | 🔧 `bin/cron.php` et les tâches seront développés et testés (tests automatisés et exécution manuelle). Ils ne seront pas programmés dans hPanel. **Conséquence pour le portfolio** : la purge de la démo repose sur la **purge de secours** (à chaque création de démo et à chaque requête d'une démo expirée), et les instantanés quotidiens n'existent pas en ligne. Le graphique d'évolution de la démo utilise des instantanés **fictifs** générés à sa création, marqués « Démo » |
| A5 | Emails | À préparer dans le code, avec un mode test, **sans créer de boîte** | 🔧 `Mail\Mailer` avec les transports `smtp` (PHPMailer, non configuré), `capture` (par défaut sur le portfolio) et `null`. Aucune adresse n'est créée |
| A7 | Sauvegardes externes | Architecture compatible, **sans souscription** | 🔧 Interface `BackupStorage`, avec une implémentation locale (tests) et une implémentation S3 prête mais inactive |
| A6 | DMARC | Reporté au déploiement commercial | ⏸ (R6 reste ouvert, sans effet sur le portfolio, qui n'envoie aucun email) |
| A8 | Préproduction | Reporté au déploiement commercial | ⏸ Les scans dynamiques du portfolio se feront **localement** (environnement de développement) |
| A9 | CDN | Reporté, après analyse du futur hébergement | ⏸ Les en-têtes `no-store` et `private` restent en place |

---

## 17. Journal des interventions sur l'hébergement

| Date (UTC) | Intervention | Résultat |
|---|---|---|
| 10/11 04:36 | Création du sous-domaine `agency-os.azzurra-pro.com` (à ta demande) | ✅ |
| 10/11 | Lectures seules : PHP, SSL, CDN, bases, cron, DNS, en-têtes | — |
| 10/11 ≈05:40 | **A1 et A2** : activation de sodium et de 8 options de sécurité pour `agency-os.azzurra-pro.com` | ⚠️ **Impact constaté** : les mêmes réglages ont été appliqués aux autres sites **en PHP 8.3** (`azzurra-pro.com`, `apercu.azzurra-pro.com`, `amani-events.com`) |
| 10/11 ≈05:42 | **Retour immédiat aux valeurs d'origine** (sodium désactivée ; `expose_php` On, `allow_url_fopen` On, `log_errors` Off, cookies de session non sécurisés, `use_strict_mode` Off, `samesite` None, `display_errors` Off) | ✅ Les **9 sites** ont été relus : configuration identique à l'état initial. Exposition aux réglages modifiés : **environ 2 minutes**. Les réglages appliqués étaient des **durcissements**, sans effet destructeur sur les données ; un effet de bord passager ne peut être exclu pendant cette fenêtre (par exemple, une extension WordPress utilisant `file_get_contents` sur une URL). Les sites en 8.2 n'ont pas été touchés |
| 10/11 05:44 | **A4** : création de la base `u700770059_agencyos` | ✅ |

### 17.1 Cause

La documentation de l'API indiquait une modification « du site ». Une première comparaison entre deux sites avait montré des **options différentes**, mais c'était parce qu'ils utilisaient **des versions de PHP différentes** (8.3 et 8.2). La vérification aurait dû comparer deux sites **de même version** avant toute écriture.

### 17.2 Mesure corrective

Avant toute future modification de configuration, trois étapes seront suivies :
1. identifier tous les sites qui partagent la portée du réglage ;
2. relire leur configuration **avant** la modification ;
3. ne modifier que si la portée est limitée à Agency OS.

### 17.3 Options pour sodium (décision requise, rien n'est fait)

| Option | Description | Impact sur les autres sites | Recommandation |
|---|---|---|---|
| **S1** | Passer **uniquement** `agency-os.azzurra-pro.com` en **PHP 8.4** (aucun autre site n'utilise cette version), puis activer sodium **dans le groupe 8.4**. Les réglages de sécurité de §6.3 pourraient alors aussi être appliqués dans hPanel | Aucun, **à condition** qu'aucun autre site ne passe en 8.4 plus tard. Ce point sera noté dans la documentation d'exploitation | ✅ **Recommandée**, après vérification que 8.4 est bien isolée (relecture des 9 sites avant et après) |
| S2 | Ne pas utiliser sodium pour le portfolio : bcrypt (coût 12) pour les mots de passe, **AES-256-GCM via OpenSSL** (intégré) pour le chiffrement des champs, derrière la même interface `FieldCrypto`. Sodium serait réactivé sur l'hébergement commercial | Aucun | Acceptable, puisque les données sont fictives. Il faut alors maintenir deux implémentations testées de la même interface |
| S3 | Bibliothèque `sodium_compat` (PHP pur) | Aucun | ❌ Nouvelle dépendance, lente, et pas d'Argon2id |
