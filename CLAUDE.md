# Rocket Doc Fusion

Brique du Middleware Rocket. Socle commun : [rocket-core](https://github.com/fayouz/rocket-core) (bundle Symfony `rocket/core-bundle` + layer Nuxt `@rocket/core`), à lire avant de modifier les comptes, le SSO, les applications, le tableau de bord ou la mise en page : ce code n’est pas ici.

## Repères
- `app_id` `doc-fusion`, jetons d’application `rda_…`, client suite `rocket-doc-fusion`, ports front 3400 · api 8400 · docs 3401 · ONLYOFFICE 8480.
- Domaine : fusion de modèles DOCX par ONLYOFFICE Docs (service `onlyoffice` de `compose.yaml`).
  - `src/Fusion` : `TemplateInspector` (variables, sections en ligne de tableau, erreurs), `MergeScript` (script Document Builder : les lignes `builder.*` sont des commandes aux arguments littéraux, pas du JavaScript, d’où l’adresse du modèle écrite dans le script généré ; les valeurs passent par `Argument`), `DocumentMerger` (création, fusion par le worker via `MergeDocument`, versions), `DocumentConverter` (PDF), `DocumentStorage` (versions `vN.docx` / `vN.pdf` sous `DATA_DIR/documents/<clé>/`), `SampleTemplate`.
  - `src/OnlyOffice` : `OnlyOfficeClient` (commandes, docbuilder, conversion), `OnlyOfficeJwt` (HS256, `ONLYOFFICE_JWT_SECRET`), `OnlyOfficeUrls` (adresses signées par `APP_SECRET` et limitées dans le temps ; `internalize()` réécrit les adresses de résultat d’ONLYOFFICE vers `ONLYOFFICE_INTERNAL_URL`), `EditorConfig` (configuration signée de `DocsAPI.DocEditor`), `OnlyOfficeLicense` (`ONLYOFFICE_LICENSE_PATH`), `OnlyOfficeProbe` (état des services).
  - Contrôleurs : `DocumentController` (`/api/documents…`, `/api/templates…`), `OnlyOfficeController` (`/api/onlyoffice/…` : modèle, `merge.js`, versions, callback ; publics dans `security.yaml`, protégés par la signature), `OnlyOfficeAdminController` (`/api/admin/onlyoffice`, licence).
  - Clé d’éditeur (`Document::$editorKey`) : ne change qu’après un callback `status` 2 (tous les éditeurs fermés, nouvelle version) ou un remplacement du contenu ; le `status` 6 (enregistrement forcé) crée une version sans changer de session.
  - Documents privés : `OwnedDocumentsExtension` (liste) et `denyUnlessOwner` (`404`), administrateurs compris. Éditeur embarqué : `EditorEmbedEndpoints`, page `frontend/app/pages/embed/editor.vue`.
- Stack : Symfony 8.1 + API Platform + Doctrine/PostgreSQL + LexikJWT + Messenger/Scheduler (`backend/`), Nuxt 4 + Nuxt UI 4 qui étend le layer (`frontend/`), Nuxt Content (`docs/`), Docker Compose (`compose.yaml` + `compose.demo.yaml`).
- Points d’extension du socle (interfaces) : `DashboardSectionInterface`, `ServiceProbeInterface`, `DemoSeederInterface`, `RecurringTaskProviderInterface`, `EmbedEndpointsInterface` ; côté front `rocket.extensions` dans `app.config.ts`.
- Mode suite : `ROCKET_AUTH_URL` non vide (voir le README de rocket-core).

## Vérifier avant de pousser
```bash
cd backend && php bin/console lint:container && php bin/console doctrine:schema:validate && php bin/phpunit
cd frontend && npm run lint && npm run typecheck
cd docs && npm run lint && npm run typecheck && npm run generate   # si docs/ a changé
```
CI : `.github/workflows/ci.yml` appelle les workflows réutilisables de rocket-core (`@vX.Y.Z`) ; scénarios de démo dans `.github/demo-scenarios.sh` (fusion, PDF et éditeur sur un vrai ONLYOFFICE).

## Pièges connus
- API Platform répond en JSON-LD par défaut : envoyer `Accept: application/json` pour obtenir un tableau (scripts, curl, jq).
- Local sans `ext-ldap` : `composer install --ignore-platform-req=ext-ldap`. Composer installe depuis les sources git : `vendor/*/*/.git` pèse plusieurs Go, à supprimer si le disque manque.
- Base de test partagée entre dépôts en local (`app_test`) : la recréer et migrer si des colonnes manquent.
- rocket-core suit semver (`^0.x`) ; Renovate ouvre les mises à jour (correctifs fusionnés seuls si la CI passe). Nouvelles migrations du socle : idempotentes (`write()` + `return`), jamais `skipIf`.
- Ne pas copier une page du layer pour l’étendre : utiliser `rocket.extensions`.
- ONLYOFFICE construit les adresses de ses résultats (document fusionné, PDF, callback `url`) avec l’hôte par lequel on l’a joint : toujours les passer par `OnlyOfficeUrls::internalize()` avant de les télécharger.
- Tests : pas de vrai ONLYOFFICE, un faux par `HttpMock` avec les `ONLYOFFICE_*` de `.env.test`. Le vrai n’est exercé que par la démo en CI.
- Codespaces : l’image universelle par défaut bloque le trafic entre conteneurs des réseaux Docker définis par l’utilisateur (iptables-legacy) : ONLYOFFICE ne joint plus l’API. Garder `base:ubuntu` + `docker-in-docker` dans `.devcontainer`.
- ONLYOFFICE : ~5 Go d’image, 4 Go de mémoire, une minute au premier démarrage ; la licence (`license.lic` dans le volume `onlyoffice_data`) ne s’applique qu’après `docker compose restart onlyoffice`.
- Notes du spike ONLYOFFICE (Document Builder, callback, conversions) : `spike/README.md` sur la branche `spike/onlyoffice`.
