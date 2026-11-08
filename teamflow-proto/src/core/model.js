/**
 * Modèle de données.
 *
 * Document  : { version, rootBoardId, boards: {id: Board}, assets: {id: Asset}, views }
 * Board     : { id, title, parent: {boardId, nodeId} | null, nodes: Node[], edges: Edge[] }
 * Node      : Card | Section
 * Card      : { id, type:'card', x, y, w, color, title, blocks: Block[], childBoardId?, collapsed? }
 * Section   : { id, type:'section', x, y, w, h, color, title }
 * Edge      : { id, from, to, label, color, dir?: 'start'|'none'|'end'|'both', style?: 'solid'|'dashed' }
 * Block     : { id, type, html?, text?, asset?, src?, checked?, open?, body?, indent? }
 * Asset     : { src }   (image en data URL pour l'instant ; plus tard IndexedDB / médiathèque WP)
 */
import { uid } from './util.js';

export const DOC_VERSION = 3;

export function newBoard(title, parent) {
  return { id: uid('b'), title: title || 'Sans titre', parent: parent || null, nodes: [], edges: [] };
}

export function newCard(x, y, props) {
  return Object.assign({ id: uid('n'), type: 'card', x: Math.round(x), y: Math.round(y), w: 280, color: 'default', title: '', blocks: [] }, props);
}

export function newSection(x, y, props) {
  return Object.assign({ id: uid('n'), type: 'section', x: Math.round(x), y: Math.round(y), w: 640, h: 380, color: 'gray', title: 'Nouvelle section' }, props);
}

export function newEdge(from, to, props) {
  return Object.assign({ id: uid('e'), from, to, label: '', color: 'default' }, props);
}

/** Crée un bloc. */
export function nb(type, html, extra) {
  return Object.assign({ id: uid('k'), type, html: html || '' }, extra);
}

export function isValidDoc(d) {
  return !!(d && typeof d === 'object' && d.boards && d.rootBoardId && d.boards[d.rootBoardId]);
}

/** Itère sur toutes les cartes de tous les whiteboards. */
export function* allCards(doc) {
  for (const id of Object.keys(doc.boards)) {
    const board = doc.boards[id];
    for (const node of board.nodes) if (node.type === 'card') yield { board, node };
  }
}
