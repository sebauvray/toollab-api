---
name: formulaires-validation
description: Formulaires côté Nuxt dans Toollab — composants d'input à label flottant, pattern CSS-only quand ils ne conviennent pas, validation locale et affichage des erreurs (champ vs bandeau), remontée des erreurs 422 de l'API, et pièges vécus (watch deep qui efface les erreurs, ancrage des labels, autocomplete). À invoquer pour créer ou corriger un formulaire.
---

# Formulaires & validation

## 1. Deux mécaniques de label flottant

### (a) Composants `components/form/*` — le cas normal
```vue
<InputText v-model="form.name" placeholder="Nom de l'établissement" required :error="errors.name" />
<InputNumber v-model="form.size" placeholder="Capacité" :min="1" :max="999" />
<InputSelect v-model="form.vat_mode" placeholder="Régime TVA" :options="vatModes" drop-up />
```
Le `placeholder` **est** le label : il remonte en `-top-2 left-2 bg-white px-1 text-default` au focus ou quand le champ est rempli. Le prop `error` colore la bordure en rouge et affiche `text-xs text-red-500 mt-1`.
⚠ Ces composants exigent un **import explicite** dans la page.

### (b) CSS-only — quand le composant ne convient pas
À utiliser pour un champ à events custom (autocomplete, stepper, input masqué) :
```html
<div class="relative">
  <input v-model="x" placeholder=" "        <!-- ESPACE obligatoire -->
         class="peer w-full px-2 py-1.5 text-sm border border-input-stroke rounded-lg
                focus:outline-none focus:border-default" />
  <span class="absolute left-2 top-[19px] -translate-y-1/2 bg-white px-1 text-sm text-placeholder
               transition-all pointer-events-none
               peer-focus:top-0 peer-focus:text-xs peer-focus:text-default
               peer-[:not(:placeholder-shown)]:top-0 peer-[:not(:placeholder-shown)]:text-xs">
    Banque
  </span>
</div>
```
⚠ **Ancrer sur `top-[19px]`, pas `top-1/2`** : un `<p>` d'erreur agrandit le conteneur `relative` et ferait glisser le label (et le suffixe `€`).

## 2. Champs spécialisés — décisions verrouillées

| Besoin | Solution retenue |
|---|---|
| Montant | `pr-7` + `<span class="absolute right-2.5 text-gray-400">€</span>` + `tabular-nums` |
| Petit compteur (nb de chèques) | **stepper − / +** borné (1-7), **pas un `<select>`** |
| Heure | **`<input type="time">` natif** — un `SelectTime` à pas de 15 min a été tenté puis **rejeté** (interdit 07:18) |
| Choix binaire | **segmented control**, jamais de radios nus |
| Rôle / carte sélectionnable | `RoleCard` (`indicator="radio\|check\|none"`) |
| Date | `<input type="date">` natif (+ `showPicker()` pour l'ouvrir par un bouton) |

## 3. Validation locale

Deux styles cohabitent selon le contexte.

### (a) Objet plat — pages simples (`paiement.vue`)
```js
const fieldErrors = ref({})

const validateForm = () => {
  const errs = {}
  if (!form.value.type) return false
  if (!form.value.montant || form.value.montant <= 0) errs.montant = 'Saisissez un montant supérieur à 0'
  if (form.value.type === 'exoneration' && !form.value.justification?.trim())
    errs.justification = "Précisez le motif de l'exonération"
  fieldErrors.value = errs
  return Object.keys(errs).length === 0
}
```

### (b) Format **compatible Laravel** — modales (`AddClassModal`, `AddElevesModal`, `EditTeacherModal`)
```js
const fieldErrors = ref({})                                   // { 'champ': ["message"] }  ← TABLEAU, comme Laravel
const setFieldError = (f, m) => { fieldErrors.value = { ...fieldErrors.value, [f]: [m] } }
const firstError = (...fields) => { for (const f of fields) { const m = fieldErrors.value[f]?.[0]; if (m) return m } return '' }
```
Les clés reprennent **la notation Laravel des tableaux** : `students.0.lastname`, `students.1.birthdate`, `schedules.2.start_time`.
**C'est ce qui permet au `catch` d'écraser `fieldErrors` avec `err.response.data.errors` sans aucune transformation** — erreurs locales et erreurs serveur s'affichent au même endroit. Reprends ce format pour tout nouveau formulaire à répétition.

Messages **en français, orientés action** (« Sélectionnez une banque », pas « Champ invalide »), plus un message global `error.value = 'Veuillez corriger les champs indiqués.'`.
Le bouton de soumission peut aussi être gardé par un `computed` de validité (`isValidNewForm`), mais **valide toujours au submit** : un `computed` ne remplit pas `fieldErrors`.

Réinitialisation : `watch(() => props.isOpen, isOpen => isOpen ? resetForm() : resetErrors())` — le formulaire est vidé **à l'ouverture** (pas à la fermeture), ce qui évite un flash de champs vides pendant l'animation.

## 4. Affichage des erreurs — la règle

| Portée | Rendu |
|---|---|
| Un champ | `<p class="text-xs text-red-500 mt-1">` (ou prop `:error` du composant) |
| La section / l'API | bandeau `bg-red-50 text-red-700 ring-1 ring-red-200 rounded-lg px-3 py-2 text-xs` |
| Résultat d'une action | `setFlashMessage({ type, message })` |

⚠ **Ne jamais doubler** un bandeau d'erreur API par un message au niveau du champ pour la même cause (« double message horrible »). Une erreur métier globale (dépassement de montant) → **bandeau seul**.

⚠ **Reformuler côté client** un message serveur qui n'a pas de sens dans le contexte :
```js
if (error.response?.status === 422 && /dépasser le montant dû/i.test(message)) {
  const reste = Math.round(Math.max(0, resteAPayer.value))
  message = {
    exoneration: `L'exonération saisie dépasse ce que la famille doit encore (reste à payer : ${reste}€).`,
    cheque:      `Le total des chèques dépasse ce que la famille doit encore (reste à payer : ${reste}€).`,
    espece:      `Ce montant dépasse ce que la famille doit encore (reste à payer : ${reste}€).`,
    carte:       `Ce montant dépasse ce que la famille doit encore (reste à payer : ${reste}€).`,
  }[form.value.type] || message
}
fieldErrors.value = { api: message }
```

## 5. Erreurs 422 de l'API

```js
import { getErrorMessage } from '~/utils/errors'

catch (e) {
  const errors = e.response?.data?.errors            // { champ: ["msg"], … }
  if (errors) {
    fieldErrors.value = Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]]))
  } else {
    message.value = { type: 'error', text: getErrorMessage(e) }
  }
}
```
Certaines pages exposent un helper `firstError('champ')` — même principe.
Le formulaire de création d'école (`pages/admin/schools/new.vue`) affiche en plus un **bandeau listant TOUTES les `errors`** : sans lui, une erreur sur un champ non rendu (`access`, `email`…) reste invisible derrière un « Veuillez corriger les erreurs ».

## 6. Effacement des erreurs — le piège vécu

⚠ **Ne pas utiliser `watch(form, …, { deep: true })` pour vider les erreurs.**
Cas réel : `onBanqueBlur` mute le formulaire **150 ms après** le clic (via `setTimeout`) → le watch se déclenche et les erreurs disparaissent instantanément, avant que l'utilisateur les lise.

Effacer les erreurs uniquement : à la **re-validation** (submit), au **changement de type/mode**, au **reset** du formulaire.

## 7. Autocomplete (liste de suggestions)

Pattern de `paiement.vue` (33 banques françaises en dur) :

```vue
<div class="banque-autocomplete relative">
  <input :value="searchTerm" @input="onInput($event.target.value)" @blur="onBlur"
         @keydown="handleKeydown" @focus="showSuggestions = true"
         placeholder=" " autocomplete="off" class="peer …" />
  <span class="… floating label …">Banque</span>

  <div v-if="showSuggestions && filtered.length"
       class="banque-suggestion absolute z-50 w-full mt-1 bg-white border rounded-lg shadow-lg max-h-48 overflow-y-auto">
    <div v-for="(b, i) in filtered" :key="b"
         @mousedown="select(b)"                              <!-- ⚠ mousedown, PAS click -->
         :class="i === selectedIndex ? 'bg-gray-100' : 'hover:bg-gray-100'"
         class="px-2.5 py-1.5 cursor-pointer text-xs">{{ b }}</div>
  </div>
</div>
```

Points critiques :
- **`@mousedown` et non `@click`** sur une suggestion : `mousedown` se déclenche **avant** le `blur` de l'input, donc la sélection passe. C'est ce qui rend possible le `setTimeout(…, 150)` du blur.
- `autocomplete="off"` pour neutraliser l'autocomplétion navigateur.
- Navigation clavier `ArrowDown` / `ArrowUp` / `Enter` / `Escape` ; l'élément courant est mis en évidence en **`bg-gray-100`** (jamais en bleu).
- Fermeture aussi par listener global `document` filtré sur `closest('.banque-autocomplete')` / `.banque-suggestion` — d'où les deux classes marqueurs.

⚠ **Le blur ne doit PAS vider un champ invalide** : la valeur reste, c'est la validation qui la signale. (Des champs « qui se vidaient mystérieusement » ont déjà été signalés.)
⚠ Le label flottant d'un **`<textarea>`** s'ancre sur `top-[1.1rem]` (et non `top-[19px]`) — la hauteur de départ diffère.

## 8. Soumission

```js
const isSubmitting = ref(false)

const handleSave = async () => {
  if (isSubmitting.value) return
  if (!validateForm()) return
  isSubmitting.value = true
  try {
    await machinService.create(payload)
    setFlashMessage({ type: 'success', message: 'Machin créé avec succès' })
    emit('save')
    resetForm()
  } catch (e) { /* cf. §5 */ }
  finally { isSubmitting.value = false }
}
```
Toujours : garde de double-soumission, `:disabled="isSubmitting"` sur les boutons, libellé de progression (« Enregistrement… »).

## 9. Checklist

- [ ] `InputText`/`InputSelect`/… importés explicitement, ou pattern CSS-only avec `placeholder=" "`.
- [ ] Labels flottants et suffixes ancrés sur une valeur fixe (`top-[19px]`).
- [ ] Validation locale avec messages FR orientés action.
- [ ] Erreur de champ **ou** bandeau — jamais les deux pour la même cause.
- [ ] 422 mappé sur les champs ; sinon `getErrorMessage`.
- [ ] Pas de `watch` deep pour effacer les erreurs.
- [ ] Garde de double-soumission + états désactivés.
- [ ] Formulaire désactivé si `isReadOnly` (année archivée).

---

**Voir aussi** : `ui-components` · `modals` · `design-system` · `front-services-api` (erreurs 422)
