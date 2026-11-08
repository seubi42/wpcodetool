/** Palette partagée par les cartes, sections et flèches. `default` = couleur neutre du thème. */
export const COLORS = {
  default: null,
  gray: '#8b8d98',
  red: '#e5484d',
  orange: '#f76b15',
  yellow: '#e9b308',
  green: '#30a46c',
  teal: '#12a594',
  blue: '#3e7bfa',
  purple: '#8e4ec6',
  pink: '#d6409f'
};

export const COLOR_LABELS = {
  default: 'Par défaut', gray: 'Gris', red: 'Rouge', orange: 'Orange', yellow: 'Jaune',
  green: 'Vert', teal: 'Turquoise', blue: 'Bleu', purple: 'Violet', pink: 'Rose'
};

export const EDGE_DEFAULT_COLOR = '#8a8f99';

/** Sens d'une flèche : `end` (par défaut) →, `start` ←, `none` —, `both` ↔. */
export const EDGE_DIRS = ['start', 'none', 'end', 'both'];

/** Trait d'une flèche : `solid` (par défaut) ou `dashed`. */
export const EDGE_STYLES = ['solid', 'dashed'];

export const ZOOM_MIN = 0.1;
export const ZOOM_MAX = 3;
