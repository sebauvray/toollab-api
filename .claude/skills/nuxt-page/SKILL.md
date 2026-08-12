---
name: nuxt-page
description: Créer une page Nuxt dans toollab-front — definePageMeta (layout, middleware, layoutData), usePageTitle, structure PageContainer + BreadCrumb, chargement des données, gestion des états loading/erreur/vide, prise en compte de l'année en lecture seule et du gating par rôle. À invoquer avant de créer un nouvel écran ou d'en restructurer un.
---

# Créer une page Nuxt

## 1. Squelette

```vue
<script setup>
import { ref, computed, onMounted } from 'vue'
import PageContainer from '~/components/layout/PageContainer.vue'
import BreadCrumb from '~/components/navigation/BreadCrumb.vue'
import { usePageTitle } from '~/composables/usePageTitle.js'
import { useSchoolYear } from '~/composables/useSchoolYear'
import machinService from '~/services/machin'

definePageMeta({
  layout: 'auth',                    // 'auth' (app) | 'admin' (super-admin) | 'default' (public)
  middleware: 'admin-director',      // optionnel — voir §2
  layoutData: { title: 'Machins' }   // convention locale, informatif
})

usePageTitle('Machins')              // → « Machins - Toollab » dans l'onglet

const { isReadOnly } = useSchoolYear()
const { setFlashMessage } = useFlashMessage()   // auto-importé

const items = ref([])
const isLoading = ref(true)
const error = ref(null)

const breadcrumbItems = computed(() => [{ name: 'Machins', path: '/machins' }])

const fetchData = async () => {
  try {
    isLoading.value = true
    error.value = null
    const response = await machinService.getMachins()
    if (response.status === 'success') items.value = response.data.items
  } catch (e) {
    console.error('Erreur chargement machins:', e)
    error.value = 'Une erreur est survenue lors du chargement des données'
  } finally {
    isLoading.value = false
  }
}

onMounted(fetchData)
</script>

<template>
  <PageContainer>
    <BreadCrumb :custom-items="breadcrumbItems" />

    <div v-if="isLoading" class="flex justify-center items-center h-64">
      <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
    </div>

    <div v-else-if="error" class="bg-red-50 text-red-700 ring-1 ring-red-200 px-3 py-2 rounded-lg text-xs">
      {{ error }}
    </div>

    <div v-else-if="items.length === 0" class="py-12">
      <!-- état vide : voir §5 -->
    </div>

    <div v-else> … </div>
  </PageContainer>
</template>
```

## 2. Layouts & middlewares

| Layout | Usage |
|---|---|
| `auth` | toutes les pages applicatives (sidebar, sélecteur d'année, bandeaux) |
| `admin` | shell super-admin (`/admin/*`) — nav à 2 entrées + menu compte **inline** |
| `default` | pages publiques (login, reset, set-password, contact, select-school) |

| Middleware | Effet |
|---|---|
| *(aucun)* | protégée par `auth.global.js` (token + école sélectionnée) |
| `admin-director` | rôle **actif** ∈ director/admin **et** confirmé côté serveur, sinon → `/` |
| `super-admin` | `is_super_admin` uniquement, sinon → `/` |
| `guest` | pages publiques (redirige un connecté vers `/`) |

`auth.global.js` s'applique **partout** : hors pages publiques, il exige un token, puis une école (`current_school_id`) sauf pour `/select-school` et `/admin/*`.

⚠ **Confinement des professeurs** : un utilisateur dont le rôle actif est **uniquement** `teacher` est redirigé vers `/professeur/classes` pour toute route hors whitelist :
```js
const teacherAllowed = to.path.startsWith('/professeur')
    || to.path === '/settings'
    || noSchoolNeeded.includes(to.path)
```
**Tout nouvel écran accessible aux profs hors `/professeur/*` doit être ajouté à cette whitelist**, sinon le prof est rebouclé (cas déjà vécu avec `/settings`).

## 2 bis. ⚠ Rendre la page atteignable — le menu est écrit à la main

Créer `pages/machins/index.vue` crée la **route**, pas l'**entrée de menu**. Le fichier `pages/` n'alimente aucune navigation automatique : la barre latérale est une liste de `<NavLink>` **codée en dur** dans `layouts/auth.vue` (l. 296-305). Oublier cette étape est le défaut le plus courant sur un nouvel écran — la page n'existe alors que par son URL.

```vue
<!-- layouts/auth.vue -->
<NavLink v-if="hasAdminAccess" to="/machins" :icon="MachinIcon" text="Machins" :collapsed="isSidebarCollapsed" />
```
L'icône doit être **importée en haut du layout** (les composants de `components/Icons/` ne sont pas auto-importés ici).

**Les 3 drapeaux de visibilité** — calculés sur le **rôle actif seul**, jamais sur l'union :

| Drapeau | Vrai pour | Entrées actuelles |
|---|---|---|
| `hasGeneralAccess` | super-admin, ou tout rôle **sauf** prof-seul | Accueil, Familles |
| `hasAdminAccess` | super-admin, ou rôle actif ∈ `director`/`admin` | Cursus, Classes, Professeurs, Tarification, Statistiques |
| `hasTeachingAccess` | rôle actif = `teacher` | Mes classes, Mon planning |

⚠ Conséquence à connaître : **un `registar` ne voit que « Accueil » et « Familles »**. Il n'existe pas de drapeau « staff opérationnel » côté menu — si un écran doit lui être ouvert, il faut ajouter le drapeau, pas réutiliser `hasAdminAccess`.

**Quatre écrans n'ont volontairement aucune entrée de menu** — les chercher ailleurs avant de conclure qu'ils sont morts :

| Écran | Point d'entrée réel |
|---|---|
| `/annees-scolaires` | pied du dropdown année (topbar), `v-if="hasAdminAccess"` |
| `/settings` | menu compte (bas de sidebar) |
| `/decisions` | bouton dans `pages/classes/index.vue:211` |
| `/contact` | **aucun** — page orpheline et non fonctionnelle (cf. `bugs-connus` B) |

`NavLink` marque l'entrée active avec `route.path.startsWith(props.to)` (`/` traité à part, en égalité stricte). Une nouvelle route préfixée par une entrée existante **allume donc cette entrée** : `/classes-archivees` surlignerait « Classes ». Choisir le segment en conséquence.

## 2 ter. Ce que le layout fournit déjà — ne pas le réimplémenter

`app.vue` monte, autour de chaque page : un splash de 300 ms, **`<FlashMessage />` global** (donc `setFlashMessage()` marche depuis n'importe où, y compris une modale), puis `NuxtLayout` > `NuxtPage`. Il n'y a **pas de `error.vue`** : une erreur Nuxt non rattrapée affiche la page d'erreur par défaut, non traduite.

`layouts/auth.vue` fournit en plus, pour toute page en layout `auth` :
- la **sidebar** (repliée automatiquement sous 1280 px) et le **menu compte** ;
- la **topbar**, qui ne contient **que** le sélecteur d'année ;
- le **bandeau ambre** « année en lecture seule » et les **bandeaux bleus d'invitation**.

Ne dupliquer aucun de ces éléments dans une page. Le changement d'école ou de rôle (`enterSchool`) fait un `window.location.reload()` dur : inutile de prévoir une réactivité fine sur ces valeurs.

## 3. Gating par rôle dans la page

Le middleware protège la route ; à l'intérieur, masquer les actions non permises :
```js
import { hasAnyRole, readActiveSchoolRoles } from '~/utils/schoolRoles'

const canPilot = ref(false)
onMounted(() => {
  if (!process.client) return
  const u = JSON.parse(localStorage.getItem('auth.user') || 'null')
  canPilot.value = !!u?.is_super_admin || hasAnyRole(readActiveSchoolRoles(), ['director', 'admin'])
})
```
Toujours **dans `onMounted`** (ou sous `process.client`) : `localStorage` n'existe pas au SSR.

Cas typique : un `ExportButton` sur une page **non** gardée `admin-director` (`/family`) doit être `v-if="canExport"`.

## 4. Année en lecture seule

```js
const { isReadOnly } = useSchoolYear()
```
```vue
<button :disabled="isReadOnly" :title="isReadOnly ? 'Année scolaire en lecture seule' : ''"
        class="… disabled:opacity-40 disabled:cursor-not-allowed">
```
Le bandeau ambre global est déjà affiché par `layouts/auth.vue` — ne pas le dupliquer. La vraie protection reste le 409 serveur.

## 5. États vides

Pas de simple « Aucune donnée » : proposer l'action suivante.
```vue
<div class="mx-auto max-w-xl bg-white border border-[#E6EFF5] rounded-2xl px-6 py-7 text-center shadow-sm">
  <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-default/10 text-default">
    <svg …/>
  </div>
  <h2 class="text-lg font-montserrat font-bold text-default mb-2">Aucune classe pour le moment</h2>
  <p class="text-sm text-gray-600 font-nunito leading-6 mb-5">Les classes sont créées depuis un cursus…</p>
  <NuxtLink to="/cursus" class="inline-flex … bg-default text-white text-sm rounded-lg">
    Aller aux cursus <span aria-hidden="true">→</span>
  </NuxtLink>
</div>
```
(Modèle : `pages/classes/index.vue`.)

## 6. Pages détail

- BreadCrumb `parent (lien) / courant` — le dernier crumb **est** le titre : pas de `<h1>` **nu** en doublon. Un `<h1>` intégré à une **carte d'identité** (avatar + métadonnées + action) reste acceptable — c'est ce que fait `pages/classes/[id].vue`, seule page applicative dans ce cas.
- Onglets : **soulignés** (`border-b-2 border-default` sur l'actif, `font-montserrat`, `text-xs`), pas des pills.
- Pré-sélection d'onglet par query : `?tab=decisions` → `activeTab.value = route.query.tab || 'defaut'`.
- Charger l'onglet paresseusement si son endpoint est coûteux (`if (key === 'attendance' && !matLoaded.value) loadMatrix()`).

## 7. Persistance d'une préférence d'affichage

```js
const viewMode = ref('detailed')
const setView = (v) => { viewMode.value = v; if (process.client) localStorage.setItem('machins_view', v) }
onMounted(() => {
  if (process.client) {
    const saved = localStorage.getItem('machins_view')
    if (saved === 'list' || saved === 'detailed') viewMode.value = saved
  }
})
```
Toujours **valider** la valeur relue (une clé corrompue ne doit pas casser le rendu).

## 8. Pièges Nuxt/SSR

- `ssr: true` : tout code au niveau `setup` s'exécute **aussi côté serveur**. Garder `localStorage`/`window` sous `process.client` ou dans `onMounted`.
- Contenu dépendant du client → `<ClientOnly>` (cf. `pages/index.vue`).
- Composants en sous-dossier → **import explicite** (`InputSelect`, `DataTable`, `SaveButton`…).
- `useFlashMessage`, `usePageTitle`, `useTablePerPage`, `useSchoolYear` sont auto-importés (`imports.dirs: ['composables/**']`).
- Nettoyer les timers/listeners dans `onUnmounted` (debounce de recherche, polling, `document.addEventListener`).

## 9. Checklist

- [ ] `definePageMeta` (layout + middleware si réservé) et `usePageTitle`.
- [ ] **Page atteignable** : `<NavLink>` ajouté dans `layouts/auth.vue` sous le bon drapeau, **ou** point d'entrée assumé et documenté (bouton, dropdown, menu compte).
- [ ] **Page de détail** (`[id].vue`) : même `middleware` que la page de liste correspondante (écart existant sur `cursus/[id].vue`).
- [ ] `PageContainer` + `BreadCrumb :custom-items`, sans `<h1>` en doublon.
- [ ] États loading / erreur / vide traités.
- [ ] `isReadOnly` appliqué aux actions mutatives.
- [ ] Gating par rôle des actions sensibles (export, création).
- [ ] Route accessible aux profs ⇒ ajoutée à `teacherAllowed` dans `auth.global.js`.
- [ ] Accès `localStorage`/`window` sous `process.client`.
- [ ] Timers et listeners nettoyés dans `onUnmounted`.
- [ ] Design conforme à la skill `design-system`.

---

**Voir aussi** : `design-system` · `ui-components` · `datatable-pagination` · `front-auth-roles` (gating) · `front-services-api` (appels)
