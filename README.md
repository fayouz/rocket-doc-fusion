# Rocket Doc Fusion

Fusion de modèles Word avec vos données, depuis le navigateur ou depuis vos applications : variables et lignes de tableau répétées dans des modèles **DOCX**, fusion par **ONLYOFFICE Docs** (Document Builder), relecture et retouche dans l’éditeur ONLYOFFICE avec des versions, conversion en **PDF**. Brique du Middleware Rocket, sur la même stack que [Rocket Mailer](https://github.com/fayouz/rocket-mailer), [Rocket Auth](https://github.com/fayouz/rocket-auth), [Rocket Cloud](https://github.com/fayouz/rocket-cloud) et [Rocket Print](https://github.com/fayouz/rocket-print). Rocket Dispatch, l’application de publipostage, l’utilise par son API et son éditeur embarqué.

| Dossier | Stack |
|---|---|
| `backend/` | Symfony 8.1, API Platform 5, Doctrine ORM 3 (PostgreSQL), StofDoctrineExtensions, LexikJWT, Messenger et Scheduler, LDAP |
| `frontend/` | Nuxt 4, Nuxt UI 4 |
| `docs/` | Site de documentation (Nuxt UI + Nuxt Content), avec le changelog sur `/changelog` : `cd docs && npm install && npm run dev`, puis http://localhost:3001 |
| `compose.yaml` | PostgreSQL, API, worker, front et **ONLYOFFICE Docs** (`onlyoffice/documentserver:9.4.0.1`, édition Community par défaut) |

Le socle commun (comptes, LDAP, SSO, applications, tableau de bord, mises à jour, modes autonome et suite) vient de **[rocket-core](https://github.com/fayouz/rocket-core)** : le bundle Symfony `rocket/core-bundle` (Composer) et le layer Nuxt `@rocket/core` (npm). Pour travailler sur les deux à la fois : `ROCKET_CORE_LAYER=../../rocket-core/nuxt npm run dev` côté front, et un dépôt `path` Composer côté backend.

## Démarrage rapide

ONLYOFFICE Docs demande environ 5 Go de disque et 4 Go de mémoire. Choisissez d’abord le secret qu’il partage avec l’API (32 caractères minimum) :

```bash
echo 'ONLYOFFICE_JWT_SECRET=un-long-secret-aléatoire-de-32-caractères-ou-plus' >> .env
docker compose up -d --build
```

Au premier lancement, http://localhost:3400 affiche la **configuration initiale** : on y crée le compte administrateur. Si l’instance est exposée avant d’être configurée, définissez `SETUP_TOKEN`. L’administrateur peut aussi être créé en ligne de commande : `docker compose exec api php bin/console app:user:create admin@example.org 'un-mot-de-passe-long' --admin`.

ONLYOFFICE met environ une minute à démarrer ; le worker l’attend. Vérifiez-le dans **Administration → ONLYOFFICE**, puis essayez **Fusionner** avec le modèle d’exemple.

- Application : http://localhost:3400
- API + documentation OpenAPI : http://localhost:8400/api/docs
- ONLYOFFICE Docs (chargé par le navigateur pour l’éditeur) : http://localhost:8480

### Démo prête à tester

`docker compose -f compose.yaml -f compose.demo.yaml up -d --build` lance une démo complète : comptes locaux et LDAP, ONLYOFFICE, un document d’Alice fusionné depuis le modèle d’exemple et une application qui fusionne en son nom. Voir [demo/README.md](demo/README.md).

### Développement sans Docker

```bash
# ONLYOFFICE Docs reste dans Docker : il doit joindre l'API locale (host.docker.internal) avec le même secret
docker compose up -d onlyoffice

# backend (PHP 8.4, PostgreSQL)
cd backend && composer install
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:migrations:migrate
cat >> .env.local <<'ENV'
MESSENGER_TRANSPORT_DSN=sync://
ONLYOFFICE_JWT_SECRET=change-me-in-production-32-characters
ONLYOFFICE_CALLBACK_URL=http://host.docker.internal:8400
ENV
php -S 0.0.0.0:8400 -t public   # ou messenger:consume async scheduler_default à côté, sans sync://
php bin/phpunit                  # ONLYOFFICE simulé (HttpMock), inutile pour les tests

# frontend
cd frontend && npm install && npm run dev -- --port 3400   # NUXT_PUBLIC_API_BASE=http://localhost:8400
```

## Fonctionnalités

### Modèles
- **Variables** `{{client_nom}}` (lettres, chiffres, `_`), retrouvées même quand Word les découpe en plusieurs morceaux, y compris dans les en-têtes et pieds de page.
- **Lignes répétées** : une ligne de tableau qui commence par `{{#lignes}}` et finit par `{{/lignes}}` est répétée pour chaque élément de la liste `lignes`.
- **Analyse** (`POST /api/templates/inspect`) : variables, sections et leurs champs, erreurs (section non fermée, fin sans début, section hors d’une ligne de tableau).
- **Modèle d’exemple** : `devis-exemple.docx` (`GET /api/templates/sample`).

### Fusion, éditeur et PDF
- **Fusionner** : dépôt du modèle, formulaire construit à partir de ses variables, lignes des sections à remplir, nom du document. Le **worker** fusionne par ONLYOFFICE (script Document Builder généré pour chaque document).
- **Éditeur ONLYOFFICE** dans le navigateur (modes Modifier et Lire, **Enregistrer** pour forcer l’enregistrement) ; chaque enregistrement, reçu par le callback d’ONLYOFFICE, crée une **version**.
- **Mes documents** : statut, version, téléchargement Word ou **PDF** (converti par ONLYOFFICE, en cache pour chaque version), suppression. Les documents sont privés, administrateurs compris.
- Taille maximale d’un modèle ou d’un document : `DOC_FUSION_MAX_FILE_SIZE` (20 Mo).

### ONLYOFFICE Docs
Administration → **ONLYOFFICE** : version, édition (Community, Enterprise, Developer), adresses, état de la licence ; installation, remplacement ou retrait du fichier `license.lic`, appliqué après `docker compose restart onlyoffice`. Adresses : `ONLYOFFICE_PUBLIC_URL` (navigateur), `ONLYOFFICE_INTERNAL_URL` (API), `ONLYOFFICE_CALLBACK_URL` (l’API vue par ONLYOFFICE) ; secret partagé `ONLYOFFICE_JWT_SECRET`. Voir `docs/content/5.administration/2.onlyoffice.md`.

### API pour les applications
```bash
curl -X POST https://fusion.exemple.com/api/documents \
  -H "Authorization: Bearer rda_…" -H "X-Impersonate-User: alice@exemple.com" -H "Accept: application/json" \
  -F template=@devis.docx -F 'values={"client_nom": "Société Martin", "lignes": [{"designation": "Audit", "quantite": "1", "prix": "750,00 €"}]}'
```
`202` avec le document `merging`, puis `ready` ou `failed`. `GET /api/documents[/{id}]`, `DELETE /api/documents/{id}`, `GET /api/documents/{id}/content[?format=pdf]`, `PUT /api/documents/{id}/content` (nouvelle version), `GET /api/documents/{id}/editor`, `POST /api/documents/{id}/force-save`, `POST /api/templates/inspect`, `GET /api/templates/sample`, et pour les administrateurs `/api/admin/onlyoffice` (état, licence). Voir `docs/content/4.api/2.documents.md`.

### Éditeur embarqué
`/embed/editor?app=<application>&document=<id>&mode=edit|view` : l’éditeur d’un document dans une iframe de votre application, avec un jeton d’embed (`POST /api/embed/token`) transmis par `postMessage`, et les messages `document`, `editor-ready`, `saved` et `save`. Voir `docs/content/3.embed/1.editor.md`.

### Socle commun Rocket (rocket-core)
- **Comptes** locaux, **LDAP** (synchronisation, rôle admin par groupe) et **SSO OpenID Connect** (Rocket Auth ou tout fournisseur).
- **Applications externes** : jeton `rda_…` (seul son hash est stocké) et impersonation par `X-Impersonate-User`, jamais avec le rôle administrateur ; en mode suite, jetons Rocket Auth (client credentials, client `rocket-doc-fusion`).
- **Tableau de bord** : fusions sur 30 jours, documents retouchés, échecs, derniers documents, état des services (base, tâches de fond, LDAP, SSO, ONLYOFFICE Docs, stockage).
- **Version et mises à jour** : Docker (Watchtower, profil `updater`), serveur sans Docker (`deploy/update.sh`) ou manuelle.
- **Traçabilité** : toutes les entités sont Timestampable et Blameable.

## CI/CD

`.github/workflows/ci.yml` :
- à chaque push et pull request : lint du container, validation du schéma Doctrine, PHPUnit, puis ESLint, typecheck et build du front et de la documentation ; la démo complète est lancée avec ONLYOFFICE, et on y fusionne, convertit en PDF et ouvre l’éditeur (`.github/demo-scenarios.sh`) ;
- sur `main`, `develop` et les tags `v*` : images `ghcr.io/fayouz/rocket-doc-fusion-api` et `ghcr.io/fayouz/rocket-doc-fusion-front`.

Le worker utilise l’image API avec `php bin/console messenger:consume async scheduler_default`.

## Gitflow

- `main` : production (images `latest` et tags `vX.Y.Z`) ; `develop` : intégration.
- `feature/*` : pull request vers `develop`, qui complète la section `[Non publié]` de [CHANGELOG.md](CHANGELOG.md), publiée sur `/changelog` dans la documentation.
- `release/*` et `hotfix/*` vers `main` : `[Non publié]` devient `[X.Y.Z] - date`, puis tag `vX.Y.Z`.
