/**
 * Catalogue des types de blocs. Pour ajouter un type :
 *  1. le déclarer ici,
 *  2. décrire son rendu dans blocks/render.js,
 *  3. (si besoin) son comportement clavier dans blocks/editor.js.
 */
export const BLOCK_TYPES = {
  text: { label: 'Texte', icon: 'Aa', keys: 'texte text paragraphe normal p', ph: 'Tape « / » pour un bloc, « @ » pour lier une carte' },
  h1: { label: 'Titre 1', icon: 'H1', keys: 'titre heading h1 header', ph: 'Titre 1' },
  h2: { label: 'Titre 2', icon: 'H2', keys: 'titre heading h2 header sous-titre', ph: 'Titre 2' },
  h3: { label: 'Titre 3', icon: 'H3', keys: 'titre heading h3 header', ph: 'Titre 3' },
  todo: { label: 'Liste à cocher', icon: '☑', keys: 'todo tâche tache checkbox case cocher', ph: 'À faire', list: true },
  bullet: { label: 'Liste à puces', icon: '•', keys: 'puce bullet liste ul', ph: 'Élément', list: true },
  number: { label: 'Liste numérotée', icon: '1.', keys: 'numéro numero number liste ol', ph: 'Élément', list: true },
  toggle: { label: 'Liste dépliable', icon: '▸', keys: 'toggle dépliable depliable replier', ph: 'Titre dépliable', list: true },
  quote: { label: 'Citation', icon: '❝', keys: 'citation quote', ph: 'Citation' },
  callout: { label: 'Encadré', icon: '💡', keys: 'encadré encadre callout note info', ph: 'Note' },
  code: { label: 'Bloc de code', icon: '&lt;/&gt;', keys: 'code pre programme', ph: 'Code' },
  divider: { label: 'Séparateur', icon: '—', keys: 'séparateur separateur divider ligne hr', isVoid: true },
  image: { label: 'Image', icon: '▣', keys: 'image photo img picture', isVoid: true }
};

/** Types dont le contenu est du texte éditable (convertibles entre eux). */
export const TEXT_TYPES = ['text', 'h1', 'h2', 'h3', 'todo', 'bullet', 'number', 'toggle', 'quote', 'callout', 'code'];

/** Entrées du menu « / ». */
export const SLASH_ITEMS = ['text', 'h1', 'h2', 'h3', 'todo', 'bullet', 'number', 'toggle', 'quote', 'callout', 'code', 'divider', 'image']
  .map((t) => ({ id: t, label: BLOCK_TYPES[t].label, icon: BLOCK_TYPES[t].icon, keys: BLOCK_TYPES[t].keys }))
  .concat([
    { id: 'mention', label: 'Lien vers une carte', icon: '@', keys: 'lien link carte mention @' },
    { id: 'date', label: 'Date du jour', icon: '📅', keys: 'date jour today aujourd' },
    { id: 'now', label: 'Date et heure', icon: '🕒', keys: 'heure now maintenant time' }
  ]);

/** Raccourcis Markdown en début de ligne (suivis d'une espace). */
export const MD_PREFIX = {
  '#': 'h1', '##': 'h2', '###': 'h3', '-': 'bullet', '*': 'bullet', '1.': 'number',
  '[]': 'todo', '[ ]': 'todo', '>': 'quote', '>>': 'toggle'
};

export const isVoid = (type) => !!(BLOCK_TYPES[type] && BLOCK_TYPES[type].isVoid);
export const isList = (type) => !!(BLOCK_TYPES[type] && BLOCK_TYPES[type].list);
