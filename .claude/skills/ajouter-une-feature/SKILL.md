---
name: ajouter-une-feature
description: Workflow complet pour ajouter une fonctionnalité full-stack à Toollab, de la migration à l'écran Nuxt — ordre des étapes, skills à enchaîner, décisions structurantes à trancher au début (year-scopé ? quel rôle ? nouvelle table ?) et points de contrôle. À invoquer au démarrage d'une feature qui traverse l'API et le front.
---

# Ajouter une fonctionnalité de bout en bout

## 0. Trancher AVANT de coder

| Question | Impact |
|---|---|
| La donnée appartient-elle à une **école** ? | trait `BelongsToSchool` + colonne `school_id` |
| Est-elle **remise à zéro chaque année** ? | trait `BelongsToSchoolYear` + route sous middleware `schoolyear` |
| Qui a le droit ? | `checkrole:` — **le registar doit-il passer ?** (inscriptions/familles/paiements → oui ; pilotage → non) |
| Faut-il une **nouvelle table** ? | migration + modèle (skill `laravel-model-migration`) ou champ sur une table existante |
| Est-ce **modifiable par un utilisateur** ? | `TrackChangesTrait` + `created_by`/`updated_by` |
| Y a-t-il un **traitement long** ? | job asynchrone (skill `queues-jobs-notifications`) |
| Est-ce **affiché aux professeurs** ? | route front à ajouter à `teacherAllowed` |
| Cela touche-t-il à **l'argent** ? | skill `tarification-paiements` — snapshot, garde-fou, encaissé vs exonéré |

Si l'une de ces réponses est ambiguë et changerait significativement le travail : **demander avant**, pas après.

## 1. Backend

### 1.1 Schéma (si nécessaire) → skill `laravel-model-migration`
```bash
docker exec api_dev_toollab php artisan make:migration create_machins_table
docker exec api_dev_toollab php artisan migrate --force
docker exec api_dev_toollab php artisan migrate:rollback --step=1 && … migrate --force   # tester le down()
```
Puis mettre à jour la skill `db-schema`.

### 1.2 Modèle
Traits, `$fillable` **sans** les champs techniques, `$casts`, relations. Voir `laravel-model-migration`.

### 1.3 Logique métier
Dans `app/Services/` dès que ça dépasse quelques lignes ou que c'est réutilisable. Le contrôleur valide, autorise, orchestre, formate.

### 1.4 Contrôleur + route → skill `api-endpoint`
- Placement dans le bon groupe de middleware (c'est **la** décision de sécurité).
- Format `{status, message, data}`.
- Gate d'appartenance (réutiliser `callerCanAccessFamily`, `canManageUser`, `guardClassroom`…).
- Validation avec contrainte `school_id` sur les `Rule::exists`.
- Transaction si multi-tables, eager loading contre le N+1.

### 1.5 Vérifier par curl → skill `debug-api`
```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/login -H "Content-Type: application/json" \
  -d '{"email":"relhanti@gmail.com","password":"password"}' | jq -r .token)
curl -s -H "Authorization: Bearer $TOKEN" -H "X-School-Id: 1" http://localhost:8000/api/machins | jq
```
Tester **aussi** les cas d'échec : sans header (400), avec un id d'une autre école (403/404), sur une année archivée (409), avec un rôle insuffisant (403).

## 2. Front

### 2.1 Service → skill `front-services-api`
`services/machin.js`, `export default {}`, `apiClient`, `try/catch` + `throw`.

### 2.2 Page ou écran → skill `nuxt-page`
`definePageMeta` (layout + middleware), `usePageTitle`, `PageContainer` + `BreadCrumb :custom-items`, états loading/erreur/vide, `isReadOnly` sur les actions, gating par rôle des boutons sensibles.

### 2.3 Composants → skills `ui-components`, `modals`, `datatable-pagination`, `formulaires-validation`
**Réutiliser avant de créer.** Liste paginée ⇒ `DataTable` + `useTablePerPage` + `@per-page-change` câblé. Modale ⇒ gabarit universel.

### 2.4 Style → skill `design-system`
Couleurs custom uniquement, Montserrat/Nunito, `bg-white rounded-2xl border`, boutons `text-xs`/`text-sm`, chips tint+ring.

### 2.5 Vérifier visuellement → skill `verification-visuelle`
Playwright, capture, relecture de l'image, script supprimé avec `;` (pas `&&`).

## 3. Livraison → skill `workflow-livraison`

Les 5 questions (fonctionnel / régression / sécurité / complexité / performance), puis restitution explicite de ce qui a été vérifié **et de ce qui ne l'a pas été**.

## 4. Ordre recommandé

```
1. décisions structurantes (§0)
2. migration + modèle          → migrate, puis rollback/migrate pour valider le down()
3. service + contrôleur + route
4. test curl (succès ET échecs)
5. service front
6. page/écran
7. vérification visuelle
8. checklist de livraison
9. mise à jour des skills impactées (db-schema, catalogues, bugs-connus)
```

**Ne pas** écrire le front avant que l'API réponde correctement : la moitié des allers-retours vient d'un contrat de réponse mal aligné.

## 5. Pièges récurrents sur ce projet

| Piège | Parade |
|---|---|
| Job/commande qui « ne trouve rien » | réinjecter `current_school_id` / `current_school_year_id` (+ `finally`) |
| Champ de formulaire perdu | l'ajouter dans la **whitelist** du service (`createClass` **et** `updateClass`) |
| Liste vide alors que les données existent | mauvaise année, ou global scope sans contexte |
| Prof rebouclé vers `/professeur/classes` | route absente de `teacherAllowed` |
| Sélecteur « par page » inerte | `@per-page-change` non câblé |
| Select clippé dans une modale | `min-h` sur le corps, ou prop `drop-up` |
| Bouton d'export visible pour un registar | gating `hasAnyRole(readActiveSchoolRoles(), ['director','admin'])` |
| Modale aux libellés par défaut | props `confirm-button-text` / `cancel-button-text` |
| Fil d'Ariane cassé | prop `:custom-items` (pas `:items`) |
| Écriture refusée en 409 | l'année consultée est archivée |

---

**Voir aussi** : `workflow-livraison` · `recherche-codebase` · `api-endpoint` · `nuxt-page`
