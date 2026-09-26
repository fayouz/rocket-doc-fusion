#!/usr/bin/env bash
# Demo scenarios of rocket-doc-fusion, checked by the CI (reusable workflow brick-demo.yml of rocket-core) once the demo stack
# (compose.yaml + compose.demo.yaml) is up. Run with "bash -e" from the repository root; environment:
# COMPOSE (docker compose -f compose.yaml -f compose.demo.yaml), FRONT, DOCS (demo front and docs URLs), APP_VERSION.
# Locally: COMPOSE="docker compose -f compose.yaml -f compose.demo.yaml" FRONT=http://localhost:3300 DOCS=http://localhost:3301 bash -e .github/demo-scenarios.sh
set -x
# Seeded accounts: the first-run setup is closed
curl -fsS $FRONT/api/setup | jq -e '.required == false'
# Local account (through the front's same-origin /api proxy, as in Codespaces)
ALICE=$(curl -fsS -X POST $FRONT/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"alice@example.org","password":"demo-alice-password"}' | jq -r .token)
# LDAP account, admin through its directory group
TOKEN=$(curl -fsS -X POST $FRONT/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"marie.martin@example.org","password":"password"}' | jq -r .token)
curl -fsS $FRONT/api/me -H "Authorization: Bearer $TOKEN" | jq -e '.roles | index("ROLE_ADMIN")'
# Version of the images, shown by the API and the interface; no one-click update without UPDATER_TOKEN
curl -fsS $FRONT/api/system/version -H "Authorization: Bearer $TOKEN" | jq -e '.version == "0.0.0-ci"'
curl -fsS $FRONT/api/system/update -H "Authorization: Bearer $TOKEN" | jq -e '.current.release == "0.0.0-ci" and .method == "manual" and .methods.docker.configured == false'
curl -fsS $FRONT/login | grep -q 'appVersion:"v0.0.0-ci"'
# LDAP settings (environment until saved): the connection test finds the directory's users
curl -fsS $FRONT/api/ldap/config -H "Authorization: Bearer $TOKEN" | jq -e '.source == "environment" and .enabled'
curl -fsS -X POST $FRONT/api/ldap/test -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{}' \
  | jq -e '.ok and .count >= 2'
# Documentation site, with the changelog
curl -fsS $DOCS/changelog | grep -q 'Non publié'
# Network health checks (also run by the worker's scheduler): the real OpenLDAP
curl -fsS -X POST $FRONT/api/health/check -H "Authorization: Bearer $TOKEN" > health.json
jq -e '[.services[] | select(.id == "ldap") | .status] == ["operational"]' health.json || { jq . health.json; exit 1; }
$COMPOSE exec -T api php bin/console app:health:check
# Dashboard: the whole platform and service details for admins
curl -fsS $FRONT/api/dashboard -H "Authorization: Bearer $TOKEN" \
  | jq -e '.scope == "platform" and (.daily | length) == 30 and ([.health.services[] | select(.id == "database" or .id == "ldap") | .status] == ["operational", "operational"])'
# An application acts as a user, never as an administrator
DEMO_TOKEN=$(grep -o 'rda_demo_[a-z_]*' compose.demo.yaml | head -1)
curl -fsS $FRONT/api/me -H "Authorization: Bearer $DEMO_TOKEN" -H 'X-Impersonate-User: admin@example.org' \
  | jq -e '.user.email == "admin@example.org" and (.roles | index("ROLE_ADMIN") | not)'
# Merge: Alice's demo document, merged by the worker through ONLYOFFICE (Document Builder)
for i in $(seq 1 60); do
  STATUS=$(curl -fsS "$FRONT/api/documents?title=Martin" -H "Authorization: Bearer $ALICE" | jq -r '.[0].status // "none"')
  [ "$STATUS" = ready ] && break; [ "$STATUS" = failed ] && break; sleep 2
done
DOC=$(curl -fsS "$FRONT/api/documents?title=Martin" -H "Authorization: Bearer $ALICE" | jq -r '.[0].id')
curl -fsS $FRONT/api/documents/$DOC -H "Authorization: Bearer $ALICE" | jq -e '.status == "ready" and .version == 1'
# The merged DOCX has the values, not the variables
curl -fsS "$FRONT/api/documents/$DOC/content" -H "Authorization: Bearer $ALICE" -o merged.docx
unzip -p merged.docx word/document.xml > merged.xml
grep -q 'Société Martin' merged.xml && grep -q 'Formation des équipes' merged.xml && ! grep -q '{{' merged.xml
# PDF conversion by ONLYOFFICE
curl -fsS "$FRONT/api/documents/$DOC/content?format=pdf" -H "Authorization: Bearer $ALICE" | head -c 4 | grep -q '%PDF'
# A new merge from an application, on behalf of Alice (template: the sample)
curl -fsS $FRONT/api/templates/sample -H "Authorization: Bearer $ALICE" -o sample.docx
curl -fsS $FRONT/api/templates/inspect -H "Authorization: Bearer $ALICE" -F file=@sample.docx | jq -e '(.variables | index("client_nom")) and .sections[0].name == "lignes"'
NEW=$(curl -fsS -X POST $FRONT/api/documents -H "Authorization: Bearer $DEMO_TOKEN" -H 'X-Impersonate-User: alice@example.org' -H 'Accept: application/json' \
  -F template=@sample.docx -F title='Devis CI' -F 'values={"client_nom":"Client CI","lignes":[{"designation":"Ligne CI","quantite":"3","prix":"9,00"}]}' | jq -r .id)
for i in $(seq 1 60); do
  STATUS=$(curl -fsS $FRONT/api/documents/$NEW -H "Authorization: Bearer $ALICE" | jq -r .status)
  [ "$STATUS" != merging ] && break; sleep 2
done
curl -fsS $FRONT/api/documents/$NEW -H "Authorization: Bearer $ALICE" | jq -e '.status == "ready" and .applicationName != null'
# The editor configuration, signed for ONLYOFFICE, and the version it downloads (signed address of the API)
curl -fsS "$FRONT/api/documents/$NEW/editor" -H "Authorization: Bearer $ALICE" | jq -e '.config.token and .config.editorConfig.mode == "edit"'
URL=$(curl -fsS "$FRONT/api/documents/$NEW/editor" -H "Authorization: Bearer $ALICE" | jq -r .config.document.url)
$COMPOSE exec -T onlyoffice curl -fsS "$URL" -o /dev/null
# ONLYOFFICE administration: Community edition, no license file
curl -fsS $FRONT/api/admin/onlyoffice -H "Authorization: Bearer $TOKEN" | jq -e '.reachable and .edition == "community" and .licenseFile.installed == false'
curl -fsS -X POST $FRONT/api/health/check -H "Authorization: Bearer $TOKEN" \
  | jq -e '[.services[] | select(.id == "onlyoffice") | .status] == ["operational"]'
