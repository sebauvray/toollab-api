---
name: glossaire-metier
description: Vocabulaire métier de Toollab et sa traduction en objets du code — termes français employés par l'utilisateur (cursus, niveau, créneau, émargement, décision, exonération, reconduction, registar…) associés aux modèles, tables, endpoints et écrans correspondants. À invoquer quand une demande utilise un terme métier dont la correspondance technique n'est pas évidente.
---

# Glossaire métier → code

L'utilisateur parle le vocabulaire des instituts d'enseignement français. Cette table traduit chaque terme en objets du code.

## Structure de l'établissement

| Terme | Code | Notes |
|---|---|---|
| **Établissement**, école, institut | `School` / `schools` | le tenant |
| **Année scolaire** | `SchoolYear` / `school_years` | 1 active par école ; `label` type `2026-2027` |
| **Année active** | `is_active = true` | seule année où l'on peut écrire |
| **Année archivée / clôturée** | `is_read_only` (`!is_active \|\| closed_at`) | écriture → **409** |
| **Cursus**, filière, matière | `Cursus` / `cursus` | **permanent**, traverse les années |
| **Progression par niveaux** | `progression = 'levels'` | génère des `CursusLevel` |
| **Cursus continu** | `progression = 'continu'` | pas de niveaux ; **interdit passage/redoublement** |
| **Niveau**, année (« 1ère année ») | `CursusLevel` / `cursus_levels` | `name` + `order` |
| **Classe**, groupe | `Classroom` / `classrooms` | **annuelle** |
| **Capacité**, effectif max | `classrooms.size` | VARCHAR en base |
| **Effectif** | `student_count` / `active_students_count` | inscriptions `active` |
| **Genre de la classe** | `gender` ∈ Hommes/Femmes/Enfants/Mixte | pilote les couleurs d'accent |
| **Créneau**, horaire, séance hebdo | `ClassSchedule` / `class_schedules` | jour + heures + prof |
| **Lien du groupe de classe** | `classrooms.telegram_link` | ⚠ **ne jamais l'appeler « Telegram » en UI** |
| **Reconduction** | `POST /classrooms/{id}/reconduct` | clone une classe vers l'année active, **sans élèves** |

## Personnes

| Terme | Code | Notes |
|---|---|---|
| **Directeur** | rôle slug `director`, contexte `school` | |
| **Administrateur** | slug `admin` | |
| **Responsable des inscriptions**, « registar », secrétariat | slug **`registar`** (sans « r ») | staff opérationnel |
| **Professeur**, enseignant | slug `teacher`, contexte **school** | lien classe via `class_schedules.teacher_id` |
| **Professeur principal** | `classrooms.main_teacher_id` | **seul à pouvoir saisir les décisions** |
| **Responsable (légal)**, parent | slug `responsible`, contexte `family` | |
| **Élève**, étudiant | slug `student`, contextes `family` **et** `classroom` | c'est un `User` |
| **Famille**, foyer | `Family` / `families` | coquille sans nom propre |
| **Super-admin**, plateforme | `is_super_admin` (dérivé de l'email) | espace `/admin` |
| **Invitation en attente** | `user_roles.accepted_at IS NULL` | nom masqué côté école |

## Inscription & scolarité

| Terme | Code | Notes |
|---|---|---|
| **Inscription** (élève ↔ classe) | `StudentClassroom` / `student_classrooms` | statut `active` |
| **Inscrire / désinscrire** | `POST /student-classrooms/enroll` / `unenroll` | pattern **replace** par cursus+type |
| **Émargement**, appel, présences, pointage | `Attendance` / `attendances` | 1 ligne par élève / classe / **date** |
| **Séance** | une **date** d'émargement | pas de FK vers le créneau |
| **Absent justifié / non justifié** | `absent_justifie` / `absent_non_justifie` | + `justification` (motif) |
| **Motif** (d'absence) | `attendances.justification` | popover ancré côté prof |
| **Décision de fin d'année** | `StudentYearOutcome` | portée par la **classe** |
| **Passage / Redoublement / Exclusion / Fin de cursus** | `passage` / `redoublement` / `exclusion` / `fin_cursus` | |
| **Ouvrir la saisie des décisions** | `school_years.outcomes_open` | toggle directeur, depuis `/decisions` |
| **Clôturer l'année** | `POST /school-years/{id}/close` | rend l'année lecture seule |

## Argent

| Terme | Code | Notes |
|---|---|---|
| **Tarif**, prix, cotisation | `Tarif` / `tarifs` (`prix` en **euros**, integer) | annuel, par cursus |
| **Réduction familiale**, fratrie | `ReductionFamiliale` | paliers `nombre_eleves_min` |
| **Réduction multi-cursus** | `ReductionMultiCursus` | bénéficiaire ← requis |
| **Attendu**, montant dû, total annuel | `montant_total` (`TarifCalculatorService`) | |
| **Règlement**, paiement | `LignePaiement` / `lignes_paiement` | espèce / carte / chèque / exonération |
| **Encaissé** | `montant_encaisse` | trésorerie réelle, **hors exonérations** |
| **Soldé / payé** | `montant_paye` | encaissé **+** exonéré |
| **Exonération** | type `exoneration` + `details.justification` | **remise**, pas un règlement |
| **Reste à payer**, impayé | `reste_a_payer` | `attendu − soldé` |
| **Facture** | `GET /families/{id}/paiements/facture` | PDF, numéro déterministe `F{year}-{family:0000}` |
| **Acquittée** | `reste === 0` | bande verte sur la facture |
| **Taux d'encaissement / de recouvrement** | `collection_rate` / `recovery_rate` | encaissé vs encaissé+exonéré |

## Statuts de règlement d'une famille (`Tag.vue`)

| Affiché | Valeur |
|---|---|
| Aucune inscription | `no_enrollment` |
| Exonéré | `exempted` (montant total = 0) |
| Incomplet | `incomplete` (rien payé) |
| Partiellement payé | `pending` |
| Payé | `paid` |

## Écrans (langage utilisateur → route)

| L'utilisateur dit | Route |
|---|---|
| « la liste des familles » | `/family` |
| « la fiche famille » | `/family/[id]` |
| « le choix des classes » | `/family/[id]/classes` |
| « la page paiement / règlements » | `/family/[id]/paiement` |
| « les classes » | `/classes` (grille ou liste) |
| « le suivi de classe », « l'émargement admin » | `/classes/[id]` |
| « la vue d'ensemble des décisions » | `/decisions` |
| « mes classes » (prof) | `/professeur/classes` |
| « mon planning » | `/professeur/planning` |
| « les tarifs » | `/tarification` |
| « les stats », « les impayées », « les chèques » | `/statistiques*` |
| « les paramètres », « mon établissement », « les utilisateurs » | `/settings` (onglets) |
| « les années scolaires » | `/annees-scolaires` |
| « l'admin », « la plateforme » | `/admin*` |

## Pièges de vocabulaire

- **« registar »** s'écrit sans deuxième « r » dans le code (`registar`, pas `registrar`).
- **« cursus »** est invariable et la table est au **singulier** (`cursus`).
- **`lignes_paiement`** : pluriel au premier mot, singulier au second.
- **« Telegram »** n'est jamais affiché : le libellé UI est « Lien du groupe de classe ».
- **« niveau »** = `CursusLevel` (structure), à ne pas confondre avec le `level` affiché dans les listes de décisions (le **nom** du niveau).
- **« séance »** = une date d'émargement, pas un créneau hebdomadaire.

---

**Voir aussi** : `parcours-utilisateur` · `db-schema` · `roles-permissions`
