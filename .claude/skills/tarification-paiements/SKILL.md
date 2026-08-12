---
name: tarification-paiements
description: Moteur de tarification Toollab (tarif de base, réduction familiale, réduction multi-cursus, snapshot figé à l'inscription) et gestion des paiements (Paiement/LignePaiement, types espece/carte/cheque/exoneration, garde-fou anti-dépassement, distinction encaissé vs exonéré, statuts de règlement). À invoquer pour toute question ou modification touchant aux montants, réductions, règlements ou statuts de paiement.
---

# Tarification & paiements

## 1. Le calcul — `App\Services\TarifCalculatorService::calculerTotalFamille()`

```php
calculerTotalFamille(Family $family, array $inscriptionsData = null): array
```
- `$inscriptionsData = null` (cas normal) → lit les inscriptions **actives** de la famille + charge les **snapshots**.
- `$inscriptionsData` fourni (simulation depuis `POST /api/tarification/calculer`) → calcul **live**, sans snapshot.

### Algorithme, par élève et par cursus

1. **Prix de base** = `Tarif::prix` du cursus (integer, exprimé en **euros**, pas en centimes malgré la migration decimal→int).
2. **Réduction familiale** : compter les élèves de la famille inscrits **dans ce cursus** ; prendre le palier `ReductionFamiliale` de `nombre_eleves_min` le plus élevé tel que `nombre_eleves_min <= N`. Aucune réduction si `N <= 1`.
3. **Réduction multi-cursus** : si l'élève est inscrit à un autre cursus, chercher une `ReductionMultiCursus(cursus_beneficiaire = ce cursus, cursus_requis ∈ autres cursus de l'élève)`. En cas de plusieurs matches → la plus élevée.
4. **`$reduction = max($familiale, $multiCursus)`** — **JAMAIS la somme**. C'est la règle métier la plus souvent mal réimplémentée.
5. `tarif_final = round($base * (1 - $reduction/100), 0, PHP_ROUND_HALF_UP)`.

### Retour

```php
[
  'total' => int, 'total_famille' => int,          // identiques (legacy)
  'details_par_eleve' => [[
      'student_id', 'student_name',
      'cursus' => [[ 'cursus_id','cursus_name','tarif_base',
                     'reduction_familiale','reduction_multi_cursus','reduction_appliquee',
                     'tarif_final','from_snapshot' ]]
  ]],
  'nombre_eleves' => int,                          // nb d'élèves AYANT une inscription
  'nom_famille' => string,                         // 1er responsable, ou 'Sans responsable'
  'id_famille' => int,
]
```

## 2. Le snapshot — pourquoi modifier un tarif ne change pas les anciennes inscriptions

À chaque `enroll`, `StudentClassroomController::buildTarifSnapshot()` fige dans `student_classrooms.tarif_snapshot` (JSON) :

```json
{
  "cursus_id": 3, "school_year_id": 2, "tarif_base": 270,
  "reductions_familiales": [{"nombre_eleves_min": 3, "pourcentage_reduction": 11.11}],
  "reductions_multi_cursus": [{"cursus_requis_id": 4, "pourcentage_reduction": 50}],
  "snapshotted_at": "2026-…"
}
```

`loadSnapshotsForFamily()` construit un index `[student_id][cursus_id]` ; s'il existe, le calcul utilise le snapshot (`getReductionFamilialeFromSnapshot`, `getReductionMultiCursusFromSnapshot`), sinon il retombe sur le calcul live.

Conséquences :
- Le **prix promis à la famille est figé** au moment de l'inscription.
- Le **nombre d'élèves** (donc le palier de réduction familiale applicable) reste calculé **dynamiquement** : inscrire un 3ᵉ enfant fait bien baisser le tarif des deux premiers, car seuls les *paliers* sont snapshotés, pas le résultat.
- Une inscription antérieure à la feature (snapshot null) → calcul live, comportement d'avant.

**Ne jamais « recalculer » un snapshot rétroactivement** sans demande explicite : c'est modifier un engagement commercial.

## 3. Modèle de données paiement

```
Paiement (1 par famille PAR ANNÉE, UNIQUE(family_id, school_year_id), BelongsToSchoolYear)
└──< LignePaiement (table `lignes_paiement`, TrackChangesTrait, PAS de scope propre)
       type_paiement ∈ espece | carte | cheque | exoneration
       montant INT
       details JSON :
         cheque      → {banque, numero, nom_emetteur}
         exoneration → {justification}
         (legacy prod possible : emetteur, motif → fallbacks conservés dans les lectures)
```

`LignePaiement` n'a pas de global scope, mais toute requête passe par `whereHas('paiement', …)` → **les scopes de `Paiement` (année) s'appliquent**. Il n'y a donc **pas** de bug de scope année sur les montants payés.

## 4. `PaiementService::getDetailsPaiement($family)` — la source unique

```php
[
  'paiement' => ?Paiement (avec ->lignes),
  'montant_total'     => int,   // = TarifCalculator total
  'montant_paye'      => int,   // encaissé + exonéré → sert au reste à payer
  'montant_encaisse'  => int,   // trésorerie RÉELLE (hors exonérations)
  'montant_exonere'   => int,
  'reste_a_payer'     => int,   // montant_total - montant_paye (peut être négatif)
  'details' => ['espece'=>, 'carte'=>, 'cheque'=>, 'exoneration'=>, 'cheques'=>[…]],
  'tarifs'  => (retour complet du TarifCalculator),
]
```

**Distinction comptable à ne jamais confondre** (fixée en juin 2026) :
- **`montant_paye`** = ce qui solde la dette (encaissé + exonéré). Base du reste à payer, de la progression et du statut.
- **`montant_encaisse`** = argent réellement reçu. Base des indicateurs de trésorerie et du « Montant réglé » de la facture.
- Une exonération est une **remise**, pas un règlement.

Idem côté stats (`StatisticsController::getPaymentStats`) :
`collection_rate` = encaissé/attendu · `recovery_rate` = (encaissé+exonéré)/attendu · `payment_rate` = alias de `recovery_rate` (conservé pour compat front).

## 5. Garde-fou anti-dépassement

`PaiementController::ajouterLigne` et `modifierLigne` refusent en **422** avec
`« Le montant total payé ne peut pas dépasser le montant dû »` si le nouveau total dépasse `montant_total`.

`ajouterLigne` s'exécute dans une `DB::transaction` avec `Family::lockForUpdate()` pour sérialiser les ajouts concurrents sur la même famille.

⚠ **Limite du pattern** : le verrou porte sur la ligne `families`, mais le contrôle de dépassement lit ensuite `lignes_paiement` par une **lecture cohérente** (snapshot REPEATABLE READ, l'isolation par défaut de MariaDB). La sérialisation fonctionne, mais le montant lu peut théoriquement ne pas refléter une ligne insérée et commitée par la transaction concurrente. Le même schéma existe dans `StudentClassroomController::enroll` (`Classroom::lockForUpdate()` puis `isFull()` qui fait un `COUNT` non verrouillé).
En pratique le risque est faible (deux saisies simultanées sur la même famille / la même classe pleine), mais si tu durcis ce point : ajouter `->lockForUpdate()` sur la requête d'agrégat, ou porter la contrainte en base.

⚠ **Bug connu, multi-chèques** : `pages/family/[id]/paiement.vue::addNewLigne` boucle et appelle l'API **une fois par chèque**. Si le lot dépasse le dû, les premiers chèques sont déjà enregistrés quand le 422 arrive → **état partiel, pas de rollback**. Correctif propre = un endpoint batch transactionnel.

Côté front, le 422 est **reformulé par type** (le message serveur est illogique pour une exonération) et affiché **uniquement** dans le bandeau rouge `fieldErrors.api` — jamais doublé d'un message au niveau du champ.

## 6. Notification de solde

Après chaque ajout/modif/suppression :
```php
CheckPaymentCompletionJob::dispatch($family, $previousResteAPayer)->afterCommit();
```
Le job notifie les responsables **uniquement à la transition** « due > 0 » → « due == 0 » (idempotence grâce à `$previousResteAPayer`). Il réinjecte le contexte école/année (voir skill `multi-tenant-scoping`) et dédoublonne les responsables par email.

## 7. Statuts de règlement d'une famille

`FamilyController::calculatePaymentStatus()` — valeurs rendues par `components/Tag.vue` :

| statut | condition | libellé UI |
|---|---|---|
| `no_enrollment` | `active_inscriptions_count == 0` | Aucune inscription |
| `exempted` | `montant_total == 0` | Exonéré |
| `paid` | `reste_a_payer <= 0` | Payé |
| `incomplete` | `montant_paye == 0` | Incomplet |
| `pending` | sinon (partiel) | Partiellement payé |

⚠ Ce statut est **calculé en PHP**, donc trier ou filtrer par statut dans `FamilyController::index` bascule la pagination **en mémoire** (toutes les familles sont calculées puis tranchées). Assumé, mais c'est un point de charge.

Ordre de tri métier appliqué : `no_enrollment < exempted < incomplete < pending < paid`.

## 8. Tarification — endpoints d'administration

`checkrole:director,admin`, tous sous `schoolyear` :
```
GET    /api/tarification/cursus                                   liste cursus + tarif + réductions
POST   /api/tarification/cursus/{cursus}/tarif                    {prix} (integer, min 1) → updateOrCreate
POST   /api/tarification/cursus/{cursus}/reduction-familiale      {nombre_eleves_min>=2, pourcentage 0-100}
PUT    /api/tarification/reduction-familiale/{reduction}
DELETE /api/tarification/reduction-familiale/{reduction}
POST   /api/tarification/cursus/{cursus}/reduction-multi-cursus   {cursus_requis_id, pourcentage}
PUT    /api/tarification/reduction-multi-cursus/{reduction}
DELETE /api/tarification/reduction-multi-cursus/{reduction}
POST   /api/tarification/calculer                                 simulation {family_id, inscriptions[]}
```

Protections métier sur `storeReductionMultiCursus` :
- un cursus ne peut pas dépendre de lui-même (422) ;
- **détection de circularité** : si B → A existe déjà, ajouter A → B est refusé (422).

`updateTarif` fait un `Tarif::updateOrCreate(['cursus_id' => …])` : le scope année filtre le SELECT et le trait pose `school_year_id` à la création → **un tarif par cursus par année**, automatiquement.

## 8 bis. L'écran `/tarification` — saisie en montant OU en pourcentage

La base ne stocke **que** `pourcentage_reduction`. Mais un directeur raisonne en **montant cible** (« la 3ᵉ inscription est à 240 € »), pas en pourcentage.

`pages/tarification/index.vue` propose donc un **segmented control** `montant | pourcentage` (`familialeForm.mode`) avec conversion bidirectionnelle :
```js
// montant cible → pourcentage (persisté)
reduction = ((tarifBase - montantCible) / tarifBase) * 100      // arrondi à 2 décimales
// pourcentage → montant affiché
montant = Math.round(tarifBase * (1 - pourcentage / 100))
```
Un montant cible **≥ tarif de base** vide le pourcentage (saisie invalide).

C'est ce qui explique les pourcentages « bizarres » en base et dans le seed : **11,11 %** = 270 → 240 €, **22,22 %** = 270 → 210 €. Ne pas les « arrondir ».

La liste des cursus proposés pour une réduction multi-cursus est **doublement filtrée côté client** (`availableCursusesForMultiCursus`) : on exclut le cursus lui-même, ceux déjà liés, **et ceux qui dépendent déjà de celui-ci** — miroir de la détection de circularité du serveur (422).

## 8 ter. L'écran `/family/[id]/paiement` — structure exacte

Deux sections en `lg:grid-cols-3`, dans un `<div>` sans `PageContainer` (mise en page propre à la page) :

**Gauche (`lg:col-span-2`) — « Règlements »**
- En-tête : titre + bouton `bg-default` « Ajouter un règlement » (`v-if="!showNewForm && !isReadOnly"`).
- Liste `rounded-xl border divide-y` : chaque ligne = **icône SVG monochrome dans une tuile `w-6 h-6 rounded-md bg-gray-100 text-gray-500`** (`v-html="typesIcons[type]"`, contenu constant) + libellé court `text-xs text-gray-700` sur `w-28`, puis le détail (banque · n° · émetteur, ou justification) en `truncate`, puis le montant `font-montserrat font-bold tabular-nums`, puis deux boutons-icônes fantômes.
- **L'édition est INLINE dans la ligne** (`editingLineId === ligne.id` bascule le rendu), pas dans une modale : montant + champs spécifiques au type + boutons « Annuler » / « Valider ».
- Le type d'une ligne **n'est pas modifiable** — seuls le montant et les détails le sont.
- État vide : « Aucun règlement enregistré pour cette année. »

**Droite — « Paiement »**
Bandeau récap (Total annuel + chip de statut + barre de progression + Encaissé/Reste clampé à 0), détail par élève, répartition par moyen de paiement, bouton « Facture PDF ».

Rappels de design verrouillés : pas d'émoji (💵💳🧾✋ retirés), **pas de chip coloré pour les types de paiement** (jugés « horriblissimes » — icône SVG + libellé gris), pas de `bg-gradient`/`shadow-inner`, boutons en `text-xs`.

## 9. Fichiers concernés

```
app/Services/TarifCalculatorService.php        le calcul
app/Services/PaiementService.php               agrégation + CRUD lignes
app/Http/Controllers/Api/PaiementController.php    show/ajouter/modifier/supprimer/facture
app/Http/Controllers/Api/TarificationController.php
app/Http/Controllers/Api/StudentClassroomController.php   buildTarifSnapshot()
app/Jobs/CheckPaymentCompletionJob.php
toollab-front/pages/family/[id]/paiement.vue   (1127 lignes — UI règlements)
toollab-front/pages/tarification/index.vue
toollab-front/services/paiement.js, tarification.js
```

## 10. Checklist « je touche à l'argent »

- [ ] Réductions : `max()`, jamais l'addition.
- [ ] Le snapshot est-il respecté (pas de recalcul rétroactif) ?
- [ ] `montant_paye` vs `montant_encaisse` : lequel est correct ici ?
- [ ] Garde-fou dépassement toujours actif côté serveur.
- [ ] `CheckPaymentCompletionJob::dispatch(..., $previousResteAPayer)->afterCommit()` conservé.
- [ ] Les 5 statuts de `Tag.vue` restent cohérents avec `calculatePaymentStatus`.
- [ ] Les fallbacks legacy `emetteur` / `motif` du JSON `details` sont préservés en lecture.

---

**Voir aussi** : `facture-pdf` (document) · `statistiques` (agrégats) · `familles-eleves` (inscriptions) · `annees-scolaires` (portée annuelle)
