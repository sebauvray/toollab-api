---
name: datatable-pagination
description: Tableaux paginés Toollab — composant DataTable (colonnes en col-span, tri, sélecteur par page), contrat de pagination front/API, composable useTablePerPage pour la persistance, tri et filtrage côté serveur, et pièges (grille désynchronisée, per-page sans handler, pagination en mémoire). À invoquer pour créer ou modifier une liste paginée.
---

# Tableaux paginés

## 1. Contrat de bout en bout

```
API  → { status, data: { items, pagination: { current_page, per_page, total, total_pages } } }
page → pagination.value = { currentPage, totalPages, perPage, total }     (camelCase)
```
La conversion snake_case → camelCase se fait **dans la page**. `DataTable` ne connaît que le format camelCase.

## 2. Page type

```vue
<script setup>
import DataTable from '~/components/table/DataTable.vue'

const items = ref([])
const isLoading = ref(true)
const searchQuery = ref('')
const searchTimeout = ref(null)
const sort = ref({ key: 'created_at', direction: 'desc' })
const pagination = ref({ currentPage: 1, totalPages: 1, perPage: 10, total: 0 })

const { loadPerPage, savePerPage } = useTablePerPage('machins_per_page')   // auto-importé

const columns = [
  { key: 'nom',    label: 'Nom',    width: '7', sortable: true },
  { key: 'nombre', label: 'Nombre', width: '3', sortable: true, sortKey: 'nombreEleves' },
  { key: 'status', label: 'Statut', width: '2', sortable: true },
]   // somme des width = 12

const fetchData = async (page = 1) => {
  try {
    isLoading.value = true
    const params = {
      page,
      per_page: pagination.value.perPage,
      sort_by: sort.value.key,
      sort_direction: sort.value.direction,
    }
    if (searchQuery.value.trim()) params.search = searchQuery.value.trim()

    const response = await machinService.getMachins(params)
    if (response.status === 'success') {
      items.value = response.data.items
      pagination.value = {
        currentPage: response.data.pagination.current_page,
        totalPages:  response.data.pagination.total_pages,
        perPage:     response.data.pagination.per_page,
        total:       response.data.pagination.total,
      }
    }
  } finally { isLoading.value = false }
}

const handlePageChange    = (p) => fetchData(p)
const handleSortChange    = (s) => { sort.value = s; fetchData(1) }
const handlePerPageChange = (n) => { savePerPage(n); pagination.value.perPage = n; fetchData(1) }
const handleSearch = (e) => {
  searchQuery.value = e.target.value
  clearTimeout(searchTimeout.value)
  searchTimeout.value = setTimeout(() => fetchData(1), 300)
}

onMounted(() => {
  pagination.value.perPage = loadPerPage()   // AVANT le premier fetch, jamais au setup (SSR)
  fetchData()
})
onUnmounted(() => clearTimeout(searchTimeout.value))
</script>

<template>
  <DataTable
      :columns="columns" :items="items" :pagination="pagination"
      :loading="isLoading" :sort="sort"
      @page-change="handlePageChange"
      @per-page-change="handlePerPageChange"
      @sort-change="handleSortChange">
    <template #default="{ item, isLastRow }">
      <NuxtLink :to="`/machins/${item.id}`"
          class="grid py-1 px-3 hover:bg-gray-50 transition-colors cursor-pointer font-nunito"
          :class="{ 'border-b border-[#E6EFF5]': !isLastRow }"
          :style="`grid-template-columns: repeat(12, minmax(0, 1fr))`">
        <div class="col-span-7 inline-flex items-center gap-x-3 pl-1">{{ item.nom }}</div>
        <div class="col-span-3 inline-flex items-center">{{ item.nombre }}</div>
        <div class="col-span-2 inline-flex items-center"><Tag :status="item.status" /></div>
      </NuxtLink>
    </template>
  </DataTable>
</template>
```

## 3. `useTablePerPage(key, fallback = 10)`

```js
const { loadPerPage, savePerPage } = useTablePerPage('machins_per_page')
```
Valide la valeur contre `[10, 25, 50, 100]`, garde `process.client`. `loadPerPage()` s'appelle **dans `onMounted`**, avant le premier fetch — jamais au niveau du setup (SSR).

Clés existantes : `families_per_page`, `cursus_per_page`, `cursus_classes_per_page`, `cheques_per_page`, `exonerations_per_page`, `impayees_per_page`.

## 4. Pièges

1. **Grille désynchronisée** — la somme des `width` des colonnes définit `grid-template-columns` de l'en-tête. Ta ligne personnalisée le redéfinit **en dur** : les deux doivent correspondre, sinon en-tête et lignes sont décalés.
2. **`@per-page-change` non câblé** → le sélecteur s'affiche mais ne fait rien. **Toujours** le brancher.
3. **`loadPerPage()` au setup** → `localStorage` indisponible au SSR (le composable renvoie le fallback, mais le pattern est fragile). Rester dans `onMounted`.
4. **Ne pas filtrer côté client des données paginées serveur** : on ne filtrerait que la page courante. Envoyer le filtre en paramètre (`?payment_status=`, `?exoneration_type=`, `?banks=`).
5. **`sortKey`** : à utiliser quand la clé d'affichage ≠ la clé attendue par l'API.
6. **Reset à la page 1** après tout changement de tri, filtre ou taille de page.

## 5. Côté API

Défaut **10**, plafond **100**, partout :
```php
// trait
$data = $this->paginateQuery($query, $request);
// manuel
$perPage = min((int) $request->get('per_page', 10), 100);
```

⚠ Ce code existant **n'a pas de borne basse** : `?per_page=0` → `ceil($total / 0)` → **500**. Dans du code neuf, écrire `max(1, min((int) …, 100))` et caster `page` en `max(1, (int) …)`. Détail : `bugs-connus` A14.

`App\Traits\PaginationTrait` n'a **qu'un seul appelant** (`FamilyController::index`) ; sa seconde méthode `formatPaginatedResponse()` n'est **jamais appelée**. Ne pas la prendre pour le standard maison : le pattern majoritaire est le paginateur manuel.

**Règle d'or : une ligne paginée = une entité paginée.** Ne jamais émettre N lignes par item via `flatMap` (bug historique de `FamilyController::index` : 10 familles → nombre de lignes variable et familles dupliquées ; corrigé en `->map()`).

Tri et filtre **en SQL** dès que possible. Trois endroits basculent volontairement **en mémoire** parce que le critère est calculé en PHP : `FamilyController::index` (tri/filtre par statut de règlement), `StatisticsController::unpaidFamilies`, `StatisticsController::payments` (filtre `exoneration_type`). Le client reçoit bien `per_page` lignes, mais le serveur traite tout l'effectif → point de charge documenté, pas un bug.

## 6. Listes volontairement non paginées

`/api/admin/classrooms`, `/api/admin/outcomes`, `/api/schools`, `/api/users/*`, `/api/schedules`, `/api/statistics/search-payments`, détail d'une famille.
`family/[id]/classes.vue` force `per_page=100` sur `/api/classrooms` pour tout charger (≤ 100 classes supposées).

## 7. Colonne d'action

Une seule action (voir le détail) ⇒ **pas de libellé de colonne** (`label: ''`) et un **bouton-icône** aligné à droite :
```html
<button class="inline-flex w-7 h-7 rounded-lg text-gray-500 hover:text-default hover:bg-gray-100"
        title="Voir le détail" @click="…">
  <!-- icône œil -->
</button>
```
**Pas** de lien texte souligné « Voir » / « Paiement » (rejeté explicitement).

---

**Voir aussi** : `ui-components` · `api-endpoint` (pagination serveur) · `design-system`
