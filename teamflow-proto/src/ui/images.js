/** Lecture d'une image locale, réduite (max `maxSize` px) pour limiter le poids. */
export function readImage(file, maxSize = 1600) {
  return new Promise((resolve, reject) => {
    const fr = new FileReader();
    fr.onerror = reject;
    fr.onload = () => {
      if (file.type === 'image/gif' || file.type === 'image/svg+xml') return resolve(fr.result);
      const img = new Image();
      img.onerror = () => resolve(fr.result);
      img.onload = () => {
        const s = Math.min(1, maxSize / Math.max(img.width, img.height));
        if (s === 1 && file.size < 400000) return resolve(fr.result);
        const c = document.createElement('canvas');
        c.width = Math.round(img.width * s);
        c.height = Math.round(img.height * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        resolve(file.type === 'image/png' ? c.toDataURL('image/png') : c.toDataURL('image/jpeg', 0.85));
      };
      img.src = fr.result;
    };
    fr.readAsDataURL(file);
  });
}
