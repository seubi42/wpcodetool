# smbb-passflow

Réservation et organisation des ateliers de la pâtisserie Passflow, bâti sur **SMBB WP CodeTool** (qui doit être actif).

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
- **Rôles** : « Gestionnaire Passflow » (capacité `manage_passflow`) et « Intervenant Passflow » (espace « Mes ateliers »).

Les tables (`wp_passflow_*`) sont créées au premier passage dans l'admin après activation.

## Lot 2 : notifications et rappels

- **Messages** (e-mail via `wp_mail`, SMS via le connecteur Twilio de CodeTool) : réservation confirmée,
  nouvelle date, report, annulation, créneau annulé (participants et intervenants), place libérée
  (liste d'attente), nouvel atelier pour un intervenant, rappels. Canaux et textes dans Réglages.
- **Rappels** : tâche CodeTool `smbb_passflow_notifications` toutes les 15 minutes. Intervenants J-7 par e-mail
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
  restent titre, textes, images, catégories et SEO, plus un encadré « Atelier Passflow ».
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
| `[passflow_activities columns="3"]` | le catalogue des ateliers actifs (photo, durée, lieu, prix, prochaine date) |
| `[passflow_activity id="2"]` | la fiche d'un atelier (id ou nom exact) et ses prochaines dates |
| `[passflow_slots atelier="2" limit="10"]` | les prochaines dates, d'un atelier ou de tous |
| `[passflow_calendar atelier="2"]` | le calendrier du mois, navigation et filtre par atelier ; liste des jours sur mobile |

« Réserver » mène à la fiche produit avec la date déjà cochée (`?passflow_slot=ID`) ; une date complète
mène au formulaire de liste d'attente. Sans produit publié, les dates s'affichent sans bouton.

## Bons cadeaux (0.4.0)

Menu Passflow › Bons cadeaux : une façade sur les codes promo WooCommerce, sans autre stockage.
Créer = code (généré si vide) + montant, et en option destinataire et date limite → code promo
« remise fixe sur le panier », une seule utilisation. Liste de tous les codes WooCommerce (non utilisés,
utilisés, expirés), PDF A5 avec QR code (TCPDF de CodeTool). Le QR code ouvre la boutique avec
`?passflow_voucher=CODE` : le code est gardé en session et appliqué dès que le panier contient un atelier.

Utiliser un bon en boutique (bloc en haut de la page) : on tape le code ou on scanne le QR code
(html5-qrcode 2.3.8, Apache 2.0, dans `assets/vendor/`), on voit le montant et l'état, on confirme.
Le bon est consommé (une utilisation WooCommerce « En boutique », méta `_passflow_redeemed` : date, personne,
note) et un e-mail part au bénéficiaire (e-mail facultatif noté à la création, `_passflow_gift_email`, ou
restriction d'e-mail du code) et aux destinataires de l'e-mail « Nouvelle commande » de WooCommerce.
En mode test, tout part à l'adresse de test.

## Billets (0.5.0)

Mon compte › Mes ateliers : colonne « Billet » pour chaque réservation à venir placée sur une date
(`src/Service/Tickets.php`). Un billet par participant : PDF A6 (nom, atelier, date, lieu, QR code),
et Apple Wallet / Google Wallet quand Réglages › Billets est rempli. Liens signés
(`?passflow_ticket=ID&k=…&format=pdf|apple|google`, HMAC sur l'id et l'e-mail).

Le QR code ouvre `admin-post.php?action=passflow_checkin` : connexion requise (équipe ou intervenant),
écran mobile avec Présent / Absent, alerte si ce n'est pas le jour de l'atelier et rappel des allergies.

Wallets : Apple demande un compte Apple Developer, un certificat Pass Type ID (PEM + clé) et le
certificat WWDR G4 ; Google demande un compte émetteur Google Wallet et le JSON d'un compte de service.
Les clés sont chiffrées en base et jamais réaffichées.

## Écran d'appel (0.10.2)

- Pointage à trois états côte à côte : Absent / À pointer / Présent. Plus de « Cocher les autres présents ».
- Chaque changement (pointage, note) est enregistré aussitôt en Ajax (`Admin\RollCall`, action `passflow_rollcall`) ; le bouton « Enregistrer l'appel » ne reste que sans JavaScript.
- Jauge empilée : présents, absents, à pointer, places libres, mise à jour en direct.
- Recherche (nom, prénom, e-mail, téléphone), filtres Tous / À pointer / Présents / Absents, liste par ordre alphabétique.
- « Scanner » : la caméra lit le QR du billet (lien de pointage signé), la personne passe présente ; pour un pass, c'est le jour affiché qui est pointé. Billet faux ou d'une autre date : message clair.

## Retours de test (0.10.1)

- Accès intervenant : WooCommerce renvoyait vers Mon compte tout compte sans `edit_posts`. Les intervenants et gestionnaires Passflow entrent maintenant dans l'administration (filtres `woocommerce_prevent_admin_access`, `woocommerce_disable_admin_bar`, `woocommerce_login_redirect`). Mon compte affiche une entrée distincte « Espace intervenant » (agenda et appel), à côté de « Mes ateliers » (réservations comme participant).
- Atelier : avec un produit WooCommerce lié, le texte et la photo sont ceux du produit (une seule source) ; la fiche atelier n'affiche plus ces champs mais un lien vers le produit. Sans produit, les champs de l'atelier servent toujours. Nouvelle section « Prochains créneaux » avec liens Ajouter (atelier présélectionné), Tous les créneaux, Calendrier.
- Intervenant : nom, e-mail et téléphone ne sont plus saisis dans Passflow. Ils sont lus sur le compte (téléphone = `billing_phone`, celui de WooCommerce) ; les colonnes de `passflow_host` restent une copie tenue à jour par `Accounts::sync`.
- Créneaux : alerte « Aucun » quand un créneau à venir n'a pas d'intervenant ; action groupée « Affecter intervenant(s)… » (fenêtre : ajouter, remplacer ou retirer ; les nouveaux sont prévenus ; créneaux annulés et pass ignorés).
- Site : étiquette devant les dates (`.passflow-day-tag--today`, `--tomorrow`, `--soon`, `--later`) : Aujourd'hui, Demain, Après-demain, J+n.

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
- Code : `Service\Passes` ; colonnes `passflow_slot.kind`, `passflow_slot.pass_slot_ids`, `passflow_booking.pass_id`
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
domaine `smbb-passflow` (gettext, ngettext, menus et libellés CodeTool), avec l'élision : « l'atelier » devient
« le stage », « d'atelier » « de stage », « à l'atelier » « au stage », « cet atelier » « ce stage ».
Seuls des noms masculins sont proposés, pour garder les accords justes. Les variables `{atelier}` et les
shortcodes ne sont jamais touchés. Les textes de messages déjà enregistrés dans les réglages restent tels quels.

## Statistiques (0.9.0)

Menu Passflow > Statistiques, par période (30 jours, 3 mois, 12 mois, année, 90 prochains jours ou dates libres) et par atelier :
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

Chaque commande porte les méta `_passflow_onsite_slot` et `_passflow_onsite_by` (vendeur), et `created_via = passflow_onsite`.
Après mise à jour, la synchronisation du schéma ajoute la colonne `extra_product_ids` aux ateliers.

## Documents des participants (0.8.0)

Réglages › Documents : une liste de documents, chacun « à lire et signer » (décharge…) ou « question
oui / non » (droit à l'image…), avec son texte, en pause ou non. Chaque participant a un lien signé
(`?passflow_documents=ID&k=…`, `src/Service/Documents.php`) vers une page mobile : lecture, nom du signataire
(participant ou représentant légal), signature au doigt, réponses oui / non. Le lien est dans l'e-mail
de confirmation et le rappel (`{lien_documents}`, vide quand tout est fait), et dans Mon compte.
Sur place, l'écran d'appel montre l'état de chaque document et « Faire signer » ouvre la même page
sur le téléphone de l'intervenant (`&sur_place=1`, réponse notée « sur place »).

Chaque réponse est une ligne de `passflow_document` : texte signé et son empreinte, réponse, signataire,
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
  (`billing_phone`). Les colonnes de `passflow_host` sont recopiées à chaque modification du compte
  (`src/Service/Accounts.php`).
- Réglages › Liste d'attente › « Proposer une liste d'attente » : désactivée, une date complète affiche
  « Complet », sans formulaire, sans relance ni place gardée.

## Espace intervenants (0.6.0)

Menu « Mes ateliers » (capacité `passflow_host`, page d'arrivée d'un intervenant après connexion) :

- agenda des dates de l'intervenant, à venir ou passées (6 mois), groupées par mois : remplissage,
  places restantes, liste d'attente, brouillon « pas encore en vente », état de l'appel ;
  un gestionnaire voit toutes les dates et leurs intervenants ;
- `?creneau=ID` : écran d'appel pensé pour le téléphone. Une carte par participant (téléphone,
  allergies, niveau, âge, remarque du client), Présent / Absent (recliquer annule), « Tous présents »,
  et une note libre par participant, colonne `host_note`. L'appel s'ouvre le jour de l'atelier ;
  avant, seules les notes s'enregistrent. Enregistrement : `admin-post.php?action=passflow_roll_call`.

La note de l'intervenant apparaît sur la fiche du créneau, la fiche de l'inscription et dans l'export CSV.
Le QR code du billet renvoie à cet écran d'appel.

## Structure

```
smbb-passflow.php            constantes, autoload Smbb\Passflow, contrôle de CodeTool
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

Actions WordPress déclenchées : `smbb_passflow_booking_cancelled`, `smbb_passflow_booking_moved`,
`smbb_passflow_slot_cancelled` (avec les réservations retirées), `smbb_passflow_waitlist_booked`,
`smbb_passflow_booking_confirmed`, `smbb_passflow_hosts_assigned`, `smbb_passflow_seat_freed` (place libérée
sur un créneau avec liste d'attente), `smbb_passflow_history_recorded`.

## 0.10.3 : coordonnées de chaque participant

- Réglages › Général › « Informations demandées aux participants » : e-mail et téléphone de chaque participant (facultatifs par défaut, obligatoires, ou non demandés).
- Fiche produit : deux champs par participant ; le participant 1 est prérempli avec le compte connecté.
- À la commande, chaque réservation prend les coordonnées de son participant (à défaut celles de l'acheteur). Billet, rappels et documents partent donc à chaque participant. L'acheteur retrouve toujours toutes ses réservations dans Mon compte (lien par customer_id).

## 0.10.4 : suivi des documents sur la fiche d'une inscription

- Le pli « Documents (x/y remplis) » est ouvert par défaut : chaque document avec sa réponse, la date, qui l'a rempli, en ligne ou sur place, et la signature.
- « Demandes envoyées » : les e-mails et SMS partis avec le lien des documents (destinataire, date, résultat).
- Dernière ouverture du lien par le participant (notée une fois par jour, jamais pour l'équipe connectée ; action d'historique `document_view`).
- Bouton « Envoyer la demande de documents » sur la fiche (action `passflow_documents_send`), nouveau message « Demande de documents » (`documents_request`, e-mail + SMS) modifiable dans Réglages › Messages.

## 0.10.5 : onglets de liste déclaratifs, document « Actif »

- CodeTool : `admin.list.presets` (onglets de préfiltres sous le titre, compteur optionnel), `admin.list.remember` (`back` par défaut : la liste ne reprend recherche/filtre/tri qu'au retour d'une fiche ; `always` ; `never`), `admin.list.filters.form: false` (cache le filtre générique, les filtres d'URL restent actifs), toggle `default`.
- Inscriptions : onglets Toutes / À confirmer (n) / Payées / Présents / Absents / Annulées déclarés dans le modèle ; filtre générique masqué.
- Réglages › Documents : « En pause » remplacé par « Actif » (migration automatique des documents existants).

## 0.10.6 : menu Documents

- Les documents quittent Réglages pour leur propre menu (ressource `passflow-documents`, option `smbb_passflow_documents`, hooks `DocumentsHooks`). Recopie automatique des documents existants à la mise à jour ; `Settings::get('documents')` lit la nouvelle option.
- Consigne « sur place » réglable (`documents_onsite_note`) ; bouton Enregistrer masqué quand tout est signé.

## 0.10.7 : prix HT ou TTC, vocabulaire « créneau »

- Réglages › Général › Prix : prix saisis TTC ou HT, classe de taxe WooCommerce (taux de la boutique). `Support\Prices` convertit pour le panier (selon « prix saisis TTC » de WooCommerce), l'affichage (selon « afficher les prix TTC / HT ») et le JSON-LD (TTC).
- Vocabulaire : « créneau » remplaçable (journée, date, séance, session, libre), accords au féminin ; « staff » ajouté pour les intervenants.

## 0.10.8 : disponibilités des intervenants

- Table `passflow_availability` (une ligne par date et intervenant : réponse, demandée le, répondue le, par qui, source). Service `Availability`.
- Fiche de la date : pastille Dispo / Non dispo / En attente / À demander par intervenant, saisie à leur place, bouton « Demander les dispos ». Liste des dates : point de couleur devant chaque intervenant.
- Espace intervenant › Mes disponibilités : liste par mois et calendrier, réponse enregistrée en Ajax (`assets/availabilities.js`).
- Messages `availability_request` (aux intervenants sans réponse, e-mail + SMS) et `availability_answer` (alerte à l'équipe quand un intervenant répond, sur l'e-mail / téléphone de contact).

## 0.10.9 : dates « toute la journée »

- Colonne `all_day` sur `passflow_slot`, case « Toute la journée » dans le formulaire. Heure de début facultative (7h00 par défaut, pour les rappels et le tri), pas d'heure de fin.
- Aucune heure affichée côté client (fiche produit, panier, commande, billet, liste des dates) : `Lookup::slotHours()` remplace `Lookup::hours()` sur les dates. Admin : « Journée ».
- Messages : « , {heure} », « à {heure} » et « ({heure}) » disparaissent quand l'heure est vide. Agenda (.ics) en événement sur la journée.

## 0.11.0 : saisie des participants façon PCCF, participants favoris, annulation d'une journée

- Fiche produit : plus de quantité à saisir, une carte par participant. « C'est moi » (client connecté) reprend nom, e-mail et téléphone du compte ; « Ajouter un participant » ; « Ajouter mes participants favoris » ouvre une fenêtre (recherche, sélection multiple, suppression confirmée). La quantité WooCommerce suit le nombre de cartes (`woocommerce_add_to_cart_quantity`).
- Case « Mémoriser ce participant dans mes favoris » sur chaque carte : enregistrée à la commande sur le compte client (aussi pour un compte créé au paiement). Méta utilisateur `smbb_passflow_favorites`, classe `WooCommerce\Favorites`.
- Mon compte › Participants favoris (`/mon-compte/participants-favoris/`) : liste, ajout, modification, suppression.
- Date annulée (ou jour de pass annulé seul) : note dans chaque commande WooCommerce liée (« journée du … annulée, remboursement de X € à convenir avec le client ») et phrase de remboursement dans l'historique de chaque réservation. Part d'un jour de pass = prix du pass ÷ nombre de jours ; rien à rembourser si la commande n'est pas payée.

## 0.11.1 : participant imprévu sur la feuille d'appel

- Bouton « Imprévu » à droite de « Scanner » : prénom, nom, e-mail, téléphone. Ajouté à la liste et pointé présent même si la date est complète (source `walkin`, action `passflow_walkin`, hook `smbb_passflow_walkin_added`).
- Avec un e-mail : compte client WooCommerce créé (ou repris), donc « Faire signer » fonctionne pour lui comme pour les autres.
- Jauge : présents, absents, à pointer, additionnels, places libres (peut devenir négatif, affiché en rouge).

## 0.11.2 : appel hors du jour de l'atelier

- Le pointage n'est plus fermé avant la date : sur un autre jour que celui de l'atelier, pointage, scan et « Imprévu » sont verrouillés derrière l'interrupteur « Pointer quand même » (envoie `hors_date=1`, vérifié côté serveur par `Actions::canPoint()`). Le jour J, rien ne change.

## 0.11.3 : imprévu payé ou à payer par SMS, lien de connexion automatique

- Imprévu : le bouton « Ajouter » ouvre un choix. « Ajout direct, prestation déjà payée » ajoute et pointe présent tout de suite. « Paiement par SMS avant ajout » crée une commande WooCommerce en attente (`WooCommerce\Walkins`, méta `_passflow_walkin`), envoie le lien de paiement (message « Lien de paiement »). Au paiement, la réservation est créée et confirmée (source `walkin`), mais pas pointée : l'équipe la pointe à la main et fait signer les documents. Liste « Imprévus en attente de paiement » avec « Renvoyer le lien » sur la feuille d'appel.
- Compte existant avec le même e-mail : toujours repris, jamais recréé.
- `Support\LoginLink` : lien `?passflow_login=` qui connecte le client pendant 10 minutes puis l'envoie sur la page de paiement. Utilisé par tous les liens de paiement des ventes sur place quand la commande a un compte client. Refusé pour les comptes de l'équipe.

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

- Menu Passflow en trois groupes titrés, déclarés dans les modèles (`admin.menu.group`) : **Au quotidien** (Tableau de bord, Calendrier, Créneaux, Inscriptions, Demandes de dispo, Bons cadeaux), **Suivi** (Factures, PCA, Statistiques, Journal), **Paramétrage** (Ateliers, Intervenants, Catalogue sur place, Documents, Réglages).
- Liste d'attente et Envois passent en `placement: hidden` : onglets « Inscriptions / Liste d'attente » et « Journal : Mouvements / Messages envoyés » (`View::sections`). Les anciennes adresses marchent toujours ; `admin.menu.highlight` garde le bon menu surligné.
- « Mes ateliers » n'apparaît plus pour un gestionnaire sans fiche intervenant (`Admin\Menu`) ; l'appel et la caisse restent accessibles depuis le tableau de bord et la barre du haut.
- Tableau de bord (`views/passflow/dashboard.php`, `assets/dashboard.css`) : Aujourd'hui (appel, caisse), À traiter (une ligne par point d'attention avec son bouton, triée par urgence), En chiffres (ventes du mois et tendance, remplissage à 30 jours, places vendues à venir et PCA, bons cadeaux), les 14 prochains jours colorés selon le remplissage, derniers mouvements et messages.
- CodeTool : `admin.menu.group`, `admin.menu.submenuTitle` (premier sous-menu « Tableau de bord » au lieu de « Passflow »), `admin.menu.highlight`.

## 0.13.2 : produits à variantes à la caisse (tailles, couleurs…)

- Le catalogue sur place accepte les produits WooCommerce variables (t-shirts, casquettes…), plus seulement les simples (`productTypes` simple + variable ; la recherche CodeTool affiche « N variantes · dès X € »).
- Caisse : un produit variable reste une seule tuile (« dès 15 € · 4 choix ») ; la toucher ouvre le choix de la variante en grandes touches (taille, prix si différent, « reste N » sous 5, rupture barrée). Une seule variante : ajoutée directement. Échap ou « Retour aux produits » revient à la grille. La recherche trouve aussi les tailles et références des variantes.
- Panier et commande par variante : `OnsiteSales::options()` liste ce qui se vend (produit simple, ou chaque variante active). Une variante « n'importe quelle taille » est dépliée en une option par valeur, pour que la ligne de commande porte toujours la taille vendue. La ligne WooCommerce est créée sur la variation (stock décompté sur elle) avec ses attributs, nommée « T-shirt – M · Bleu ».
- Stock : vérifié par variante, et en commun quand les variantes partagent le stock du produit parent (à la caisse comme à l'enregistrement). Stock non suivi mais « en rupture » : la tuile est grisée.

## 0.13.3 : mise en vente différée des créneaux

- Fiche d'un créneau, section **Ouverture des réservations** : option « Mise en vente différée » et date et heure d'ouverture facultatives. Colonnes `sale_delayed`, `sale_opens_at` sur `passflow_slot` (ajoutées toutes seules à la montée de version).
- Avant l'ouverture, le créneau (statut « En vente ») s'affiche sur la fiche produit, dans les shortcodes liste et calendrier, avec « Ouvre le sam. 12 déc. à 10h00 » (ou « Bientôt en vente » sans date), mais ne se réserve pas : `Capacity::forSale` renvoie 0, le panier et le paiement refusent avec « Ouverture des réservations le … ». Données structurées Google : `PreSale` et `validFrom` à l'ouverture.
- Si la liste d'attente est activée (Réglages) et choisie sur le créneau, les clients peuvent s'y inscrire en attendant. Sinon la date est affichée sans réservation possible.
- Ouverture : à la date (au premier affichage qui la voit passée, sinon par la tâche planifiée des 15 minutes, `SaleWindow::openDue`) ou en décochant l'option. Les personnes en attente sont prévenues en premier, dans l'ordre d'arrivée, autant que les places le permettent (`Waitlist::notifyAtOpening`), avec le nouveau message « Ouverture des réservations (liste d'attente) » ; leurs places sont gardées le délai habituel. Action `smbb_passflow_sale_opened`.
- Admin : pastille « Ouvre le … » à la place de « En vente » dans la liste et sur la fiche du créneau. L'ajout manuel d'un participant et la caisse sur place ne sont pas bloqués par l'ouverture (l'équipe garde la main).
- Scan à la caisse (douchette ou saisie + Entrée) : une référence exacte met directement au panier le produit ou la variante qui la porte, sans passer par le choix de la taille. La ligne du panier montre la variante en pastille (« T-shirt [M · Bleu] ») et clignote à l'ajout. Rupture ou référence inconnue : message dans la caisse. Une saisie faite hors d'un champ (douchette alors qu'une tuile a le focus) part dans la recherche.

## 0.13.4 : date d'ouverture affichée ou masquée

- Créneau en vente différée : option « Afficher la date d'ouverture aux clients » (colonne `sale_show_date`, activée par défaut). Désactivée, les clients voient « Bientôt en vente » / « Réservations bientôt ouvertes » (fiche produit, shortcodes, liste d'attente, messages du panier, pas de `validFrom` pour Google) ; l'ouverture se fait quand même à la date prévue. L'admin voit toujours la date (`SaleWindow::label($slot, false)`).

## 0.13.5 : pas de liste d'attente avant l'ouverture

- Décision de Seb : une liste d'attente sur une date pas encore ouverte viderait la vente à l'ouverture. Avant l'ouverture, la date s'affiche sans réservation **ni** liste d'attente ; à l'ouverture, tout le monde part à égalité. Les mentions de liste d'attente pré-ouverture des notes 0.13.3 ne s'appliquent plus.
- Retirés : le choix « En attendant l'ouverture » (`presale_mode`, la colonne éventuellement créée en 0.13.3 reste en base, inutilisée), `SaleWindow::waitlistBefore`, `Waitlist::notifyAtOpening` et le message « Ouverture des réservations (liste d'attente) ». L'inscription en liste d'attente sur une date pas encore ouverte est refusée côté serveur.


## 0.13.6 : facture globale automatique après un délai

- Réglage **Facture globale automatique après (jours)** (`invoice_delay`, 30 par défaut, 0 = jamais), section Émission et envoi. Passé ce délai après le paiement, ce qui n'est pas encore facturé (tout, ou le reste si le client a déjà fait une partie) part en une seule facture aux coordonnées de la commande, envoyée par e-mail (auteur `auto`).
- Tâche planifiée `smbb_passflow_invoice_delay` (toutes les heures, 20 factures au plus par passage) : `Invoices::runDelay`. Commandes en cours ou terminées seulement ; chaque commande traitée reçoit la méta `_passflow_invoice_swept`.
- Le délai ne vaut que pour les commandes payées après sa mise en route (option `smbb_passflow_invoice_delay_start`) : l'activer ne facture pas tout l'historique à la date du jour. Passer à 0 puis le remettre repart de zéro.
- Correction en cours (un avoir a rouvert des lignes sans nouvelle facture) : pas de facture automatique, une note sur la commande demande à l'équipe de l'émettre. Les avoirs de remboursement ne comptent pas.
- Mon compte › Factures : la date limite s'affiche sur la commande (« jusqu'au … ») et dans « À facturer ». Encadré Factures de la commande : « Sinon, facture globale automatique à partir du … ».

## Correspondance avec smbb-tomo

Passflow est la reprise de smbb-tomo : tout ce qui s'appelait `tomo` s'appelle `passflow` (namespace `Smbb\Passflow`, constantes `SMBB_PASSFLOW_*`, text domain `smbb-passflow`, options `smbb_passflow_*`, hooks `smbb_passflow_*`, classes CSS `passflow-*`, variables CSS `--passflow-*` (les couleurs de thème restent les `--smbb-t-*` de CodeTool, à ne pas renommer), widgets `smbb-pfw-*`).

Les noms techniques français sont passés en anglais :

| Tomo | Passflow |
|---|---|
| tomo_atelier / atelier_id | passflow_activity / activity_id |
| tomo_creneau / creneau_id | passflow_slot / slot_id |
| tomo_inscription / inscription_id | passflow_booking / booking_id |
| tomo_intervenant / intervenant_id(s) | passflow_host / host_id(s) |
| tomo_attente / attente_id | passflow_waitlist / waitlist_id |
| tomo_campagne / campagne_id | passflow_campaign / campaign_id |
| tomo_campagne_dest | passflow_campaign_recipient |
| tomo_catalogue | passflow_catalog |
| tomo_compteur | passflow_counter |
| tomo_dispo | passflow_availability |
| tomo_envoi | passflow_delivery |
| tomo_facture | passflow_invoice |
| tomo_historique | passflow_history |
| tomo_espace / tomo_calendrier / tomo_bons / tomo_factures / tomo_campagnes | passflow_portal / passflow_calendar / passflow_vouchers / passflow_invoices / passflow_campaigns |

Restent en français, volontairement : les textes affichés, les variables des modèles de messages (`{atelier}`, `{lien_dispos}`, `{numero_facture}`…), les mots du vocabulaire réglable et les paramètres d'URL simples (`?atelier=`, `?creneau=`).

## 0.15.0 : assistant de démarrage, profils d'activité, données et factures de démo

L'assistant s'ouvre à l'activation (et depuis Réglages › Configuration, ou le widget « Configuration » du tableau de bord, réservé aux administrateurs ; « Ne plus montrer » le décoche dans les Options de l'écran). Le moteur est générique et vit dans CodeTool 0.4.0 (`src/Wizard/`) : un plugin déclare son assistant dans `codetool/wizards/<nom>.json`, avec des étapes typées (`appearance`, `plugins`, `form`, `profiles`, `view`, `finish`) et une classe de hooks (`stepVisible`, `stepDone`, `stepContext`, `saveStep`, `applyProfile`, `finish`). Une étape `form` enregistre dans un modèle d'option CodeTool (fusion avec l'existant, hooks du modèle compris).

Étapes de Passflow (`codetool/wizards/passflow.json`, hooks `codetool/hooks/WizardHooks.php`) :

1. **Apparence** (facultative) : thème et préréglages de la peau CodeTool.
2. **Boutique** : installe et active WooCommerce, coupe son propre assistant, règle France / euro, pages, compte client à la commande, et propose la carte fictive CodeTool (admins seulement, pour tout le monde, ou non).
3. **Entreprise et TVA** : sections Identité légale et Contact des Réglages, choix HT / TTC et taux, appliqués à WooCommerce (taxes, classe de taux, adresse de la boutique). Réutilisable seule depuis Réglages › Configuration.
4. **Profil d'activité** : `profiles/<clé>/profile.json` (format de l'export de configuration, `smbb-passflow-config` v1, avec un en-tête `profile`) et `extras.json` facultatif (apparence CodeTool, options WooCommerce autorisées, offre type, données de démo). Profils livrés : Atelier créatif, Billetterie VIP (badge, pack week-end), Atelier de cuisine, Stage sportif.
5. **Première offre** : fiche + produit WooCommerce publié, puis liste d'attente, bons cadeaux, émission des factures.
6. **Billets et messages** : sections des Réglages (billet, e-mails, expéditeur, rappels).
7. **Données de démo** (facultative) : 1 intervenant, 2 clients (dont une société), 2 dates (ou samedi + dimanche + pack 2 jours pour le profil VIP), 2 commandes payées, 1 inscription manuelle, mini-catalogue sur place et un bon de 50 €. Tout est marqué et se supprime d'un clic ; le mode test est forcé (les messages partent à l'adresse de l'administrateur).
8. **Mise en production** : supprimer la démo, couper le mode test, les factures démo et la carte fictive.

**Factures de démo** : réglage « Factures en mode démo » (Réglages › Factures), actif aussi tant que la démo existe. Numérotation à part (`DEMO-…`, compteurs `demo-*`), filigrane « DÉMO · SANS VALEUR », pas de copie au comptable, exclues des exports CSV / ZIP, purge en un clic. Un avoir suit le statut de sa facture d'origine. La colonne `demo` est ajoutée à la table des factures par la montée de version.

Les sections des Réglages sont maintenant construites par `src/Admin/SettingsSections.php`, partagé entre l'écran des Réglages et l'assistant.

## Communication aux inscrits d'une journée (0.15.3)

- Fiche d'une journée : à côté de « Gestion mobile » (2/3), tuile blanche « Communication » (1/3), empilées sur téléphone. Elle affiche « N envois en cours » tant qu'une diffusion n'est pas finie.
- Écran `?view=communication&id=` du créneau : cible (tous, présents, absents, imprévus = source walkin, non pointés = à confirmer/confirmé) avec le nombre de personnes et d'adresses par canal, SMS et/ou e-mail, objet, message avec variables ({prenom}, {atelier}, {date_courte}, {heure}, {lieu}…), modèles rapides (retard, changement de lieu, annulation, absents, merci), compteur de SMS. Liste des communications avec progression et bouton « Arrêter ».
- File d'envoi : depuis la 0.17.0 (CodeTool 0.4.4), les messages passent par la file commune de CodeTool (`Smbb\WpCodeTool\Outbox\Outbox`, tables `smbb_outbox` et `smbb_outbox_item`, écran CodeTool › Communication › File d'envoi). Passflow s'y déclare comme source `passflow` (filtre `smbb_wpcodetool_outbox_sources`, contexte `slot` = la journée, `ref` = id de l'inscription, `kind` manual/review, `meta` target/consent) et `Broadcasts::sendItem` personnalise et envoie chaque message (contrôle RGPD, `Notifier::sendOne`, mode test, journal Messages, événement `broadcast` ou `review`). Cadence (X messages par lot, un lot toutes les Y minutes, défaut 60 / 1 min) réglée dans CodeTool, reprise une fois depuis les anciens réglages `broadcast_batch` / `broadcast_every`. Trois tentatives par message, arrêt et relance des échecs depuis l'écran CodeTool. Les anciennes tables `passflow_broadcast(_queue)` sont recopiées une fois dans la file CodeTool (option `smbb_passflow_broadcast_migrated`) puis ne servent plus.

## Bilan d'une journée (0.16.1)

Tuile « Bilan » sur la fiche Journée (à côté de Gestion mobile et Communication), vue CodeTool `report` du modèle passflow-slot (`codetool/views/passflow-slot/report.php`), réservée aux gestionnaires.

- Présence : inscrits, présents (taux), absents, non pointés, imprévus, annulés, barre de répartition, liste nominative des absents / non pointés.
- Ventes sur place : encaissé, en attente, bons d'achat émis, par moyen de paiement, par produit, détail des commandes (OnsiteSales::summary).
- Documents : faits / refusés / manquants par document, participants à régulariser (absents exclus).
- Messages : SMS / e-mails envoyés, échecs, par type de message ; nombre de communications.
- Notes de l'intervenant.
- Export CSV (`passflow_report_csv`, Service\Report::csv) : blocs chiffrés puis liste nominative avec documents. Impression : tout se déplie.

## 0.16.3 : rappel du jour même, paramétrable

Deuxième rappel aux participants, en plus de celui de la veille. Réglages › Général › Rappels, « Rappel participants le jour même » :

- **Non** (par défaut) : rien ne change.
- **Le matin, à une heure fixe** (`reminder_day_time`, 08:00 par défaut) : part à cette heure le jour de l'atelier, pour les créneaux qui commencent après cette heure.
- **Quelques heures avant le début** (`reminder_day_hours`, 1 à 23, 3 par défaut).

Canaux (SMS coché par défaut, e-mail possible) et textes : onglet Messages, modèle `reminder_participant_day` « Rappel participant (le jour même) ». Envoi par la tâche des rappels (15 min), clé anti-doublon `reminder:j0:…` : un seul envoi par participant et par date. Les jours d'un pass suivent la même règle que pour la veille.

## 0.16.4 : Comparer des dates ou des ateliers

Page Suivi › **Comparer** (modèle `passflow-compare`, vue `codetool/views/passflow-compare/compare.php`, gestionnaires seulement), au 2e niveau de la barre d'app à côté de Statistiques. Depuis le Bilan d'une journée, bouton « Comparer avec d'autres dates » : la date du Bilan (en référence) et les 5 précédentes du même atelier.

- **Des dates** : jusqu'à 8 créneaux cochés (période + filtre atelier ; dates sans inscrit non proposées, pass exclus).
- **Des ateliers** : toutes les dates de la période additionnées par atelier (8 au plus gros CA si rien n'est coché ; brouillons vides et créneaux annulés exclus).
- Indicateurs (Service\Compare, mêmes définitions que le Bilan) : remplissage (places, inscrits, %, liste d'attente, annulations), présence (présents, taux = présents / inscrits, absents, non pointés, imprévus), chiffre d'affaires (réservations, ventes sur place encaissées, total, CA moyen par inscrit). Les ratios sont recalculés sur les sommes.
- Affichage : « En un coup d'œil » (barres remplissage / présence / CA), puis tableau côte à côte avec écart à la référence (vert = mieux, rouge = moins bien) et meilleure valeur en gras. Référence au choix (« Comparer à »).
- Export CSV `passflow_compare_csv` (une ligne par indicateur, une colonne par date ou atelier) ; impression.

## 0.16.5 : transfert d'un billet à une autre personne

- Le participant qui ne peut pas venir donne sa place depuis la page de son billet (bloc replié « Transférer ce billet à une autre personne ») ou le lien « Transférer » de Mon compte et de la page de commande. L'équipe peut aussi le faire depuis la fiche d'une inscription (pli « Transférer le billet », sans délai).
- Même réservation (place, commande, facture, pass) : seuls la personne et le billet changent (`Service\Transfers`). Colonnes `ticket_rev` (version du billet) et `transfers` (JSON des anciens titulaires) sur `passflow_booking`.
- La version entre dans le jeton du billet et des documents, le numéro de série Apple (`passflow-ID-rN`) et l'identifiant Google : ancien lien, PDF, QR code et lien des documents ne marchent plus. L'ancien lien affiche « Ce billet a été transféré », un scan de l'ancien QR affiche « Billet transféré » à l'équipe.
- Wallet : l'ancien billet est barré (Apple `voided`, Google `INACTIVE`) au nom de l'ancien participant (`WalletSync::voidPrevious`, service web Apple qui sert les anciennes versions).
- Messages (Réglages › Envoi, journal des messages et note de commande) : `transfer_received` au nouveau participant avec son billet en pièce jointe + SMS, `transfer_buyer` à l'acheteur, `transfer_previous` à l'ancien participant s'il n'est pas l'acheteur. Variables `{ancien_participant}`, `{nouveau_participant}`.
- Commande WooCommerce : nom du participant mis à jour sur la ligne (Mon compte), note privée. Historique : action « Billet transféré ». Documents signés par l'ancien participant supprimés logiquement.
- Réglages › Billets › Transfert des billets : `ticket_transfer` (oui par défaut), `ticket_transfer_hours` (0 = jusqu'au début). Jamais après un pointage « présent ».

## Consentement RGPD et questionnaire de satisfaction (0.17.0)

- **Documents** : interrupteur « Consentement RGPD » sur un document (un seul actif). Il devient une question oui / non (texte type proposé si vide). `Documents::gdprDoc()`, `gdprAnswers()`, `hasGdprConsent()`.
- **Communication** : au-dessus des cibles, deux positions. « Avec consentement RGPD » (par défaut) ne vise que les « oui », revérifiés au moment de l'envoi (élément `skipped` sinon). « Cas d'urgence » vise tout le monde. Colonnes `passflow_broadcast.consent` et `kind` (manual, review). Les modèles Merci et Demande d'avis repassent en mode consentement.
- **Réglages › Avis** : formulaire CodeTool › Formulaires (bouton « Créer le questionnaire type »), délai après la fin, cible (présents et non pointés, ou présents), e-mail et/ou SMS avec `{lien_avis}`. Tâche CodeTool `smbb_passflow_reviews` (15 min, journées des 7 derniers jours, `passflow_slot.review_sent_at`). Pass : une demande, après le dernier jour, sur la réservation principale.
- **Page publique** `?passflow_avis=<id>&k=<jeton>` : une réponse par réservation, entrée Forms d'origine `passflow_review`, réf. `booking:<id>`, sans notification.
- **Bilan** : carte « Avis des participants » (note moyenne, réponses, taux, répartition, verbatims) + bouton « Envoyer maintenant » ; section dans le CSV.
- **CodeTool 0.4.2** : type de champ « Note (étoiles) » (`rating`, 1 à 5), modèle « Questionnaire de satisfaction », `FormRepository::createFromTemplate()`, `EntryRepository::forSource()`.

## Ventes en direct (bulle + petit son)

Brique CodeTool `Live\LiveFeed` (CodeTool 0.4.5) : dans l'admin, une bulle discrète en bas à droite (en haut sur téléphone, pour ne jamais cacher le clavier ni la caméra) et deux notes douces à chaque nouvelle vente. Chaque onglet visible demande les nouveautés toutes les 20 s ; une vente ne sonne qu'une fois même avec plusieurs onglets ; un onglet caché rattrape les 15 dernières minutes à son retour.

Passflow (`WooCommerce\LiveSales`) habille la bulle :
- **Nouvelle réservation** (en ligne) : client · atelier — date · nombre de places, lien vers la fiche de la date ;
- **Vente en caisse** (payée sur place) : client · articles · atelier, moyen de paiement, lien vers la caisse. Le vendeur ne reçoit pas sa propre vente ;
- **Paiement reçu** (lien de paiement réglé, vente à payer en ligne ou participant imprévu) : vu aussi par l'intervenant qui a envoyé le lien.

Qui la voit : gestionnaires Passflow et comptes qui gèrent les commandes ; intervenants pour leurs liens. Réglages du site : CodeTool › WooCommerce › Ventes en direct (bulle à chaque commande payée, son par défaut). Pour soi : widget « Mes préférences d'affichage » › Ventes en direct (Avec son / Sans son / Coupé, « Voir un exemple ») ou le haut-parleur de la bulle. Une page peut mettre les bulles en pause avec un élément `[data-smbb-live-quiet]`.

## Carte des participants

« D'où viennent les participants » : carte de France SVG (départements teintés + un point par commune, taille selon le nombre), lieu de l'atelier en étoile avec des arcs vers les communes, encadré outre-mer / étranger, chiffres clés (personnes localisées, communes, distance médiane, part à moins de 30 km), palmarès des communes et des départements. Zoom animé automatique sur la zone des participants quand ils viennent surtout du coin (bouton « Toute la France » pour revenir).

- Où : Bilan d'une journée (version compacte) et Suivi › Statistiques (suit les filtres période / atelier / créneau).
- Adresse : code postal de facturation de l'acheteur (commande WooCommerce, sinon méta `billing_*` du compte). Une personne compte une fois (e-mail + prénom, sinon commande + nom).
- Aucun service externe : `src/Service/data/fr-postcodes.txt` (6 063 codes postaux → position, d'après codes-postaux La Poste/INSEE et france-geojson, licence ouverte) et `src/Service/data/fr-departments.php` (tracés simplifiés). Code postal inconnu (CEDEX) → centre du département. Lieu : latitude/longitude de l'atelier, sinon code postal de son adresse, sinon celui de la boutique WooCommerce.
- Code : `Service\ParticipantMap` (forSlots, aggregate, render ; cache transitoire 10 min), `assets/participant-map.css|js` chargés seulement quand la carte s'affiche.

## Récap animé façon « Wrapped » (0.17.x)

- Bilan d'une date › « Voir le récap animé » : cartes plein écran façon stories (ouverture, présence en anneaux, comparaison aux 5 dates précédentes du même atelier avec badge « Record ! », chiffre d'affaires, meilleure vente sur place, avis avec citations, coulisses SMS/e-mails/documents, synthèse finale). Confettis maison, compteurs animés, `prefers-reduced-motion` respecté.
- Commandes : toucher droite/gauche, glisser, flèches, espace (pause), appui long (pause), Échap (retour au Bilan). Défilement automatique 7 s par carte.
- Partage : lien public signé `?passflow_recap=ID&k=…` (HMAC, sans aucun nom de participant), avec ou sans chiffre d'affaires (`ca=1`, compris dans la signature). « Télécharger l'image » produit une image story 1080 × 1920 (feuille de partage sur téléphone).
- Code : `Service\Recap` (données = `Report::data` + `Compare::query`, lecteur, page publique), vue `passflow-slot/wrapped.php`, `assets/recap.js`, `assets/recap.css` (préfixe `rc-`).

## Recherche Ctrl K enrichie (2026-11-08)

La recherche Ctrl K de CodeTool (bouton « Chercher ou faire… » de la barre d'app) trouve maintenant les données Passflow, en plus des pages et des contenus. Moteur dans CodeTool (`Smbb\WpCodeTool\Admin\PaletteSearch`, filtre `smbb_wpcodetool_palette_providers`), résultats fournis par `src/Admin/PaletteSearch.php` :

- **Participants** : nom, prénom, e-mail ou téléphone (« 06 12 34 » trouve « +33 6 12 34… »). Pastille d'état, créneau et contact sur la 2e ligne. Actions rapides : Marquer payée ou Pointer présent / Annuler le pointage (le jour même), Billet, Commande n°…, Faire signer (documents manquants, sur place), Fiche du créneau, Appeler (téléphone), E-mail.
- **Commandes** : « 1234 », « #1234 » ou « n° 1234 » → la commande WooCommerce, avec ses participants en actions.
- **Prochains créneaux** : le nom d'un atelier → ses prochaines dates, places libres ; actions Faire l'appel, Caisse et Bilan (le jour même), Inscrits, Communication.

Clavier : ↑ ↓ pour choisir, Tab ou → pour parcourir les actions, Entrée pour lancer, Ctrl + Entrée pour un nouvel onglet. Recherche vide : les derniers résultats ouverts. Réservé aux gestionnaires (`manage_passflow`). Sur les écrans CodeTool, la palette se charge dès qu'une extension fournit des résultats, même si « Recherche Ctrl K partout » est coupé. Sur téléphone, la fenêtre suit la partie visible de l'écran quand le clavier est ouvert.

## Gestion mobile « PDA » (0.17.1 à 0.17.3)

L'écran d'appel de l'espace intervenant reprend la scène sombre du mode Jour J sur toutes les tailles d'écran (`assets/pda.css`, classe `html.passflow-pda` posée par `passflow-portal/rollcall.php`). Sur ordinateur, la page devient une colonne centrée de 640 px.

0.17.2 : la barre du bas et la feuille Plus sont déplacées sur `body` (couche `.passflow-pda-layer`) car l'animation d'entrée du skin (transform) piégeait les éléments fixes ; barre WP et barre d'app masquées sans espace réservé ; surcharges en `html.passflow-pda:not(#pda-z) …` pour battre le preset Cristal (fonds blancs forcés sur les `-card`, `-tile`, `-panel`) ; cibles tactiles de 44 px minimum, champs en 16 px (pas de zoom iOS), retour visuel à l'appui ; panier déplié en feuille posée sur le magasin (un toucher derrière la referme) ; tuiles caisse jamais écrasées ; glisser possible aussi à la souris.

0.17.3 : interlignes explicites sur la feuille de paiement (le total chevauchait le titre). Test sans douchette depuis la console du navigateur, sur l'écran d'appel (fenêtre badge) et le suivi tablette Jour J : `scan("PCCF-T-00009")` (alias `passflowScan`). Le code est rejoué comme une vraie douchette, selon le réglage « Pointage à la douchette » (rafale de touches + Entrée sans champ, ou saisie dans le champ). Sur l'écran d'appel, la fenêtre badge s'ouvre si besoin ; sur le Jour J hors date, il faut d'abord « Pointer quand même ».

- En-tête d'une ligne : retour à l'agenda, anneau présents / inscrits (suivi en direct par `gauge()`), atelier, heure du serveur et compte à rebours. La barre WordPress, le menu et la barre CodeTool sont masqués sur cet écran.
- Recherche toujours visible, qui trouve aussi le n° du billet (`PCCF-T-00042`, ou celui du pass). Nouvelles puces « Docs à signer » et « À confirmer » (affichées seulement s'il y a quelqu'un dedans), à côté de Tous / À pointer / Présents / Absents.
- Cartes au pouce : initiales, nom, billet, pastilles (pass, imprévu, à confirmer, allergies, documents). Grosse coche de 52 px : un toucher = présent (ou retour à « à pointer »), un appui long = absent. Glisser à droite = arrivé ; glisser à gauche = Appeler, Note, Vente. Les trois boutons radio restent et enregistrent comme avant (synchro, confettis, coche). Bandeau « X arrivé · Annuler » pendant 5 s, vibration et petit son. Hors du jour de l'atelier, la coche est grisée et un toucher explique pourquoi.
- Barre du bas : Liste, Imprévu, Badge (ouvre la fenêtre « Pointage badge », inchangée), Caisse (ouvre la vente, avec l'encaissé du jour), Plus (ventes de la journée, télécharger la liste, fiche du créneau, mode Jour J, agenda).
- Caisse : tuiles en deux colonnes avec la photo produit sur fond blanc, stock restant, compteur rose ; encaissé du jour et accès aux ventes dans l'en-tête ; barre blanche « Panier · total · Encaisser ». « Encaisser » ouvre une feuille avec le montant en gros et quatre moyens (Carte par défaut, Espèces, Lien SMS, Autre) : un toucher valide la vente (même envoi Ajax qu'avant), puis coche verte plein écran.
