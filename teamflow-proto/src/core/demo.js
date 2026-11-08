/** Contenu de démonstration. */
import { DOC_VERSION, newBoard, newCard, newSection, newEdge, nb } from './model.js';

export function demoData() {
  const root = newBoard('Tableau principal');
  const wp = newBoard('Intégration WordPress');
  const sec = newBoard('Sécurité');
  const boards = {};
  boards[root.id] = root; boards[wp.id] = wp; boards[sec.id] = sec;
  const T = (h) => nb('text', h);

  // --- root
  const welcome = newCard(-440, 20, {
    w: 350, color: 'green', title: 'Bienvenue dans TeamFlow',
    blocks: [
      T('Un démonstrateur de whiteboard : des cartes faites de <b>blocs</b>, reliées par des flèches.'),
      nb('h3', 'Pour commencer'),
      nb('bullet', '<b>Double-clic</b> sur le fond : nouvelle carte'),
      nb('bullet', '<b>Double-clic</b> sur une carte : la modifier'),
      nb('bullet', 'Tape <code>/</code> dans une carte pour insérer un bloc, <code>@</code> pour lier une autre carte'),
      nb('bullet', 'Attrape la <b>poignée ⠿</b> à gauche d\'un bloc pour le déplacer, même vers une autre carte'),
      nb('bullet', 'Tire un <b>point bleu</b> au bord d\'une carte pour créer une flèche'),
      nb('callout', 'Les cartes avec l\'icône ▦ ouvrent un <b>sous-whiteboard</b>.')
    ]
  });
  const s1 = newSection(0, 0, { w: 960, h: 460, color: 'yellow', title: '⭐ Lancement du projet' });
  const vision = newCard(40, 50, {
    w: 280, title: 'Vision',
    blocks: [
      T('Un espace visuel pour organiser projets et idées.'),
      nb('quote', 'Des cartes libres, reliées entre elles, et imbriquées à volonté.')
    ]
  });
  const demo = newCard(360, 50, {
    w: 280, color: 'blue', title: 'Démonstrateur JS',
    blocks: [
      nb('todo', 'Canevas infini, zoom &amp; minimap', { checked: true }),
      nb('todo', 'Flèches et sous-whiteboards', { checked: true }),
      nb('todo', 'Cartes en blocs + menu /', { checked: true }),
      nb('todo', 'Glisser des blocs entre cartes', { checked: true }),
      nb('todo', 'Tags et recherche')
    ]
  });
  const wpCard = newCard(680, 50, {
    w: 240, color: 'purple', title: 'Intégration WordPress', childBoardId: wp.id,
    blocks: [T('Plus tard : intégrer TeamFlow dans l\'admin WordPress.')]
  });
  const questions = newCard(360, 270, {
    w: 280, color: 'orange', title: 'Questions ouvertes',
    blocks: [
      nb('number', 'Usage perso ou collaboratif ?'),
      nb('number', 'Où stocker les données ?'),
      nb('toggle', 'Pistes de stockage', { open: false, body: 'localStorage pour la démo, puis API REST WordPress.' })
    ]
  });
  const s2 = newSection(0, 560, { w: 960, h: 340, color: 'blue', title: '💡 Idées en vrac' });
  const nav = newCard(40, 610, {
    w: 280, title: 'Modes de navigation',
    blocks: [
      nb('h3', 'Souris'),
      T('Molette = zoom, glisser le fond = se déplacer.'),
      nb('h3', 'Touchpad'),
      T('Deux doigts = se déplacer, pincer = zoomer, glisser le fond = sélection.'),
      nb('divider'),
      T('Le bouton en bas à droite bascule entre les deux.')
    ]
  });
  const keys = newCard(360, 610, {
    w: 280, title: 'Raccourcis',
    blocks: [
      nb('bullet', '<b>/</b> : menu des blocs'),
      nb('bullet', '<b># </b>, <b>## </b>, <b>- </b>, <b>1. </b>, <b>[] </b>, <b>&gt; </b> : blocs en Markdown'),
      nb('bullet', '<b>Tab</b> : indenter une liste'),
      nb('bullet', '<b>Ctrl+Z / Ctrl+Y</b> : annuler / rétablir'),
      nb('bullet', '<b>Ctrl+D</b> : dupliquer'),
      nb('bullet', '<b>Échap</b> : terminer, puis remonter d\'un niveau')
    ]
  });
  const ideas = newCard(680, 610, {
    w: 240, color: 'pink', title: 'Pistes',
    blocks: [nb('bullet', 'Tags et recherche'), nb('bullet', 'Export en image'), nb('bullet', 'Édition à plusieurs')]
  });
  root.nodes.push(s1, s2, welcome, vision, demo, wpCard, questions, nav, keys, ideas);
  root.edges.push(
    newEdge(vision.id, demo.id),
    newEdge(demo.id, wpCard.id, { label: 'ensuite' }),
    newEdge(demo.id, questions.id, { label: 'à trancher', color: 'orange' })
  );

  // --- WordPress sub board
  wp.parent = { boardId: root.id, nodeId: wpCard.id };
  const admin = newCard(0, 0, {
    color: 'purple', title: 'Page d\'admin',
    blocks: [T('Une page sous le menu WordPress qui charge <code>teamflow.js</code> et appelle <code>TeamFlow.mount()</code>.')]
  });
  const api = newCard(380, 0, {
    color: 'blue', title: 'API REST',
    blocks: [
      nb('code', '', { text: "register_rest_route(\n  'teamflow/v1',\n  '/boards/(?P<id>\\d+)'\n);" }),
      T('Adaptateur prêt : <code>TeamFlow.storage.rest()</code>')
    ]
  });
  const store = newCard(760, 0, {
    title: 'Stockage',
    blocks: [T('Un type de contenu « teamflow_board », le JSON du whiteboard en post meta.')]
  });
  const secCard = newCard(380, 280, {
    color: 'red', title: 'Sécurité', childBoardId: sec.id,
    blocks: [T('Droits et nonces, détaillés dans ce sous-whiteboard.')]
  });
  wp.nodes.push(admin, api, store, secCard);
  wp.edges.push(newEdge(admin.id, api.id, { label: 'fetch' }), newEdge(api.id, store.id), newEdge(api.id, secCard.id, { color: 'red' }));

  // --- Security sub-sub board
  sec.parent = { boardId: wp.id, nodeId: secCard.id };
  const c1 = newCard(0, 0, { title: 'Capacités', blocks: [T('Vérifier <code>current_user_can(\'edit_posts\')</code> dans chaque route.')] });
  const c2 = newCard(360, 0, { title: 'Nonce', blocks: [T('Envoyer l\'en-tête <code>X-WP-Nonce</code> (wp_rest).')] });
  sec.nodes.push(c1, c2);
  sec.edges.push(newEdge(c1.id, c2.id));

  return { version: DOC_VERSION, rootBoardId: root.id, boards, assets: {}, views: {} };
}
