---
name: import-familles
description: Import en masse de familles, élèves et responsables depuis un fichier .xlsx/.csv — format des 20 colonnes, App\Services\FamilyImportService (openspout, validation tout-ou-rien), ProcessFamilyImportJob asynchrone, suivi FamilyImport avec polling, et composant StudentImport.vue. À invoquer pour modifier le format d'import, la validation, ou débugger un import échoué.
---

# Import de familles / élèves

## 1. Vue d'ensemble du flux

```
/settings → onglet « Import élèves » (StudentImport.vue)
  1. bouton « Télécharger le modèle »  → GET /api/families/import-template   (xlsx prérempli)
  2. drag & drop d'un .xlsx ou .csv    → POST /api/families/import            → 202 + {id, status:'pending'}
       fichier stocké sur le disque `local` sous family-imports/<uuid>.<ext>
       ligne FamilyImport créée, ProcessFamilyImportJob dispatché
  3. polling toutes les 2 s            → GET /api/families/imports/{id}
       jusqu'à status ∈ {completed, failed}
  4. rendu du rapport : résumé, ou tableau d'erreurs (ligne / cellule / colonne / valeur / message)
```
Les 4 routes sont sous `families` + `checkrole:director,admin` (+ `school` + `schoolyear`).

## 2. Format du fichier — 20 colonnes, 1 ligne = 1 élève

| # | Colonne | Obligatoire |
|---|---|---|
| A | Référence famille | **oui** — regroupe la fratrie (ex. `FAM001`) |
| B–E | Nom élève, Prénom élève, Date de naissance, Genre | **oui** |
| F–L | Responsable 1 : Nom, Prénom, Email, Téléphone, Adresse, Code postal, Ville | **oui, sur CHAQUE ligne** |
| M–S | Responsable 2 : mêmes colonnes | optionnel — **mais si une seule est remplie, toutes deviennent obligatoires** |
| T | Élève est son propre responsable | `Oui`/`Non` |

- **Ligne 1 = en-têtes**, validées de façon souple : contient « reference » (A), « naissance » (D), « genre » (E), et ≥ 12 colonnes.
- Dates acceptées : `Y-m-d`, `d/m/Y`, `d-m-Y`, `Y/m/d` (validation stricte : `createFromFormat('!'.$format)` + `getLastErrors()` sans warning).
- Genre accepté : `M/masculin/garcon/h/homme` → `M` ; `F/feminin/fille/femme` → `F` (insensible casse/accents).
- « Oui » accepté : `OUI, O, YES, Y, TRUE, VRAI, 1, X`.
- Le délimiteur CSV est **sniffé** sur la 1re ligne (`;` si ≥ autant que `,`), encodage forcé UTF-8. Seule la **première feuille** est lue.

Limites : **5000 lignes de données**, **200 erreurs retournées** (`errors_truncated` + `error_count` indiquent le dépassement).

⚠ **Taille de fichier : 10 Mo annoncés, 1 Mo réellement acceptés.** La validation Laravel dit `max:10240`, mais nginx ne définit **aucun `client_max_body_size`** (défaut **1 Mo**) et PHP garde ses défauts (`upload_max_filesize = 2M`). Un fichier plus gros renvoie un **413 en HTML nginx**, que le front ne sait pas interpréter. Pour vraiment autoriser 10 Mo : `client_max_body_size 12m;` dans `docker/nginx/{dev,prod}.conf.template` **et** un `php.ini` cohérent dans l'image PHP.
En pratique, 1 Mo de CSV représente déjà plusieurs milliers de lignes — la limite de 5000 lignes est atteinte avant.

## 3. Stratégie : TOUT OU RIEN

Si **une seule** ligne est invalide → **aucune écriture**, et le rapport liste toutes les erreurs (triées par ligne puis cellule) avec `{row, colonne, cell (ex. "D7"), valeur, message}`.

Trois passes successives, chacune bloquante :
1. `readRows()` — lecture, normalisation des cellules (dates → `Y-m-d`, floats entiers → int pour éviter `75011.0`, `trim`).
2. `buildFamilies()` — regroupement par référence + validation champ par champ + **anti-doublon intra-fichier** (même nom+prénom+naissance dans la même référence famille).
3. `validateNoExistingStudents()` — refuse si un élève **existe déjà en base** (même `first_name` + `last_name` + `birthdate`). Le fichier n'ayant pas d'identifiant stable, c'est le garde-fou contre le double import.

Puis `persist()` dans **une seule `DB::transaction`**.

## 4. Ce que l'import crée

```php
Family::create()                                   // school_id posé par le trait
  → responsables : User existant réutilisé par email, sinon créé
       nouveau  → setInfo()          écrase (updateOrCreate)
       existant → setInfoIfMissing() n'écrase JAMAIS les infos existantes
  → élèves : User créé avec un email synthétique
       prenom.nom.student.<uniqid>@school.com
  → UserRole(responsible|student, roleable=family)
```

Cas **« l'élève est son propre responsable »** (colonne T = Oui) : **un seul `User`, deux rôles** (`responsible` + `student`) ; identité prise du Responsable 1, complétée par la date de naissance et le genre de l'élève.

Tous les comptes créés reçoivent **le même hash de mot de passe aléatoire** (`Hash::make(str()->random(32))` calculé une fois par import) : personne ne peut s'y connecter, et on économise N bcrypt.

Résumé retourné : `{families, students, responsibles_created, responsibles_reused}`.

## 5. Le job — `ProcessFamilyImportJob`

`timeout = 300 s`.

⚠ **Le `retry_after` de la queue vaut 90 s** (`config/queue.php`, non surchargé dans le `.env`) : un import qui dépasse 90 secondes est **relancé en parallèle** par un autre worker (8 en prod). La seconde exécution échoue alors sur « Cet élève existe déjà » et **écrase le statut en `failed`** alors que l'import a réussi. Fix : `DB_QUEUE_RETRY_AFTER=600`. Voir `bugs-connus` (A9). Il **réinjecte le contexte** école/année depuis la ligne `FamilyImport` (obligatoire, sinon les global scopes fail-closed) et le restaure en `finally`.

Statuts : `pending → processing → completed | failed`.
Le fichier uploadé est **supprimé dans le `finally`**, quel qu'en soit l'issue.
En cas d'exception, le message technique n'est exposé que si `config('app.debug')`.

⚠ **Le worker doit tourner** : `QUEUE_CONNECTION=database`. En dev, sans worker, l'import reste bloqué en `pending`.
```bash
docker exec api_dev_toollab php artisan queue:work --once      # dépiler un job
docker exec -d api_dev_toollab php artisan queue:listen         # worker continu
```
En prod, supervisord lance 8 workers `queue:listen`.

⚠ **Permissions** : le pool php-fpm de prod tourne sous `$CURRENT_USER` (et non `www-data`) précisément pour que le fichier uploadé par la requête web soit lisible **et supprimable** par le worker. Ne pas remettre `user = www-data` dans `php-fpm.d/www.conf`.

**Où est physiquement le fichier ?** Le disque `local` pointe sur **`storage_path('app/private')`** (défaut Laravel 11) :
```bash
docker exec api_dev_toollab ls -la storage/app/private/family-imports/
```
Il est donc **hors du web root** (bien) et **supprimé dans le `finally`** du job — s'il en reste, c'est que le worker n'a jamais tourné.

## 6. Front — `components/settings/StudentImport.vue`

- Zone drag & drop + `<input type="file">` masqué ; extensions acceptées `xlsx`, `csv`.
- `familyService.importStudents(file)` envoie un `FormData` avec **`headers: { 'Content-Type': undefined }`** — indispensable pour qu'axios pose lui-même le boundary multipart.
- `waitForImportResult(id)` : `setTimeout` récursif de 2 s, `stopPolling()` sur `onBeforeUnmount`.
- **Rapport d'erreurs en tableau** : `cell` (ex. `D7`, en `font-mono`), `colonne` (libellé FR), `valeur` — avec trois rendus distincts : la valeur en `font-mono` si présente, **« (vide) » en italique si la chaîne est vide**, « — » si `null` — puis le `message`. Mention explicite si `errors_truncated`.
- **Rapport de succès** : « N famille(s) », « N élève(s) », « N responsable(s) créé(s) », et « N responsable(s) existant(s) réutilisé(s) » (affiché seulement si > 0).

La distinction « (vide) » / « — » vient directement du service : `addError()` passe `''` pour un champ vide et `null` quand la valeur n'est pas pertinente. **Conserver cette sémantique** si tu ajoutes une erreur.

## 6 bis. ✅ C'est la partie la mieux testée du projet — 13 tests

`tests/Feature/FamilyImportServiceTest.php` verrouille : fratrie + 2 responsables, famille de 5, élève majeur son propre responsable (1 `User`, 2 rôles), genre invalide, date invalide, responsable 1 manquant, réutilisation d'un responsable par email, **non-écrasement** des infos d'un responsable existant, élève déjà présent, fichier trop volumineux, **tout-ou-rien**, et les deux chemins du job (succès / échec sans doublon).

**Toute modification de `FamilyImportService` doit relancer cette suite** — c'est le seul filet du projet :
```bash
docker exec api_dev_toollab ./vendor/bin/pest tests/Feature/FamilyImportServiceTest.php
```
(La base `testing` doit exister sur MariaDB — voir skill `tests`.)

## 7. Modifier le format

Les **trois** endroits doivent rester synchronisés :
1. `FamilyImportController::HEADERS` (en-têtes du modèle téléchargeable) ;
2. `FamilyImportController::template()` (lignes d'exemple : famille de 5 avec 2 responsables, famille avec 1 responsable, cas « élève = son propre responsable ») ;
3. `FamilyImportService::COLS` (index 0-based → libellé affiché dans les erreurs) + les offsets de `parseResponsible()` (bases **5** et **12**) + `validateHeader()`.

Ajouter une colonne au milieu **décale tous les index** : vérifie `buildFamilies()`, `parseResponsible($c, $line, 5|12, …)`, `hasAny($c, range(12,18))` et l'index `19` de la colonne « propre responsable ».

## 8. Débugger

```bash
docker exec api_dev_toollab php artisan tinker --execute="
  echo \App\Models\FamilyImport::latest()->first()?->toJson(JSON_PRETTY_PRINT);
"
docker logs -f api_dev_toollab | grep FamilyImport
docker exec api_dev_toollab php artisan queue:failed
```
Symptômes fréquents :
- bloqué en `pending` → aucun worker actif ;
- « Le fichier n'a pas pu être lu » → xlsx corrompu ou ancien format `.xls` ; le message technique est ajouté si `APP_DEBUG=true` ;
- « Cet élève existe déjà » → import rejoué ; c'est le garde-fou anti-doublon, pas un bug ;
- toutes les lignes en erreur sur la colonne A → en-têtes modifiées ou fichier sans ligne d'en-tête.

---

**Voir aussi** : `queues-jobs-notifications` (worker, retry_after) · `familles-eleves` · `exports-xlsx` (le modèle est un export) · `commandes-artisan` (imports legacy)
