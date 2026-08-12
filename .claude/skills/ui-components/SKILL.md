---
name: ui-components
description: Catalogue des composants Vue réutilisables de toollab-front — props exactes, événements, pièges d'utilisation (imports explicites requis, noms de props inhabituels, panneaux clippés) et quand réutiliser plutôt que recréer. À invoquer avant de créer un composant ou d'en assembler dans une page.
---

# Catalogue des composants

> Avant de créer quoi que ce soit : **vérifie ici**. La plupart des besoins sont couverts.

## 1. Règle d'import

Nuxt auto-importe les composants racine (`components/*.vue`) et les composables. Les composants **en sous-dossier** utilisés sous leur nom court (`<InputSelect>`, `<SaveButton>`, `<DataTable>`) exigent un **import explicite** dans la page :
```js
import InputSelect from '~/components/form/InputSelect.vue'
```
Symptôme si oublié : `WARN Failed to resolve component` + composant absent du rendu.
Auto-importés sans effort : `<FlashMessage>`, `<Tag>`, `<ExportButton>`.

## 2. Layout & navigation

### `layout/PageContainer.vue`
Wrapper de toute page : `flex flex-col gap-y-1.5 w-full xl:pt-5 pt-3 xl:px-8 px-3 pb-3 font-montserrat`. Aucun prop.

### `navigation/BreadCrumb.vue`
```vue
<BreadCrumb :custom-items="[{ name: 'Familles', path: '/family' }, { name: 'Dupont', path: null }]" />
```
- ⚠ Le prop est **`customItems`** → `:custom-items`. **`:items` est silencieusement ignoré** et déclenche le mode auto (découpage de `route.path`, liens potentiellement morts).
- Le **dernier crumb est rendu en gras et sert de titre de page** : ne pas ajouter de `<h1>` **nu** qui le répéterait.
  **Exception assumée** : `pages/classes/[id].vue` est la seule page applicative à cumuler BreadCrumb **et** `<h1>` — parce que le titre y est intégré à une **carte d'identité** (accent `border-l-4` couleur genre + avatar d'initiales + sous-ligne cursus/niveau/genre/effectif + créneaux), pas posé comme un titre isolé. Les 4 pages d'auth et les 5 pages `/admin` ont un `<h1>` **sans** BreadCrumb, ce qui est normal.
- Positionné `absolute top-6` : il se superpose au contenu, prévoir la place.
- Masque « Familles » / « Classes » sous 820 px, masque l'icône Accueil sous 520 px.

### `navigation/NavLink.vue`
```vue
<NavLink to="/family" :icon="FamilyTLB" text="Familles" :collapsed="isSidebarCollapsed" />
```
Actif = `route.path.startsWith(to)` (exact pour `/`). Affiche un tooltip au survol quand `collapsed`.

## 3. Tableaux

### `table/DataTable.vue`
```vue
<DataTable
  :columns="[{ key:'nom', label:'Nom', width:'7', sortable:true, sortKey:'nom' }]"
  :items="rows" :pagination="pagination" :loading="isLoading" :sort="sort"
  @page-change="…" @per-page-change="…" @sort-change="…">
  <template #default="{ item, index, isLastRow }"> … ligne personnalisée … </template>
</DataTable>
```
- `width` = **nombre de `col-span` Tailwind** ; la somme des `width` définit la grille. Ta ligne personnalisée doit reprendre **la même somme** en dur (ex. `repeat(12, minmax(0,1fr))`).
- `pagination` = `{ currentPage, totalPages, perPage, total }` (camelCase côté front, snake_case côté API : mapper).
- Sélecteur « Par page » intégré (10/25/50/100) → émet `per-page-change`. **Sans handler, il s'affiche mais ne fait rien.**
- Tri : `sortable: true` + `sortKey` optionnel ; le caret est géré par le composant.
- Le composant ne fixe **aucune** taille de page : elle vient du `per_page` de l'appel parent.

Voir la skill `datatable-pagination` pour le pattern complet côté page.

### `Tag.vue`
```vue
<Tag :status="item.status" />
```
Valeurs : `paid`, `pending`, `incomplete`, `exempted`, `no_enrollment`, `''`. Rend un badge bordé. **Ne pas recréer** de chip de statut de règlement.

### `ExportButton.vue`
```vue
<ExportButton :loading="exporting" label="Exporter" @click="doExport" />
```
Bouton clair + logo Excel officiel. Voir skill `exports-xlsx` (dont la règle de gating par rôle).

## 4. Formulaires (`components/form/`)

| Composant | Props | Notes |
|---|---|---|
| `InputText` | `placeholder`, `type`, `required`, `disabled`, `error` + `v-model` | label flottant ; `error` colore la bordure et affiche le message |
| `InputNumber` | idem + `min`, `max` | `type="number"` avec une classe `no-spinners` (`<style>` scoped) qui masque les flèches natives Chrome/Firefox |
| `InputSelect` | `options: [{value,label}]`, `placeholder`, `required`, `error`, **`dropUp`** + `v-model` | panneau **`absolute z-50`**, pas un `<select>` natif |
| `InputCross` | `v-model` ; émet `delete` (vide aussi le modèle) | ⚠ **placeholder codé en dur « Nom du niveau »** + **plus importé nulle part** (code mort) |
| `SearchInput` | — | **autonome** : recherche d'élèves de la page d'accueil. Debounce 300 ms, **min. 2 caractères**, `hideSuggestions` avec `setTimeout(200)` pour laisser passer le clic, navigation directe vers `/family/{family_id}`. ⚠ L'API (`/users/search`) ne renvoie que les élèves **ayant une inscription active dans l'année courante** — un élève non inscrit est introuvable |
| `SelectDay` | jour de la semaine | même mécanique de panneau `absolute z-50` |
| `SelectGenre` | `placeholder` (défaut « Genre ») + `v-model` | ⚠ **genre de CLASSE** (`Hommes`/`Femmes`/`Enfants`/`Mixte`), pas de personne. Affiche une **pastille de couleur** par option et embarque sa propre copie de la palette. Utilisé uniquement par `AddClassModal` et `UpdateClassModal` |
| `DatePicker` | `class`, `placeholder` + `v-model` | ⚠ le `v-model` est une **chaîne ISO complète** (`toISOString()`), pas `YYYY-MM-DD` — convertir avant d'envoyer à l'API |
| `ToogleButton` | `v-model` booléen | switch `h-6 w-11`. ⚠ typo « Tooge » dans le nom de fichier |
| `ToogleCursus` | `v-model` `levels`\|`continu` | segmented `bg-gray-100` + pill blanche `h-[48px]`. **Exception historique** au design system (qui bannit les pills grises) : ne pas le prendre comme modèle pour un nouveau segmented control |
| `SaveButton` | prop `class` surchargeable ; émet `click` | défaut `bg-default … px-4 py-1.5 text-sm rounded-lg` |
| `CancelButton` | idem | défaut `border border-gray-300 … px-4 py-1.5 text-sm` |

⚠ **Piège des panneaux clippés** : `InputSelect` et `SelectDay` rendent leur liste en `absolute z-50`. Dans un corps de modale `overflow-y-auto`, la liste est **coupée**. Solutions : donner un `min-h` suffisant au corps (ex. `min-h-[28rem]` des modales classe), ou passer **`drop-up`** quand le select est en bas.

## 5. Modales (`components/modals/`)

`ConfirmationModal` · `AddClassModal` · `UpdateClassModal` · `AddCursusModal` · `UpdateCursusNameModal` · `AddElevesModal` · `EditElevesModal` · `AddResponsableModal` · `AddNewResponsableModal` · `EditResponsableModal` · `EditTeacherModal` · `ConfirmationClasseModal`.

Gabarit commun et pièges de props : voir la skill **`modals`**.

Rappels critiques :
- `ConfirmationModal` attend **`confirmButtonText`** / **`cancelButtonText`** (pas `confirm-text`) et émet `confirm`/`cancel`.
- **`UpdateClassModal` émet `update`, pas `save`** — la seule dans ce cas. `@save` dessus ne déclenche rien.
- **7 modales sur 12** émettent `(payload, { resolve, reject })` : le parent **doit** appeler `callbacks?.resolve?.()` après succès, sinon le formulaire reste bloqué.
- `UpdateClassModal` est réutilisée par `/classes/[id]` (bouton « Modifier », ouverture sur place sans redirection).

## 6. Paramètres (`components/settings/`)

### `UserList.vue`
```vue
<UserList :school-id="school.id" :selected-user-id="managingUser?.user.id" ref="userListRef" @manage="handleManage" />
```
Expose `refreshUsers()` via `defineExpose`. Regroupe les rôles par utilisateur, filtre sur `['director','admin','registar','teacher']`, masque le nom des invitations `pending` et affiche des chips de rôle teintés.

### `RoleCard.vue`
```vue
<RoleCard :role="role" :selected="…" indicator="check|radio|none" compact :disabled="…" @select="…" />
```
Les définitions de rôles (icône, couleurs, permissions listées) vivent dans **`utils/staffRoleCards.js`** (`STAFF_ROLE_CARDS`). Ajouter un rôle attribuable = éditer ce fichier, pas le composant.

### `StudentImport.vue`
Import de familles, autonome (drag & drop + polling). Voir skill `import-familles`.

## 7. Divers

| Composant | Usage |
|---|---|
| `FlashMessage.vue` | monté globalement dans `app.vue` ; alimenté par `useFlashMessage()` ; `Teleport to="body"`, auto-dismiss 3 s avec barre de progression. **Un seul message à la fois**, et le `setTimeout` du précédent n'est **jamais annulé** → deux flashs rapprochés partagent la première échéance et le second peut ne s'afficher qu'un instant. Ne pas enchaîner deux `setFlashMessage()` dans le même handler : n'en émettre qu'un, le plus informatif |
| `UserDropdown.vue` | ⚠ **code mort** : plus référencé nulle part. `layouts/admin.vue` a désormais son propre menu compte inline (commit `030e8e0`). L'ancienne doc affirmait qu'il fallait le conserver — **c'est faux**. Ne pas le remettre dans `layouts/auth.vue` (doublon supprimé volontairement) |
| `auth/AuthGuard.vue` | garde côté **rendu** (masque le slot tant que le token n'est pas vérifié) — utilisé par `pages/index.vue` et `pages/login.vue`. Props : `requiresAuth` (défaut `true`) |
| `schedule/ScheduleGrid.vue` | grille de planning — skill `planning-creneaux` |
| `ui/LoadingScreen.vue` | props `message`, `fullScreen`. ⚠ **importé nulle part** — code mort |
| `tarification/RecapitulatifTarifs.vue` | ⚠ **code mort** : importé nulle part, structure de données incompatible avec le service |

### `components/Icons/` — 42 icônes, **à vérifier avant d'inliner un SVG**

```
Navigation / chrome : Home  Home-TLB  Setting  Search  Cross  Dots  Plus  PlusLight
                      Edit  Trash  Check-TLB  IconCheck  Valid  Notification  ChartBar
Métier              : Family-TLB  IconFamily  Student-TLB  Teacher-TLB  Responsable-TLB
                      IconClassroom  IconUsers  Cursus  Notebook-TLB  Clock-TLB
                      User  User-TLB  UserMale-TLB  UserFemale-TLB
Argent              : CurrencyEuro  Monnaie-TLB  IconCash  IconMoney  IconCard  Card-TLB  IconGift
Contact             : Mail-TLB  Phone-TLB  Comment-Empty  Paiement-Empty
Marque              : Logo  LogoText
```
Deux familles cohabitent : les `*-TLB` (jeu maison) et les `Icon*` (surtout les cartes de statistiques). **Suivre la famille déjà employée dans l'écran** plutôt que de mélanger.

⚠ **Elles ne sont pas uniformes** — vérifier le fichier avant de colorer :

| Type | Exemple | Coloration |
|---|---|---|
| `fill="currentColor"` + prop `class` | `Family-TLB`, la plupart des `*-TLB` | `class="text-primary size-4"` fonctionne |
| `stroke="currentColor" fill="none"` | `IconUsers`, `ChartBar` | `class="text-blue-600 w-5 h-5"` fonctionne |
| **ni `fill` ni prop `class`** | `Home.vue` | `text-*` **n'a aucun effet** → utiliser `fill-gray-600` (c'est ce que fait `BreadCrumb`) |

S'utilisent en `<MonIcone class="size-4" />` ou `<component :is="icon" class="size-[1.15rem]" />` (cf. `NavLink`).

## 7 bis. Les deux seuls `defineExpose` du projet

```js
// components/settings/UserList.vue
defineExpose({ refreshUsers })                 // le parent rafraîchit la liste après une action
// components/modals/EditTeacherModal.vue
defineExpose({ setError, setErrors })          // le parent y renvoie les erreurs 422 de l'API
```
Usage :
```js
const userListRef = ref(null)
userListRef.value?.refreshUsers()

const editModalRef = ref(null)
editModalRef.value?.setErrors(err.response?.data?.errors || {}, message)
```
C'est le pattern retenu quand un enfant doit être piloté par son parent — préférable à un `key` qui remonte, ou à un `watch` sur une prop compteur.

## 7 ter. Le spinner est dupliqué 18 fois

```html
<div class="animate-spin rounded-full h-12 w-12 border-b-2 border-default"></div>
```
Ce bloc (parfois en `h-10 w-10` ou `h-8 w-8`, parfois `border-primary`) est réécrit à la main dans **18 fichiers**, alors que `ui/LoadingScreen.vue` existe et n'est utilisé **nulle part**.

Quand tu ajoutes un état de chargement : réutilise le motif du fichier voisin (pour la cohérence visuelle) ou, si tu factorises, remplace-les **tous** en une fois. Ne crée pas une 19ᵉ variante.
Tailles en usage : `h-12 w-12` (page entière), `h-10 w-10` (section), `h-8 w-8` (bloc/DataTable). Couleur : `border-default` (privilégier) ou `border-primary` (legacy).

## 8. Feedback utilisateur

```js
const { setFlashMessage } = useFlashMessage()
setFlashMessage({ type: 'success' | 'error', message: '…' })
```
Convention : **flash** pour le résultat d'une action (création, suppression, échec d'export) ; **message inline** (`bg-red-50 ring-1`) pour une erreur de formulaire ou de section.

## 9. Créer un nouveau composant — checklist

- [ ] Aucun composant existant ne couvre le besoin (relire §2-§7).
- [ ] Placé dans le bon dossier (`form/`, `modals/`, `table/`, `navigation/`, `settings/`, `layout/`).
- [ ] Props typées via `defineProps`, événements via `defineEmits`.
- [ ] Respecte la skill `design-system` (couleurs custom, `text-xs`/`text-sm`, `rounded-2xl`/`rounded-lg`).
- [ ] Aucun accès `localStorage`/`window` hors `process.client`.
- [ ] Si utilisé en sous-dossier : documenter l'import explicite requis.
- [ ] Ajouté à ce catalogue.

---

**Voir aussi** : `design-system` · `modals` · `datatable-pagination` · `formulaires-validation`
