# Agency OS — Déploiement du portfolio (phase 5)

> Mise en ligne : **11/11/2026**, https://agency-os.azzurra-pro.com (profil `portfolio`, environnement `production`).
> Aucune modification de la configuration globale de l'hébergement, du CDN, des extensions PHP ni des autres sites.

## 1. Architecture sur Hostinger (hébergement mutualisé)

La racine web du sous-domaine (`domains/agency-os.azzurra-pro.com/public_html`) ne peut pas pointer vers le dossier
`public_html/` du dépôt, et l'API de fichiers n'écrit que sous cette racine. Le dépôt y est donc déposé tel quel :

```
<racine web>/
├── .htaccess          ← deploy/root.htaccess : TOUTE requête est réécrite vers public_html/
├── public_html/       ← seul contenu servi (index.php + assets/)
├── app/ bin/ database/ config/ storage/   ← jamais servis (réécriture + « Require all denied » dans chacun)
```

- `DirectorySlash Off` : sans cela, LiteSpeed redirige `/app` vers `/app/` (dossier du code) avant le routage.
- Dans `public_html/.htaccess`, les assets sont reconnus par un motif **relatif au répertoire** (`^assets/`), fiable
  derrière la réécriture de la racine (une condition sur `%{REQUEST_URI}` échouait sous LiteSpeed).
- Pas de `vendor/` : l'autoloader de secours de `app/bootstrap.php` suffit (PHPMailer ne sert qu'au transport SMTP,
  interdit en profil portfolio).

## 2. Secrets (jamais dans Git)

| Élément | Emplacement | Remarque |
|---|---|---|
| `config/.env` | serveur uniquement | `APP_KEY` aléatoire (256 bits), mot de passe de la base, `MAIL_TRANSPORT=capture`, `LEGAL_*` |
| Clé de données `config/keys/data.k1` et `keyring.json` | serveur uniquement | **à sauvegarder hors ligne** : sans elle, les données chiffrées sont irrécupérables |
| Mot de passe de la base | `config/.env` | renouvelé au déploiement (l'ancien avait circulé dans une conversation) |

## 3. Procédure suivie (reproductible)

1. Paquet : `git archive` sans `prototype/`, `tests/`, `docs/`, `.github/`, `tools/`, fichiers de configuration des outils.
2. Secrets générés localement : `APP_KEY`, clé de données (`bin/generate-key.php --data-key`), mot de passe de la base
   (32 caractères), jeton de déploiement (256 bits).
3. Mot de passe de la base renouvelé par l'API Hostinger (`hosting_databases_change-password`).
4. Envoi des fichiers par l'API de fichiers (TUS), fichier par fichier ; `.htaccess` de la racine en dernier.
5. **Migrations sans accès SSH ni MySQL distant** : `deploy/once-migrate.php.dist` est copié à la racine sous un nom
   aléatoire, avec l'empreinte SHA-256 d'un jeton de 256 bits. Il ne répond qu'en POST avec le jeton (404 sinon), vérifie
   les exigences (PHP ≥ 8.2, bcmath, pdo_mysql, openssl AES-256-GCM, mbstring, droits d'écriture), applique les
   migrations en attente avec contrôle des sommes, **puis se supprime**. Une exception temporaire dans le `.htaccess` de la
   racine l'autorise ; elle est retirée juste après. Le migrateur du dépôt reste réservé à la ligne de commande.
6. Suppression de la page par défaut de Hostinger (`default.php`).
7. Vérifications (§4).

Pour une mise à jour : envoyer les fichiers modifiés ; si une migration est ajoutée, répéter l'étape 5.

## 4. Vérifications en production (11/11/2026)

| Contrôle | Résultat |
|---|---|
| Exigences serveur | PHP 8.3.36 (LiteSpeed), bcmath, pdo_mysql, openssl (AES-256-GCM), mbstring : OK ; dossiers `storage/` inscriptibles |
| Migrations | 0001 à 0008 appliquées, 32 tables ; script de déploiement supprimé (404 ensuite) |
| HTTPS | HTTP → HTTPS (301) ; HSTS `max-age=31536000; includeSubDomains` |
| En-têtes | CSP stricte (Trusted Types), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, COOP/CORP, `Permissions-Policy`, `Cache-Control: no-store` sur les pages |
| Cookie de session | `__Host-aos_sid`, `Secure`, `HttpOnly`, `SameSite=Lax`, posé seulement quand c'est nécessaire (aucun cookie sur les pages publiques) |
| Fichiers sensibles | `config/.env`, clés, `app/`, `bin/`, `database/`, `storage/`, `.htaccess`, `vendor/`, traversées `..` : 403 ou 404, aucun contenu servi |
| Parcours | démo, vue d'ensemble, diagnostic (radar), pricing (aperçu en direct), récurrent, stratégie (acceptation), plan, paramètres, sortie de la démo ; mobile sans défilement horizontal |
| Limitation | 5 démonstrations par IP et par heure : appliquée en production |

**Écarts corrigés pendant le déploiement** (avec test de non-régression) :
- `KEYS_PATH=` vide (documenté « vide = config/keys ») donnait un chemin vide : trousseau introuvable.
- Comparaisons SQL paramètre / littéral texte (`IF(:statut = 'new', …)`) : collations différentes sur le serveur
  (« Illegal mix of collations »). Les comparaisons sont désormais faites en PHP.
- Assets en 404 et redirection `/app` → `/app/` sous LiteSpeed (voir §1).

**Remarque** : l'hébergement ajoute un second en-tête `Content-Security-Policy: upgrade-insecure-requests` ; les deux
politiques se cumulent (la plus stricte s'applique), sans effet sur l'application.

## 5. Ce qui reste volontairement désactivé (décisions A3, A5, A7)

- **Tâche planifiée (cron)** non programmée sur Hostinger : la purge des démos expirées s'exécute aussi à chaque création
  de démo ; les instantanés quotidiens, la maintenance (purge des comptes à J+7) et la réévaluation quotidienne ne
  tournent pas. Pour les activer : une seule ligne cron toutes les 15 minutes, `php <racine>/bin/cron.php`.
- **Emails** : `MAIL_TRANSPORT=capture` (aucun envoi ; les messages sont écrits dans `storage/mail-capture/`).
- **Sauvegardes externes** : code prêt, non configuré.

## 6. À faire par l'éditrice

1. Renseigner `LEGAL_PUBLISHER` et `LEGAL_CONTACT_EMAIL` (et selon le statut `LEGAL_ADDRESS`, `LEGAL_REGISTRATION`) dans
   `config/.env` via le gestionnaire de fichiers de hPanel ; les pages légales affichent « à compléter » d'ici là.
2. Télécharger et conserver **hors ligne** `config/keys/data.k1` et `config/keys/keyring.json`.
3. Confirmer la durée de conservation des sauvegardes Hostinger (citée dans la politique de confidentialité).

## 7. Journal des mises à jour

| Date | Contenu | Fichiers | Migration |
|---|---|---|---|
| 12/11/2026 | Glossaire (`/glossaire`, `/app/glossaire`), bulles d'info pédagogiques, débordements mobiles corrigés | 23 fichiers (`app/`, `public_html/assets/`), contenu en ligne vérifié identique à `d0519a8` avant envoi | aucune |
