# Essai : ONLYOFFICE Docs comme moteur de Rocket Doc Fusion

Essai du 26/09/2026 dans un Codespace (4 cœurs, 16 Go), avec `onlyoffice/documentserver:latest` : **édition Community 9.4.0**, sans licence, JWT activé (`JWT_SECRET`). Objectif : valider la fusion, l'édition et la conversion avant de construire la brique.

## Résultats

| Point | Résultat |
|---|---|
| Document Builder web (`POST /docbuilder`) dans l'édition Community | ✅ disponible, asynchrone (`key`, puis `end` et `urls`) |
| Ouvrir un modèle existant | ✅ `builder.OpenFile("<url>")`, **URL écrite en dur** dans le script (voir ci-dessous) |
| Variable `{{client_nom}}` coupée par Word en plusieurs morceaux | ✅ remplacée par `ApiDocument.SearchAndReplace` |
| Section répétée `{{#lignes}}…{{/lignes}}` dans une ligne de tableau | ✅ une ligne par élément (`AddRow`, puis suppression de la ligne modèle) |
| Sortie DOCX et PDF dans le même script | ✅ deux `builder.SaveFile` |
| Conversion DOCX → PDF (`POST /converter`) | ✅ pour Rocket Print |
| État de la licence (`POST /command`, `c: license`) | ✅ édition, dates, connexions, version (`packageType` 0 = Community) |
| Édition dans l'éditeur (`DocsAPI.DocEditor`), enregistrement par le rappel | ✅ `forcesave` → rappel `status: 6` avec `url` → le DOCX récupéré contient la modification |

Résultat de la fusion : [`resultat-fusion.pdf`](resultat-fusion.pdf).

## Ce qu'il faut savoir pour la brique

- **Les lignes `builder.*` ne sont pas du JavaScript** : le moteur les lit comme des commandes, avec des arguments littéraux. `builder.OpenFile(Argument.template)` plante (erreur `-3`, `ReferenceError: builder is not defined`) ; Doc Fusion doit **générer le script de chaque fusion** avec l'URL du modèle écrite en dur (URL signée, à usage unique, servie par Doc Fusion). Les valeurs passent par `argument` (global `Argument`, utilisable dans le code `Api.*`).
- **Mise en forme d'une variable coupée** : le remplacement reprend la mise en forme du premier morceau (dans l'essai, la partie en gras de `nom}}` a perdu son gras). À documenter : mettre en forme la variable entière.
- **Texte des cellules** : `GetText()` renvoie la tabulation et le retour de fin de paragraphe ; les retirer avant de remplir les lignes répétées (sinon les lignes sont plus hautes).
- **Adresses** : les URL renvoyées (résultats, rappel) utilisent l'adresse par laquelle le navigateur ou l'API a joint le serveur (`http://localhost:8480/...`). Côté serveur, Doc Fusion doit les réécrire vers l'adresse interne (`http://documentserver/...`), comme `cb/server.mjs`.
- **Réseau** : le serveur doit joindre Doc Fusion (modèles, scripts, documents à éditer, rappel) ; le navigateur doit joindre le serveur (`web-apps/apps/api/documents/api.js`). Adresses privées autorisées par `ALLOW_PRIVATE_IP_ADDRESS=true`.
- **Poids** : image de 4,86 Go ; démarrage en une minute environ. Pas sur un poste presque plein : Codespaces ou serveur.
- **Codespaces** : l'image par défaut (universal) garde une chaîne `FORWARD` d'`iptables-legacy` en `DROP` qui bloque le trafic entre conteneurs d'un réseau Docker créé à la main. La brique utilisera l'image `base:ubuntu` avec la feature `docker-in-docker`, comme les autres briques.
- **Licence** : sans fichier, édition Community. Le fichier `license.lic` se place dans `/var/www/onlyoffice/Data/license.lic` ; `c: license` permet d'afficher l'état dans l'administration.

## Rejouer l'essai

Dans un Codespace (ou toute machine avec Docker) :

```bash
docker network create spike
docker run -d --name ds --network spike -p 8480:80 -e JWT_ENABLED=true -e JWT_SECRET=spike-secret-at-least-32-characters-long -e ALLOW_PRIVATE_IP_ADDRESS=true onlyoffice/documentserver
docker run -d --name files --network spike -p 8081:80 -v "$PWD/www":/usr/share/nginx/html:ro nginx:alpine
docker run -d --name cb --network spike -v "$PWD/cb":/app:ro -v "$PWD/cb-out":/out node:22-alpine node /app/server.mjs
pip install python-docx playwright && python3 -m playwright install --with-deps chromium
python3 make_template.py        # www/template.docx
python3 call.py merge.js        # fusion : out-fusion.docx, out-fusion.pdf
cp out-fusion.docx www/fusion-copy.docx
python3 convert_license.py      # conversion PDF, état de la licence
python3 editor.py               # éditeur : saisie, forcesave, fichier reçu par le rappel dans cb-out/
```
