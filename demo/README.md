# Environnement de démo

## Dans GitHub Codespaces (rien à installer)

1. Sur GitHub, ouvre le dépôt, choisis la branche qui contient la démo, puis **Code → Codespaces → Create codespace on …**. Il faut une machine de **4 cœurs et 16 Go** : ONLYOFFICE Docs est gourmand.
2. Attends la fin de la commande de démarrage dans le terminal (5 à 10 minutes au premier lancement, le temps de construire les images et de démarrer ONLYOFFICE). Elle affiche les URLs de la démo.
3. Dans l’onglet **Ports**, ouvre « Rocket Doc Fusion » (3400) ou « Documentation et changelog » (3401).

L’éditeur est chargé par ton navigateur depuis ONLYOFFICE (port 8480, « ONLYOFFICE Docs (éditeur) ») : la commande de démarrage rend ce port public et donne son adresse à l’API (`ONLYOFFICE_PUBLIC_URL`). Si l’éditeur reste blanc, vérifie dans l’onglet **Ports** que le port 8480 est public, puis relance `bash demo/codespaces/start.sh`.

> ⚠️ Les mots de passe de démo sont publics. Arrête le codespace quand tu as fini (menu Codespaces → *Stop codespace*).

Pour relancer la démo à la main : `bash demo/codespaces/start.sh`.

## En local

Pré-requis : Docker avec Compose v2.24 ou plus récent, et environ 5 Go de disque et 4 Go de mémoire libres pour ONLYOFFICE.

```bash
docker compose -f compose.yaml -f compose.demo.yaml up -d --build
```

Le service `demo-seed` prépare la base, charge les données de démo et synchronise l’annuaire LDAP, puis s’arrête : `docker compose -f compose.yaml -f compose.demo.yaml logs -f demo-seed`.

| Adresse | Contenu |
|---|---|
| http://localhost:3400 | Rocket Doc Fusion |
| http://localhost:3401 | Documentation, et le changelog sur `/changelog` |
| http://localhost:8400/api/docs | Documentation de l’API |
| http://localhost:8480 | ONLYOFFICE Docs (chargé par le navigateur pour l’éditeur) |

Au premier démarrage, ONLYOFFICE met environ une minute à répondre. Ensuite, le worker fusionne le document de démo d’Alice, « Devis Société Martin », depuis le modèle d’exemple.

## Comptes

| Compte | Mot de passe | Type |
|---|---|---|
| `admin@example.org` | `demo-admin-password` | local, administrateur |
| `alice@example.org` | `demo-alice-password` | local |
| `marie.martin@example.org` | `password` | LDAP, administratrice via le groupe `rocket-admins` |
| `jean.dupont@example.org` | `password` | LDAP |

L’application « Application de démo » a le jeton `rda_demo_rocket_doc_fusion_do_not_use_in_production` et peut agir au nom des utilisateurs.

## Scénarios à tester

1. **Fusionner.** Connecte-toi avec `alice@example.org`, ouvre *Fusionner* et clique sur *Télécharger un modèle d’exemple*. Dépose le fichier `devis-exemple.docx` : ses variables deviennent un formulaire, et la section `lignes` des lignes à remplir. Remplis-les, puis *Fusionner* : l’éditeur ONLYOFFICE s’ouvre sur le document fusionné.
2. **Retoucher.** Modifie le texte dans l’éditeur, puis clique sur *Enregistrer*. Quelques secondes plus tard, le document est en version 2 dans *Mes documents*.
3. **PDF.** Sur la page du document, *Télécharger → PDF* : ONLYOFFICE convertit la dernière version. Le document « Devis Société Martin » se télécharge de la même façon depuis *Mes documents*.
4. **Administration.** Avec `admin@example.org`, ouvre *Administration → ONLYOFFICE* : le serveur répond, en édition Community 9.4, sans licence. Le tableau de bord montre « ONLYOFFICE Docs » dans l’état des services.
5. **Application et impersonation.** Une application fusionne au nom d’Alice, avec le modèle d’exemple :
   ```bash
   curl -X POST http://localhost:3400/api/documents \
     -H "Authorization: Bearer rda_demo_rocket_doc_fusion_do_not_use_in_production" \
     -H "X-Impersonate-User: alice@example.org" -H "Accept: application/json" \
     -F template=@devis-exemple.docx -F title='Devis Dupont' \
     -F 'values={"client_nom": "Dupont SARL", "lignes": [{"designation": "Maintenance", "quantite": "1", "prix": "300,00 €"}]}'
   ```
   La réponse (`202`) est le document, en cours de fusion. Il apparaît dans *Mes documents* d’Alice, avec le nom de l’application. En impersonnant `admin@example.org`, l’application n’obtient pas pour autant les droits administrateur.
6. **Connexion LDAP.** Connecte-toi avec `marie.martin@example.org` / `password` : elle est administratrice grâce à son groupe LDAP.

## Réinitialiser

```bash
docker compose -f compose.yaml -f compose.demo.yaml down -v
```
