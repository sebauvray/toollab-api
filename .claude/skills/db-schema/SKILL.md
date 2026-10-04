---
name: db-schema
description: Référence complète et exacte du schéma MariaDB de Toollab — toutes les tables, colonnes, types, contraintes uniques, index, clés étrangères, plus les incohérences connues entre $fillable et colonnes réelles. À invoquer avant d'écrire une requête complexe, une migration, ou pour vérifier qu'une colonne existe vraiment.
---

# Schéma de base de données (état réel après toutes les migrations)

## Tables métier

```sql
schools (
  id, name, email?, phone?, address TEXT, zipcode?, city?, country?,
  logo?, siret VARCHAR(30)?, vat_mode VARCHAR(20)?, vat_number VARCHAR(30)?,
  access BOOL, timestamps
)
-- vat_mode ∈ association | enseignement | franchise | assujetti | null
-- access n'est vérifié NULLE PART au login (feature non implémentée)

school_years (
  id, school_id FK→schools CASCADE, label, opened_at?, closed_at?,
  is_active BOOL default false, outcomes_open BOOL default false,
  created_by?, updated_by?, timestamps
)
UNIQUE(school_id, label) · INDEX(school_id, is_active)
-- « une seule année active par école » = règle métier applicative, PAS une contrainte DB

users (
  id, first_name NULLABLE, last_name NULLABLE, email UNIQUE,
  email_verified_at?, password, access BOOL, remember_token, timestamps
)
-- first_name/last_name nullables depuis 2026_06_19_102552 (invitations sans nom)

user_infos (id, user_id FK CASCADE, key, value TEXT, timestamps)
-- key ∈ phone | address | zipcode | city | birthdate | gender  (aucune validation DB)

roles (id, name, description?, slug, timestamps)
-- ⚠ slug n'a PAS de contrainte unique (RoleSeeder est idempotent par firstOrCreate)

user_roles (
  id, role_id FK, user_id FK, roleable_type, roleable_id,
  accepted_at TIMESTAMP?, created_by?, updated_by?, timestamps
)
UNIQUE unique_user_role_context(user_id, role_id, roleable_type, roleable_id)
INDEX(user_id, roleable_type, roleable_id)
-- ⚠ user_id et role_id sont ->constrained() SANS onDelete → RESTRICT (défaut MariaDB)
-- ⚠ roleable_type/roleable_id sont un morphs() → AUCUNE FK, aucune cascade

families (id, school_id FK→schools CASCADE NULLABLE, timestamps)
-- une famille n'a AUCUN champ d'identité : son nom vient de ses responsables

comments (id, content TEXT, family_id FK, user_id FK NULL ON DELETE, timestamps)

cursus (
  id, name, progression ENUM('levels','continu') default 'levels',
  levels_count INT default 1, school_id FK CASCADE, created_by?, updated_by?, timestamps
)
-- table au singulier ($table = 'cursus'). PERMANENT : pas de school_year_id.

cursus_levels (id, cursus_id FK CASCADE, name, order INT default 0, timestamps)

classrooms (
  id, school_id FK, school_year_id FK→school_years RESTRICT **NOT NULL**,
  cursus_id FK?, level_id FK→cursus_levels?, main_teacher_id FK→users NULL ON DELETE,
  name, years INT, type VARCHAR, size VARCHAR(!), gender VARCHAR default 'Mixte',
  telegram_link TEXT?, created_by?, updated_by?, timestamps
)
-- ⚠ size est un VARCHAR en base (migration d'origine), traité comme int en PHP (cast loose)
-- gender ∈ Hommes | Femmes | Enfants | Mixte (validé côté FormRequest uniquement)
-- telegram_link : nom legacy — l'UI dit « Lien du groupe de classe », JAMAIS « Telegram »

class_schedules (
  id, classroom_id FK CASCADE, teacher_id FK→users RESTRICT (restrictOnDelete()),
  day ENUM('Lundi'…'Dimanche'), start_time TIME, end_time TIME,
  teacher_name VARCHAR? , timestamps
)
-- teacher_id supprimé en 2025_06_19 puis RÉINTRODUIT en 2026_06_01_120000
-- teacher_name = legacy texte, conservé comme fallback d'affichage

student_classrooms (
  id, student_id FK→users CASCADE, classroom_id FK CASCADE, family_id FK CASCADE,
  school_year_id FK RESTRICT **NOT NULL**, status ENUM('active','inactive','pending') default 'pending',
  enrollment_date DATE, tarif_snapshot JSON?, created_by?, timestamps
)
UNIQUE(student_id, classroom_id)
-- ⚠ PAS de colonne updated_by (migration add_updated_by_… inexistante) alors que
--   le modèle utilise TrackChangesTrait → l'événement updating tente de setter un champ absent

attendances (
  id, student_id FK CASCADE, classroom_id FK CASCADE, school_id FK CASCADE,
  school_year_id FK CASCADE, date DATE,
  status ENUM('present','absent_justifie','absent_non_justifie'),
  justification TEXT?, created_by? , updated_by?, timestamps
)
UNIQUE att_student_class_date_unique(student_id, classroom_id, date) · INDEX(classroom_id, date)

student_year_outcomes (
  id, student_id FK CASCADE, school_year_id FK CASCADE, classroom_id FK CASCADE,
  outcome ENUM('passage','redoublement','exclusion','fin_cursus'),
  commentaire TEXT?, decided_by FK→users NULL?, decided_at?, timestamps
)
UNIQUE syo_student_year_class_unique(student_id, school_year_id, classroom_id)
INDEX(school_year_id, classroom_id)
-- ⚠ le modèle ne porte AUCUN global scope → scoper school_year_id/classroom_id à la main

tarifs (id, cursus_id FK CASCADE, school_year_id FK RESTRICT NOT NULL,
        prix INT, actif BOOL default true, created_by?, updated_by?, timestamps)
-- prix : decimal(10,2) à l'origine, converti en INT par 2025_06_21_150334

reduction_familiales (id, cursus_id FK CASCADE, school_year_id FK NOT NULL,
        nombre_eleves_min INT, pourcentage_reduction DECIMAL(5,2),
        actif BOOL, created_by?, updated_by?, timestamps)

reduction_multi_cursuses (id, school_year_id FK NOT NULL,
        cursus_beneficiaire_id FK CASCADE, cursus_requis_id FK CASCADE,
        pourcentage_reduction DECIMAL(5,2), actif BOOL, created_by?, updated_by?, timestamps)
UNIQUE rmc_beneficiaire_requis_year_unique(cursus_beneficiaire_id, cursus_requis_id, school_year_id)
INDEX rmc_cursus_beneficiaire_idx
-- l'ancien UNIQUE(beneficiaire, requis) a été DROP par la migration school_year_feature

paiements (id, family_id FK CASCADE, school_year_id FK RESTRICT NOT NULL,
           created_by FK→users, timestamps)
UNIQUE paiements_family_year_unique(family_id, school_year_id) · INDEX(created_at)
-- l'ancien UNIQUE(family_id) seul a été DROP → 1 paiement par famille PAR ANNÉE

lignes_paiement (            -- ⚠ nom de table au pluriel-singulier « lignes_paiement »
  id, paiement_id FK CASCADE, type_paiement ENUM('espece','carte','cheque','exoneration'),
  montant INT, details JSON?, created_by?, updated_by?, timestamps
)
INDEX(paiement_id) · INDEX(type_paiement)
-- details : cheque → {banque, numero, nom_emetteur} ; exoneration → {justification}
--           clés legacy possibles en prod : emetteur, motif (fallbacks conservés)

invitation_tokens (id, email INDEX, token UNIQUE, school_id FK NULL ON DELETE?,
                   expires_at, timestamps)
-- school_id null = activation de compte ; renseigné = acceptation d'une école précise

director_handovers (
  id, school_id FK CASCADE, from_user_id FK users CASCADE, to_user_id FK users NULL ON DELETE?,
  email, outgoing_role VARCHAR(20) ∈ admin|registar|none, remove_teacher_role BOOL default 0, token_hash CHAR(64) UNIQUE (sha256, jamais le jeton brut),
  status VARCHAR(20) default 'pending' ∈ pending|accepted|declined|cancelled|expired,
  expires_at, responded_at?, created_by?, updated_by?, timestamps
)
INDEX dh_school_status_index(school_id, status)
-- Passation de direction (BelongsToSchool + TrackChangesTrait). Une seule « pending » par école,
-- garantie par lockForUpdate sur schools dans DirectorHandoverService::initiate (pas de contrainte SQL).

family_imports (
  id, school_id FK CASCADE, school_year_id FK NULL?, user_id FK NULL?,
  original_filename, stored_path, status VARCHAR(30) INDEX default 'pending',
  message TEXT?, summary JSON?, errors JSON?, error_count INT default 0,
  errors_truncated BOOL, errors_limit INT, started_at?, finished_at?, timestamps
)
INDEX(school_id, created_at) -- status ∈ pending | processing | completed | failed
```

## Tables framework

`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`.
Queue, cache, session sont **sur la base de données** (pas de Redis).

## Index — ce qui existe, ce qui manque

Index déclarés explicitement :
```
user_roles            (user_id, roleable_type, roleable_id)   + UNIQUE(user_id, role_id, roleable_type, roleable_id)
school_years          (school_id, is_active)                  + UNIQUE(school_id, label)
paiements             (created_at)                            + UNIQUE(family_id, school_year_id)
lignes_paiement       (paiement_id), (type_paiement)
attendances           (classroom_id, date)                    + UNIQUE(student_id, classroom_id, date)
student_year_outcomes (school_year_id, classroom_id)          + UNIQUE(student_id, school_year_id, classroom_id)
student_classrooms                                            + UNIQUE(student_id, classroom_id)
reduction_multi_cursuses (cursus_beneficiaire_id)             + UNIQUE(beneficiaire, requis, school_year_id)
family_imports        (school_id, created_at), (status)
invitation_tokens     (email)                                 + UNIQUE(token)
```
(MariaDB crée en plus un index automatique sur chaque colonne de clé étrangère contrainte.)

### ⚠ Supprimer un `User` : ce qui bloque, ce qui suit

Toutes les FK vers `users` ne se comportent pas pareil. Recensement exhaustif des migrations :

| Comportement | Colonnes | Effet d'un `$user->delete()` |
|---|---|---|
| **RESTRICT** (bloque) | `user_roles.user_id`, `paiements.created_by`, `class_schedules.teacher_id` | **erreur SQL 23000 → 500** |
| CASCADE | `user_infos.user_id`, `student_classrooms.student_id`, `attendances.student_id`, `student_year_outcomes.student_id` | lignes supprimées |
| SET NULL | `comments.user_id`, `classrooms.main_teacher_id`, `family_imports.user_id`, `attendances.created_by`/`updated_by`, `student_year_outcomes.decided_by` | référence vidée |

Conséquences pratiques :
- **En pratique tout `User` porte au moins un `user_role`** (les deux chemins de création — `StaffController::createStaffUser` et `FamilyController::addStudents`/`addResponsible` — posent le rôle dans la foulée ; seul `POST /api/users`, réservé au super-admin et sans appelant front, peut créer un compte nu) → un `delete()` nu échoue. C'est exactement pourquoi `FamilyController::deleteStudent` purge `user_infos` puis `user_roles` **avant** de supprimer (cf. `bugs-connus` A12 pour le défaut de scoping de cette purge).
- **Un membre du staff ayant enregistré un règlement est indéboulonnable** : `paiements.created_by` est RESTRICT. Le retirer de l'école (`POST /users/remove-from-school`, qui ne supprime que des `user_roles`) est la seule voie — et c'est le comportement voulu.
- Les `roleable_*` de `user_roles` sont un `morphs()` : **aucune FK**, donc supprimer une école, une famille ou une classe n'en nettoie rien (cf. `bugs-connus` A11).

⚠ La migration `2025_01_07_191445_create_user_roles_table` a un `down()` qui fait `Schema::dropIfExists('role_user')` — **mauvais nom de table**. Un `migrate:rollback` jusque-là laisse `user_roles` en place sans erreur.

### ⚠ Deux index manquants qui coûtent cher

1. **`user_roles(roleable_type, roleable_id)`** — l'index existant commence par `user_id`, donc il **ne peut pas servir** au pattern de batch le plus utilisé du projet :
   ```php
   UserRole::where('roleable_type','family')->whereIn('roleable_id', $familyIds)   // scan
   ```
   Ce pattern est employé par `computeFamilyFinancials`, `formatPaymentLignes`, `collectUnpaidFamilies`, `searchPayments`, `exportStudents`, `FamilyController::index`…
2. **`user_infos(user_id, key)`** — aucun index composite, alors que la table est interrogée en permanence (`whereHas('infos', key='birthdate')`, jointures de `searchStudents`, `pluck('value','key')`).

Ajouter ces deux index est le gain de performance le plus rentable du schéma actuel.

## Incohérences `$fillable` ↔ colonnes

| Modèle | Problème |
|---|---|
| `StudentClassroom` | `TrackChangesTrait` définit `updated_by` sur `updating`, mais **la colonne n'existe pas** |
| `Cursus` | la colonne `levels_count` existe mais n'est jamais vraiment exploitée (les niveaux réels sont dans `cursus_levels`) |
| `Paiement` | garde `created_by` dans `$fillable` (pas de `TrackChangesTrait`, pas de colonne `updated_by`) |

## Colonnes volontairement HORS `$fillable`

`school_id`, `school_year_id`, `created_by`, `updated_by` sur Family, Classroom, Cursus, Tarif, Reduction*, UserRole, StudentClassroom, LignePaiement, Attendance, SchoolYear.
`main_teacher_id` sur Classroom (posé uniquement par `ClassroomController::syncMainTeacher`).
→ les remettre dans `$fillable` rouvrirait la forge cross-tenant. **Ne jamais le faire.**

## Inspecter le schéma réel

```bash
docker exec api_dev_toollab php artisan tinker --execute="
  echo collect(\Illuminate\Support\Facades\Schema::getColumnListing('NOM_TABLE'))->implode(', ');
"
docker exec db_dev_toollab mysql -usail -ppassword toollab_api -e "SHOW CREATE TABLE nom_table\G"
docker exec api_dev_toollab php artisan migrate:status
```

## Ce que le schéma NE contient pas

- **Aucun soft delete** (`deleted_at` absent partout) → tout DELETE est définitif et cascade.
- Aucune table `students` / `teachers` : ce sont des `users` + `user_roles`.
- Aucune table d'historique de transactions (le modèle `TransactionPaiement` a été supprimé).
- Aucune contrainte DB sur « une seule année active par école » ni sur `roles.slug`.

---

**Voir aussi** : `laravel-model-migration` · `multi-tenant-scoping` · `bugs-connus` (incohérences de schéma)
