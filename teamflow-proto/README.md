# TeamFlow — démonstrateur (v0.4)

Whiteboard sur canevas infini : cartes riches et colorées, sections, flèches, sous-whiteboards imbriqués.
JavaScript pur (modules ES), sans framework ni dépendance à l'exécution.

## Lancer

Ouvrir `index.html` dans Chrome, Edge ou Firefox (double-clic suffit) : il charge `dist/`, déjà compilé.
Les données sont enregistrées dans le navigateur (localStorage). Export / import JSON dans la barre du haut.

## Développer

Le code source est dans `src/` ; `dist/` est produit par [esbuild](https://esbuild.github.io/) (seule dépendance, de développement).

```
npm install
npm run build      # src/ → dist/teamflow.js + dist/teamflow.css
npm run watch      # recompile à chaque enregistrement
```

Penser à changer `?v=` dans `index.html` (et `version` dans `src/index.js`) à chaque livraison, pour que le navigateur ne garde pas l'ancienne version en cache.

Tests (Playwright, navigateur sans interface) : `node tests/features.js`, `tests/blockselect.js`, `tests/regression.js`, `tests/smoke.js`,
et `node tests/migrate.js <chemin de l'index.html v0.3>` pour vérifier la reprise des anciennes données.

## Utilisation

- **Double-clic sur le fond** : nouvelle carte. **Double-clic sur une carte** : la modifier. Échap (ou « Terminer ») : finir.
- **Titre facultatif** : une carte peut ne contenir qu'une image ou du texte. En édition, le bouton « T » au-dessus de la carte ajoute un titre.
- **Outils de carte** (au survol, en haut à droite) : replier / déplier (l'état est enregistré), ouvrir en plein écran.
- **Plein écran** : la carte s'ouvre comme une page pour écrire confortablement ; « Revenir au schéma » ou Échap pour revenir.
- **Cartes en blocs** : texte, titres 1-3, liste à cocher, à puces, numérotée, dépliable, citation, encadré, code, séparateur, image.
  - Tape **/** pour le menu des blocs (filtrer en tapant : `/tit`, `/code`, `/image`…), ou bouton « Bloc » dans la barre.
  - Raccourcis Markdown en début de ligne : `# `, `## `, `### `, `- `, `1. `, `[] `, `> `, `>> ` (dépliable), ` ``` ` (code), `---` (séparateur).
  - Entrée : nouveau bloc · Maj+Entrée : retour à la ligne · Tab / Maj+Tab : indenter une liste · Retour arrière en début de bloc : fusionner avec le précédent.
  - **Poignée ⠿** à gauche de chaque bloc : glisser pour réordonner, l'envoyer dans une autre carte, ou le lâcher sur le canevas pour en faire une carte. Un clic ouvre le menu du bloc.
  - Coller ou glisser une image dans une carte ajoute un bloc image.
- **Sélectionner plusieurs blocs** (en édition) : glisser la souris d'un bloc à l'autre, Maj+clic, Maj+↑/↓ depuis le bord d'un bloc, ou Ctrl+A deux fois.
  Ensuite : Suppr, Ctrl+C / Ctrl+X puis Ctrl+V dans n'importe quelle carte (les blocs sont recréés à l'identique ; en dehors de TeamFlow on obtient du texte ou du HTML),
  Tab / Maj+Tab, glisser la poignée pour déplacer tout le groupe, Échap pour annuler.
- **Liens entre cartes** : tape **@** puis le début du titre (ou du texte) d'une carte, de n'importe quel whiteboard. Un clic sur le lien amène à la carte, en changeant de whiteboard si besoin. Le lien suit les changements de titre.
- **Flèches** : glisser un point bleu au bord d'une carte vers une autre. Sélectionner une flèche : couleur, libellé, sens (← — → ↔), trait plein ou pointillé, inverser, supprimer.
- **Sections** : on les déplace par leur titre ; elles emportent leurs cartes. Coin bas-droit pour redimensionner.
- **Sous-whiteboard** : bouton « Whiteboard » ou « En whiteboard » sur une carte sélectionnée, puis « Ouvrir ». Fil d'Ariane en haut, Échap pour remonter.
- **En bas à droite** : thème clair / sombre, vue d'ensemble, mode souris / touchpad, zoom −/+, 100 %, tout afficher.
  - Souris : molette = zoom, glisser le fond = se déplacer. Touchpad : deux doigts = se déplacer, pincer = zoomer, glisser le fond = sélection.
  - Dans les deux modes : Espace + glisser ou clic molette = se déplacer, Maj + glisser = sélection.
- **Clavier** : Suppr, Ctrl+Z / Ctrl+Y, Ctrl+D, Ctrl+A, Entrée (modifier), +/−/0, Maj+1 (tout afficher).
- Les confirmations et saisies passent par les boîtes de dialogue de l'application, jamais par celles du navigateur.

## Architecture

Point d'entrée : `src/index.js` expose `window.TeamFlow` (`mount`, `storage`, `version`…). Tout le CSS est confiné sous `.tf-root`.
`TeamFlow.mount(element, { storage })` crée une `App` dans n'importe quel conteneur.

```
src/
  index.js            API publique
  app.js              orchestration : relie les modules, actions de l'utilisateur
  core/               sans DOM d'interface
    model.js            fabriques (whiteboard, carte, section, flèche, bloc), validation
    store.js            document, historique annuler/rétablir, images, enregistrement différé
    migrate.js          reprise des formats 1 et 2
    selection.js        sélection de nœuds / flèche
    geometry.js         ancres et tracés des flèches, intersections, zoom
    constants.js, util.js, demo.js
  storage/            adaptateurs { load(), save(doc) } : local (localStorage), rest (WordPress)
  canvas/             le whiteboard
    viewport.js         déplacement / zoom
    nodes.js            DOM des cartes et sections
    edges.js            flèches (SVG), mises à jour ciblées
    interactions.js     souris / touchpad sur le canevas
    minimap.js
  blocks/             contenu des cartes
    types.js            catalogue des blocs et du menu /
    render.js           blocs → HTML ; liens @
    editor.js           édition (clavier, Markdown, collage, menus / et @)
    blockselect.js      sélection de plusieurs blocs
    clipboard.js        copier / coller de blocs
    drag.js             glisser des blocs (seul ou en groupe)
    caret.js, sanitize.js, model.js
  features/focus.js   vue plein écran d'une carte
  ui/                 barre du haut, barres flottantes, menus, dialogues, raccourcis, icônes
  styles/             un fichier CSS par zone, réunis par index.css
```

Règles suivies : les modules `core/` ne touchent pas au DOM de l'interface ; chaque fonctionnalité a son module ;
les rendus sont incrémentaux (seules les flèches des cartes qui bougent sont recalculées, le contenu d'une carte n'est
reconstruit que s'il a changé).

### Données (format 3)

```
{ version: 3, rootBoardId,
  boards: { id: { title, parent, nodes[], edges[] } },
  assets: { id: dataURL },          // images, hors de l'historique d'annulation
  views:  { boardId: { x, y, z } } }
```

Une carte : `{ id, type:'card', x, y, w, title?, color, collapsed?, childBoardId?, blocks[] }`.
Un bloc : `{ id, type, html | text | asset, checked?, open?, body?, indent? }` ; un lien @ est `<span class="tf-mention" data-card="id">`.
Une flèche : `{ id, from, to, color, label, dir?: 'start'|'none'|'end'|'both', style?: 'solid'|'dashed' }`.
Les données des versions précédentes sont converties automatiquement au chargement.

### Intégration WordPress (plus tard)

Une page d'admin fait `wp_enqueue_script/style` de `dist/teamflow.js` et `dist/teamflow.css`, puis appelle
`TeamFlow.mount(el, { storage: TeamFlow.storage.rest(url, { nonce }) })` avec une route `register_rest_route` protégée par `X-WP-Nonce`.
