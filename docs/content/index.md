---
title: Rocket Doc Fusion
description: Fusionnez des modèles Word avec vos données, retouchez le résultat dans ONLYOFFICE et obtenez un PDF, depuis le navigateur ou depuis vos applications.
seo:
  title: Rocket Doc Fusion — Documentation
---

::u-page-hero
---
orientation: horizontal
title: Vos modèles Word, remplis par API.
---
#description
Rocket Doc Fusion remplit vos modèles **DOCX** avec des valeurs, grâce à **ONLYOFFICE Docs** : variables, lignes de tableau répétées, en-têtes et pieds de page. Le document fusionné se retouche dans l’éditeur ONLYOFFICE, garde ses versions et se télécharge en Word ou en **PDF**.

#links
  :::u-button
  ---
  to: /api/documents
  size: xl
  trailing-icon: i-lucide-arrow-right
  ---
  Fusionner depuis une application
  :::

  :::u-button
  ---
  to: /getting-started/introduction
  size: xl
  color: neutral
  variant: subtle
  icon: i-lucide-book-open
  ---
  Découvrir Rocket Doc Fusion
  :::

#default
  ```bash [Terminal]
  curl -X POST https://fusion.exemple.com/api/documents \
    -H "Authorization: Bearer rda_…" \
    -H "X-Impersonate-User: alice@exemple.com" \
    -H "Accept: application/json" \
    -F template=@devis.docx \
    -F 'values={"client_nom": "Société Martin", "lignes": [{"designation": "Audit", "quantite": "1", "prix": "750,00 €"}]}'
  # → 202 { "id": "…", "status": "merging", … }
  ```
::

::u-page-section
#title
Ce que vous pouvez faire

#features
  :::u-page-feature
  ---
  icon: i-lucide-braces
  to: /templates/write-template
  ---
  #title
  Des modèles Word simples

  #description
  Des variables `{{client_nom}}` et des lignes de tableau répétées `{{#lignes}}…{{/lignes}}`, écrites directement dans Word, même quand Word les découpe.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-file-pen-line
  to: /templates/merge-and-edit
  ---
  #title
  Retouche dans ONLYOFFICE

  #description
  Le document fusionné s’ouvre dans l’éditeur ONLYOFFICE, dans le navigateur. Chaque enregistrement crée une nouvelle version.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-file-type
  to: /api/documents#télécharger-en-word-ou-en-pdf
  ---
  #title
  Word ou PDF

  #description
  Téléchargez la dernière version en DOCX, ou en PDF converti par ONLYOFFICE et gardé en cache pour chaque version.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-code
  to: /api/documents
  ---
  #title
  API pour vos applications

  #description
  Vos applications, comme Rocket Dispatch, fusionnent au nom de leurs utilisateurs (impersonation) et suivent chaque document.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-square-dashed-mouse-pointer
  to: /embed/editor
  ---
  #title
  Éditeur embarqué

  #description
  Affichez l’éditeur d’un document dans votre application, dans une iframe, sans jamais exposer de secret.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-file-cog
  to: /administration/onlyoffice
  ---
  #title
  ONLYOFFICE administré

  #description
  Édition Community par défaut, licence Enterprise ou Developer installée depuis l’administration, serveur surveillé par le tableau de bord.
  :::
::
