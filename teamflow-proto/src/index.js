/**
 * Point d'entrée public : window.TeamFlow
 *
 *   const app = TeamFlow.mount(document.getElementById('app'), {
 *     storage: TeamFlow.storage.local('teamflow-demo')     // ou TeamFlow.storage.rest(url, { nonce })
 *   });
 */
import './styles/index.css';
import { App } from './app.js';
import { storage } from './storage/index.js';
import { COLORS } from './core/constants.js';
import { demoData } from './core/demo.js';

export const version = '0.4.0';

export function mount(el, opts) {
  return new App(el, opts);
}

export { storage, COLORS as colors, demoData, App };
