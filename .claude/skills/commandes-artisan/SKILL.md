---
name: commandes-artisan
description: Commandes artisan maison de Toollab (toollab:create-super-admin, import:families-csv, import:student-infos-csv, delete:student-info-keys), leur contexte historique de migration de données, et les règles pour en écrire une nouvelle (contexte multi-tenant, idempotence, confirmation). À invoquer pour lancer, comprendre ou créer une commande console.
---

# Commandes artisan

## 1. Les 4 commandes maison

| Signature | Fichier | Statut |
|---|---|---|
| `toollab:create-super-admin {email?} {--password=} {--first-name=} {--last-name=}` | `CreateSuperAdmin.php` | **active** — bootstrap plateforme |
| `import:families-csv` | `ImportFamiliesFromCsv.php` | **legacy** — migration ponctuelle, ne plus utiliser |
| `import:student-infos-csv` | `ImportStudentInfosFromCsv.php` | **legacy** — idem |
| `delete:student-info-keys` | `DeleteStudentInfoKeys.php` | **utilitaire de nettoyage** |

`routes/console.php` ne contient que la commande `inspire` du squelette Laravel.

## 2. `toollab:create-super-admin` — la seule d'usage courant

Voir la skill `seeders-donnees-test` pour le détail. Résumé :
```bash
docker exec -it api_dev_toollab php artisan toollab:create-super-admin --password=motdepasse
docker exec -it api_dev_toollab php artisan toollab:create-super-admin admin@ex.com --password=…
```
Idempotente (crée **ou** réinitialise le mot de passe). Sans argument : traite **tous** les emails de `SUPER_ADMIN_EMAILS`. Avertit si l'email n'est pas dans l'env (le compte sera créé mais pas super-admin). Minimum 8 caractères.

## 3. Les commandes d'import CSV — contexte historique important

`import:families-csv` et `import:student-infos-csv` lisent **un chemin en dur** :
```php
Reader::createFromPath(base_path('resources/data/EXP_ELEVE.csv'), 'r')->setDelimiter(';')
```
Le fichier `resources/data/EXP_ELEVE.csv` (~84 Ko) est un **export d'un ancien logiciel scolaire**, versionné dans le dépôt.

Elles ont servi **une fois** à la reprise de données et expliquent deux bizarreries encore visibles aujourd'hui :

### (a) Les emails `@corriger.com`
```php
'email' => (!empty($row['R1_EMAIL']) && !User::where('email', $row['R1_EMAIL'])->exists())
    ? $row['R1_EMAIL']
    : strtolower($row['R1_NOM'].'.'.$row['R1_PRENOM'].'@corriger.com'),
```
Quand le CSV n'avait pas d'email valide (ou qu'il était déjà pris), un email de substitution `@corriger.com` était généré. D'où le badge **« À corriger »** dans `pages/family/[id]/index.vue` (`responsible.email?.endsWith('@corriger.com')`).
→ C'est un **marqueur de dette de données**, pas une feature. Un flag DB serait plus propre.

### (b) Les clés `user_infos` de fin d'année, supprimées
`import:student-infos-csv` créait 9 clés : `statut_scolaire`, `abandon`, `renvoi`, `renvoi_motif`, `passage`, `redoublement`, `autre`, `commentaires`, `classe_precedente`.
Elles ont été **remplacées par la table `student_year_outcomes`** et sont supprimées par `delete:student-info-keys`.
→ Le front ne référence plus ces champs (l'ancien `student.year_infos` a disparu). **Ne pas les réintroduire.**

**Ne pas relancer ces deux commandes** : elles ne sont pas idempotentes de façon fiable, écrivent sans contexte école et créent des données obsolètes. L'import actuel, supporté et testé, est `POST /api/families/import` (skill `import-familles`).

## 4. `delete:student-info-keys`

Nettoie les 9 clés listées ci-dessus dans `user_infos`. Compte d'abord, **demande confirmation** (`$this->confirm(..., true)`), puis supprime.
Utile après une reprise de données ; sans effet si les clés n'existent plus.

## 5. Écrire une nouvelle commande

```php
class MaCommande extends Command
{
    protected $signature = 'toollab:ma-commande {argument?} {--option=}';
    protected $description = 'Ce que fait la commande, en français.';

    public function handle(): int
    {
        // 1. CONTEXTE MULTI-TENANT — sinon les global scopes fail-closed → 0 ligne
        $school = School::findOrFail($this->argument('school_id'));
        $year = SchoolYear::query()->withoutGlobalScopes()
            ->where('school_id', $school->id)->where('is_active', true)->firstOrFail();

        request()->attributes->set('current_school_id', $school->id);
        request()->attributes->set('current_school_year_id', $year->id);

        // 2. confirmation avant toute action destructrice
        if (!$this->confirm('Continuer ?', true)) {
            $this->info('Opération annulée.');
            return self::SUCCESS;
        }

        // 3. travail, dans une transaction si multi-tables
        DB::transaction(fn () => …);

        $this->info('Terminé.');
        return self::SUCCESS;
    }
}
```

Règles :
- **Préfixe `toollab:`** pour toute nouvelle commande métier (les `import:` / `delete:` sont legacy).
- **Réinjecter le contexte** école/année, sinon rien n'est visible ni correctement écrit (skill `multi-tenant-scoping`).
- **Idempotence** : `firstOrCreate` / `updateOrCreate` sur une clé naturelle, pour pouvoir relancer sans dégât.
- **Confirmation** (`$this->confirm`) avant toute suppression — rappel : **il n'y a pas de soft delete**.
- Retourner `self::SUCCESS` / `self::FAILURE`.
- Sorties utilisateur en **français** (`$this->info`, `warn`, `error`).
- Pas de chemin de fichier en dur : passer par un argument.

## 6. Lancer

```bash
docker exec -it api_dev_toollab php artisan toollab:ma-commande     # -it pour les prompts interactifs
docker exec api_dev_toollab php artisan list | grep -E "toollab|import|delete"
```
En prod, exécuter dans le conteneur API (`docker exec <api> php artisan …`). Aucune commande n'est planifiée : `routes/console.php` ne définit aucun scheduling, et **aucun cron n'est configuré** dans les images.

---

**Voir aussi** : `seeders-donnees-test` · `multi-tenant-scoping` · `import-familles` (import supporté)
