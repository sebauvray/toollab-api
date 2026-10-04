---
name: modals
description: Gabarit universel des modales Toollab (overlay, panneau, en-tête, corps, pied), table des events exacts des 12 modales existantes, pattern promise resolve/reject, pièges connus (ConfirmationModal, UpdateClassModal qui émet update, panneaux de select clippés) et anti-patterns bannis. À invoquer pour créer ou modifier une modale.
---

# Modales

## 1. Le gabarit universel

**Toutes** les modales de l'app suivent cette structure. Ne pas en inventer une autre.

```vue
<template>
  <div v-if="isOpen"
       class="fixed inset-0 z-50 font-nunito bg-black/50 flex items-center justify-center p-3"
       @click.self="$emit('close')">

    <div class="bg-white rounded-2xl shadow-xl w-full max-w-xl">
      <!-- max-w-md | max-w-xl | max-w-2xl | max-w-3xl selon le contenu -->

      <!-- EN-TÊTE : titre à GAUCHE (jamais centré) + croix -->
      <div class="px-5 pt-4 pb-3 border-b border-[#E6EFF5] flex items-center justify-between">
        <h2 class="text-base font-bold text-default font-montserrat">Titre de la modale</h2>
        <button @click="$emit('close')" aria-label="Fermer"
                class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-50">
          <Cross class="size-4" />
        </button>
      </div>

      <!-- CORPS -->
      <div class="px-5 py-4 space-y-4">
        <div v-if="error" class="bg-red-50 text-red-700 ring-1 ring-red-200 px-3 py-2 rounded-lg text-xs">
          {{ error }}
        </div>

        <div>
          <h3 class="text-xs font-montserrat font-semibold text-gray-500 mb-2">Section</h3>
          <InputText v-model="form.name" placeholder="Nom" required />
          <p v-if="firstError('name')" class="text-xs text-red-600 mt-1">{{ firstError('name') }}</p>
        </div>
      </div>

      <!-- PIED : boutons à DROITE (jamais centrés) -->
      <div class="px-5 py-3 border-t border-[#E6EFF5] flex justify-end gap-x-1.5">
        <CancelButton @click="$emit('close')" :disabled="isSubmitting">Annuler</CancelButton>
        <SaveButton @click="handleSave" :disabled="isSubmitting">
          {{ isSubmitting ? 'Enregistrement…' : 'Enregistrer' }}
        </SaveButton>
      </div>
    </div>
  </div>
</template>
```

### Variante « contenu long »
⚠ À utiliser dès que le contenu dépasse ~350 px de haut (formulaire + liste de choix + bandeau) : sans `max-h` ni corps défilant, sur une fenêtre basse la modale déborde de l'écran **sans pouvoir défiler** et les boutons du pied deviennent inatteignables (vécu sur `DirectorHandoverModal`). Si le bandeau d'erreur est en haut d'un corps défilant, remonter le corps (`scrollTo({top:0})`) quand une erreur apparaît.
```html
<div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl max-h-[88vh] flex flex-col">
  <div class="… shrink-0">en-tête</div>
  <div class="px-5 py-4 flex-1 overflow-y-auto min-h-[28rem]">corps</div>
  <div class="shrink-0 border-t …">pied</div>
</div>
```
Le `min-h` du corps n'est pas cosmétique : voir §4.

### Variante `<Teleport>`
`ConfirmationModal` utilise `<Teleport to="body">` avec un garde `isBrowser` (le Teleport doit s'exécuter côté client uniquement) et bloque le scroll du body (`document.body.style.overflow = 'hidden'`, restauré dans `onBeforeUnmount`).
À reprendre pour toute modale susceptible d'être rendue dans un conteneur avec `overflow` ou `transform`.

## 2. Contrat props / events

```js
defineProps({ isOpen: { type: Boolean, required: true }, /* données d'édition */ })
defineEmits(['close', 'save'])
```
- Le **parent** possède `showXxxModal` et détruit/masque la modale.
- Fermer par : croix, `CancelButton`, clic sur l'overlay (`@click.self`).
- `save` porte la donnée métier, pas l'état d'UI.

### Les 12 modales existantes — events exacts

| Modale | Events | Payload promise | Particularité |
|---|---|---|---|
| `ConfirmationModal` | **`confirm`**, **`cancel`** | non | `<Teleport to="body">` + garde `isBrowser` + blocage du scroll |
| `ConfirmationClasseModal` | `close`, `save` | non | confirmation spécialisée (choix des classes) |
| `AddClassModal` | `close`, `save` | **oui** | créneaux + prof principal |
| `UpdateClassModal` | `close`, **`update`** ⚠ | **oui** | **seule modale qui n'émet pas `save`** ; diff des créneaux ; réutilisée par `/classes/[id]` |
| `AddCursusModal` | `close`, `save` | **oui** | `ToogleCursus` + `InputNumber` |
| `UpdateCursusNameModal` | `close`, `save` | **oui** | |
| `AddElevesModal` | `close`, `save` | **oui** | lot d'élèves |
| `EditElevesModal` | `close`, `save` | **oui** | |
| `EditTeacherModal` | `close`, `save` | **oui** | `error` + `fieldErrors` + `isSubmitting` internes |
| `AddResponsableModal` | `close`, `save` | non — **autonome** | crée la famille **et** son 1er responsable |
| `AddNewResponsableModal` | `close`, `save` | non — **autonome** | ajoute un responsable à une famille |
| `EditResponsableModal` | `close`, `save` | non — **autonome** | met à jour un responsable |

⚠ **`UpdateClassModal` émet `update`, pas `save`.** Brancher `@save` dessus ne déclenche rien, silencieusement. Les deux pages qui l'utilisent (`cursus/[id].vue`, `classes/[id].vue`) écoutent bien `@update`.

### Le pattern promise `{resolve, reject}` — majoritaire, pas exceptionnel

**7 modales sur 12** émettent `(payload, { resolve, reject })`. La modale **attend la promesse** avant de se réinitialiser et de se fermer :

```js
// CÔTÉ MODALE
const handleSave = async () => {
  error.value = ''; fieldErrors.value = {}
  if (!form.value.name) { setFieldError('name', 'Le nom est requis.'); error.value = 'Veuillez corriger les champs indiqués.'; return }

  try {
    isSubmitting.value = true
    await new Promise((resolve, reject) => {
      emit('save', payload, { resolve, reject })       // ← le parent tranche
    })
    resetForm()
    emit('close')                                       // la MODALE se ferme elle-même
  } catch (err) {
    fieldErrors.value = err.response?.data?.errors || {}   // remontée des 422 champ par champ
    error.value = Object.keys(fieldErrors.value).length
      ? 'Veuillez corriger les champs indiqués.'
      : getErrorMessage(err, 'Une erreur est survenue')
  } finally {
    isSubmitting.value = false
  }
}
```
```js
// CÔTÉ PAGE
const handleSave = async (payload, callbacks = null) => {
  try {
    const response = await service.create(payload)
    setFlashMessage({ type: 'success', message: response.message })
    await refresh()
    callbacks?.resolve?.()          // ⚠ sans ça, la modale reste bloquée en « envoi »
  } catch (e) {
    callbacks?.reject?.(e)          // ⚠ sans ça, la modale n'affiche jamais l'erreur
  }
}
```

Trois conséquences :
1. **Ne pas fermer la modale depuis la page** quand elle utilise ce pattern : elle le fait elle-même après `resolve`. (Certaines pages le font quand même — inoffensif, mais redondant.)
2. **Toujours `reject(e)`** en cas d'échec : c'est ce qui alimente `fieldErrors` et affiche les erreurs 422 dans la modale.
3. Le `?.` est indispensable : certaines pages appellent le handler sans callbacks.

### ⚠ Deux architectures de modales cohabitent

| Architecture | Qui appelle l'API ? | Modales concernées |
|---|---|---|
| **Déléguée** (pattern promise) | **le parent**, la modale attend `resolve`/`reject` | `AddClassModal`, `UpdateClassModal`, `AddCursusModal`, `UpdateCursusNameModal`, `AddElevesModal`, `EditElevesModal`, `EditTeacherModal` |
| **Autonome** | **la modale elle-même**, puis elle émet `save` avec le résultat | `AddResponsableModal`, `AddNewResponsableModal`, `EditResponsableModal`, `ConfirmationClasseModal` |

Les modales **autonomes** n'ont donc pas besoin de `{resolve, reject}` : elles connaissent déjà l'issue de l'appel. Le parent ne reçoit `save` qu'en cas de **succès**, et s'en sert pour rafraîchir ou naviguer.

**Avant de modifier une modale, identifie sa famille** : ajouter un `await service.x()` dans une modale déléguée (ou l'inverse) casse la gestion d'erreur.

### Les 3 modales responsable

Formulaire **identique** (nom, prénom, contact, adresse, toggle « est aussi élève » + naissance/genre conditionnels), mais **trois endpoints différents** :

| Modale | Props | Appelle |
|---|---|---|
| `AddResponsableModal` | `isOpen` | `familyService.createFamily()` — **crée la famille ET son premier responsable** |
| `AddNewResponsableModal` | `isOpen`, `familyId` | `familyService.addResponsibleToFamily(familyId)` |
| `EditResponsableModal` | `isOpen`, `familyId`, `responsable` | `familyService.updateResponsible(familyId, responsable.id)` |

Candidates à factorisation (un composant de formulaire partagé + 3 enveloppes), mais **ne pas les fusionner sans demande** : la première crée une famille, ce qui n'est pas du tout la même opération métier.

## 3. Pièges de props connus

### `ConfirmationModal`
```vue
<ConfirmationModal
  :is-open="show" title="Retirer l'élève"
  message="Êtes-vous sûr ?"
  confirm-button-text="Retirer"     <!-- ⚠ PAS confirm-text -->
  cancel-button-text="Annuler"
  confirm-button-class="bg-red-600 hover:bg-red-700 text-white"
  @confirm="…" @cancel="show = false" />
```
`pages/classes/index.vue` passe `confirm-text`/`cancel-text` → **libellés par défaut affichés** (bug listé dans `bugs-connus`).

### `UpdateClassModal` / `AddClassModal`
- Props : `cursusName`, `levels[]`, `classData` (édition).
- Chargent la liste des profs à l'ouverture (`watch(isOpen)` → `userService.listTeachers()`), pas au montage.
- Le formulaire utilise **`levelId`** (camelCase) ; `services/classe.js` le mappe en `level_id`.
- **Créneaux** : un sous-formulaire (`newSchedule`) validé indépendamment (`schedule_day`, `schedule_start_time`, `schedule_end_time`) qui **empile** dans `newClass.schedules` ; `UpdateClassModal` gère en plus le **diff** : `{id}` existant → update ; `{id, delete:true}` → suppression ; sans `id` → création.
- **Professeur principal** : `distinctTeacherIds` (ordre d'apparition dans les créneaux) alimente le select, visible seulement si ≥ 2 profs distincts, en **`drop-up`**. Un `watch(distinctTeacherIds)` **remet automatiquement le principal sur le premier** s'il disparaît des créneaux — miroir exact de `ClassroomController::syncMainTeacher`.
- Libellé d'un créneau : `getScheduleTeacherLabel()` → nom du prof si `teacher_id` connu, sinon **fallback `teacher_name`** (legacy), sinon « Aucun professeur ».
- ⚠ **`services/classe.js` reconstruit le payload en whitelist** : tout nouveau champ doit être ajouté dans `createClass` **et** `updateClass`, sinon il est perdu silencieusement.
- Réutilisée par `/classes/[id]` (édition sur place, sans redirection) : elle a besoin de `cursus_id`/`type`/`years`, exposés par `ClassroomController::show`.

### Validation locale : `fieldErrors` + `firstError`

Pattern commun aux modales riches :
```js
const fieldErrors = ref({})                                   // { champ: ["message"] }
const setFieldError = (f, m) => { fieldErrors.value = { ...fieldErrors.value, [f]: [m] } }
const firstError = (...fields) => { for (const f of fields) { const m = fieldErrors.value[f]?.[0]; if (m) return m } return '' }
```
La forme `{ champ: [messages] }` est **volontairement identique à celle de Laravel** : le `catch` peut donc écraser `fieldErrors` avec `err.response.data.errors` sans transformation.

## 4. Le piège des `InputSelect` clippés

`InputSelect` et `SelectDay` rendent leur liste dans un panneau **`absolute z-50`**, pas dans un `<select>` natif. Dans un corps `overflow-y-auto`, la liste est **coupée**.

Deux parades :
1. `min-h-[28rem]` (ou plus) sur le corps de la modale — c'est ce que font les modales classe ;
2. prop **`drop-up`** sur les selects situés en bas du corps.

## 5. Anti-patterns bannis

| ✗ | ✓ |
|---|---|
| Titre centré (`mx-auto`) | titre **à gauche** |
| Boutons centrés en pied | boutons **à droite** |
| Séparateur `h-px bg-gray-200` | `border-b`/`border-t border-[#E6EFF5]` |
| Erreur `bg-red-100` pleine | `bg-red-50 … ring-1 ring-red-200 rounded-lg text-xs` |
| Radios nus pour un choix binaire | **segmented control** `inline-flex rounded-lg border divide-x`, actif `bg-default text-white` |
| Boutons en `text-base` | `SaveButton`/`CancelButton` (`text-sm`) ou `text-xs` |
| Blocs `bg-gray-50` empilés pour une liste | liste bordée `divide-y divide-[#E6EFF5]` |

## 6. Checklist

- [ ] Gabarit respecté (overlay `bg-black/50 p-3` + `@click.self`, panneau `rounded-2xl shadow-xl`).
- [ ] En-tête `px-5 pt-4 pb-3 border-b`, titre à gauche, croix à droite.
- [ ] Corps `px-5 py-4`, kickers de section `text-xs font-montserrat font-semibold text-gray-500`.
- [ ] Pied `px-5 py-3 border-t`, boutons à droite.
- [ ] Contenu long → `max-h-[88vh] flex flex-col` + corps `flex-1 overflow-y-auto` (+ `min-h` si selects).
- [ ] `isOpen` en prop, `close`/`save` en events, état possédé par le parent.
- [ ] Le service front transporte bien **tous** les champs du payload.
- [ ] Nouveau champ classe ⇒ ajouté dans `createClass` ET `updateClass`.

---

**Voir aussi** : `ui-components` · `design-system` · `formulaires-validation` · `nuxt-page`
