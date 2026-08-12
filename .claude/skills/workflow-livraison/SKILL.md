---
name: workflow-livraison
description: Checklist obligatoire avant de déclarer un bloc de travail terminé sur Toollab — les 5 questions (fonctionnel, régression, sécurité, complexité, performance) déclinées en vérifications concrètes propres à ce codebase, avec le format de restitution attendu. À invoquer systématiquement à la fin d'une implémentation, avant de répondre à l'utilisateur.
---

# Checklist de livraison

> **Règle du projet** : mentionner explicitement dans la réponse ce qui a été vérifié et ce qui a été nettoyé. **Ne rien mentionner = ne pas avoir regardé.**

## (a) La feature fait ce qui est demandé

- [ ] Lister les cas d'usage : **golden path + cas limites** (liste vide, 1 seul élément, valeur nulle, année archivée, rôle non autorisé, plusieurs écoles).
- [ ] Vérifier chaque cas **dans l'app** si possible : curl (skill `debug-api`) pour l'API, Playwright (skill `verification-visuelle`) pour l'UI.
- [ ] Un cas non testé (pas d'accès, environnement non lancé) doit être **déclaré comme tel**, jamais présenté comme OK.
- [ ] Le périmètre demandé est **entièrement** livré. Si une partie est bloquée, terminer tout le reste et dire précisément ce qui manque et pourquoi.

## (b) Aucune régression

- [ ] Relire le **diff complet** de chaque fichier touché (`git diff`).
- [ ] Identifier **tous les appelants** de ce que tu as modifié :
  - contrôleur modifié → quelles pages Nuxt l'appellent ? (`grep -rn "api/xxx" toollab-front/services`)
  - ex. toucher `getAdminClassrooms` impacte `/classes` **et** `/cursus/[id]` ;
  - composant modifié → `grep -rn "MonComposant" toollab-front/pages toollab-front/components`.
- [ ] **Fallbacks legacy préservés** : `teacher_name` (texte) quand `teacher_id` est null ; `details->emetteur` / `details->motif` des lignes de paiement seedées/prod ; `roleable_type` valant `App\Models\School` ; `current_school_role` (singulier) en lecture ; `first_name`/`last_name` **nullables**.
- [ ] Cas `null` / collection vide traités (`attendance: []` et non `{}` pour un élève sans émargement).
- [ ] Un champ ajouté à un formulaire est bien transporté par le **service** (whitelists de `services/classe.js`).
- [ ] Format de réponse d'un endpoint existant **non cassé** (le front en dépend).

## (c) Aucune faille introduite

- [ ] **IDOR** : chaque ressource atteinte par un id client est vérifiée (`currentSchoolId()`, `callerCanAccessFamily`, `guardClassroom`, lien parent-enfant).
- [ ] `school_id` **jamais** déduit d'un payload ; les `Rule::exists` portent une contrainte `school_id`.
- [ ] **Mass assignment** : aucun champ technique ajouté à `$fillable`.
- [ ] Route mutative sous `auth:sanctum` + `school` (+ `schoolyear` si year-scopée, + `checkrole:` si réservée).
- [ ] Messages d'erreur **génériques** côté client ; détails en `Log::warning`/`Log::error`.
- [ ] **Aucun `catch` sans `Log::error`** : un catch qui renvoie son propre 500 court-circuite le handler global, l'erreur devient invisible en prod.
- [ ] Pas de `v-html` sur du contenu utilisateur ; pas de concaténation SQL.
- [ ] Nouvelle route publique → `throttle:` ; opération sensible sur mot de passe → révocation des tokens.
- [ ] Réponses concernant une adhésion `pending` : nom toujours masqué.
→ détail : skill `securite-api`.

## (d) Complexité justifiée

- [ ] « Un dev senior dirait-il que c'est sur-conçu ? »
- [ ] Aucune abstraction (service, trait, helper, composable, composant) créée pour **un seul** appelant. Inline d'abord ; extraire à partir de 3 occurrences.
- [ ] Méthode > ~30 lignes ou > 3 niveaux d'imbrication → elle fait trop de choses.
- [ ] Conventions locales respectées : `{status,message,data}`, slugs (jamais noms FR), naming FR/EN du fichier voisin.
- [ ] **Aucun commentaire** dans le nouveau code (exception : one-liner pour une raison non-dérivable). Pas de docblock multi-lignes, pas de `// removed`, `// added by`.
- [ ] Code mort supprimé plutôt que contourné.

## (e) Performance

- [ ] Pas de N+1 : une boucle qui appelle une relation ⇒ `with([...])` ou `withCount` en amont.
- [ ] Pas d'`$appends` coûteux ajouté à un modèle listé.
- [ ] Membres de famille chargés par **batch `UserRole`** (jamais `with('responsibles')`, qui renvoie vide).
- [ ] Front : pas d'appel API par item dans une boucle — un seul fetch parent.
- [ ] Grosse collection PHP itérée plusieurs fois ⇒ `->get()` une fois puis filtres en mémoire, pas des `->filter()` qui re-requêtent.
- [ ] Attention aux endpoints qui paginent **en mémoire** (`unpaidFamilies`, `payments` filtré, `families` trié par statut) : ne pas y ajouter de calcul coûteux supplémentaire.

## Format de restitution attendu

Court, factuel, sans emphase :

```
**Fait** — <ce qui a été implémenté, 1-3 lignes>

**Vérifié**
- fonctionnel : <cas testés, comment>
- régression : <appelants relus, fallbacks préservés>
- sécurité : <gates, scoping, messages>
- non testé : <ce qui ne l'a pas été, et pourquoi>

**Nettoyé** — <code mort supprimé, incohérences corrigées> (si applicable)
```

## Rappels transverses

- **Pas de soft delete** : toute suppression est définitive et cascade. Le rappeler à l'utilisateur avant d'exécuter un DELETE.
- **Ne jamais commiter ni pousser** sans demande explicite.
- Un apprentissage réutilisable découvert pendant le travail → l'ajouter à la **skill concernée** (ou au CLAUDE.md si transverse), puis conclure par la ligne rituelle demandée dans la section « Continuous Learning » du CLAUDE.md.

---

**Voir aussi** : `securite-api` · `verification-visuelle` · `tests` · `bugs-connus` (ne pas re-signaler un bug connu)
