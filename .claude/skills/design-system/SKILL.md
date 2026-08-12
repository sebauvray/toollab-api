---
name: design-system
description: Langage visuel de Toollab — palette Tailwind custom, typographie Montserrat/Nunito, densité et tailles de boutons, structure des conteneurs, chips de statut, segmented controls, et la liste des anti-patterns explicitement rejetés par l'utilisateur. À invoquer AVANT d'écrire la moindre classe Tailwind ou de concevoir un écran.
---

# Langage visuel Toollab

> Ces règles sont le fruit d'itérations validées (et de rejets explicites). Les respecter évite de refaire un travail déjà tranché.

## 1. Couleurs — uniquement celles de `tailwind.config.js`

```js
'primary'      : '#343C6A'   // IDENTITÉ seulement : avatars ronds, coches, accents de marque
'default'      : '#222222'   // ACTION : tout bouton primaire, onglet actif, tag actif
'gray-blue'    : '#F8FAFC'   // fond d'application (jamais blanc pur)
'gray-light'   : '#F5F7FA'
'input-stroke' : '#DDDDDD'   // bordure de champ de formulaire
'placeholder'  : '#6A6A6A'   // texte secondaire
'gray-tlb'     : '#A2A1A8'
'blue-link'    : '#718EBF'
'green-tlb'    : '#2EA279'
'yellow-tlb'   : '#FDE047'   // ⚠ BANNI des boutons d'action
```
Séparateur interne récurrent : **`border-[#E6EFF5]`** (seule valeur hex en dur tolérée, omniprésente).

**À ne jamais faire** : couleur hex arbitraire, `bg-indigo-*`/`bg-violet-*` pour une action, `bg-primary` sur un bouton d'action (le primaire d'action est `bg-default`), bouton jaune.

## 1 bis. ⚠ LA BASE : `1rem = 14px`, pas 16px

`assets/css/main.css` (chargé globalement par `nuxt.config.ts`) redéfinit la racine :
```css
html { font-size: 14px; }
@media (max-width: 640px)  { html { font-size: 13px; } }   /* mobile  */
@media (min-width: 1536px) { html { font-size: 15px; } }   /* 2xl     */
```

**Toutes les tailles Tailwind sont donc réduites d'environ 12,5 %** :

| Classe | rem | px réels (desktop) |
|---|---|---|
| `text-xs` | 0.75 | **10,5 px** |
| `text-sm` | 0.875 | **12,25 px** |
| `text-base` | 1 | **14 px** |
| `text-lg` | 1.125 | 15,75 px |

C'est **la** raison pour laquelle l'interface paraît dense et pourquoi `text-base` (14 px réels) est déjà jugé trop gros pour un bouton. Ne « corrige » jamais cette base : tout l'écran a été calibré dessus, et le `rem` s'adapte automatiquement au mobile et aux très grands écrans.

Autres éléments du reset global :
- `appearance: none` sur `input/select/textarea/button` (radios et cases restaurés en `auto`) → un `<select>` natif **n'a plus de flèche** : c'est pour ça que le chevron est dessiné à la main.
- `svg { display:inline-block; vertical-align:middle; flex-shrink:0 }` → pas besoin de `shrink-0` sur chaque icône.
- Scrollbars fines custom (8 px, `rgba(0,0,0,.18)`), `scroll-behavior: smooth`, `overscroll-behavior: contain` sur le body.
- Indicateurs de `input[type=date|time]` : `opacity .6` → `1` au survol.
- ⚠ `body { font-family: 'Nunito', 'Montserrat Alternates', … }` — **« Montserrat Alternates » traîne encore ici** en fallback alors que la police n'est plus chargée. Résidu inoffensif (aucun rendu) mais à nettoyer si tu passes dans ce fichier.
- ⚠ La classe utilitaire `.responsive-modal` (plein écran sous 768 px) est définie mais **utilisée nulle part**.

## 2. Typographie

| Police | Usage |
|---|---|
| **Montserrat** | chrome & structure : titres, en-têtes de tableau, noms propres, libellés de navigation |
| **Nunito** | données & contenu : lignes de tableau, listes, corps de formulaire |

Chargées par `<link>` Google Fonts dans `nuxt.config.ts` : **Montserrat 400-800 + Nunito 400-700**, rien d'autre.
⚠ C'est la **vraie Montserrat**, pas « Montserrat Alternates » (glyphes ronds décoratifs, rejetés).

Application : `font-montserrat` sur les conteneurs de page/chrome, `font-nunito` sur les zones de données (`DataTable`, matrices, listes).

Tailles : `text-xs` / `text-sm` dominants. **`text-2xl` et plus est proscrit** en dehors du titre de la page d'accueil.

## 3. Conteneurs — une seule couche

```html
<div class="bg-white rounded-2xl border">
  <div class="… border-b border-[#E6EFF5]">en-tête</div>
  <div class="divide-y divide-[#E6EFF5]">lignes</div>
</div>
```
- **Une seule couche** de surface : `bg-white rounded-2xl border` + séparateurs internes fins.
- **Jamais de card-dans-card.** Jamais une bordure autour de chaque petit élément.
- `rounded-2xl` pour les tables/cartes/modales, `rounded-lg` pour les boutons et petits éléments.
- Ombres : `shadow-sm` maximum sur une carte, `shadow-xl` réservé aux modales. **Pas d'ombre lourde décorative.**

## 4. Boutons

| Contexte | Classes |
|---|---|
| Action de page (barre d'actions, en-tête de section) | `px-3 py-1.5 text-xs font-medium rounded-lg` |
| Formulaire / modale | `px-4 py-1.5 text-sm rounded-lg` (`SaveButton`, `CancelButton`) |
| Primaire | `bg-default text-white hover:opacity-90` — **sans bordure** |
| Secondaire | `bg-white border border-gray-300 text-gray-700 hover:bg-gray-50` |
| Danger | `border border-red-200 text-red-600 hover:bg-red-50` (ou `bg-red-600 text-white` en confirmation) |
| Bouton-icône fantôme | `inline-flex w-7 h-7 rounded-lg text-gray-500 hover:text-default hover:bg-gray-100` + `title` |

**Toujours `text-xs` ou `text-sm`.** Sans classe de taille, l'élément hérite de `text-base` = **14 px réels** (cf. §1 bis) — signalé « trop gros » plusieurs fois.
Un bouton d'action de page en `text-sm` a été signalé « plus gros que les autres » : sur une barre d'actions, rester en `text-xs`.

**Bordure des secondaires = `border-gray-300`** (visible). `gray-200` et `input-stroke` (#DDDDDD) se lisent comme « pas de bordure » — standardisé, ne pas redescendre.
Les **champs de formulaire**, eux, gardent `border-input-stroke`.

## 5. Chips & statuts

**Statut (label passif)** : tint léger + ring + texte de la même teinte, avec un point optionnel.
```html
<span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[11px] font-medium ring-1
             bg-green-50 text-green-700 ring-green-200">
  <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Payé
</span>
```
Palette sémantique en vigueur : vert = validé/payé · ambre = partiel/attention · rouge = manquant/exclusion · bleu = information/exonéré/fin de cursus · violet = directeur · gris = neutre/non décidé.

**Exception unique** — un chip en **aplat plein** (`bg-{c}-600 text-white` + ✓ + `ring-2`) signifie **filtre actif** (stat-chips de `/decisions`), jamais un statut.

Le composant `Tag.vue` gère les 5 statuts de règlement (`paid`, `pending`, `incomplete`, `exempted`, `no_enrollment`) — **l'utiliser plutôt que de recréer un chip**.

## 6. Choix binaires & sélections

**Segmented control** — jamais de radios nus :
```html
<div class="inline-flex rounded-lg border border-input-stroke divide-x divide-input-stroke overflow-hidden">
  <button :class="v === 'M' ? 'bg-default text-white' : 'bg-white text-gray-700'"
          class="px-3 py-1.5 text-xs">Masculin</button>
  …
</div>
```
Utilisé pour : genre H/F, montant/pourcentage, type de règlement, bascule de vue.

**Toggle de vue à icônes** (cf. `/classes`) : capsule `inline-flex p-1 bg-white border border-gray-300 rounded-lg`, bouton actif `bg-default text-white`, `w-7 h-7 rounded-md`, mémorisé en `localStorage`.

## 5 bis. Couleurs de genre — DEUX palettes, dupliquées 9 fois

**Palette CLASSE** (`classrooms.gender`) :
```js
{ Hommes: '#93C5FD', Femmes: '#FDA4AF', Enfants: '#FCD34D', Mixte: '#86EFAC' }   // fallback #6B7280 / #9CA3AF
```
**Palette PERSONNE** (`user_infos.gender`) :
```js
{ M: '#93C5FD', F: '#FDA4AF' }                                                    // fallback #343C6A ou #9CA3AF
```

Elles sont **redéfinies localement dans 10 fichiers** (aucune constante partagée) :

| Fichier | Palette | `Mixte` présent ? |
|---|---|---|
| `components/form/SelectGenre.vue` | classe (+ pastilles) | ✔ |
| `components/schedule/ScheduleGrid.vue` | classe | ✔ |
| `pages/classes/index.vue` · `classes/[id].vue` · `cursus/[id].vue` | classe | ✔ |
| `pages/professeur/classes/index.vue` · `family/[id]/classes.vue` | classe | ✔ |
| **`pages/annees-scolaires/index.vue`** · **`reconduire.vue`** | classe | **✖ oubli** → une classe mixte n'a pas d'accent coloré |
| `pages/family/[id]/index.vue` | personne (M/F) | — (normal) |

Usage : bandeau de carte (`backgroundColor`), accent `border-l-4`, point de couleur, avatar d'initiales.
Si tu factorises, fais-le en **deux** constantes distinctes (classe ≠ personne) et corrige les deux `Mixte` manquants au passage.

## 6 bis. Cases à cocher — il n'existe pas de composant

`main.css` neutralise l'apparence native de tous les champs (`appearance: none`) **puis la restaure** pour `input[type=radio|checkbox]`. Résultat : une case à cocher a le style natif du système, sauf si la page la re-neutralise.

Deux pages le font (`statistiques/cheques.vue`, `statistiques/exonerations.vue`) avec un `<style scoped>` **dupliqué** de ~40 lignes, dont un override `input[type="checkbox"] { appearance: none !important }` — d'où les 66 `!important` du projet, tous concentrés là.

Le motif retenu (case noire, coche SVG en `data:` URI) :
```css
.custom-checkbox { width:1rem; height:1rem; border:1px solid #d1d5db; border-radius:.25rem;
                   background:#fff; appearance:none; cursor:pointer; flex-shrink:0 }
.custom-checkbox:checked { background-color:#000; border-color:#000;
                   background-image:url("data:image/svg+xml,…coche blanche…"); background-size:100% 100% }
.custom-checkbox:focus, .custom-checkbox:focus-visible { outline:none; box-shadow:none; border-color:#000 }
```
Si tu as besoin de cases à cocher sur un **troisième** écran : extrais d'abord ce bloc dans un composant `form/InputCheckbox.vue` plutôt que de le copier une fois de plus.

## 7. Densité

`py-1.5` sur les lignes, `gap-x-1.5`/`gap-2` entre éléments proches, `px-5 py-4` dans les blocs de contenu, `mb-3`/`mb-4` entre sections. Les inputs **et** les `<select>` natifs sont alignés sur `py-1.5 text-sm` (un select en `py-2` sans `text-sm` dépasse les `InputText`). Chevron de select centré : `absolute right-2.5 top-1/2 -translate-y-1/2`.

## 8. Tableaux & matrices

- En-tête `font-montserrat font-semibold text-gray-600`, corps `font-nunito`.
- Colonne d'identité **figée** : `sticky left-0 z-10 bg-white` (+ `border-r border-[#E6EFF5]`).
- Colonnes de dates **groupées par mois** (double `<thead>` avec `colspan`).
- **Pas de ligne de totaux ∑** en pied de matrice (retirée des deux matrices d'émargement).
- Une seule action par ligne ⇒ **pas d'en-tête de colonne** (`label: ''`) et **bouton-icône** aligné à droite — jamais un lien texte souligné « Voir » / « Paiement ».

## 9. Popovers & infobulles

- **Jamais le `title` natif** pour une information métier importante (infobulle système jugée moche et rejetée).
- Motif/note au survol : **`hoverTip` maison** — `ref` + `getBoundingClientRect()`, positionné `fixed`, `z-40`, `pointer-events-none`, clampé `Math.min(r.left, window.innerWidth - 220)`, style `bg-white border border-amber-200 rounded-lg shadow-md text-[11px]`.
- Édition ancrée (motif d'absence, note de décision) : même mécanique en `z-50`, avec `nextTick(() => input.focus())`, commit au `@blur` / `@keydown.enter`.
- **Règle** : le survol est une affordance d'**édition**. En lecture seule (vues admin), l'information s'affiche **en clair, inline**.

## 10. Formulaires

Deux mécaniques de label flottant coexistantes :
1. **Composants** `form/InputText|InputNumber|InputSelect|SelectDay|SelectGenre` — `isFocused`/`isFloating`, label qui remonte en `-top-2 left-2 bg-white px-1 text-default`.
2. **CSS-only** (quand on ne peut pas utiliser `InputText`, ex. autocomplete à events custom) : `peer` + `placeholder=" "` (espace obligatoire) + `<span>` avec `peer-focus:top-0` et `peer-[:not(:placeholder-shown)]:top-0`.

⚠ Ancrer les labels flottants et les suffixes (€) sur une **valeur fixe** (`top-[19px]`), **pas `top-1/2`** : sinon ils glissent quand un `<p>` d'erreur agrandit le conteneur `relative`.

Montants : `€` intégré (`pr-7` + `<span class="absolute right-2.5 text-gray-400">€</span>`) + `tabular-nums`.
Petits compteurs : **stepper − / +** borné, pas un `<select>`.
Heures : **`<input type="time">` natif, point final** — un `SelectTime` à pas de 15 min a été tenté puis **rejeté** (empêche 07:18). Le picker natif est moche mais correct.

## 11. Erreurs

- Message inline sous le champ : `text-xs text-red-500 mt-1`.
- Bandeau de section : `bg-red-50 text-red-700 ring-1 ring-red-200 rounded-lg px-3 py-2 text-xs`.
- Succès : `bg-green-50 text-green-700 ring-1 ring-green-200`. **Jamais `bg-red-500`/`bg-green-500` pleins** pour un message.
- **Ne jamais doubler** un bandeau d'erreur API par un message au niveau du champ (« double message horrible »).
- Reformuler côté client un message serveur qui n'a pas de sens dans le contexte (ex. « ne peut pas dépasser le montant dû » pour une exonération).

## 12. Anti-patterns explicitement rejetés

| ✗ | Pourquoi |
|---|---|
| Segmented pills grises `rounded-full` | tic « template IA » |
| Chip-soup (plusieurs chips par ligne) | illisible |
| Bordure autour de chaque élément | bruit visuel |
| Card-dans-card | profondeur inutile |
| `text-2xl`+ pour un titre de section | disproportionné |
| Ombres lourdes, gradients décoratifs, `shadow-inner` | daté |
| Boutons indigo/violet, bouton jaune `bg-yellow-tlb` | hors charte |
| Émojis (💵💳🧾✋) dans les vues métier | non professionnel → **icônes SVG monochromes** dans une tuile `w-6 h-6 rounded-md bg-gray-100 text-gray-500` |
| Chips colorés pour les **types de paiement** | jugés « horriblissimes » — icône SVG + libellé `text-xs text-gray-700` |
| Gros carrés `border-2` avec encoche verte (sélection) | kitsch → **lignes radio** dans un conteneur `divide-y` |
| Pulse / animation décorative, skeleton, accordéon redondant | bruit |
| Sticky `top-[Npx]` en dur | fragile |
| Avatars ou liseré coloré sur **chaque** ligne de table | surcharge |

## 13. Animations

Discrètes et utiles : `transition-colors`, `transition-opacity`, `duration-150/200`.
Une seule animation « signature » tolérée : les **écrans d'auth** (`panel-mesh`, `panel-grid`, `logo-pop`, `letter-in` lettre par lettre, `baseline-in`) — **coupées par `prefers-reduced-motion`**. Ce style est **dupliqué dans les 4 pages** `login`, `set-password`, `reset-password`, `forgot-password` : toute retouche doit être faite 4 fois (skill `pages-auth-publiques`).
Panneau de formulaire qui s'ouvre : `.panel-in` (fade + translateY, 220 ms, en `<style scoped>`).

## 13 bis. E-mails : autre design system

Les templates Blade d'e-mails **ne suivent pas** cette charte (contraintes des clients mail) : `<style>` dans le `<head>`, classes simples, largeur 600 px, police Arial, bouton en **`#343C6A`** (le `primary`, pas le `default`). Voir la skill `emails-templates`. **Ne pas y appliquer Tailwind ni les tokens de l'app.**

## 14. Écrans de référence (à imiter)

| Écran | Pourquoi c'est la référence |
|---|---|
| `pages/decisions/index.vue` | bandeau de pilotage + stat-chips filtrants + table triable |
| `pages/family/[id]/index.vue` | bandeau d'identité pleine largeur, cartes de même hauteur, barre d'actions |
| `pages/family/[id]/paiement.vue` | liste `divide-y`, formulaire en panneau, floating labels CSS-only |
| `pages/professeur/classes/[id].vue` | matrice éditable, autosave, popovers ancrés |
| `pages/settings/index.vue` | onglets soulignés, cartes `rounded-2xl border p-5`, messages en tint doux |
| `components/settings/RoleCard.vue` | carte sélectionnable propre (radio/check/affichage) |

---

**Voir aussi** : `ui-components` (ce qui existe déjà) · `modals` (gabarit) · `formulaires-validation` (inputs et erreurs) · `verification-visuelle` (contrôler le rendu)
