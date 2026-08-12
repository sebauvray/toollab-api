---
name: conventions-code
description: Conventions d'écriture du code Toollab — politique de commentaires (quasi zéro), naming mixte FR/EN assumé, structure des contrôleurs et services PHP, style Vue/JS, organisation des fichiers et règles de suppression de code mort. À invoquer avant d'écrire du code, pour que le résultat se fonde dans le codebase existant.
---

# Conventions de code

## 1. Commentaires — la règle la plus importante

**Pas de commentaires dans le nouveau code.** Les identifiants portent l'intention.

✗ Interdits :
```php
// Récupère les familles de l'école          ← décrit ce que le code dit déjà
/** @param int $id ... @return array */      ← docblock multi-lignes
// removed old logic                          ← historique (c'est le rôle de git)
// added by X for feature Y
```

✓ Seule exception : **un one-liner** quand la raison est **non-dérivable du code** — workaround d'un bug externe, contrainte cachée, invariant subtil :
```php
// withoutGlobalScopes : currentSchoolId() n'est pas encore set ici,
// le scope fail-closed retournerait 0 rows et bloquerait l'accès.
$familyIds = Family::query()->withoutGlobalScopes()->where('school_id', $schoolId)->pluck('id');
```
```php
// Le pool php-fpm doit tourner sous le même user que les workers, sinon
// un fichier uploadé par le web n'est pas supprimable par le worker.
```
Le test : *« si je retire ce commentaire, un dev compétent perd-il une information qu'il ne peut pas retrouver dans le code ? »* Si non → le retirer.

Corollaire : les commentaires **existants** de ce type sont précieux. **Ne pas les supprimer** lors d'un refactor.

## 2. Naming — mixte FR/EN assumé

```
FR : Paiement, LignePaiement, ReductionFamiliale, ReductionMultiCursus, Tarif,
     Cursus, CursusLevel, TarifCalculatorService, PaiementService, FacturePdfService
EN : User, Family, Classroom, School, SchoolYear, Role, UserRole, Attendance,
     StudentClassroom, StudentYearOutcome, ExportService
```
**Ne renomme rien pour « harmoniser ».** Suis le style du domaine que tu touches : une nouvelle méthode sur `PaiementService` se nomme en français (`ajouterLignePaiement`), une nouvelle méthode sur `ClassroomController` en anglais (`getAdminClassrooms`).

Variables de contrôleur : souvent en français (`$montantTotal`, `$resteAPayer`, `$detailsParEleve`).
Clés d'API : snake_case (`student_id`, `montant_total`, `is_main_teacher`).
Front : camelCase (`montantTotal`, `isReadOnly`), sauf les clés reçues de l'API qu'on conserve telles quelles.

## 3. Contrôleurs PHP

```php
class MachinController extends Controller
{
    public function __construct(private MachinService $service) {}   // ou propriété + assignation

    public function index(Request $request) { … }
    public function store(Request $request) { … }
    …
    private function helperPrive() { … }     // helpers privés en bas
}
```
- Logique métier → **service** (`app/Services/`). Le contrôleur valide, autorise, orchestre, formate.
- Validation inline (`$request->validate`) pour les cas simples ; `FormRequest` pour les payloads riches.
- `DB::transaction` (ou `beginTransaction`/`commit`/`rollBack`) dès qu'on écrit dans ≥ 2 tables.
- Mapping vers un array **à la main** : il n'y a **pas** de `JsonResource` dans ce projet. Ne pas en introduire pour un seul endpoint.
- Le format `{status, message, data}` pour tout nouvel endpoint.

## 4. Modèles

Ordre : `use` de traits → `$table` (si non standard) → `$fillable` → `$appends` → `$hidden` → `$casts` → relations → accesseurs → méthodes métier.
Voir la skill `laravel-model-migration` pour les traits et les règles de `$fillable`.

## 5. Vue / JS

```vue
<script setup>          // TOUJOURS, jamais l'Options API
import { ref, computed, onMounted } from 'vue'
// 1. imports composants   2. imports composables/services   3. definePageMeta/usePageTitle
// 4. état (ref)   5. computed   6. fonctions   7. lifecycle
</script>

<template> … </template>

<style scoped> … </style>   <!-- uniquement pour ce que Tailwind ne peut pas faire -->
```
- `const` par défaut, `let` seulement si réassignation.
- Fonctions fléchées pour les handlers ; `async/await`, pas de chaînes `.then()`.
- Pas de TypeScript applicatif (le `tsconfig.json` est celui généré par Nuxt).
- `defineProps` / `defineEmits` explicites, avec types et valeurs par défaut.
- `process.client` autour de tout accès `localStorage` / `window`.
- Nettoyage dans `onUnmounted` (timers, listeners, polling).

## 6. Organisation des fichiers

```
API
  app/Models/            un modèle par fichier
  app/Http/Controllers/Api/   un contrôleur par domaine
  app/Http/Requests/     FormRequests
  app/Services/          logique métier réutilisable
  app/Jobs/ Notifications/ Observers/ Traits/ Support/ Console/Commands/
  routes/api.php         source de vérité des endpoints

FRONT
  pages/                 routing par fichier
  components/{form,modals,table,navigation,settings,layout,schedule,ui,Icons}/
  composables/           auto-importés
  services/              un fichier par domaine, export default {}
  utils/                 helpers purs (dateFormatter, download, errors, schoolRoles, …)
  middleware/            auth.global.js + middlewares nommés
```

## 7. Code mort

Quand tu croises du code mort listé dans la skill `bugs-connus` **et que tu travailles dans ce fichier** : supprime-le plutôt que de le contourner. Ne lance pas de campagne de nettoyage globale non demandée.

Cas particuliers à **ne pas** supprimer :
- (⚠ `components/UserDropdown.vue` **n'est plus** dans ce cas : il est bel et bien mort depuis que `layouts/admin.vue` a son menu compte inline) ;
- les commentaires « pourquoi » (§1) ;
- les fallbacks legacy (`teacher_name`, `details->emetteur`, `roleable_type` FQCN, `current_school_role`).

## 8. Git

- **Ne jamais commiter ni pousser sans demande explicite.**
- Messages en français, style conventionnel observé : `fix(compta): …`, `feat(roles): …`, `chore(emails): …` (mais des messages libres existent aussi — rester lisible).
- Les deux dépôts sont **distincts** : un changement full-stack = deux commits, dans deux repos.

## 9. Formatage

`./vendor/bin/pint` (preset Laravel par défaut, aucune config custom). Le codebase n'est **pas** intégralement formaté : lancer Pint sur tout le projet produirait un diff illisible. **Formate uniquement les fichiers que tu as touchés** :
```bash
docker exec api_dev_toollab ./vendor/bin/pint app/Http/Controllers/Api/MachinController.php
```
Côté front, aucun Prettier/ESLint configuré : imiter le style du fichier voisin (indentation 4 espaces dans les services et les pages anciennes, 2 espaces dans les composants récents — **suivre le fichier**).

## 10. Langue

- **UI, messages d'erreur, e-mails, libellés : français**, avec accents corrects (jamais « eleve » pour « élève »).
- Noms de code : voir §2.
- Commentaires (les rares) : français.
- Réponses à l'utilisateur : français.

⚠ **Aucun fichier de langue n'est publié** (`lang/` n'existe pas) et `APP_LOCALE=en`. Les messages de validation Laravel sortent donc **en anglais** s'ils ne sont pas surchargés. C'est pourquoi 7 des 9 FormRequests ont un long `messages()` FR écrit à la main. **Toute nouvelle règle exposée à l'utilisateur doit avoir son message français.**

---

**Voir aussi** : `workflow-livraison` · `api-endpoint` · `nuxt-page` · `bugs-connus` (code mort à supprimer)
