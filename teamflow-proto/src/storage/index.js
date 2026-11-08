import { localStorageAdapter } from './local.js';
import { restAdapter } from './rest.js';

export const storage = { local: localStorageAdapter, rest: restAdapter };
