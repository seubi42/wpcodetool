OCR embarqué de SignaConnect (lecture des zones de reconnaissance sur les PDF sans texte).

- tesseract.min.js, worker.min.js : tesseract.js 6.0.1 (Apache-2.0)
- core/ : tesseract.js-core 6.1.2, variantes LSTM seules (avec et sans SIMD) (Apache-2.0)
- lang/fra.traineddata.gz : @tesseract.js-data/fra 1.0.0, modèle 4.0.0_best_int (Apache-2.0)

Tout s'exécute dans le navigateur : aucune image n'est envoyée à un service tiers.
Chargé à la demande par assets/zones.js, seulement quand une zone ne contient pas de texte.
