# smbb-tomo

Réservation et organisation des ateliers de la pâtisserie Tomo, bâti sur **SMBB WP CodeTool** (qui doit être actif).

## Lot 1 : back-office (cette version)

- **Ateliers** : nom, lieu (un seul par atelier), durée, prix et places par défaut, couleur du calendrier.
- **Créneaux** : date, horaires, places en vente, prix, intervenants. « Complet » est posé et retiré tout seul.
  La fiche d'un créneau donne la liste des inscrits, le pointage, la liste d'attente, l'export CSV,
  l'impression, la duplication sur d'autres dates et l'annulation (les réservations repassent « à confirmer » sans date).
- **Inscriptions** : une ligne par participant (allergies, niveau, âge). Ajout manuel, déplacement,
  report (réservation « à confirmer » sans date) ou annulation définitive, historique de chaque mouvement.
- **Statuts d'une réservation** : à confirmer (bloque la place ; sans créneau = en attente d'une date),
  confirmée, présent à l'atelier, absent à l'atelier, annulée.
- **Stock** : places vendues à venir et réservations sans date, avec placement en un clic.
- **Liste d'attente** : par créneau, dans l'ordre d'arrivée, inscription en un clic quand une place se libère.
- **Calendrier** : mois ou semaine, pastilles colorées selon le remplissage.
- **Rôles** : « Gestionnaire Tomo » (capacité `manage_tomo`) et « Intervenant Tomo » (espace « Mes ateliers »).

Les tables (`wp_tomo_*`) sont créées au premier passage dans l'admin après activation.

## Lot 2 : notifications et rappels

- **Messages** (e-mail via `wp_mail`, SMS via le connecteur Twilio de CodeTool) : réservation confirmée,
  nouvelle date, report, annulation, créneau annulé (participants et intervenants), place libérée
  (liste d'attente), nouvel atelier pour un intervenant, rappels. Canaux et textes dans Réglages.
- **Rappels** : tâche CodeTool `smbb_tomo_notifications` toutes les 15 minutes. Intervenants J-7 par e-mail
  avec la liste des inscrits, participants et intervenants la veille par SMS. Une clé par personne,
  créneau et date empêche tout doublon. Renvoi manuel depuis l'onglet Messages d'un créneau.
- **Liste d'attente** : à chaque place libérée, la première demande qui tient dans les places libres est
  prévenue et a le délai réglé (24 h par défaut) ; ensuite la suivante.
- **Journal** : menu Journal › Messages envoyés, avec le texte envoyé et le résultat. Mode test et envoi d'essai dans Réglages > Envoi.
- Les messages partent en fin de requête (après la réponse quand PHP-FPM le permet).

## Lot 4 : vente en ligne avec WooCommerce (0.3.0)

Chargé seulement si WooCommerce est actif (`src/WooCommerce/`).

- **Lien atelier ↔ produit** : colonne « Boutique » dans la liste des ateliers (état du produit ou bouton
  « Créer le produit »), carte et section « Boutique » dans la fiche atelier (choix d'un produit existant).
  Un produit ne vend qu'un atelier. Le produit est forcé virtuel, non téléchargeable, sans stock WooCommerce,
  au prix par défaut de l'atelier. Sur la fiche produit WordPress, le bloc « Données produit » est masqué :
  restent titre, textes, images, catégories et SEO, plus un encadré « Atelier Tomo ».
- **Fiche produit** : choix de la date (places restantes, prix du créneau), nombre de places borné par la date,
  un bloc prénom / nom (+ niveau, âge, allergies selon les réglages) par participant. Dates complètes :
  formulaire de liste d'attente. Les places gardées pour une personne prévenue ne sont pas vendues aux autres.
- **Panier** : prix du créneau, quantité figée, revérification des places au panier et à la commande.
- **Commande** : une réservation « à confirmer » par participant dès la commande (la place est bloquée) ;
  payée → confirmées (un seul message au client) et demande de liste d'attente servie ; annulée, remboursée ou
  échouée → annulées sans message, places proposées à la liste d'attente ; non payée après 60 min → places
  libérées par la tâche planifiée. Un paiement tardif reprend les réservations libérées (note sur la commande
  si la jauge est dépassée).
- **Mon compte** : onglet « Mes ateliers » (à venir, passés).
- Compatible HPOS et panier / commande en blocs (déclaré).

## Lot 3 : site (0.4.0)

Shortcodes (`src/Front/Shortcodes.php`, styles dans `assets/front.css`) :

| Shortcode | Affiche |
|---|---|
| `[tomo_ateliers columns="3"]` | le catalogue des ateliers actifs (photo, durée, lieu, prix, prochaine date) |
| `[tomo_atelier id="2"]` | la fiche d'un atelier (id ou nom exact) et ses prochaines dates |
| `[tomo_creneaux atelier="2" limit="10"]` | les prochaines dates, d'un atelier ou de tous |
| `[tomo_calendrier atelier="2"]` | le calendrier du mois, navigation et filtre par atelier ; liste des jours sur mobile |

« Réserver » mène à la fiche produit avec la date déjà cochée (`?tomo_creneau=ID`) ; une date complète
mène au formulaire de liste d'attente. Sans produit publié, les dates s'affichent sans bouton.

## Bons cadeaux (0.4.0)

Menu Tomo › Bons cadeaux : une façade sur les codes promo WooCommerce, sans autre stockage.
Créer = code (généré si vide) + montant, et en option destinataire et date limite → code promo
« remise fixe sur le panier », une seule utilisation. Liste de tous les codes WooCommerce (non utilisés,
utilisés, expirés), PDF A5 avec QR code (TCPDF de CodeTool). Le QR code ouvre la boutique avec
`?tomo_bon=CODE` : le code est gardé en session et appliqué dès que le panier contient un atelier.

Utiliser un bon en boutique (bloc en haut de la page) : on tape le code ou on scanne le QR code
(html5-qrcode 2.3.8, Apache 2.0, dans `assets/vendor/`), on voit le montant et l'état, on confirme.
Le bon est consommé (une utilisation WooCommerce « En boutique », méta `_tomo_redeemed` : date, personne,
note) et un e-mail part au bénéficiaire (e-mail facultatif noté à la création, `_tomo_gift_email`, ou
restriction d'e-mail du code) et aux destinataires de l'e-mail « Nouvelle commande » de WooCommerce.
En mode test, tout part à l'adresse de test.

## Billets (0.5.0)

Mon compte › Mes ateliers : colonne « Billet » pour chaque réservation à venir placée sur une date
(`src/Service/Tickets.php`). Un billet par participant : PDF A6 (nom, atelier, date, lieu, QR code),
et Apple Wallet / Google Wallet quand Réglages › Billets est rempli. Liens signés
(`?tomo_billet=ID&k=…&format=pdf|apple|google`, HMAC sur l'id et l'e-mail).

Le QR code ouvre `admin-post.php?action=tomo_checkin` : connexion requise (équipe ou intervenant),
écran mobile avec Présent / Absent, alerte si ce n'est pas le jour de l'atelier et rappel des allergies.

Wallets : Apple demande un compte Apple Developer, un certificat Pass Type ID (PEM + clé) et le
certificat WWDR G4 ; Google demande un compte émetteur Google Wallet et le JSON d'un compte de service.
Les clés sont chiffrées en base et jamais réaffichées.

## Écran d'appel (0.10.2)

- Pointage à trois états côte à côte : Absent / À pointer / Présent. Plus de « Cocher les autres présents ».
- Chaque changement (pointage, note) est enregistré aussitôt en Ajax (`Admin\RollCall`, action `tomo_appel`) ; le bouton « Enregistrer l'appel » ne reste que sans JavaScript.
- Jauge empilée : présents, absents, à pointer, places libres, mise à jour en direct.
- Recherche (nom, prénom, e-mail, téléphone), filtres Tous / À pointer / Présents / Absents, liste par ordre alphabétique.
- « Scanner » : la caméra lit le QR du billet (lien de pointage signé), la personne passe présente ; pour un pass, c'est le jour affiché qui est pointé. Billet faux ou d'une autre date : message clair.

## Retours de test (0.10.1)

- Accès intervenant : WooCommerce renvoyait vers Mon compte tout compte sans `edit_posts`. Les intervenants et gestionnaires Tomo entrent maintenant dans l'administration (filtres `woocommerce_prevent_admin_access`, `woocommerce_disable_admin_bar`, `woocommerce_login_redirect`). Mon compte affiche une entrée distincte « Espace intervenant » (agenda et appel), à côté de « Mes ateliers » (réservations comme participant).
- Atelier : avec un produit WooCommerce lié, le texte et la photo sont ceux du produit (une seule source) ; la fiche atelier n'affiche plus ces champs mais un lien vers le produit. Sans produit, les champs de l'atelier servent toujours. Nouvelle section « Prochains créneaux » avec liens Ajouter (atelier présélectionné), Tous les créneaux, Calendrier.
- Intervenant : nom, e-mail et téléphone ne sont plus saisis dans Tomo. Ils sont lus sur le compte (téléphone = `billing_phone`, celui de WooCommerce) ; les colonnes de `tomo_intervenant` restent une copie tenue à jour par `Accounts::sync`.
- Créneaux : alerte « Aucun » quand un créneau à venir n'a pas d'intervenant ; action groupée « Affecter intervenant(s)… » (fenêtre : ajouter, remplacer ou retirer ; les nouveaux sont prévenus ; créneaux annulés et pass ignorés).
- Site : étiquette devant les dates (`.tomo-day-tag--today`, `--tomorrow`, `--soon`, `--later`) : Aujourd'hui, Demain, Après-demain, J+n.

## Pass plusieurs jours (0.10.0)

Pour un événement sur deux jours ou plus (PCCF : samedi, dimanche ou week-end). Conception :
https://claude.ai/artifact/SnPotVHTBCZhh5dhRAo7At

- Créez une date par jour (chacune garde sa jauge, son intervenant, son appel et reste vendable seule), puis un
  créneau de type « Pass plusieurs jours » qui coche ces dates. Places du pass : vide = le jour le plus rempli
  décide ; sinon nombre maximum de pass. Prix : celui du pass entier.
- Réserver un pass (en ligne ou à la main) crée une inscription principale sur le pass (prix, billet, QR,
  documents), puis une inscription par jour (`pass_id`), visible à l'appel de chaque jour avec la pastille « Pass ».
- Un seul billet, un seul fichier agenda (un événement par jour) et un seul QR : scanné un jour, il pointe la
  place de ce jour. Les documents signés une fois valent pour tous les jours.
- Confirmation, annulation, report et déplacement de l'inscription principale s'appliquent aux jours.
  Un jour peut être annulé seul (client absent le dimanche) sans toucher au reste.
- Pas de liste d'attente sur un pass ; les rappels partent la veille de chaque jour ; un pass ne se duplique pas.
- Stock et statistiques comptent un pass comme une vente (les jours comptent dans le remplissage de chaque date).
- Code : `Service\Passes` ; colonnes `tomo_creneau.kind`, `tomo_creneau.pass_creneau_ids`, `tomo_inscription.pass_id`
  (ajoutées par la synchronisation du schéma à la mise à jour).

## Site : agenda, commande et référencement (0.9.0)

- Chaque réservation a un fichier agenda (.ics, avec rappel la veille) : lien « Ajouter à mon agenda » dans
  Mon compte, sur la page de commande et dans les messages (variable `{lien_agenda}`, ajoutée à la confirmation par défaut).
- La page de remerciement et le détail de commande listent les réservations : date, billet, agenda et documents à remplir.
- Les fiches produit des ateliers publient des données structurées schema.org `Event` (date, lieu, prix,
  disponibilité, intervenant) pour l'affichage des dates dans les résultats Google.

## Vocabulaire (0.9.0)

Réglages > Général > Vocabulaire : choisir comment s'appellent les dates proposées (atelier, événement, stage,
cours, trackday, pack, ou un mot libre) et les personnes qui animent (intervenant, animateur, chef, formateur,
moniteur, instructeur, coach, ou un mot libre). `Support\Vocabulary` réécrit à la volée tous les textes du
domaine `smbb-tomo` (gettext, ngettext, menus et libellés CodeTool), avec l'élision : « l'atelier » devient
« le stage », « d'atelier » « de stage », « à l'atelier » « au stage », « cet atelier » « ce stage ».
Seuls des noms masculins sont proposés, pour garder les accords justes. Les variables `{atelier}` et les
shortcodes ne sont jamais touchés. Les textes de messages déjà enregistrés dans les réglages restent tels quels.

## Statistiques (0.9.0)

Menu Tomo > Statistiques, par période (30 jours, 3 mois, 12 mois, année, 90 prochains jours ou dates libres) et par atelier :
- remplissage et présence des créneaux qui ont lieu sur la période, graphique par mois avec le détail en tableau ;
- inscriptions prises sur la période : nombre, montants encaissés, annulations, origine, délai médian de réservation ;
- stock vendu non réalisé (lien vers la page Stock) et ventes sur place payées ;
- remplissage selon le jour de la semaine, pour choisir les prochains créneaux.

## Vente sur place (0.9.0)

Dans la fiche atelier, section « Vente sur place » : cochez les produits WooCommerce que l'intervenant
peut vendre le jour J (livre, ustensiles, boîtes…). Les produits qui vendent des places d'atelier sont exclus.

Dans l'écran d'appel, la carte « Vente sur place » permet de choisir les quantités et le client (un
participant ou une autre personne), puis :
- **Payé sur place** (carte, espèces, chèque, autre) : commande WooCommerce terminée tout de suite, stock décompté ;
- **Envoyer un lien de paiement** : commande en attente, lien de paiement envoyé par SMS et e-mail
  (message « Lien de paiement » dans les réglages des messages, variables {lien_paiement}, {montant},
  {produits}, {commande}). Le lien peut être renvoyé depuis la liste des ventes du créneau.

Chaque commande porte les méta `_tomo_onsite_creneau` et `_tomo_onsite_by` (vendeur), et `created_via = tomo_onsite`.
Après mise à jour, la synchronisation du schéma ajoute la colonne `extra_product_ids` aux ateliers.

## Documents des participants (0.8.0)

Réglages › Documents : une liste de documents, chacun « à lire et signer » (décharge…) ou « question
oui / non » (droit à l'image…), avec son texte, en pause ou non. Chaque participant a un lien signé
(`?tomo_documents=ID&k=…`, `src/Service/Documents.php`) vers une page mobile : lecture, nom du signataire
(participant ou représentant légal), signature au doigt, réponses oui / non. Le lien est dans l'e-mail
de confirmation et le rappel (`{lien_documents}`, vide quand tout est fait), et dans Mon compte.
Sur place, l'écran d'appel montre l'état de chaque document et « Faire signer » ouvre la même page
sur le téléphone de l'intervenant (`&sur_place=1`, réponse notée « sur place »).

Chaque réponse est une ligne de `tomo_document` : texte signé et son empreinte, réponse, signataire,
signature PNG, méthode, IP, navigateur, date. La dernière réponse fait foi (un oui / non peut changer,
une signature est définitive). Fiche inscription : réponses, signatures, lien à renvoyer ; export CSV :
une colonne par document.

Aussi en 0.8.0 : tableau de bord « Aujourd'hui » (ateliers du jour, état de l'appel, bouton « Faire
l'appel »), alerte sur les ateliers des 7 derniers jours pas entièrement pointés, `{lien_billet}` dans
les messages, billet PDF sur la fiche inscription.

## Intervenants et liste d'attente (0.7.0)

- Un intervenant est un compte WordPress : on le cherche par nom, e-mail ou téléphone (champ CodeTool
  `search` avec `resource => 'wp_user'`), ou on le crée depuis la fiche (e-mail d'accès envoyé).
  Nom et e-mail viennent du compte ; le téléphone de la fiche est enregistré sur le compte
  (`billing_phone`). Les colonnes de `tomo_intervenant` sont recopiées à chaque modification du compte
  (`src/Service/Accounts.php`).
- Réglages › Liste d'attente › « Proposer une liste d'attente » : désactivée, une date complète affiche
  « Complet », sans formulaire, sans relance ni place gardée.

## Espace intervenants (0.6.0)

Menu « Mes ateliers » (capacité `tomo_intervenant`, page d'arrivée d'un intervenant après connexion) :

- agenda des dates de l'intervenant, à venir ou passées (6 mois), groupées par mois : remplissage,
  places restantes, liste d'attente, brouillon « pas encore en vente », état de l'appel ;
  un gestionnaire voit toutes les dates et leurs intervenants ;
- `?creneau=ID` : écran d'appel pensé pour le téléphone. Une carte par participant (téléphone,
  allergies, niveau, âge, remarque du client), Présent / Absent (recliquer annule), « Tous présents »,
  et une note libre par participant, colonne `intervenant_note`. L'appel s'ouvre le jour de l'atelier ;
  avant, seules les notes s'enregistrent. Enregistrement : `admin-post.php?action=tomo_roll_call`.

La note de l'intervenant apparaît sur la fiche du créneau, la fiche de l'inscription et dans l'export CSV.
Le QR code du billet renvoie à cet écran d'appel.

## Structure

```
smbb-tomo.php            constantes, autoload Smbb\Tomo, contrôle de CodeTool
codetool/models/         ressources CodeTool (tables, menus, listes, filtres)
codetool/views/          écrans (listes, formulaires, fiches, calendrier, stock, tableau de bord)
codetool/hooks/          validations et effets de bord des sauvegardes CodeTool
src/Service/             Booking, Capacity, History, Notifier (e-mails, SMS), Reminders (cron), Waitlist
src/Repository/          lectures SQL (créneaux avec remplissage, inscriptions, libellés)
src/Admin/               actions admin-post et helpers de vue
src/Front/               Shortcodes du site
src/WooCommerce/         Module (chargement), Products, Front (fiche, panier), Orders, Account, GiftCards
src/Support/             rôles, schéma, listes de valeurs, réglages, normalisation des saisies
```

## Points d'accroche pour les lots suivants

Actions WordPress déclenchées : `smbb_tomo_inscription_cancelled`, `smbb_tomo_inscription_moved`,
`smbb_tomo_creneau_cancelled` (avec les réservations retirées), `smbb_tomo_waitlist_booked`,
`smbb_tomo_inscription_confirmed`, `smbb_tomo_intervenants_assigned`, `smbb_tomo_seat_freed` (place libérée
sur un créneau avec liste d'attente), `smbb_tomo_history_recorded`.

## 0.10.3 : coordonnées de chaque participant

- Réglages › Général › « Informations demandées aux participants » : e-mail et téléphone de chaque participant (facultatifs par défaut, obligatoires, ou non demandés).
- Fiche produit : deux champs par participant ; le participant 1 est prérempli avec le compte connecté.
- À la commande, chaque réservation prend les coordonnées de son participant (à défaut celles de l'acheteur). Billet, rappels et documents partent donc à chaque participant. L'acheteur retrouve toujours toutes ses réservations dans Mon compte (lien par customer_id).

## 0.10.4 : suivi des documents sur la fiche d'une inscription

- Le pli « Documents (x/y remplis) » est ouvert par défaut : chaque document avec sa réponse, la date, qui l'a rempli, en ligne ou sur place, et la signature.
- « Demandes envoyées » : les e-mails et SMS partis avec le lien des documents (destinataire, date, résultat).
- Dernière ouverture du lien par le participant (notée une fois par jour, jamais pour l'équipe connectée ; action d'historique `document_view`).
- Bouton « Envoyer la demande de documents » sur la fiche (action `tomo_documents_send`), nouveau message « Demande de documents » (`documents_request`, e-mail + SMS) modifiable dans Réglages › Messages.

## 0.10.5 : onglets de liste déclaratifs, document « Actif »

- CodeTool : `admin.list.presets` (onglets de préfiltres sous le titre, compteur optionnel), `admin.list.remember` (`back` par défaut : la liste ne reprend recherche/filtre/tri qu'au retour d'une fiche ; `always` ; `never`), `admin.list.filters.form: false` (cache le filtre générique, les filtres d'URL restent actifs), toggle `default`.
- Inscriptions : onglets Toutes / À confirmer (n) / Payées / Présents / Absents / Annulées déclarés dans le modèle ; filtre générique masqué.
- Réglages › Documents : « En pause » remplacé par « Actif » (migration automatique des documents existants).

## 0.10.6 : menu Documents

- Les documents quittent Réglages pour leur propre menu (ressource `tomo-documents`, option `smbb_tomo_documents`, hooks `DocumentsHooks`). Recopie automatique des documents existants à la mise à jour ; `Settings::get('documents')` lit la nouvelle option.
- Consigne « sur place » réglable (`documents_onsite_note`) ; bouton Enregistrer masqué quand tout est signé.

## 0.10.7 : prix HT ou TTC, vocabulaire « créneau »

- Réglages › Général › Prix : prix saisis TTC ou HT, classe de taxe WooCommerce (taux de la boutique). `Support\Prices` convertit pour le panier (selon « prix saisis TTC » de WooCommerce), l'affichage (selon « afficher les prix TTC / HT ») et le JSON-LD (TTC).
- Vocabulaire : « créneau » remplaçable (journée, date, séance, session, libre), accords au féminin ; « staff » ajouté pour les intervenants.

## 0.10.8 : disponibilités des intervenants

- Table `tomo_dispo` (une ligne par date et intervenant : réponse, demandée le, répondue le, par qui, source). Service `Availability`.
- Fiche de la date : pastille Dispo / Non dispo / En attente / À demander par intervenant, saisie à leur place, bouton « Demander les dispos ». Liste des dates : point de couleur devant chaque intervenant.
- Espace intervenant › Mes disponibilités : liste par mois et calendrier, réponse enregistrée en Ajax (`assets/dispos.js`).
- Messages `dispo_request` (aux intervenants sans réponse, e-mail + SMS) et `dispo_answer` (alerte à l'équipe quand un intervenant répond, sur l'e-mail / téléphone de contact).

## 0.10.9 : dates « toute la journée »

- Colonne `all_day` sur `tomo_creneau`, case « Toute la journée » dans le formulaire. Heure de début facultative (7h00 par défaut, pour les rappels et le tri), pas d'heure de fin.
- Aucune heure affichée côté client (fiche produit, panier, commande, billet, liste des dates) : `Lookup::slotHours()` remplace `Lookup::hours()` sur les dates. Admin : « Journée ».
- Messages : « , {heure} », « à {heure} » et « ({heure}) » disparaissent quand l'heure est vide. Agenda (.ics) en événement sur la journée.

## 0.11.0 : saisie des participants façon PCCF, participants favoris, annulation d'une journée

- Fiche produit : plus de quantité à saisir, une carte par participant. « C'est moi » (client connecté) reprend nom, e-mail et téléphone du compte ; « Ajouter un participant » ; « Ajouter mes participants favoris » ouvre une fenêtre (recherche, sélection multiple, suppression confirmée). La quantité WooCommerce suit le nombre de cartes (`woocommerce_add_to_cart_quantity`).
- Case « Mémoriser ce participant dans mes favoris » sur chaque carte : enregistrée à la commande sur le compte client (aussi pour un compte créé au paiement). Méta utilisateur `smbb_tomo_favorites`, classe `WooCommerce\Favorites`.
- Mon compte › Participants favoris (`/mon-compte/participants-favoris/`) : liste, ajout, modification, suppression.
- Date annulée (ou jour de pass annulé seul) : note dans chaque commande WooCommerce liée (« journée du … annulée, remboursement de X € à convenir avec le client ») et phrase de remboursement dans l'historique de chaque réservation. Part d'un jour de pass = prix du pass ÷ nombre de jours ; rien à rembourser si la commande n'est pas payée.

## 0.11.1 : participant imprévu sur la feuille d'appel

- Bouton « Imprévu » à droite de « Scanner » : prénom, nom, e-mail, téléphone. Ajouté à la liste et pointé présent même si la date est complète (source `walkin`, action `tomo_walkin`, hook `smbb_tomo_walkin_added`).
- Avec un e-mail : compte client WooCommerce créé (ou repris), donc « Faire signer » fonctionne pour lui comme pour les autres.
- Jauge : présents, absents, à pointer, additionnels, places libres (peut devenir négatif, affiché en rouge).

## 0.11.2 : appel hors du jour de l'atelier

- Le pointage n'est plus fermé avant la date : sur un autre jour que celui de l'atelier, pointage, scan et « Imprévu » sont verrouillés derrière l'interrupteur « Pointer quand même » (envoie `hors_date=1`, vérifié côté serveur par `Actions::canPoint()`). Le jour J, rien ne change.

## 0.11.3 : imprévu payé ou à payer par SMS, lien de connexion automatique

- Imprévu : le bouton « Ajouter » ouvre un choix. « Ajout direct, prestation déjà payée » ajoute et pointe présent tout de suite. « Paiement par SMS avant ajout » crée une commande WooCommerce en attente (`WooCommerce\Walkins`, méta `_tomo_walkin`), envoie le lien de paiement (message « Lien de paiement »). Au paiement, la réservation est créée, confirmée et pointée présente (source `walkin`). Liste « Imprévus en attente de paiement » avec « Renvoyer le lien » sur la feuille d'appel.
- Compte existant avec le même e-mail : toujours repris, jamais recréé.
- `Support\LoginLink` : lien `?tomo_login=` qui connecte le client pendant 10 minutes puis l'envoie sur la page de paiement. Utilisé par tous les liens de paiement des ventes sur place quand la commande a un compte client. Refusé pour les comptes de l'équipe.

## Catalogue sur place, caisse et bons de remboursement (0.11.4)

- Menu **Catalogue sur place** : catalogues et sous-catalogues (« Rangé dans »), chacun pour certains ateliers ou tous, certains intervenants ou tous, avec une liste ordonnée de produits WooCommerce (produits simples, publiés, achetables). Les anciens produits « Vente sur place » de chaque atelier sont repris une fois dans un catalogue « {Atelier} — sur place ».
- Feuille d'appel : bouton **Vente** sur chaque participant (client et compte repris de la réservation) et **Nouvelle vente**. Fenêtre de caisse : onglets par catalogue, recherche, tuiles photo / réf. / description / prix, panier avec quantités, payé sur place (moyen de paiement) ou lien de paiement par SMS avec connexion automatique.
- Onglet **Caisse** sur la feuille d'appel : encaissé, en attente, nombre de ventes, total, par moyen de paiement, par produit, et la liste des ventes de la date (imprévus compris), avec « Renvoyer le lien ».
- **Rembourser en bon d'achat** sur chaque ligne d'une vente payée : remboursement WooCommerce de la ligne (sans toucher au paiement, produit remis en stock) et code promo du même montant, usage unique, réservé à l'e-mail du client, libellé « Remboursement commande n° … », envoyé par SMS et e-mail (message « Remboursement en bon d'achat », lien qui connecte le client et applique le bon). Le bon apparaît aussi dans Bons cadeaux.

## 0.13.1 : corrections issues de la revue de code

- Billets et documents : le jeton des liens et du QR code ne dépend plus que de l'id de la réservation (`Tickets::token`, `Documents::token`). Corriger l'e-mail d'un participant ne rend plus son billet, son QR code ou son lien de documents inutilisables. L'ancien jeton (avec l'e-mail) reste accepté pour ce qui a déjà été envoyé.
- Passage en caisse : `Orders::reserve($order, true)` prend un verrou MySQL par date (`Capacity::lock`), recompte les places et arrête la commande avant paiement si la date s'est remplie entre-temps (message au client, note sur la commande). Commandes payées ou créées dans l'admin : inchangé.
- Avoirs : `Invoices::credit` et `creditAmount` prennent le même verrou par commande que l'émission et relisent la facture sous verrou. Montants calculés en cumulé (`share`) : les factures et avoirs successifs d'une même ligne tombent juste au centime ; un avoir sur montant fait exactement le montant remboursé. Un avoir partiel nomme d'abord les participants annulés.
- Tables : la synchro se lance sur `init` (et plus seulement dans l'admin) quand la version change, sous verrou ; `Schema::ready()` est gardé en mémoire.
- Réglages gardés en mémoire pour la requête (`Settings::flush` à chaque changement d'option) ; expressions du vocabulaire construites une fois par mot.
- Factures : la recherche garde les filtres type et source.
- Export de configuration « avec les clés secrètes » réservé aux administrateurs (`manage_options`).

## Admin rangée : menus groupés et nouveau tableau de bord

- Menu Tomo en trois groupes titrés, déclarés dans les modèles (`admin.menu.group`) : **Au quotidien** (Tableau de bord, Calendrier, Créneaux, Inscriptions, Demandes de dispo, Bons cadeaux), **Suivi** (Factures, PCA, Statistiques, Journal), **Paramétrage** (Ateliers, Intervenants, Catalogue sur place, Documents, Réglages).
- Liste d'attente et Envois passent en `placement: hidden` : onglets « Inscriptions / Liste d'attente » et « Journal : Mouvements / Messages envoyés » (`View::sections`). Les anciennes adresses marchent toujours ; `admin.menu.highlight` garde le bon menu surligné.
- « Mes ateliers » n'apparaît plus pour un gestionnaire sans fiche intervenant (`Admin\Menu`) ; l'appel et la caisse restent accessibles depuis le tableau de bord et la barre du haut.
- Tableau de bord (`views/tomo/dashboard.php`, `assets/dashboard.css`) : Aujourd'hui (appel, caisse), À traiter (une ligne par point d'attention avec son bouton, triée par urgence), En chiffres (ventes du mois et tendance, remplissage à 30 jours, places vendues à venir et PCA, bons cadeaux), les 14 prochains jours colorés selon le remplissage, derniers mouvements et messages.
- CodeTool : `admin.menu.group`, `admin.menu.submenuTitle` (premier sous-menu « Tableau de bord » au lieu de « Tomo »), `admin.menu.highlight`.
