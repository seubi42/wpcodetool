/** Mise à niveau des anciens documents vers DOC_VERSION. */
import { DOC_VERSION } from './model.js';
import { uid } from './util.js';
import { htmlToBlocks } from '../blocks/sanitize.js';

export function migrate(doc) {
  doc.assets = doc.assets || {};
  for (const id of Object.keys(doc.boards)) {
    for (const n of doc.boards[id].nodes) {
      if (n.type !== 'card') continue;
      // v1 -> v2 : HTML libre -> blocs
      if (!Array.isArray(n.blocks)) { n.blocks = htmlToBlocks(n.html); delete n.html; }
      // v2 -> v3 : images intégrées -> assets (hors historique d'annulation)
      for (const b of n.blocks) {
        if (b.type === 'image' && b.src && b.src.startsWith('data:')) {
          const aid = uid('a');
          doc.assets[aid] = { src: b.src };
          b.asset = aid;
          delete b.src;
        }
      }
    }
  }
  doc.version = DOC_VERSION;
  return doc;
}

/** Supprime les images qui ne sont plus utilisées par aucun bloc. */
export function collectAssets(doc) {
  const used = new Set();
  for (const id of Object.keys(doc.boards)) {
    for (const n of doc.boards[id].nodes) {
      if (n.type === 'card') for (const b of n.blocks) if (b.asset) used.add(b.asset);
    }
  }
  for (const aid of Object.keys(doc.assets)) if (!used.has(aid)) delete doc.assets[aid];
}
