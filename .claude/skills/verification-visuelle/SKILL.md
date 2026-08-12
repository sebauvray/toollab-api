---
name: verification-visuelle
description: Vérifier une modification d'interface Toollab dans un vrai navigateur avec Playwright (installé en devDependency du front) — script jetable de connexion, navigation, capture d'écran, et règle impérative de suppression du script. À invoquer après toute modification UI non triviale, avant de déclarer le travail terminé.
---

# Vérification visuelle

## 1. Pourquoi

Une modification d'interface n'est **pas** vérifiée tant qu'elle n'a pas été rendue. Relire le template ne détecte ni un composant non résolu, ni une grille décalée, ni un panneau de select clippé, ni un label flottant qui glisse.

Playwright est déjà en **devDependency de `toollab-front`** (chromium dans `~/Library/Caches/ms-playwright`).

## 2. Règles impératives

1. Le script jetable **doit** être créé dans `toollab-front/` (résolution du module `playwright`).
2. Le script **doit** être supprimé, **même si l'exécution échoue** :
```bash
node verif.mjs; rm -f verif.mjs      # ⚠ point-virgule, PAS && :
                                     #   un crash laisserait le fichier traîner
```
3. Les captures vont dans le **scratchpad de session**, jamais dans le dépôt.

## 3. Script type

```js
// toollab-front/verif.mjs
import { chromium } from 'playwright'

const OUT = '/private/tmp/claude-501/<session>/scratchpad'
const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } })

// 1. connexion
await page.goto('http://localhost:3000/login')
await page.fill('input[type="email"]', 'relhanti@gmail.com')
await page.fill('input[type="password"]', 'password')
await page.click('button[type="submit"]')
await page.waitForURL(/\/(select-school)?$/, { timeout: 15000 })

// 2. écran à vérifier
await page.goto('http://localhost:3000/family')
await page.waitForSelector('text=Familles', { timeout: 10000 })
await page.waitForTimeout(500)                    // laisse finir les fetch
await page.screenshot({ path: `${OUT}/familles.png`, fullPage: true })

// 3. interaction (modale, onglet…)
await page.click('text=Créer une famille')
await page.waitForTimeout(300)
await page.screenshot({ path: `${OUT}/modale.png` })

// 4. erreurs console
page.on('console', m => m.type() === 'error' && console.log('CONSOLE ERROR:', m.text()))

await browser.close()
```

Puis **relire la capture** avec l'outil Read (l'image est affichée) — ne pas se contenter de « le script a tourné ».

## 4. Ce qu'il faut regarder

- [ ] Aucun `Failed to resolve component` dans la console (import de sous-dossier oublié).
- [ ] En-tête et lignes de `DataTable` alignés (somme des `col-span` cohérente).
- [ ] Boutons homogènes en taille (`text-xs` sur une barre d'actions).
- [ ] Panneau d'`InputSelect` **non clippé** dans une modale scrollable.
- [ ] Labels flottants et `€` qui ne glissent pas quand une erreur apparaît.
- [ ] Bandeau ambre de lecture seule visible sur une année archivée.
- [ ] Responsive : refaire une capture en `{ width: 1024 }` et `{ width: 768 }` si la page est dense.

## 5. Prérequis

```bash
docker ps --format '{{.Names}}\t{{.Status}}'          # nuxt_toollab et api_dev_toollab up ?
curl -s http://localhost:8000/up
curl -sI http://localhost:3000 | head -1
```
Si l'environnement n'est pas lancé, voir la skill `docker-dev`. Si tu ne peux pas le lancer, **dis-le explicitement** dans ta réponse plutôt que d'affirmer que c'est vérifié.

## 6. Alternative : Chrome piloté

Les outils `mcp__claude-in-chrome__*` permettent de piloter le navigateur de l'utilisateur (utile pour rejouer un bug avec sa session réelle). Commencer par `tabs_context_mcp`, créer un **nouvel onglet** plutôt que réutiliser les siens, et éviter tout élément déclenchant une `alert()`/`confirm()` (cela bloque l'extension).

## 7. Ce que la vérification visuelle ne remplace pas

Le comportement serveur (403/409/422, isolation multi-tenant, sémantique « état complet ») se teste par **curl** (skill `debug-api`) ou par un test Pest (skill `tests`). Une capture d'écran ne prouve pas qu'un gate fonctionne.

---

**Voir aussi** : `docker-dev` (lancer l'env) · `design-system` (quoi regarder) · `workflow-livraison`
