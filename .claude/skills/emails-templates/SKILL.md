---
name: emails-templates
description: Templates d'e-mails Blade de Toollab — structure HTML compatible clients mail, partial de logo (PNG hébergé, jamais SVG), variables disponibles par template, construction des liens vers le front, et test dans Maildev. À invoquer pour créer ou modifier un e-mail transactionnel.
---

# Templates d'e-mails

## 1. Les 6 templates

| Template | Notification | Variables clés |
|---|---|---|
| `emails/director-invitation` | `DirectorInvitation` | `$schoolName`, `$actionUrl` |
| `emails/staff-invitation` | `StaffInvitation` | `$schoolName`, `$roleNames[]`, `$actionUrl`, `$notifiable` |
| `emails/school-invitation` | `SchoolInvitationNotification` | `$actionUrl` (→ **`/login`**, pas `/set-password`), `$schoolName`, `$roleName`, `$roleNames[]`, `$notifiable` |
| `emails/staff-role-changed` | `StaffRoleChangedNotification` | `$schoolName`, `$action` (`added`\|`removed`\|`removed_from_school`), `$roleNames[]`, `$remainingRoleNames[]` |
| `emails/payment-completed` | `PaymentCompletedNotification` | famille, détails du paiement, année |
| `emails/reset-password` | `CustomResetPasswordNotification` | `$actionUrl`, `$count` (durée de validité) |
| `emails/director-handover-invitation` | `DirectorHandoverInvitation` (**on-demand** : `Notification::route('mail', $email)`, pas de `$notifiable` nommé) | `$actionUrl` (→ `/passation-direction?token=`), `$schoolName`, `$fromName`, `$expiresAt` (d/m/Y, Europe/Paris) |
| `emails/director-handover-status` | `DirectorHandoverStatusNotification` | `$action` (`accepted`\|`declined`), `$counterpartName`, `$newRoleName` (null = a quitté l'école), `$notifiable` |

Tous sont rendus via `->view('emails.xxx', [...])` depuis `toMail()`, **jamais** via le markdown Laravel par défaut.

## 2. Structure obligatoire

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>…</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header  { padding: 40px 30px; text-align: center; background:#fff; border-bottom:1px solid #e0e0e0; }
        .content { padding: 20px; background:#fff; }
        .button  { display:inline-block; padding:12px 24px; background-color:#343C6A; text-decoration:none; border-radius:4px; }
        .footer  { text-align:center; padding:20px; font-size:12px; color:#666; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">@include('emails.partials.logo')</div>
    <div class="content">…</div>
    <div class="footer">…</div>
</div>
</body>
</html>
```

Contraintes clients mail :
- **`<style>` dans le `<head>` + classes simples** — c'est le pattern du projet. Pas de framework CSS, pas de flex/grid, pas de variables CSS.
- Largeur **600 px max**.
- Bouton d'action en `background-color: #343C6A` (le `primary` de la charte) — **pas** `#222`, contrairement à l'app.
- Police système (`Arial, sans-serif`).

## 3. Le logo — règle non négociable

`resources/views/emails/partials/logo.blade.php` :
```blade
<img src="{{ config('app.url') }}/images/logo-email.png"
     alt="Toollab" width="276" height="52"
     style="display:block; margin:0 auto; border:0; outline:none; text-decoration:none;">
```

- **PNG raster hébergé**, servi en **URL absolue** depuis `public/images/logo-email.png` (servi par nginx).
- ⚠ **Jamais de SVG inline** : Gmail et Outlook ne le rendent pas. Régression déjà vécue et corrigée (commit `1bf61e1`) — ne pas y revenir.
- L'URL est construite sur **`config('app.url')`** (= `APP_URL`, l'**API**), et non `FRONTEND_URL`. Si `APP_URL` est mal renseignée en prod, **le logo est cassé dans tous les e-mails** alors que l'app fonctionne. C'est le premier point à vérifier sur un « logo absent ».

## 4. Les liens vers le front

```php
$this->frontendUrl = config('app.frontend_url', 'http://localhost:3000');   // env FRONTEND_URL
$url = $this->frontendUrl.'/set-password?token='.$this->token.'&email='.urlencode($notifiable->email);
$url = $frontendUrl.'/reset-password?token='.$this->token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
```
⚠ **Toujours `urlencode()` l'email.** Deux bases d'URL cohabitent : `app.url` (API, pour les images) et `app.frontend_url` (application, pour les liens cliquables). Ne pas les confondre.

## 5. Noms d'utilisateur potentiellement nuls

`users.first_name` / `last_name` sont **nullables** (invitations). Pattern à reprendre :
```blade
Bonjour {{ trim(($notifiable->first_name ?? '').' '.($notifiable->last_name ?? '')) ?: $notifiable->email }},
```

## 5 bis. `payment-completed` — le template le plus riche (228 lignes)

C'est le récapitulatif envoyé quand le dossier est soldé. Il rend, **par élève** :
```
nom de l'élève
  └── pour chaque inscription active de l'année :
        cursus · niveau · classe
        professeur(s) (via classroom.schedules.teacher, fallback teacher_name)
        créneaux (jour + horaires)
        lien du groupe de classe (classrooms.telegram_link) si renseigné
puis le récapitulatif financier :
        Total annuel / Montant réglé (montant_encaisse) / Exonération (si > 0)
```
Points à préserver :
- **`$paymentDetails['montant_encaisse'] ?? $paymentDetails['montant_paye']`** — le fallback couvre les anciens payloads sérialisés en file d'attente avant l'ajout du champ. Ne pas le retirer tant que la queue peut contenir d'anciens jobs.
- L'exonération n'est affichée **que si `> 0`**, comme une ligne distincte du montant réglé.
- Formatage FR : **`number_format($x, 2, ',', ' ')`** (virgule décimale, espace comme séparateur de milliers) — la seule place du projet où les montants ont 2 décimales (l'UI et la facture arrondissent à l'entier).
- Le libellé « Lien du groupe de classe » ne doit **jamais** mentionner une plateforme, malgré le nom de colonne `telegram_link`.

## 5 ter. Conventions communes aux 6 templates

- **Le bouton d'action a toujours `style="color: white"` en inline** en plus de la classe `.button` : certains clients mail ignorent la couleur héritée d'une classe sur un `<a>`.
- **Chaque template répète l'URL en clair** sous le bouton :
  > « Si vous rencontrez des problèmes en cliquant sur le bouton "X", copiez et collez l'URL ci-dessous dans votre navigateur web : {{ $actionUrl }} »

  À reprendre systématiquement (certains clients bloquent les liens des boutons).
- Libellés de bouton par template : « Activer mon compte » (director/staff-invitation), « Me connecter » (school-invitation), « Réinitialiser le mot de passe » (reset-password).
- `staff-role-changed` branche sur `$action` (`added` / `removed` / `removed_from_school`) et liste **les rôles changés** puis **les rôles restants** (`$currentRoles`, affichés uniquement s'il en reste).

## 6. Contenu conditionnel

`staff-invitation` et `staff-role-changed` adaptent leur texte au nombre de rôles :
```blade
@if(!empty($roleNames) && count($roleNames) > 1)
    <p>Les rôles qui vous ont été attribués sont :</p>
    <ul>@foreach($roleNames as $role)<li><strong>{{ $role }}</strong></li>@endforeach</ul>
@else
    …
@endif
```
Les rôles sont passés en **noms FR** (`Role::name`), pas en slugs : ils sont destinés à l'utilisateur final.

## 7. Tester

```bash
docker compose up -d          # profil dev → conteneur maildev
# UI : http://localhost:1080
```
Déclencher l'envoi (créer un staff, réinitialiser un mot de passe, solder un paiement) puis **ouvrir le mail dans Maildev** et vérifier :
- [ ] le logo s'affiche (sinon : `APP_URL` ou `public/images/logo-email.png`) ;
- [ ] le lien pointe vers le **front** et non l'API ;
- [ ] pas de nom vide (« Bonjour , ») ;
- [ ] rendu correct en largeur réduite.

⚠ **5 notifications sur 6 sont `ShouldQueue`** : sans worker en dev, **elles ne partent pas**.
```bash
docker exec api_dev_toollab php artisan queue:work --once     # dépiler
docker exec -d api_dev_toollab php artisan queue:listen        # worker continu
```
Seule `CustomResetPasswordNotification` est synchrone (le mot de passe oublié doit toujours partir).

## 8. Créer un template

- [ ] Copier la structure d'un template existant (`staff-invitation` est le plus représentatif).
- [ ] `@include('emails.partials.logo')` dans le header.
- [ ] Styles inline/`<style>` simples, largeur 600, bouton `#343C6A`.
- [ ] Textes en **français**, accents corrects.
- [ ] Liens construits sur `config('app.frontend_url')`, email `urlencode()`.
- [ ] Noms tolérants au `null`.
- [ ] `ShouldQueue` sur la notification si l'envoi peut être lent.
- [ ] Vérifié dans Maildev.

---

**Voir aussi** : `queues-jobs-notifications` · `roles-permissions` (invitations) · `super-admin-ecoles`
