---
name: familles-eleves
description: Gestion des familles, responsables et élèves dans Toollab — modèle d'identité (User + UserRole polymorphe, UserInfo en clé-valeur), endpoints FamilyController, règle du gate callerCanAccessFamily, inscription en classe (pattern replace), et écrans de la fiche famille. À invoquer pour toute modification touchant les familles, les élèves, les responsables ou leurs inscriptions.
---

# Familles, responsables & élèves

## 1. Le modèle d'identité

```
Family (id, school_id)                     ← AUCUN champ d'identité propre
   └── user_roles (roleable = family)
         ├── role responsible  → User (email réel, contacts dans UserInfo)
         └── role student      → User (email SYNTHÉTIQUE, naissance + genre dans UserInfo)
```

- **Un élève est un `User`** portant `UserRole(student, roleable=family)`. Il n'existe pas de table `students`.
- **Une famille n'a pas de nom** : son libellé est celui de ses responsables (`FamilyController::index` joint tous les noms par `, `, ou « Sans responsable »).
- Une **même personne peut être responsable ET élève** (case « est aussi élève ») → **deux lignes `user_roles`, un seul `User`**. Conséquence : **dédoublonner** partout où on liste des personnes (`->unique('id')`).
- Email synthétique d'un élève créé par l'app :
  `strtolower(prenom.nom.student.<uniqid>@school.com)`.
- Les infos de contact vivent dans `user_infos` (clé-valeur, **aucune validation DB**) : `phone`, `address`, `zipcode`, `city`, `birthdate`, `gender`.
- `users.first_name` / `last_name` sont **nullables** (invitations sans nom) → tolérer `null` dans tout affichage.

## 2. Le gate — `FamilyController::callerCanAccessFamily(Family $family): bool`

**`public static`, réutilisé par `PaiementController` et `StudentClassroomController`.** Ne pas le dupliquer.

```
super-admin                                        → oui
school courante ≠ école de la famille              → non
staff de l'école (director | admin | registar)     → oui
membre de la famille (n'importe quel UserRole)     → oui
sinon                                              → non (403 + Log::warning)
```

Complément : `ensureMemberOfFamily($user, $family, $roleSlug)` vérifie qu'un élève/responsable ciblé appartient bien à cette famille (sinon **404 générique**, pas 403 : on ne confirme pas l'existence).

## 3. Endpoints

Tous sous `school` + `schoolyear` (donc **écriture bloquée en 409** sur année archivée).

| Route | Notes |
|---|---|
| `GET /families` | paginé, `search`, `sort_by` (`created_at\|nom\|nombreEleves\|status`), `sort_direction`, `payment_status`. **Un non-staff ne voit que ses propres familles** |
| `POST /families` | crée la famille + le 1er responsable (réutilise le `User` si l'email existe) + option « est aussi élève » |
| `GET /families/{family}` | responsables, élèves (avec classes actives), commentaires |
| `POST /families/{family}/comments` | |
| `POST /families/{family}/students` | lot d'élèves |
| `PUT/DELETE /families/{family}/students/{student}` | ⚠ le DELETE supprime `user_infos`, **TOUS** les `user_roles` du user (sans filtre famille/école !) **et le `User`** — définitif. Voir `bugs-connus` A12 |
| `POST /families/{family}/responsibles` | rattache un `User` **existant** (doit déjà appartenir à l'école) |
| `POST /families/{family}/responsible` | crée/rattache un responsable depuis un formulaire complet |
| `PUT /families/{family}/responsible/{responsible}` | met à jour ; **décocher « est aussi élève » supprime le rôle student** |
| `GET /families/{family}/enrollments` | inscriptions actives par élève |
| `GET /families/export` | `checkrole:director,admin` — 1 ligne par élève |
| `GET/POST /families/import*` | `checkrole:director,admin` — skill `import-familles` |
| `GET /families/{family}/deletion-preview` | `checkrole:director,admin` — chiffres + `can_delete` + `blockers` |
| `DELETE /families/{family}` | `checkrole:director,admin` — suppression réversible, **409 si la famille a de l'activité** |
| `GET /families/trashed` | `checkrole:director,admin` — corbeille **de l'année consultée** ; déclarée **avant** `/{family}` |
| `POST /families/{familyId}/restore` | `checkrole:director,admin` |

`findOrCreateResponsibleUser()` **réutilise systématiquement** un `User` existant par email : un parent présent dans plusieurs écoles n'est jamais dupliqué.

## 3 bis. Supprimer une famille

`FamilyDeletionController`. Supprimer = **couper les rattachements à l'école**, pas détruire des personnes. Quatre ensembles passent en soft delete avec le **même `deleted_at`** (ce qui permet à `restore()` de ne ressusciter que ces lignes-là) : la famille, les `user_roles` de contexte `family`, les `student_classrooms` de l'**année courante**, et les `user_roles` de contexte `classroom` correspondants.

Restent intacts : les `users` (comptes partageables entre écoles), `paiements` / `lignes_paiement`, `comments`.

### Les garde-fous — `deletionBlockers()`

La suppression est **refusée en 409** tant que la famille porte de l'activité sur l'**année courante** :

| Code | Condition |
|---|---|
| `enrollments` | au moins une `student_classrooms` de statut `active` |
| `payments` | au moins une `lignes_paiement` rattachée au `Paiement` de l'année |

Le payload du 409 et celui de `deletion-preview` portent la même structure `blockers: [{code, label}]`, `label` étant directement affichable. `preview` ajoute `can_delete`.

Conséquences à connaître :
- **Le parcours obligatoire est : désinscrire → retirer les règlements → supprimer.** `DeleteFamilyModal` affiche les motifs et deux raccourcis (`/family/{id}/classes`, `/family/{id}/paiement`) au lieu du bouton rouge.
- **La suppression ne libère plus jamais une place** : la désinscription l'a déjà fait.
- **La restauration ne réinscrit pas** : les inscriptions reviennent inactives, donc elle ne peut plus faire dépasser la capacité d'une classe.
- La portée « année courante » vient des global scopes (`StudentClassroom` et `Paiement` portent `BelongsToSchoolYear`) : une activité entièrement archivée **ne bloque pas**.

### Visibilité en archive

`VisibleUntilYearClosedScope` (sur `Family` **et** `UserRole`, plus les 7 relations pivot via `visibleRolesFilter()`) rend une famille supprimée à nouveau visible dans toute année **déjà clôturée au moment de la suppression** — règle `deleted_at > année.closed_at`. Sur une année active, il se réduit exactement au soft delete standard.

### La corbeille est bornée à l'année consultée

`families` n'a **pas** de `school_year_id` — une famille traverse les années, comme un cursus. Le rattachement à une année se fait donc sur la **date de suppression** : `trashed()` borne `deleted_at` par `[opened_at, closed_at]` de l'année courante (`opened_at` étant nullable sur les années backfillées, fallback sur `created_at`).

Sans cette borne, la corbeille cumulait toutes les suppressions depuis la création de l'école, et une famille supprimée après la clôture d'une année apparaissait **à la fois** dans la liste de cette année (ressuscitée par `VisibleUntilYearClosedScope`) et dans sa corbeille.

⚠ **Conséquence assumée** : une famille supprimée pendant une année désormais close n'est plus restaurable. La fenêtre pour revenir en arrière, c'est l'année en cours.

`trashed()` **ne pose aucun LIMIT** : une troncature muette laisserait croire à une liste complète. Le coût est tenu par `trashedFamilyNames()`, version batch de `familyName()` qui résout tous les noms en **3 requêtes quel que soit le volume** (mesuré : 300 familles → 10 requêtes au total, 61 ms, 25 ko ; contre 208 requêtes pour 100 familles avant). Un test verrouille les deux (`renvoie toute la corbeille sans plafond ni N+1`).

Côté front : bouton **Corbeille** sur `/family`, `v-if="canPilot && !isReadOnly"` — **masqué sur une année clôturée**, où `restore` (un POST) serait de toute façon refusé en 409 par le middleware `schoolyear`. Il ouvre `TrashedFamiliesModal` :

- liste **scrollable** (panneau `max-h-[88vh] flex flex-col`, corps `flex-1 overflow-y-auto`, en-tête/pied `shrink-0`) ;
- **barre de recherche** placée **hors** de la zone scrollable, donc toujours visible ; filtrage **en mémoire** (l'API renvoie tout, pas d'aller-retour par frappe), insensible à la casse **et aux accents** (`normalize('NFD')`) ;
- compteur en pied : « 60 familles », ou « 7 sur 60 » quand un filtre est actif ;
- bouton **Restaurer** par ligne, une seule restauration à la fois (`restoringId`). La modale émet `restored`, la page recharge sa page courante pour que la famille réapparaisse sans la fermer.

Couverture : `FamilyDeletionTest` (28) + `FamilyDeletionHttpTest` (26).

## 4. Statut de règlement (champ `status`)

Calculé en PHP par `calculatePaymentStatus()` : `no_enrollment` · `exempted` · `paid` · `incomplete` · `pending`.
Rendu par `components/Tag.vue`. Tri métier : `no_enrollment < exempted < incomplete < pending < paid`.
⚠ Trier ou filtrer par ce statut fait basculer la pagination **en mémoire** (voir skill `datatable-pagination`).

## 5. Inscription en classe — `StudentClassroomController`

`POST /api/student-classrooms/enroll` (`checkrole:director,admin,registar`), en transaction avec `Classroom::lockForUpdate()` :
1. `callerCanAccessFamily` ;
2. l'élève a bien `UserRole(student, family)` → sinon **400** ;
3. `$classroom->isFull()` → **400** ;
4. doublon exact (student, classroom) → **400** ;
5. **replace** : suppression de l'inscription active existante sur le **même cursus ET même type** (+ son `UserRole(student, classroom)`) ;
6. création de `StudentClassroom(active)` avec **`tarif_snapshot`** ;
7. création de `UserRole(student, classroom)`.

`unenroll` supprime l'inscription **et** le `UserRole(student, classroom)`.
⚠ `ClassroomController::removeStudentFromClass` (retrait depuis `/classes`) supprime l'inscription **mais oublie le `UserRole`** — incohérence listée dans `bugs-connus` A11.
⚠ Le `lockForUpdate()` porte sur la ligne `classrooms`, mais `isFull()` fait un `COUNT` non verrouillé : la sérialisation est assurée, la lecture du compteur reste une lecture cohérente (voir la limite décrite dans `tarification-paiements`).

⚠ Divergence connue : le front (`family/[id]/classes.vue::toggleClass`) désinscrit toutes les classes du **même cursus**, le backend seulement même cursus **+ même type**. Sans effet aujourd'hui car `type` est corrélé 1:1 au cursus — **`'Standard'` pour toute classe créée via l'UI** (le champ n'est exposé dans aucune modale), **`'Arabe'`/`'Coran'` dans le seed de dev**.

## 6. Écrans

### `/` — recherche d'élève (page d'accueil)
`SearchInput` interroge `GET /api/users/search?query=` (min. 2 caractères, debounce 300 ms) et navigue vers `/family/{family_id}`.
Cet endpoint est **year-scopé à deux branches** (décision utilisateur 2026-09-01) : il renvoie les élèves ayant une inscription **active dans l'année sélectionnée** OU n'ayant **jamais été affectés à aucune classe, toutes années confondues** (`StudentClassroom::withoutGlobalScope(BelongsToSchoolYearScope::class)`). Raison : pendant la période d'inscription, une famille existe avant toute affectation en classe et doit rester trouvable ; mais un élève inscrit uniquement dans une autre année ne doit pas polluer l'année sélectionnée. Ne restreindre ni aux seuls inscrits (recherche vide en septembre), ni élargir à tous les élèves (fuite entre années). La recherche ne matche **que les élèves** (slug `student`), jamais les responsables.
La recherche accepte nom, prénom, « nom prénom », « prénom nom », et la date de naissance en `Y-m-d`, `d/m` ou `d/m/Y`.
La page ne montre la barre de recherche qu'après avoir confirmé que l'utilisateur **n'est pas** professeur.

### `/family` — liste
Recherche par responsable (debounce 300 ms), filtre par statut de règlement, tri serveur, `DataTable` 12 colonnes (7/3/2), `ExportButton` **gaté par rôle** (la page n'a pas le middleware `admin-director`).

### `/family/[id]` — fiche (V2 validée, ne pas régresser)
- **Barre d'actions** : 2 boutons secondaires `border-gray-300` (responsable / élèves) + primaire `bg-default` « Choix des classes → ». Le bouton jaune est **banni**. *(L'utilisateur apprécie particulièrement cette barre — ne pas y toucher.)*
- **Bandeau d'identité pleine largeur** : **tous** les responsables côte à côte (avatar `bg-primary`, nom, crayon fantôme, contacts inline) — **pas de switcher** (dropdown et pills rejetés **deux fois**). À droite, séparé par `border-l`, mini-stats (« N élèves », « n/N inscrits », chip de statut).
- **Élèves** pleine largeur : liste `divide-y`, avatar genré, nom + naissance + **âge calculé**, chip violet « Responsable » si applicable, classes en chips neutres (`bg-gray-blue ring-[#E6EFF5]`) ou « Non inscrit » en italique.
- **Paiement + Commentaires** côte à côte, `items-stretch`, **même hauteur `h-80`** (contenu paiement en `overflow-y-auto`).
- Carte Paiement : même bandeau jauge/chip/encaissé-reste que la page paiement + répartition icône+libellé + bouton secondaire « Voir le paiement → » (ni lien bleu souligné, ni émoji). Le bloc n'apparaît que si `montant_total > 0 || montant_paye > 0`.
- Badge « À corriger » si l'email d'un responsable finit par `@corriger.com` (marqueur de données importées, cf. skill `commandes-artisan`).

Règles d'interaction à préserver :
- **« Choix des classes » disparaît** (`v-if="!isReadOnly"`) en année archivée, alors que les deux boutons secondaires sont seulement **désactivés** (`:disabled` + `title`). C'est volontaire : la navigation vers un écran d'inscription n'a pas de sens en lecture seule.
- **Le bouton Supprimer est masqué** (`v-if="!student.is_responsible"`) pour un élève qui est aussi responsable : le supprimer détruirait le `User` du responsable. Passer par la modale responsable pour lui retirer le rôle élève.
- Couleurs d'avatar : `GENDER_COLORS = { M:'#93C5FD', F:'#FDA4AF' }`, fallback `#343C6A` (le `primary`) quand le genre est absent.
- L'âge est **calculé côté front** (`studentAge`), l'API ne renvoie que `birthdate`.

⚠ **Code mort dans cette page** (~60 lignes) : `handleEdit`, `handleSave`, `isEditing`, `contactInfo`, `editForm`, `isDropdownOpen` et l'`import axios` ne sont **jamais utilisés dans le template** — vestiges de l'édition inline remplacée par `EditResponsableModal`. `updateContactInfo()` n'alimente plus que des refs mortes. À supprimer quand on repasse dans le fichier.

⚠ **Bug latent** : `breadcrumbItems` fait `family.value.responsibles[0].first_name` **sans garde**. Une famille **sans responsable** (cas prévu par l'API, qui affiche « Sans responsable ») provoque une `TypeError` au rendu. Fix : `family.value.responsibles?.[0]`.

### `/family/[id]/classes` — choix des classes

#### ⚠ RÈGLE MÉTIER MAJEURE : filtrage des classes par genre, **front uniquement**

`shouldShowClass(classe)` :
```js
if (!hasAdminAccess) {                       // registar, responsable, prof…
    if (classe.gender === 'Enfants' || classe.gender === 'Mixte') return true
    if (student.gender === 'M') return classe.gender === 'Hommes'
    if (student.gender === 'F') return classe.gender === 'Femmes'
    return false
}
return true                                   // director / admin voient TOUT
```
- Un **director/admin** voit **toutes** les classes, y compris celles qui ne correspondent pas au genre de l'élève (souplesse volontaire pour les cas particuliers).
- Tout autre rôle ne voit que les classes compatibles.
- **Le backend n'applique AUCUN de ces filtres** : `enroll` accepte n'importe quelle combinaison. C'est une contrainte purement d'interface — ne pas la supposer garantie côté API, et ne pas la retirer côté front sans demande.

`hasAdminAccess` est calculé par `checkAdminAccess()` à partir des rôles récupérés dans un **second `onMounted`** ⇒ voir le point de vigilance plus bas.

#### Interactions

- `canClickClass` : une classe **complète** (`available_spots <= 0`) n'est pas cliquable, **sauf** si l'élève y est déjà inscrit (pour pouvoir l'en retirer).
- `toggleClass` : désinscrit d'abord **toutes les classes du même cursus** (le back, lui, filtre cursus **+ type**), puis inscrit. Rappelle ensuite `fetchClasses()` (rafraîchit `available_spots`) et `fetchTarif()` (total annuel live).
- Jauge de capacité : `fillRatio = (size - available_spots) / size` ; couleur `capacityBarColor` — rouge si `available_spots <= 0`, ambre si `<= 5`, vert sinon.
- `allStudentsHaveClasses` : si un élève n'a aucune classe, le CTA ouvre `ConfirmationClasseModal` au lieu de naviguer.

#### Design (refonte validée)
- **Élèves en onglets-cartes** horizontaux (avatar initiales couleur genre `M=#93C5FD` / `F=#FDA4AF`, sous-état « N classes » vert ou « Non inscrit » gris).
- **Classes en LIGNES radio** par cursus dans un conteneur `rounded-xl border divide-y` : radio `w-4 h-4` (inscrit = `border-[5px] border-green-600`, fond de ligne `bg-green-50/60`, chip « Inscrit ✓ »), point de genre, nom + niveau, créneaux + prof inline, **capacité `n/size` + mini-jauge `w-12 h-1`** (vert/ambre/rouge), classe complète en `opacity-45` + chip grise.
- **Récap sticky** par élève + « Élèves inscrits n/N » + **« Total annuel » live** rafraîchi à chaque toggle.
- CTA `bg-default` « Continuer vers le paiement → » + compteur ambre d'élèves sans classe.
- ✗ **Ne pas revenir** aux « gros carrés » `border-2` avec encoche verte (jugés kitsch).
- La page force `per_page=100` sur `/api/classrooms` pour tout charger. ⚠ Elle envoie aussi `available_only: true`, **paramètre que l'API ignore** (`ClassroomController::index` ne lit que `cursus_id` et `per_page`) — le filtrage des classes pleines est fait côté front.

#### ⚠ Deux `onMounted` en concurrence

```js
onMounted(async () => { await fetchFamilyData(); await fetchClasses(); await fetchEnrollments(); fetchTarif() })
onMounted(async () => { user = …; await loadUserSchools() })   // → checkAdminAccess() → hasAdminAccess
```
`groupedClasses` dépend de `shouldShowClass`, donc de **`hasAdminAccess`**, renseigné par le **second** `onMounted`. Selon l'ordre d'arrivée des réponses, la liste peut s'afficher **filtrée par genre puis se compléter** (ou l'inverse) pour un directeur.
De plus, `loadUserSchools()` fait **un `getSchool(id)` par école** juste pour connaître le rôle — redondant avec `layouts/auth.vue` qui a déjà l'information.
Correctif propre : lire le rôle actif via `readActiveSchoolRoles()` (synchrone, depuis `localStorage`) au lieu de refaire un aller-retour API.

### `/family/[id]/paiement`
Voir skill `tarification-paiements`.

## 7. Checklist

- [ ] Gate = `callerCanAccessFamily` (jamais un check ad hoc).
- [ ] Cible d'une action = `ensureMemberOfFamily` (404 générique).
- [ ] Personnes dédoublonnées (`->unique('id')`) quand responsable = élève.
- [ ] `first_name`/`last_name` nullables tolérés.
- [ ] Membres chargés par batch `UserRole` (jamais `with('responsibles')`).
- [ ] Suppression d'élève : rappeler que c'est **définitif** (user + infos + rôles).
- [ ] `ExportButton` gaté par rôle sur `/family`.

---

**Voir aussi** : `tarification-paiements` · `classes-cursus` · `import-familles` · `datatable-pagination`
