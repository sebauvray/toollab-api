---
name: statistiques
description: Module statistiques de Toollab — endpoints StatisticsController (overview, impayées, paiements, chèques, exonérations, tendances, exports), invariants de calcul à respecter (encaissé vs exonéré, cohérence des compteurs), pagination en mémoire, et état de dette design des pages /statistiques. À invoquer pour modifier un indicateur, une page de stats ou un export financier.
---

# Module statistiques

## 1. Endpoints — `checkrole:admin,director`, sous `school` + `schoolyear`

```
GET  /api/statistics/overview                 4 blocs : enrollments, payments, classes, families, cursus
GET  /api/statistics/unpaid-families          liste paginée des familles qui doivent encore
POST /api/statistics/search-payments          recherche de chèque (numéro | émetteur | banque)
GET  /api/statistics/enrollment-trends        inscriptions par mois (6 derniers mois)
GET  /api/statistics/revenue-by-month         montants par mois et par type (6 derniers mois)
GET  /api/statistics/payments                 lignes de paiement paginées + filtres
GET  /api/statistics/available-banks          banques distinctes des chèques saisis
GET  /api/statistics/export-payments          export .xlsx (colonnes selon payment_type)
GET  /api/statistics/export-unpaid-families   export .xlsx
```

Filtres de `payments` / `export-payments` : `search` (n° de chèque, nom de responsable, téléphone), `payment_type` (`cheque|espece|carte|exoneration`), `banks` (CSV), `exoneration_type` (`complete|partial`).

⚠ **Trois de ces endpoints ne sont consommés par aucun écran** : `enrollment-trends`, `revenue-by-month` (prévus pour des graphiques jamais branchés) et `search-payments` (les pages utilisent `searchPaymentsPaginated` → `GET /statistics/payments`). Les méthodes existent dans `services/statistics.js` mais aucune page ne les appelle. Les 6 réellement utilisées : `getOverview`, `getUnpaidFamilies` (×2), `searchPaymentsPaginated` (×2), `getAvailableBanks`, `exportPayments` (×2), `exportUnpaidFamilies`.
Les listes et leurs exports partagent les mêmes helpers (`buildPaymentsQuery`, `collectUnpaidFamilies`, `formatPaymentLignes`) : **ce qu'on voit à l'écran est exactement ce qu'on exporte**. Conserver ce couplage.

## 2. Invariants de calcul — à ne jamais casser

### Encaissé ≠ soldé
```php
$encaisse = lignes ∈ {cheque, espece, carte}          // trésorerie réelle
$exonere  = lignes de type exoneration                 // remise, PAS un règlement
remaining        = attendu − encaissé − exonéré
collection_rate  = encaissé / attendu                  // taux d'encaissement
recovery_rate    = (encaissé + exonéré) / attendu      // taux de recouvrement
payment_rate     = alias de recovery_rate              // conservé pour compat front
```

### Effectifs — `getEnrollmentStats`
Tout élève actif est classé **exactement une fois** : enfant si `age < 16`, sinon par genre, **`women` par défaut** si le genre est absent.
→ **Invariant : `men + women + children == total`**, même sans date de naissance. Ne pas introduire de catégorie « inconnu » qui casserait cette somme.

### Familles — `getFamilyStats`
```
paid_count            expected <= 0 (exonérée) OU paid >= expected
partially_paid_count  0 < paid < expected
fully_unpaid_count    paid == 0 et expected > 0
unpaid_count          = partially + fully
```
→ **Invariant : `paid + partially + fully == total`.**
Côté UI : la carte « Familles » affiche `fully_unpaid_count` en « Non payées » ; la carte « Reste à payer » affiche `unpaid_count` (familles qui doivent encore).

### Attendu
`computeFamilyFinancials()` calcule l'attendu **une seule fois par famille** via `TarifCalculatorService::calculerTotalFamille` ; l'attendu total est la **somme** de ces valeurs.
⚠ **Ne jamais réintroduire de montant ou de réduction en dur** dans les stats (bug historique : 210/240/270 hardcodés).

### Périmètre = année courante
`Paiement` porte `BelongsToSchoolYear` : toute requête `LignePaiement::whereHas('paiement', …)` est **déjà filtrée par l'année**. Il n'existe **pas** de « bug de scope année » sur les montants payés — ne pas ajouter de filtre redondant.
De même, `StudentClassroom::whereHas('classroom', …)` applique le scope année de `Classroom`.

## 2 bis. ⚠ Un seul endpoint sur 8 valide ses paramètres

`searchPayments` est le **seul** à faire un `$request->validate([...])`. `unpaidFamilies`, `payments`, `exportPayments`, `exportUnpaidFamilies`, `enrollmentTrends`, `revenueByMonth`, `availableBanks` lisent leurs paramètres **sans aucune validation** (`page`, `per_page`, `search`, `payment_type`, `banks`, `exoneration_type`, `filter`).

Ce n'est **pas une faille** — les valeurs passent par des bindings Eloquent (pas d'injection SQL), une valeur inconnue de `payment_type` renvoie simplement 0 ligne. Mais **deux paramètres font tomber l'endpoint en 500** :
- `page` n'est **pas casté en int** dans `unpaidFamilies` (l. 296) ni dans `payments` (l. 469) : `$page = $request->input('page', 1)` puis `($page - 1) * $perPage`. En PHP 8, une chaîne non numérique — dont la **chaîne vide** d'un `?page=` — lève `TypeError: Unsupported operand types: string - int` → **500**. (`"5abc"` passe avec un warning et vaut 5.)
- `per_page` est casté et plafonné mais **pas borné en bas** : `?per_page=0` → `ceil($total / 0)` → `DivisionByZeroError` → **500**.

Aucun retour d'erreur exploitable pour le front dans ces cas.

Si tu touches à l'un de ces endpoints, **ajoute la validation au passage** :
```php
$request->validate([
    'page' => 'nullable|integer|min:1',
    'per_page' => 'nullable|integer|min:1|max:100',
    'payment_type' => 'nullable|in:espece,carte,cheque,exoneration',
    'exoneration_type' => 'nullable|in:complete,partial',
    'filter' => 'nullable|in:unpaid,partial',
    'search' => 'nullable|string|max:100',
    'banks' => 'nullable|string|max:500',
]);
```

## 3. Performance — pagination en mémoire

`computeFamilyFinancials($schoolId)` calcule l'attendu de **toutes** les familles ayant au moins une inscription active. Il est appelé par `overview`, `unpaidFamilies` et indirectement par `formatPaymentLignes` (via un cache local `$expectedCache` par famille).

Conséquence : `unpaidFamilies` et `payments` (avec filtre d'exonération) **paginent en mémoire** — le client reçoit `per_page` lignes, mais le serveur traite tout l'effectif à chaque page.

**Ne pas aggraver** : ne pas ajouter d'appel par famille dans ces chemins. Si la volumétrie devient un problème, la refonte consiste à matérialiser l'attendu (colonne ou table d'agrégat) plutôt qu'à recalculer.

Les contacts (responsables, téléphone) sont chargés **par batch `UserRole`** — ne jamais revenir à `with('responsibles')` qui renvoie vide (voir skill `laravel-model-migration`).

## 4. Recherche de chèques

`searchPayments` accepte `search_type` ∈ `cheque_number | emitter_name | bank` et interroge le JSON :
```php
->where('details->numero', 'like', "%$v%")
->where(fn($q) => $q->where('details->nom_emetteur','like',…)
                    ->orWhere('details->emetteur','like',…))   // ⚠ fallback legacy
->where('details->banque', 'like', "%$v%")
```
**Le fallback `emetteur` doit être conservé** (données legacy en prod).

`availableBanks` renvoie les banques distinctes des chèques saisis, ou une liste par défaut de 5 banques si l'école n'en a aucune.

## 5. Pages front

| Page | Contenu |
|---|---|
| `/statistiques` | 4 cartes cliquables (inscrits, reste à payer, classes, familles) + répartition par mode + remplissage par cursus + graphiques Chart.js |
| `/statistiques/impayees` | table paginée + recherche + filtre (`unpaid` / `partial`) + export |
| `/statistiques/cheques` | table paginée + filtre multi-banques + export |
| `/statistiques/exonerations` | table paginée + filtre complète/partielle + export. `selectedTypes` est un tableau mais **`exoneration_type` n'est envoyé que si UN SEUL type est coché** (les deux cochés = pas de filtre). `expandedComments` (Set) déplie les motifs longs |

Toutes sont sous `middleware: 'admin-director'` → l'`ExportButton` peut y être affiché sans gating supplémentaire.
Persistance des tailles de page : `impayees_per_page`, `cheques_per_page`, `exonerations_per_page`.

Les 4 pages partagent la même ossature (`params` → `statisticsService` → `DataTable` + `ExportButton`), avec quelques duplications à connaître :
- **`formatCurrency` est réécrit à l'identique dans les 4 fichiers** (`Intl.NumberFormat` FR, EUR, 0 décimale) — candidat évident pour `utils/` si tu repasses dessus.
- Les dropdowns de filtre sont des `<div>` maison avec `ref` + listener `document` (pas `InputSelect`), chacun avec sa propre implémentation.
- `cheques.vue` et `exonerations.vue` embarquent le même `<style scoped>` de checkbox custom (voir `design-system` §6 bis).

⚠ **Ne plus filtrer côté client** des données paginées serveur (`filter_types` a été supprimé) : on ne filtrerait que la page courante.

Le service front est **nommé** : `import { statisticsService } from '~/services/statistics'` (le seul du projet à ne pas être `export default`).

## 5 bis. Graphiques — Chart.js

`pages/statistiques/index.vue` importe **`chart.js/auto`** directement (pas de wrapper Vue) et construit deux graphiques :

| Graphique | Type | Données |
|---|---|---|
| Répartition | `doughnut` | `enrollments.men / women / children`, labels avec pourcentage calculé |
| Remplissage | (barres) | `cursus[].fill_rate` |

Patterns à respecter :
```js
let chartInstance = null
const create = () => {
  if (chartInstance) chartInstance.destroy()        // ⚠ sinon fuite mémoire + superposition
  const ctx = canvasRef.value?.getContext('2d')
  if (!ctx) return                                   // le canvas peut ne pas être monté
  chartInstance = new Chart(ctx, { … })
}
await nextTick()
setTimeout(() => { createGenderChart(); createFillRateChart() }, 100)   // hack DOM, fragile mais en place
```
Les couleurs du doughnut **reprennent la palette de genre** utilisée par les classes : `#93C5FD` (Hommes) · `#FDA4AF` (Femmes) · `#FCD34D` (Enfants). **Garder cette cohérence** avec `GENDER_COLORS` de `ScheduleGrid`, `/classes` et `/classes/[id]`.

Montants formatés via `Intl.NumberFormat('fr-FR', { style:'currency', currency:'EUR', minimumFractionDigits:0, maximumFractionDigits:0 })` — **pas de décimales**.

Chargement : `Promise.all([getOverview, getUnpaidFamilies])`, erreurs passées par `getErrorMessage()`.

## 6. Dette de design connue

Les pages `/statistiques/*` sont les **moins alignées** sur le design system : `rounded-lg` au lieu de `rounded-2xl`, `border-gray-200` au lieu de `border-[#E6EFF5]`, `shadow-sm` sur les blocs, un spinner `border-indigo-600`, des dropdowns de filtre maison plutôt que `InputSelect`.

Si tu retouches ces pages, aligne-les progressivement (skill `design-system`) — mais **ne lance pas** une refonte visuelle globale non demandée.

## 7. Checklist

- [ ] Encaissé et exonéré restent distincts.
- [ ] Les invariants de somme (`men+women+children`, `paid+partially+fully`) tiennent toujours.
- [ ] Aucun montant ni réduction en dur.
- [ ] Filtres serveur, jamais côté client sur des données paginées.
- [ ] Liste et export partagent le même helper de requête.
- [ ] Aucun nouvel appel par famille dans `computeFamilyFinancials` ou `formatPaymentLignes`.
- [ ] Fallbacks legacy `emetteur` / `motif` préservés.

---

**Voir aussi** : `tarification-paiements` (encaissé vs exonéré) · `exports-xlsx` · `datatable-pagination` · `bugs-connus` (pagination en mémoire)
