# SignaConnect Upload (client Windows)

Petit exe WPF (.NET Framework 4.8.1, aucune dépendance à installer) qui envoie un PDF dans
SignaConnect puis ouvre le navigateur sur le brouillon, à l'étape « Placer les zones ».
Il se branche derrière une imprimante PDF (PDF24 ou PDFCreator) : on imprime, la suite se fait
dans le navigateur.

Nécessite le plugin **SignaConnect 0.7.0** ou plus sur le site.

## Compiler

Ouvrir `SignaConnectUpload.sln` dans Visual Studio 2022, configuration **Release**, Générer.
L'exe est dans `SignaConnectUpload\bin\Release\net481\SignaConnectUpload.exe` : c'est le seul
fichier à copier sur les postes (le setup Inno Setup l'installe dans `C:\Program Files (x86)\SignaConnect\`).

## Premier lancement

Lancer l'exe sans argument : adresse du site (ex. `https://wpcodetool.smbb-logiciel.com`),
identifiant WordPress et mot de passe. Le mot de passe sert une seule fois : le site renvoie un
jeton propre au poste, gardé chiffré par Windows dans `%APPDATA%\SignaConnect\client.json` (repris automatiquement de `%APPDATA%\SignConnect` après la mise à jour)
(lisible seulement par ce compte Windows). Le poste apparaît ensuite dans SignaConnect,
onglet **Mon profil > Postes connectés**, où on peut le révoquer.

## Utilisation

```
SignaConnectUpload.exe [--delete] "C:\chemin\document.pdf"
```

* Une seule équipe : l'envoi part tout de suite, le navigateur s'ouvre, la fenêtre se ferme.
* Plusieurs équipes : liste déroulante (la dernière utilisée est présélectionnée), puis Entrée.
* `--delete` : supprime le PDF après un envoi réussi (fichiers temporaires de l'imprimante).
* Sans argument : écran de connexion, bouton « Choisir un PDF… », glisser-déposer d'un PDF.

Le lien ouvert dans le navigateur connecte l'utilisateur automatiquement : il ne sert qu'une
fois et expire au bout de 2 minutes. Expiré, il mène à l'écran de connexion puis au brouillon.

## Brancher PDF24 Creator

Le mieux est une imprimante dédiée, pour que l'imprimante « PDF24 » habituelle reste inchangée.

1. Ouvrir **PDF24 > Paramètres > Imprimante PDF** et ajouter une imprimante nommée par
   exemple « SignaConnect » (ou utiliser l'imprimante PDF24 existante).
2. Action après l'impression : **Enregistrer automatiquement**, dans un dossier temporaire
   (ex. `%TEMP%\SignaConnect`).
3. Cocher **Exécuter la commande suivante après l'enregistrement** et saisir :
   ```
   "C:\Program Files (x86)\SignaConnect\SignaConnectUpload.exe" --delete "$file"
   ```
   (`$file` est remplacé par PDF24 par le chemin complet du PDF enregistré.)

## Brancher PDFCreator Free (testé en 5.1.2)

1. **Profils > Ajouter** : profil « SignaConnect ».
2. **Sauvegarder** : « Automatique », nom `<Title>`, répertoire `C:\Temp\SignaConnect`.
3. **Actions > Ajouter une action > Exécuter le programme** :
   programme `C:\Program Files (x86)\SignaConnect\SignaConnectUpload.exe`,
   paramètres supplémentaires `--delete`, « Attendre la fin d'exécution » décoché.
   PDFCreator ajoute le chemin du PDF à la ligne de commande.
4. **Imprimante > Ajouter une imprimante** « SignaConnect » liée à ce profil.

## API utilisée (plugin SignaConnect)

Routes appelées via `/?rest_route=` (marche avec ou sans permaliens) :

| Route | Rôle |
|---|---|
| `POST /signconnect/v1/client/login` | `login`, `password`, `device` → `token`, utilisateur, équipes |
| `GET /signconnect/v1/client/me` | utilisateur et équipes (en-tête `X-SignConnect-Token`) |
| `POST /signconnect/v1/client/upload` | multipart `file` + `team_id` → `document_id`, `open_url` |
| `POST /signconnect/v1/client/logout` | révoque le jeton du poste |

10 échecs de connexion en 15 minutes depuis une même IP bloquent la route de connexion
pendant 15 minutes.
