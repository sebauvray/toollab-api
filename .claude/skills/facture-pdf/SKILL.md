---
name: facture-pdf
description: Facture PDF des familles dans Toollab — endpoint GET /api/families/{family}/paiements/facture, App\Services\FacturePdfService (PDF 1.4 écrit à la main, zéro dépendance), régimes de TVA et mentions légales CGI, numérotation déterministe, et bouton de téléchargement côté front. À invoquer pour modifier la facture, ses mentions fiscales ou son rendu.
---

# Facture PDF

## 1. Endpoint

```
GET /api/families/{family}/paiements/facture   →  PaiementController::facture()
```
Dans le préfixe `families/{family}/paiements`, sous `school` + `schoolyear`.

- **Gate de lecture identique à `show`** : `FamilyController::callerCanAccessFamily($family)` → staff **et membres de la famille**. Un responsable télécharge SA facture ; 403 sinon.
- Reste lisible sur une **année archivée** (le middleware ne bloque que les écritures).
- Vérifie que la `SchoolYear` du header appartient bien à l'école de la famille (400 sinon).
- **422** si `total <= 0 && payé <= 0 && exonéré <= 0` (rien à facturer).

## 2. Contenu

État courant du paiement de l'année du header `X-School-Year-Id`, via `PaiementService::getDetailsPaiement()` (donc **snapshots de tarif respectés**).

- **Une seule ligne** : « Frais de scolarité — Année scolaire {label} » + le nombre d'élèves. **Pas de détail par élève** (choix utilisateur : facture sobre).
- « Montant réglé » = **`montant_encaisse`** (hors exonérations) ; l'exonéré est affiché **séparément** comme une remise.
- `reste = max(0, total - payé - exonéré)` → **clampé à 0** : un trop-perçu affiche « acquittée », le montant réglé reste affiché tel quel.
- Bande verte « FACTURE ACQUITTÉE LE {date de la dernière ligne} » si `reste === 0`, sinon bande ambre « non acquittée — reste à payer : X € ».
- « Facturé à » : tous les responsables, **dédoublonnés** (`->unique('id')` — une personne responsable ET élève a deux lignes `user_roles`). Adresse = celle du premier responsable qui en a une.

### Numéro de facture

```php
$numero = 'F' . $year->id . '-' . str_pad((string) $family->id, 4, '0', STR_PAD_LEFT);
```
**Déterministe et régénérable** : c'est un document d'**état**, pas une séquence chronologique légale. Choix assumé et validé en revue — si une numérotation séquentielle légale devient nécessaire, il faudra une table dédiée avec compteur par école/année.

## 3. TVA — `schools.vat_mode`

| valeur | mention imprimée en pied |
|---|---|
| `association` | TVA non applicable — article 261, 7-1° du CGI |
| `enseignement` | TVA non applicable — article 261, 4-4° du CGI |
| `franchise` | TVA non applicable, art. 293 B du CGI |
| `assujetti` | *(aucune mention)* + décomposition **Total HT / TVA 20 % / Total TTC** (les prix sont traités TTC) + « N° TVA : … » en en-tête |
| `null` | aucune mention |

Map : constante `PaiementController::VAT_MENTIONS`.

Contexte métier (recherche validée) : les écoles clientes sont quasi toujours exonérées — association loi 1901 → `261 7-1°` ; OGEC / enseignement réglementé → `261 4-4°`. **C'est au directeur de choisir** ; le texte d'aide de `/settings` renvoie à l'expert-comptable.

`vat_number` n'est **pas** requis même en `assujetti` (choix : ne pas bloquer la sauvegarde) et reste persisté si le régime change (voulu).

Champs école concernés : `siret` (libellé UI « SIRET (ou n° RNA) »), `vat_mode`, `vat_number` — dans `$fillable` de `School` et dans les règles des **deux** `SchoolRequest`. ⚠ `SchoolController::store()` ne les persiste pas à la création (sans impact aujourd'hui).

## 4. `App\Services\FacturePdfService`

PDF **1.4 écrit à la main**, même philosophie que `ExportService` : **zéro dépendance composer**.

```php
FacturePdfService::download(string $filename, array $facture): BinaryFileResponse
```

Structure : 6 objets PDF (Catalog, Pages, Page, Font Helvetica, Font Helvetica-Bold, Contents), xref à offsets exacts (entrées de 20 octets), page A4 `595.28 × 841.89`.

### Contraintes techniques à respecter absolument

- **Encodage** : polices Helvetica natives en `WinAnsiEncoding`. Tout texte passe par `iconv('UTF-8','CP1252//TRANSLIT//IGNORE')`, strip des caractères de contrôle, échappement de `\`, `(`, `)`.
- **Nombres** : tous les opérateurs PDF utilisent `sprintf('%.2F', …)`. **JAMAIS `%f`** — locale-dépendant, une virgule décimale corromprait le fichier.
- **Alignement** : table de chasses `HELVETICA_WIDTHS` (unités/1000) pour calculer la largeur d'une chaîne et aligner à droite / centrer. Caractères spéciaux CP1252 utiles : `€` = 128, em-dash = 151, `°` = 176. **Ajouter un glyphe hors de cette table casse l'alignement** — étendre la constante si besoin.
- Couleurs en constantes RGB normalisées (`DARK`, `GRAY`, `GREEN`, `AMBER`, `RED`).

### Payload attendu par `download()`

```php
[
  'numero', 'date', 'year_label',
  'school'  => ['name','address','zipcode','city','email','phone','siret','vat_number'],
  'client'  => ['names' => [...], 'address', 'zipcode', 'city'],
  'nombre_eleves' => int,
  'total', 'paye', 'exonere', 'reste' => int,
  'assujetti' => bool, 'acquittee' => bool, 'acquittee_le' => ?string,
  'vat_mention' => ?string,
]
```

## 5. Front

Dans l'en-tête de la section « Paiement » de `pages/family/[id]/paiement.vue` :
```vue
<button v-if="factureDisponible" @click="telechargerFacture">Facture PDF</button>
```
- `factureDisponible = montantTotal > 0 || montantPaye > 0`.
- Style **bouton secondaire clair** (comme `ExportButton` mais **sans le logo Excel**).
- **Visible pour TOUS les rôles ayant accès à la page** — la facture est destinée aux familles. Contrairement aux exports, **ne pas la gater director/admin**.
- Téléchargement : `paiementService.telechargerFacture(familyId)` (blob) puis **`saveBlob(blob, 'facture_YYYY-MM-DD.pdf')`** — **pas `saveExport`**, qui suffixerait `.xlsx`.

## 6. Modifier le rendu — carte du layout

Tout le dessin est dans `layout(array $f)`, en **coordonnées absolues, origine en haut à gauche** (`y` croissant vers le bas ; les helpers convertissent via `PAGE_H - yTop`). Page A4 : `595.28 × 841.89`, marges `LEFT = 50`, `RIGHT = 545.28`.

```
 y=64    nom de l'école (15 pt, F2)              │ « FACTURE » aligné droite (19 pt, F2)
 y=82+   schoolLines() (9 pt, GRAY, pas de 13)   │ y=84  N° facture
                                                  │ y=97  Date d'émission
                                                  │ y=110 Année scolaire
 y=172   « FACTURÉ À » (8 pt, F2, GRAY)
 y=189   noms des responsables (10.5 pt, F2)
 y=203+  clientAddressLines() (9.5 pt, GRAY, pas de 13)
 y=256   bandeau du tableau : rect gris (0.95 0.96 0.97), h=24
         + « Désignation » (gauche+10) et « Montant » (droite−10), 9 pt F2
 y=300   « Frais de scolarité — Année scolaire {label} »  + montant à droite (10 pt)
 y=313   « N élève(s) inscrit(s) » (8.5 pt, GRAY) — seulement si nombre_eleves > 0
 y=324   trait de séparation
 y=348+  totalRows() à x=330, pas de 17 :
             assujetti  → Total HT · TVA (20 %) · Total TTC  (HT = total / 1.2)
             sinon      → Montant total
             puis       → Montant réglé · Exonéré (si > 0) · Reste à payer
                          (RED si reste > 0, GREEN sinon)
 +14     bande pleine largeur, h=34, texte centré 11 pt F2 :
             acquittée  → fond vert clair  « FACTURE ACQUITTÉE [LE jj/mm/aaaa] »
             sinon      → fond ambre clair « Facture non acquittée — reste à payer : X € »
 y=778   trait de pied
 y=792   mention TVA centrée (8.5 pt, GRAY) — si vat_mention
```

`schoolLines()` : adresse (multi-lignes découpée sur `\n`), `CP Ville`, `email · téléphone`, `SIRET : …`, et `N° TVA : …` **uniquement si `assujetti`**.
`clientAddressLines()` : adresse multi-lignes + `CP Ville`.

### Helpers disponibles
```php
$this->text($x, $yTop, $size, 'F1'|'F2', $str, $color)   // F1 = Helvetica, F2 = Helvetica-Bold
$this->textRight($xRight, $yTop, …)                       // aligné droite via width()
$this->textCenter($yTop, …)                               // centré sur la page
$this->rect($x, $yTop, $w, $h, '0.95 0.96 0.97')          // aplat RGB normalisé
$this->line($x1, $yTop1, $x2, $yTop2)                     // trait 0.7 pt gris
self::euros($value, $cents = false)                       // « 1 234 € » / « 1 234,56 € »
self::width($str, $size)                                  // largeur en points (HELVETICA_WIDTHS)
```
⚠ `width()` retombe sur **556 unités** pour tout caractère absent de la table → un glyphe non listé décale l'alignement droite/centre sans erreur visible.
⚠ `euros()` produit un **espace insécable fin** comme séparateur de milliers via `number_format(..., ',', ' ')` — cohérent avec le reste de l'app.
⚠ Les prix sont **TTC** : en mode `assujetti`, le HT est **rétro-calculé** (`total / 1.2`), il n'est jamais stocké.

Après modification, **ouvre réellement le PDF** (Preview/Acrobat) : un xref décalé ne se voit pas à la lecture du code.
```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/login -H "Content-Type: application/json" \
  -d '{"email":"relhanti@gmail.com","password":"password"}' | jq -r .token)
curl -s -H "Authorization: Bearer $TOKEN" -H "X-School-Id: 1" \
  http://localhost:8000/api/families/1/paiements/facture -o /tmp/facture.pdf && open /tmp/facture.pdf
```

## 7. Checklist

- [ ] Gate = `callerCanAccessFamily` (pas un gate staff).
- [ ] « Montant réglé » = `montant_encaisse`, exonéré affiché à part.
- [ ] `reste` clampé à 0.
- [ ] Responsables dédoublonnés.
- [ ] `%.2F` partout, jamais `%f`.
- [ ] Nouveaux caractères → présents dans `HELVETICA_WIDTHS`.
- [ ] PDF ouvert et vérifié visuellement.
- [ ] Front : `saveBlob` (pas `saveExport`), bouton non gaté par rôle.

---

**Voir aussi** : `tarification-paiements` · `familles-eleves` · `super-admin-ecoles` (champs TVA de l'école)
