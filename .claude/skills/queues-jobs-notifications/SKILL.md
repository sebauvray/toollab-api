---
name: queues-jobs-notifications
description: Jobs asynchrones et e-mails dans Toollab — queue sur driver database, réinjection obligatoire du contexte école/année hors HTTP, jobs existants (CheckPaymentCompletionJob, ProcessFamilyImportJob), les 6 notifications Mailable et leurs templates Blade, Maildev en dev et supervisord en prod. À invoquer pour créer un job, une notification, ou débugger un e-mail/job qui ne part pas.
---

# Jobs, queue & notifications

## 1. Infrastructure

```
QUEUE_CONNECTION=database        table `jobs` (+ job_batches, failed_jobs)
  retry_after = 90 s             (DB_QUEUE_RETRY_AFTER, non défini dans .env → défaut Laravel)
CACHE_STORE=database             table `cache`
SESSION_DRIVER=database          table `sessions`, lifetime 120 min
MAIL_MAILER=smtp → maildev:1025  en dev (UI http://localhost:1080)
reset password                   expire 60 min, throttle 60 s entre deux demandes (config/auth.php)
```

### ⚠ `retry_after` vs `timeout` — règle absolue

**`retry_after` doit être STRICTEMENT SUPÉRIEUR au temps d'exécution le plus long de n'importe quel job.** Sinon la queue considère le job comme abandonné et **le relance dans un autre worker pendant qu'il tourne encore**.

État actuel : `retry_after = 90 s` mais **`ProcessFamilyImportJob::$timeout = 300 s`** → un import de plus de 90 s est exécuté **deux fois** (8 workers en prod). Bug listé dans `bugs-connus` (A9).

**Avant de poser un `$timeout` supérieur à 90 s sur un nouveau job**, augmente `DB_QUEUE_RETRY_AFTER` en conséquence.
**Aucun Redis.** En prod, `supervisord` lance **8 workers** `php artisan queue:listen --sleep=3 --tries=3` (voir `generate-supervisord-conf.sh`), logs dans `storage/logs/worker.log`.

En dev, **aucun worker ne tourne par défaut** :
```bash
docker exec api_dev_toollab php artisan queue:work --once     # dépiler 1 job
docker exec -d api_dev_toollab php artisan queue:listen        # worker continu
docker exec api_dev_toollab php artisan queue:failed           # jobs en échec
docker exec api_dev_toollab php artisan queue:retry all
```
Si un mail ou un import « ne part pas » en dev, **la première hypothèse est l'absence de worker**.

## 2. RÈGLE N°1 — un job n'a pas de contexte multi-tenant

Hors HTTP, `request()->attributes` est vide → `currentSchoolId()` = `null` → le global scope `BelongsToSchool` **fail-closed** et toutes les requêtes renvoient 0 ligne.

**Pattern obligatoire dans tout `handle()`** :

```php
public function handle(SomeService $service): void
{
    $previous = [
        request()->attributes->get('current_school_id'),
        request()->attributes->get('current_school_year_id'),
    ];
    request()->attributes->set('current_school_id', $schoolId);
    request()->attributes->set('current_school_year_id', $yearId);

    try {
        // … travail
    } finally {
        request()->attributes->set('current_school_id', $previous[0]);
        request()->attributes->set('current_school_year_id', $previous[1]);
    }
}
```

Le `finally` est **critique** : un worker `queue:listen` enchaîne plusieurs jobs dans le même process ; une fuite de contexte contaminerait le job suivant (fuite cross-tenant).

Pour retrouver l'année quand tu n'as qu'une famille :
```php
$activeYear = SchoolYear::query()->withoutGlobalScopes()
    ->where('school_id', $family->school_id)->where('is_active', true)->first();
```

## 3. Les jobs existants

### `CheckPaymentCompletionJob(Family $family, $previousResteAPayer = null)`
Dispatché après chaque ajout/modif/suppression de ligne de paiement, **avec `->afterCommit()`** (sinon le job pourrait lire un état pré-commit).
Notifie les responsables **uniquement à la transition** « due > 0 » → « due == 0 » : d'où le paramètre `$previousResteAPayer`, qui rend le job **idempotent** (un re-run ne renotifie pas).
Les responsables sont dédoublonnés par email (`->unique(fn($u) => strtolower($u->email))`) : une même personne peut avoir plusieurs lignes `user_roles`.

### `ProcessFamilyImportJob(int $familyImportId)`
`timeout = 300`. Voir skill `import-familles`.

Conventions communes : `implements ShouldQueue`, traits `Dispatchable, InteractsWithQueue, Queueable, SerializesModels`, dépendances injectées dans `handle()` (pas dans le constructeur).

⚠ `SerializesModels` **re-résout le modèle depuis la base au moment de l'exécution** — donc via les global scopes, **avant** que tu aies réinjecté le contexte. Passer un `Family` en propriété fonctionne aujourd'hui (résolution par clé primaire sans scope sur `Family`… qui porte pourtant `BelongsToSchool`). **Pour un nouveau job, préfère passer un `int $id`** et recharger explicitement avec `withoutGlobalScopes()` — c'est le pattern de `ProcessFamilyImportJob`.

## 4. Les notifications

| Classe | Template Blade | Déclencheur | Queue | `afterCommit` |
|---|---|---|---|---|
| `DirectorInvitation` | `emails/director-invitation` | création d'école, directeur inconnu | ✔ | ✔ |
| `StaffInvitation` | `emails/staff-invitation` | `create-staff` sur un email inconnu | ✔ | ✔ |
| `SchoolInvitationNotification` | `emails/school-invitation` | `create-staff` sur un utilisateur existant n'ayant pas accepté cette école | ✔ | ✔ |
| `StaffRoleChangedNotification` | `emails/staff-role-changed` | rôle `added` \| `removed` \| `removed_from_school` | ✔ | ✔ |
| `PaymentCompletedNotification` | `emails/payment-completed` | solde atteint (via le job) | ✔ | — (le job est déjà `->afterCommit()`) |
| `CustomResetPasswordNotification` | `emails/reset-password` | `User::sendPasswordResetNotification()` | ✖ synchrone | — |

- Canal **`mail` uniquement** (`via()` → `['mail']`).
- **5 des 6 notifications sont `ShouldQueue`** avec `public int $tries = 3` et `public array $backoff = [60, 300, 900]` (1 / 5 / 15 min). Reprends ces valeurs pour toute nouvelle notification.
- **`$this->afterCommit = true` dans le constructeur** (4 notifications) : garantit que le mail ne part pas avant le commit de la transaction qui l'a déclenché. **À reprendre systématiquement** pour une notification émise depuis un `DB::transaction`.
- Seule `CustomResetPasswordNotification` reste synchrone (elle doit partir immédiatement, même sans worker actif — sinon un utilisateur qui a perdu son mot de passe n'aurait aucun retour).
- Les sujets sont contextualisés (`match ($this->action)` dans `StaffRoleChangedNotification` : « Nouveau rôle dans X » / « Rôle retiré dans X » / « Accès retiré à X »).
- `SchoolInvitationNotification` pointe vers **`/login`** (et non `/set-password`) : l'utilisateur existe déjà, l'acceptation se fait dans l'application via le bandeau d'invitations.

### ⚠ Une notification `ShouldQueue` doit AUSSI réinjecter le contexte

`PaymentCompletedNotification` est sérialisée puis rendue **par le worker**, donc dans un process qui n'a plus le contexte du job qui l'a déclenchée. Elle refait donc le même travail dans `toMail()` :
```php
public function __construct(Family $family, array $paymentDetails, ?int $schoolYearId = null)
{
    $this->schoolId = $family->school_id;          // on capture les ids À LA CONSTRUCTION
    $this->schoolYearId = $schoolYearId;
}

public function toMail($notifiable): MailMessage
{
    $previous = [ request()->attributes->get('current_school_id'), … ];
    request()->attributes->set('current_school_id', $schoolId);
    request()->attributes->set('current_school_year_id', $schoolYearId ?? $this->activeSchoolYearId($schoolId));
    try { return $this->buildMail(…); } finally { /* restauration */ }
}
```
Et par sécurité, **toutes ses requêtes utilisent `withoutGlobalScopes()`** avec un filtre explicite (ceinture + bretelles). C'est le modèle à suivre pour toute notification différée qui lit des données scopées.

Robustesse : `public $tries = 3;` et `public $backoff = [60, 300, 900];` (1 min, 5 min, 15 min) — à reprendre pour un envoi critique.
Sujet du mail : **« Confirmation d'inscription »** (et non « Paiement complété ») : c'est le message de bienvenue une fois le dossier soldé.
- Templates dans `resources/views/emails/` (+ partial `emails/partials/logo.blade.php`) → détail dans la skill **`emails-templates`**.

⚠ **Logo des e-mails** : PNG hébergé servi en URL absolue depuis **`config('app.url')`** (donc `APP_URL`, l'API) : `{APP_URL}/images/logo-email.png`. **Jamais de SVG inline** (non rendu par Gmail — régression déjà vécue, corrigée par `1bf61e1`). Un « logo cassé » en prod = `APP_URL` mal renseignée.

Liens **cliquables** : construits sur `config('app.frontend_url')` / env `FRONTEND_URL` (défaut `http://localhost:3000`), email **`urlencode()`** :
```
{FRONTEND_URL}/set-password?token=XXX&email=YYY
{FRONTEND_URL}/reset-password?token=XXX&email=YYY
```
Deux bases d'URL cohabitent donc : `app.url` pour les images (API), `app.frontend_url` pour les liens (application). Ne pas les confondre.

## 5. Créer un job

```php
namespace App\Jobs;

class MonJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(public int $machinId) {}

    public function handle(MonService $service): void
    {
        $machin = Machin::query()->withoutGlobalScopes()->findOrFail($this->machinId);

        $previous = [ /* … cf. §2 … */ ];
        request()->attributes->set('current_school_id', $machin->school_id);
        try {
            $service->fais($machin);
        } finally { /* restauration */ }
    }
}
```
Dispatch : `MonJob::dispatch($id)->afterCommit();` si l'appel se fait dans une transaction.

## 6. Créer une notification

```php
class MaNotification extends Notification   // + implements ShouldQueue si l'envoi peut être lent
{
    use Queueable;

    public function __construct(private string $schoolName, private string $token) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('…')
            ->view('emails.ma-notification', [
                'user' => $notifiable,
                'url' => config('app.frontend_url').'/…',
            ]);
    }
}
```
Le template doit inclure `@include('emails.partials.logo')` et rester en **tables HTML inline** (compatibilité clients mail). Tester **systématiquement** le rendu dans Maildev (`http://localhost:1080`) : un mail cassé ne se voit pas dans le code.

## 7. Débugger

```bash
docker logs -f api_dev_toollab
docker exec api_dev_toollab tail -f storage/logs/laravel.log
docker exec api_dev_toollab php artisan queue:failed
docker exec api_dev_toollab php artisan tinker --execute="
  echo \Illuminate\Support\Facades\DB::table('jobs')->count();
"
```

| Symptôme | Cause la plus probable |
|---|---|
| Le job ne s'exécute jamais | aucun worker (dev) |
| Le job « ne trouve rien » | contexte école/année non réinjecté |
| Le job échoue après un déploiement | payload sérialisé d'une ancienne signature → `queue:flush` |
| Le mail ne part pas en dev | Maildev éteint, ou profil `dev` non activé (`COMPOSE_PROFILES=dev`) |
| Le mail part 2 fois | job non idempotent — vérifier la condition de transition |
| Logo absent dans Gmail | quelqu'un a remis un SVG inline |

---

**Voir aussi** : `multi-tenant-scoping` (contexte hors HTTP) · `emails-templates` · `import-familles` · `tarification-paiements`
