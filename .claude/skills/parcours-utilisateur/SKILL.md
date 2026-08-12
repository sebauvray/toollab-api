---
name: parcours-utilisateur
description: Flux métier de Toollab de bout en bout — création d'une école par le super-admin, onboarding du directeur, mise en place pédagogique et tarifaire, inscription d'une famille, règlement, vie de l'année (émargement, décisions), passage à l'année suivante. À invoquer pour comprendre le contexte fonctionnel d'une demande ou situer une modification dans le parcours global.
---

# Parcours utilisateur de bout en bout

## Vue d'ensemble

```
SUPER-ADMIN         crée l'école + le directeur
DIRECTEUR           active son compte → configure cursus, tarifs, classes, profs, staff
REGISTAR / ADMIN    crée les familles, inscrit les élèves, encaisse les règlements
PROFESSEUR          émarge ses séances, saisit les décisions (si principal)
DIRECTEUR           ouvre les décisions, clôture l'année, crée la suivante
FAMILLE             consulte sa fiche, ses paiements, télécharge sa facture
```

---

## 1. Création d'une école (super-admin)

`/admin/schools/new` → `POST /api/schools` (`auth:sanctum` + middleware `superadmin`).

Dans **une transaction** :
1. `School` (+ logo optionnel sur le disque `public`) ;
2. `User` directeur — réutilisé s'il existe déjà, sinon créé avec un mot de passe aléatoire ;
3. `UserRole(director, school)` — **accepté immédiatement** si le directeur existait déjà (le super-admin fournit lui-même son identité) ;
4. **`SchoolYear` active** `AAAA-AAAA+1` (bascule au 1er septembre) — sans elle, le directeur se prendrait des 409 partout ;
5. si le directeur est nouveau : `InvitationToken` (7 j, avec `school_id`) + mail `DirectorInvitation` → lien `set-password?token=…&email=…`.

⚠ Sur base non seedée, l'absence de rôles fait échouer cette route en 500 (`Role::where('slug','director')` → null).

**Bootstrap œuf/poule** : il n'existe aucun moyen in-app de créer le premier super-admin → `php artisan toollab:create-super-admin` (skill `seeders-donnees-test`).

## 2. Activation du compte directeur

`set-password.vue` → `POST /api/check-invitation-token` puis `POST /api/set-password`.
- Mot de passe min. 8, confirmé.
- Si `first_name`/`last_name` sont vides (invitations sans nom), ils deviennent **obligatoires** (`requires_profile`).
- L'activation via ce lien **vaut acceptation de CETTE école** (`accepted_at`), les autres invitations restent en attente.
- Tous les tokens Sanctum existants sont révoqués, le token d'invitation est supprimé.

Login → 1 école → auto-sélection → `/`.

## 3. Mise en place pédagogique (directeur / admin)

```
/cursus            créer un cursus : nom + progression (levels | continu) + nb de niveaux
                   → CursusLevel « Niveau 1..N » générés automatiquement (renommables)
/cursus/[id]       créer les classes du cursus : nom, niveau, genre, capacité,
                   créneaux (jour + heures + professeur), professeur principal, lien de groupe
/settings          onglet Utilisateurs : inviter admin / registar / professeur
                   (multi-rôles possibles ; l'invité doit accepter)
/tarification      prix par cursus, réductions familiales (paliers), réductions multi-cursus
/professeurs       vue des professeurs et de leurs créneaux
```
Rappels : `Cursus`/`CursusLevel` sont **permanents** ; `Classroom` et la tarification sont **annuels**.
Un professeur est rattaché à l'**école** (`UserRole(teacher, school)`) ; le lien avec une classe passe par `class_schedules.teacher_id`.

## 4. Création d'une famille et inscription (registar / admin / directeur)

```
/family                      liste + recherche + filtre par statut de règlement
  → « Créer une famille »    AddResponsableModal : le 1er responsable
                             (option « est aussi élève » → 2 rôles pour un seul User)
/family/[id]                 fiche : responsables, élèves, paiement, commentaires
  → « Ajouter des élèves »   AddElevesModal (nom, prénom, naissance, genre)
                             → User + UserInfo + UserRole(student, family)
                             → email synthétique prenom.nom.student.<uniqid>@school.com
/family/[id]/classes         choix des classes
/family/[id]/paiement        règlements
```

**Import en masse** : `/settings` → onglet « Import élèves » (fichier .xlsx/.csv, 20 colonnes, tout-ou-rien, asynchrone) — skill `import-familles`.

### Inscription — la règle « replace »
`POST /api/student-classrooms/enroll` (`checkrole:director,admin,registar`) :
1. l'élève doit avoir `UserRole(student, family)` → sinon 400 ;
2. la classe ne doit pas être pleine (`isFull()`) → sinon 400 ;
3. pas de doublon exact (student, classroom) → sinon 400 ;
4. **remplacement** : toute inscription active de cet élève sur le **même cursus ET le même type** est supprimée (on ne cumule pas) ;
5. création de `StudentClassroom(active)` + **snapshot du tarif** + `UserRole(student, classroom)`.

`unenroll` supprime l'inscription **et** le `UserRole(student, classroom)`.

L'écran affiche en temps réel le **total annuel** recalculé (`paiementService.getDetailsPaiement`) à chaque changement.

## 5. Règlement

`/family/[id]/paiement` — `POST /api/families/{id}/paiements/lignes` (staff uniquement).
Types : espèces, carte, chèque (banque + n° + émetteur, saisie multi-chèques), exonération (motif obligatoire).
- Refus en **422** si le total dépasse le montant dû.
- À l'atteinte du solde, `CheckPaymentCompletionJob` notifie les responsables (**une seule fois**, à la transition).
- Bouton **Facture PDF** disponible pour **tous** les rôles ayant accès à la page (y compris la famille).

Statut affiché (`Tag.vue`) : Aucune inscription · Exonéré · Incomplet · Partiellement payé · Payé.

## 6. Vie de l'année scolaire

**Professeur** (`/professeur/classes`, `/professeur/planning`) : matrice d'émargement (clic sur cellule, autosave, motif d'absence justifiée), et — **si professeur principal et si le directeur a ouvert la saisie** — matrice des décisions.

**Directeur / admin** :
- `/classes` (vue grille ou liste) → `/classes/[id]` : carte d'identité de la classe (prof + créneaux), puis émargement et décisions **en lecture seule** (taux de présence par élève) ;
- `/decisions` : vue d'ensemble multi-classes, filtres, tri, et **toggle d'ouverture de la saisie des décisions** ;
- `/statistiques` : effectifs, paiements, remplissage, impayées, chèques, exonérations, exports.

Décisions possibles : `passage`, `redoublement`, `exclusion`, `fin_cursus` — **portées par la classe**, pas par le cursus. Un cursus `continu` interdit `passage` et `redoublement`.

## 7. Passage à l'année suivante

`/annees-scolaires` → assistant en 4 étapes (libellé → tarifs à cloner → classes à reconduire → confirmation).
1. `POST /api/school-years` **clôture l'année active** et crée la nouvelle (active), en clonant tarifs et réductions des cursus sélectionnés ;
2. boucle de `POST /api/classrooms/{id}/reconduct` : chaque classe cochée est clonée **sans élèves**, avec ses créneaux et son professeur principal ;
3. bascule sur la nouvelle année.

L'ancienne année devient **lecture seule** : consultable (y compris facture PDF), toute écriture renvoie **409**.
Il n'existe **aucune réinscription automatique** des élèves : c'est un geste manuel volontaire.

## 8. Ce qui n'existe PAS (ne pas le supposer)

- Pas d'espace élève ni de connexion élève (les comptes existent mais ne servent qu'à porter l'identité).
- Pas de bulletins, notes, devoirs, messagerie interne.
- Pas de paiement en ligne (les règlements sont saisis par le staff).
- Pas de réinscription automatique d'une année sur l'autre.
- Pas de verrouillage d'accès d'une école (`schools.access` n'est vérifié nulle part).
- Pas de soft delete : toute suppression est **définitive**.

---

**Voir aussi** : `glossaire-metier` · `familles-eleves` · `annees-scolaires` · `super-admin-ecoles`
