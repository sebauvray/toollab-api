---
name: exports-xlsx
description: Génération de fichiers Excel .xlsx dans Toollab via App\Services\ExportService (ZipArchive + OOXML écrit à la main, zéro dépendance), protection anti-injection de formules, ajout d'un nouvel endpoint d'export et câblage du bouton ExportButton côté Nuxt. À invoquer pour créer, modifier ou débugger un export Excel.
---

# Exports Excel (.xlsx)

## 1. Principe

`App\Services\ExportService::xlsx()` produit un **vrai fichier .xlsx** en assemblant à la main une archive OOXML avec `ZipArchive` (extension `ext-zip`, native, présente dans `docker/php/Dockerfile`). **Aucune dépendance composer** pour l'écriture.

> `openspout/openspout` est installé, mais **uniquement pour la LECTURE** (import de familles). Ne pas l'utiliser pour écrire un export : le format maison est stylé, testé, et sans risque de régression de lock.

## 2. API

```php
use App\Services\ExportService;

return ExportService::xlsx(
    'nom_de_base',                       // sanitizé : [^A-Za-z0-9_-] → _ , suffixé .xlsx
    ['Colonne A', 'Colonne B'],          // en-têtes
    $rows                                // iterable de tableaux (array ou Collection)
);
```
Retourne un `BinaryFileResponse` : `response()->download(...)->deleteFileAfterSend(true)` + `Content-Type` OOXML + `X-Content-Type-Options: nosniff`.

Parties générées : `[Content_Types].xml`, `_rels/.rels`, `xl/workbook.xml`, `xl/_rels/workbook.xml.rels`, `xl/styles.xml`, `xl/worksheets/sheet1.xml`.

Mise en forme automatique :
- ligne 1 figée (`pane ySplit="1"`) + **autofilter** sur la ligne d'en-tête ;
- en-tête sur fond `#222222`, texte blanc gras, hauteur 24 ;
- lignes alternées (`#F6F8FB`) + bordure basse `#E6EFF5` ;
- largeur de colonne calculée sur le contenu le plus long, bornée à `[9, 55]`.

## 3. Sécurité — anti-injection de formule

```php
is_int($value) || is_float($value)  →  <c><v>123</v></c>          // cellule numérique
sinon                               →  <c t="inlineStr"><is><t>…</t></is></c>
```
Le texte passe **toujours** par `inlineStr`, jamais par `<f>` : une valeur `=cmd|…` reste du **texte**, elle n'est pas interprétée par Excel.
`escape()` retire les caractères de contrôle (`\x00-\x08\x0B\x0C\x0E-\x1F`) puis applique `htmlspecialchars(ENT_QUOTES|ENT_XML1)`.

⚠ Si tu passes un montant en `string`, il sera écrit en texte (aligné à gauche, non sommable). **Caste tes nombres en `int`/`float`.**

## 4. Endpoints d'export existants

| Route | Contrôleur | Contenu |
|---|---|---|
| `GET /api/admin/classrooms/export` | `ClassroomController::exportClassrooms` | Cursus, Niveau, Classe, Genre, Effectif, Capacité, Professeur(s), Créneaux |
| `GET /api/families/export` | `FamilyController::exportStudents` | 1 ligne/élève : Nom, Prénom, Naissance, Classes actives, Responsable(s), Email, Tél, Adresse, CP, Ville |
| `GET /api/statistics/export-payments` | `StatisticsController::exportPayments` | colonnes variables selon `?payment_type=cheque\|exoneration\|(vide)` |
| `GET /api/statistics/export-unpaid-families` | `StatisticsController::exportUnpaidFamilies` | Responsable(s), Email, Tél, Nb élèves, Attendu, Payé, Reste, Taux |
| `GET /api/families/import-template` | `FamilyImportController::template` | modèle d'import prérempli (voir skill `import-familles`) |

**Tous sont `checkrole:director,admin`** et scopés `currentSchoolId()`.

## 5. Ajouter un export — recette

### Backend
```php
public function exportMachins(Request $request)
{
    $schoolId = currentSchoolId();

    // Réutiliser le MÊME helper que la liste paginée pour garantir des filtres
    // identiques entre ce qu'on voit à l'écran et ce qu'on exporte.
    $items = $this->buildMachinsQuery($request, $schoolId)->get();

    $headers = ['Colonne A', 'Colonne B', 'Montant (€)'];
    $rows = $items->map(fn ($m) => [$m->a, $m->b, (int) $m->montant]);

    return ExportService::xlsx('machins', $headers, $rows);
}
```
- Route dans le groupe `checkrole:director,admin` approprié.
- Charger les relations en amont (`with`, `withCount`) : un export non paginé amplifie tout N+1.
- Batcher les rôles/contacts via `UserRole::whereIn(...)->groupBy('roleable_id')` (cf. `formatPaymentLignes`).

### Front
```js
// services/xxx.js
async exportMachins(params = {}) {
  const response = await apiClient.get('/api/machins/export', { params, responseType: 'blob' })
  return response.data
}
```
```vue
<ExportButton :loading="exporting" @click="doExport" />
```
```js
import { saveExport } from '~/utils/download'
const exporting = ref(false)
const doExport = async () => {
  if (exporting.value) return
  exporting.value = true
  try {
    saveExport(await xxxService.exportMachins(filtres), 'machins')   // → machins_YYYY-MM-DD.xlsx
  } catch (e) {
    useFlashMessage().setFlashMessage({ type: 'error', message: 'Échec de l\'export' })
  } finally { exporting.value = false }
}
```
`responseType: 'blob'` passe par `apiClient` → token + `X-School-Id` + `X-School-Year-Id` injectés automatiquement.
`saveExport(blob, base)` suffixe `_YYYY-MM-DD.xlsx` ; `saveBlob(blob, nom)` pour un nom complet (PDF).

### ⚠ Gating du bouton
Les exports sont **director/admin only** côté serveur. Sur une page déjà protégée par `middleware: 'admin-director'` (`/classes`, `/statistiques/*`) le bouton peut être affiché tel quel.
Sur une page **non gardée** (`/family` est accessible au registar et aux responsables), il **doit** être masqué :
```js
canExport.value = !!storedUser?.is_super_admin
    || hasAnyRole(readActiveSchoolRoles(), ['director', 'admin'])
```
```vue
<ExportButton v-if="canExport" … />
```

## 6. Le composant `ExportButton.vue`

```vue
<button class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-default
               bg-white border border-gray-300 rounded-lg hover:bg-gray-50 …">
  <img src="/excel.png" class="w-4 h-4 object-contain shrink-0" /> Exporter
</button>
```
Props : `loading`, `label`. Émet `@click`.

**Décision de design verrouillée** : bouton **clair**, vrai logo Excel officiel (`public/excel.png`) posé **directement**, sans pastille ni cadre. Un logo coloré doit vivre sur fond clair.
Rejetés définitivement : SVG maison, bouton vert, logo dans une pastille blanche (effet « rustine »).

## 7. Débugger un .xlsx corrompu

Excel refuse d'ouvrir → l'XML est mal formé. Vérifier dans l'ordre :
```bash
unzip -o /tmp/export.xlsx -d /tmp/x && xmllint --noout /tmp/x/xl/worksheets/sheet1.xml
```
- caractère de contrôle non filtré ;
- valeur numérique `NAN`/`INF` (`is_finite()` est déjà testé) ;
- décimale écrite avec une virgule → tout nombre d'opérateur doit passer par `sprintf('%.2F', …)`, **jamais `%f`** (locale-dépendant) ;
- nombre de colonnes incohérent entre `$headers` et une ligne.

---

**Voir aussi** : `statistiques` · `familles-eleves` · `classes-cursus` · `front-services-api` (blobs)
