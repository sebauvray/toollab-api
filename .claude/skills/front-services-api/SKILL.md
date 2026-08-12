---
name: front-services-api
description: Couche d'accès API du front Toollab — client axios unique (services/api.js), injection automatique des headers de contexte, conventions des 17 services, gestion des erreurs avec utils/errors.js, téléchargements blob et upload multipart. À invoquer pour ajouter un appel API, créer un service, ou débugger une requête front.
---

# Couche services / API

## 1. Le client unique — `services/api.js`

```js
import apiClient from './api'        // instance axios partagée
```

`setupInterceptors()` est appelé **une seule fois**, par `plugins/auth.js`, côté client. Il :
- fixe `baseURL = useRuntimeConfig().public.apiUrl` (**lu au runtime**, donc le même bundle est déployable partout) ;
- injecte à chaque requête : `Authorization: Bearer <auth.token>`, `X-School-Id`, `X-School-Year-Id` (depuis `localStorage`) ;
- sur **401** : purge `auth.token`, `auth.user`, `current_school_id`, `current_school_year_id`, les rôles, puis `window.location.href = '/login'` (**hard reload volontaire**, pas `navigateTo`).

⚠ **Ne jamais créer une seconde instance axios** ni appeler `fetch` directement dans une page applicative : tu perdrais token et contexte.
Seule exception tolérée : les pages publiques `set-password.vue`, `reset-password.vue`, `forgot-password.vue` (aucun contexte requis).

### ⚠ `getCurrentSchoolId()` — legacy à ne pas reproduire

`utils/schoolContext.js` expose un `getCurrentSchoolId()` appelé dans **11 fichiers** (`services/classe.js`, `services/cursus.js`, les 4 pages `/statistiques*`, `classes/index.vue`, `cursus/[id].vue`, `family/[id]/classes.vue`) pour glisser un `school_id` dans un payload ou un `params`.

**Cet identifiant est systématiquement ignoré par l'API** : `school_id` est hors `$fillable` sur tous les modèles (posé par `BelongsToSchool`), et les contrôleurs lisent `currentSchoolId()`, alimenté par le header `X-School-Id` que l'intercepteur injecte déjà. Exemple vérifié : `statisticsService.getAvailableBanks(schoolId)` envoie `?school_id=…` que `StatisticsController::availableBanks` n'ouvre jamais.

C'est donc du bruit — et un bruit **qui lève** :
```js
export function getCurrentSchoolId() {
  if (!process.client) throw new Error(missingSchoolMessage)          // ⚠ throw au SSR
  const schoolId = localStorage.getItem('current_school_id')
  if (!schoolId || Number.parseInt(schoolId, 10) <= 0) throw new Error(missingSchoolMessage)
  return schoolId
}
```
Deux règles : **ne pas l'ajouter dans du code neuf** (le header suffit) ; et s'il faut vraiment l'appeler, **jamais au niveau racine de `<script setup>`** — uniquement dans un handler ou un `onMounted`, sous `try/catch`, sinon le rendu SSR casse.

## 2. Anatomie d'un service

```js
// services/machin.js
import apiClient from './api'

export default {
  async getMachins(params = {}) {
    try {
      const response = await apiClient.get('/api/machins', { params })
      return response.data
    } catch (error) {
      console.error('Erreur lors de la récupération des machins:', error)
      throw error
    }
  },
}
```

Conventions :
- **`export default {}`** partout — sauf `services/statistics.js` qui fait `export const statisticsService = …` (import **nommé**, incohérence historique à connaître).
- Le service **relaie** l'erreur (`throw error`) : c'est la **page** qui décide du message affiché.
- Chemins **toujours préfixés `/api/`**.

### ⚠ Deux conventions de retour cohabitent

| Convention | Services | Ce que reçoit la page |
|---|---|---|
| **Enveloppe complète** `response.data` | 15 services : `auth`, `classe`, `cursus`, `family`, `invitations`, `paiement`, `schedule`, `school`, `staff`, `statistics`, `studentClassroom`, `tarification`, `teacher`, `user` | `{ status, message, data }` → lire `response.data.items` |
| **Enveloppe déballée** `data?.data` | **`schoolYear.js`** (6 méthodes) et **`suivi.js`** (2 méthodes) | directement le contenu → `const years = await schoolYearService.list()` |

**Vérifie toujours la convention du service que tu appelles** avant d'écrire `response.data.xxx` : c'est la source d'erreur n°1 quand on branche un nouvel écran.

## 3. Les 17 services

| Fichier | Domaine |
|---|---|
| `api.js` | client axios + interceptors |
| `auth.js` | login/logout/reset/invitation + `getUser()`/`isAuthenticated()` |
| `user.js` | CRUD users, rôles, infos, recherche d'élèves, liste des profs |
| `staff.js` | création staff, ajout/retrait de rôle, retrait de l'établissement, users d'une école |
| `invitations.js` | `getMine` / `accept` / `decline` |
| `family.js` | familles, élèves, responsables, commentaires, export, import + statut d'import |
| `studentClassroom.js` | enroll / unenroll / inscriptions d'une famille |
| `classe.js` | CRUD classes, classes admin, retrait d'élève, export |
| `cursus.js` | CRUD cursus + classes d'un cursus |
| `schedule.js` | créneaux (vue admin) |
| `teacher.js` | classes du prof, élèves, décisions, émargement (+ matrice), planning |
| `suivi.js` | suivi d'une classe, vue d'ensemble des décisions |
| `paiement.js` | détails, lignes, facture PDF |
| `tarification.js` | tarifs, réductions, simulation |
| `statistics.js` | overview, impayées, recherche de paiements, tendances, exports, banques |
| `school.js` | CRUD écoles (multipart) |
| `schoolYear.js` | années, clôture, toggle décisions, reconduction |

## 4. Gestion des erreurs côté page

```js
import { getErrorMessage } from '~/utils/errors'

try {
  await machinService.create(payload)
  setFlashMessage({ type: 'success', message: 'Machin créé' })
} catch (e) {
  message.value = { type: 'error', text: getErrorMessage(e, 'Une erreur est survenue') }
}
```

`getErrorMessage(error, fallback)` : prend la **première erreur de validation** (`data.errors`), sinon `data.message`, sinon `data.error`, sinon `error.message`, et **traduit** les messages techniques anglais (`Network Error`, `Request failed with status code 403`, `Unauthenticated.`…) en français.

**Ne jamais afficher `error.message` brut** à l'utilisateur : passer par `getErrorMessage`.

Codes à traiter spécifiquement :
- **409** avec `read_only: true` → année archivée : « Cette action est impossible sur une année scolaire clôturée. »
- **422** → `error.response.data.errors` pour un affichage champ par champ.
- **401** → déjà géré globalement par l'intercepteur, ne pas le retraiter.

## 5. Téléchargements (blob)

```js
// service
async exportMachins(params = {}) {
  const response = await apiClient.get('/api/machins/export', { params, responseType: 'blob' })
  return response.data
}
```
```js
// page
import { saveExport, saveBlob } from '~/utils/download'
saveExport(blob, 'machins')                                   // → machins_YYYY-MM-DD.xlsx
saveBlob(blob, `facture_${new Date().toISOString().slice(0,10)}.pdf`)   // nom complet
```
⚠ `saveExport` suffixe **toujours `.xlsx`** : pour un PDF, utiliser `saveBlob`.

## 6. Upload multipart

```js
// fichier simple
const formData = new FormData()
formData.append('file', file)
await apiClient.post('/api/families/import', formData, {
  headers: { 'Content-Type': undefined }     // ⚠ laisse axios poser le boundary
})
```

```js
// formulaire mixte (école + logo) — services/school.js
for (const [key, value] of Object.entries(schoolData)) {
  if (key === 'logo' && value instanceof File) formData.append('logo', value)
  else if (typeof value === 'boolean')         formData.append(key, value ? '1' : '0')  // ⚠
  else if (value !== null && value !== undefined) formData.append(key, value)
}
```
⚠ **Booléen en FormData** : un `true` JS devient la chaîne `"true"`, **rejetée** par la règle Laravel `boolean` (qui n'accepte que `1/0/"1"/"0"/true/false`). Toujours convertir en `'1'`/`'0'`.

⚠ **PUT en multipart** : `updateSchool` fait un **POST** avec `formData.append('_method', 'PUT')` (method spoofing Laravel) — PHP ne parse pas les corps multipart en PUT.

## 7. Payloads en whitelist — le piège

`services/classe.js` **reconstruit** le payload champ par champ :
```js
const payload = { name, cursus_id, level_id, gender, size, years, type, telegram_link, main_teacher_id, schedules }
```
Un champ ajouté dans la modale mais **pas** dans `createClass` **et** `updateClass` est **silencieusement perdu**. Même vigilance pour tout service qui reconstruit plutôt que de transmettre l'objet.

## 8. Créer un service

- [ ] Fichier `services/<domaine>.js`, `export default {}`.
- [ ] `import apiClient from './api'` (jamais une nouvelle instance axios).
- [ ] Une méthode = un endpoint, nommée d'après l'action métier.
- [ ] `try/catch` + `console.error` contextualisé + `throw error`.
- [ ] Fichier → `responseType: 'blob'` ; upload → `Content-Type: undefined`.
- [ ] Booléens en multipart convertis en `'1'`/`'0'`.
- [ ] Aucun `X-School-Id` posé à la main (l'intercepteur s'en charge) — sauf cas particulier documenté comme `updateSchool`.
- [ ] Service ajouté au tableau du §3.

---

**Voir aussi** : `api-endpoint` (côté serveur) · `front-auth-roles` (headers) · `exports-xlsx` (blobs) · `nuxt-page`
