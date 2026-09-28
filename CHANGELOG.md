# Changelog

Toutes les évolutions notables de Rocket Doc Fusion. Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

Première version de Rocket Doc Fusion, la brique de fusion documentaire du Middleware Rocket.

### Ajouté

- **Fusion par ONLYOFFICE Docs** : le worker fusionne chaque document avec un script Document Builder généré pour lui ; ONLYOFFICE télécharge le modèle et le script par des adresses signées et limitées dans le temps. Service `onlyoffice` dans `compose.yaml` (`onlyoffice/documentserver:9.4.0.1`, édition Community par défaut, JWT activé), que le worker attend avant de démarrer.
- **Modèles DOCX** : variables `{{nom}}`, retrouvées même quand Word les découpe en plusieurs morceaux, y compris dans les en-têtes et pieds de page ; **lignes de tableau répétées** `{{#lignes}}…{{/lignes}}`, une ligne par élément ; valeurs absentes remplacées par un texte vide. Analyse des modèles (`POST /api/templates/inspect`) avec les erreurs : section non fermée, fin sans début, section hors d’une ligne de tableau. Modèle d’exemple `devis-exemple.docx` (`GET /api/templates/sample`).
- **Fusionner** : dépôt du modèle, formulaire construit à partir de ses variables, lignes des sections à remplir, nom du document ; **Mes documents** : statut en direct, version, téléchargement Word ou PDF, suppression. Les documents sont privés, administrateurs compris.
- **Éditeur ONLYOFFICE** : page du document avec l’éditeur (modes Modifier et Lire), **Enregistrer** (enregistrement forcé) et téléchargement. Chaque enregistrement, reçu par le callback signé d’ONLYOFFICE, crée une **version** ; remplacer le contenu par l’API en crée une aussi et relance les éditeurs ouverts.
- **Conversion en PDF** par ONLYOFFICE (`GET /api/documents/{id}/content?format=pdf`), gardée en cache pour chaque version.
- **Administration → ONLYOFFICE** : version, édition (Community, Enterprise, Developer), adresses publique et interne, état de la licence (client, dates, connexions, utilisateurs) ; installation, remplacement et retrait du fichier `license.lic`, appliqué au redémarrage d’ONLYOFFICE.
- **Tableau de bord** : fusions sur 30 jours, documents retouchés dans l’éditeur, échecs, derniers documents, fusions en échec dans l’activité. ONLYOFFICE Docs est vérifié toutes les 5 minutes (contrôle de santé et version) et apparaît dans l’état des services.
- **Éditeur embarqué** : `/embed/editor?app=…&document=…&mode=edit|view`, dans une iframe d’une application autorisée, avec un jeton d’embed transmis par `postMessage` ; messages `document`, `editor-ready`, `saved` et `save`.
- **API** : `POST /api/documents` (multipart : modèle, valeurs JSON, nom ; `202`), `GET /api/documents` (filtres, tri, pagination), `GET` et `DELETE /api/documents/{id}`, `GET` et `PUT /api/documents/{id}/content`, `GET /api/documents/{id}/editor`, `POST /api/documents/{id}/force-save`, `GET /api/documents/settings`, et `/api/admin/onlyoffice` pour les administrateurs. Les applications fusionnent au nom d’un utilisateur (`rda_…` et `X-Impersonate-User`, ou jeton Rocket Auth en mode suite).
- **Démo** : ONLYOFFICE, un document d’Alice fusionné depuis le modèle d’exemple, une application de démo qui fusionne en son nom ; Codespaces (4 cœurs, 16 Go) avec le port de l’éditeur. La CI fusionne, convertit en PDF et ouvre l’éditeur sur la démo.
- **Socle commun Rocket**, partagé avec Rocket Mailer, Rocket Auth, Rocket Cloud et Rocket Print : configuration initiale, comptes locaux, LDAP et SSO (OpenID Connect, Rocket Auth), serveurs d’authentification, applications externes et impersonation, tableau de bord extensible, sondes de santé, version et mises à jour (Docker, serveur sans Docker, manuelle), modes autonome et suite, déconnexion depuis Rocket Auth (back-channel logout).
