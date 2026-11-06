<?php
/**
 * Réglages Tomo : général, envoi (Twilio, expéditeur, mode test) et textes des messages.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\Tomo\Admin\Actions;
use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Support\Settings;
use Smbb\Tomo\Support\Templates;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';

// Valeurs par défaut des messages et jeton Twilio en clair dans le champ masqué.
$form = View::refillForm($form, function (array $item) {
    $defaults = Settings::defaults();

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $item) || (strpos($key, 'tpl_') === 0 && trim((string) $item[$key]) === '')) {
            $item[$key] = $value;
        }
    }

    // Les clés des wallets ne sont jamais réaffichées : le champ vide garde la valeur enregistrée.
    foreach (array('apple_private_key', 'apple_key_password', 'google_service_account') as $secret) {
        $item[$secret] = '';
    }

    if (!empty($item['twilio_token']) && $item['twilio_token'] === Settings::get('twilio_token')) {
        $item['twilio_token'] = Settings::twilioToken();
    }

    return $item;
});

/**
 * Bloc d'un message : canaux actifs et textes.
 */
$message_section = function ($key, array $message) use ($form, $d) {
    $icons = array('participant' => 'dashicons-admin-users', 'intervenant' => 'dashicons-businessperson', 'attente' => 'dashicons-list-view');

    $reset = '';

    if (Templates::customized($key)) {
        $reset = '<p class="tomo-template-custom"><span class="tomo-badge tomo-badge--pending">' . esc_html__('Texte personnalisé', $d) . '</span> '
            . '<a href="' . esc_url(View::actionUrl(\Smbb\Tomo\Admin\Actions::TEMPLATES_RESET, array('scope' => $key))) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__('Remettre le texte d\'origine de ce message ? Vos modifications seront perdues.', $d))) . ');">'
            . esc_html__('Revenir au texte d\'origine', $d) . '</a></p>';
    }

    return $form->section($message['label'], $message['help'], array(
        $reset,
        $form->row(
            $form->toggle(__('Par e-mail', $d))->setName('notify_' . $key . '_email'),
            $form->toggle(__('Par SMS', $d))->setName('notify_' . $key . '_sms')
        ),
        $form->text(__("Objet de l'e-mail", $d))->setName('tpl_' . $key . '_subject')->showIf('notify_' . $key . '_email', '1'),
        $form->textarea(__("Texte de l'e-mail", $d), array('rows' => 8))->setName('tpl_' . $key . '_body')->showIf('notify_' . $key . '_email', '1'),
        $form->textarea(__('Texte du SMS', $d), array('rows' => 2))->setName('tpl_' . $key . '_text')->showIf('notify_' . $key . '_sms', '1')
            ->setHelp(__('160 caractères par SMS ; au-delà, il compte pour deux.', $d)),
    ))->setIcon(isset($icons[$message['to']]) ? $icons[$message['to']] : 'dashicons-email-alt');
};

$participants = array();
$intervenants = array();

foreach (Templates::messages() as $key => $message) {
    if (in_array($message['to'], array('intervenant', 'admin'), true)) {
        $intervenants[] = $message_section($key, $message);
    } else {
        $participants[] = $message_section($key, $message);
    }
}

$variables = '';

foreach (Templates::variables() as $name => $label) {
    $variables .= '<li><code>{' . esc_html($name) . '}</code> ' . esc_html($label) . '</li>';
}

$reset_note = function ($scope) use ($d) {
    return View::note(__('Textes d\'origine', $d), '<p>' . esc_html__('Les textes modifiés ne suivent plus les améliorations des textes d\'origine (nouveaux liens, nouvelles variables).', $d) . '</p>'
        . '<p><a class="button" href="' . esc_url(View::actionUrl(\Smbb\Tomo\Admin\Actions::TEMPLATES_RESET, array('scope' => $scope))) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__('Remettre le texte d\'origine de tous les messages de cet onglet ? Les canaux choisis (e-mail, SMS) ne changent pas.', $d))) . ');">'
        . '<span class="dashicons dashicons-image-rotate" aria-hidden="true"></span> ' . esc_html__('Remettre tous les textes d\'origine', $d) . '</a></p>', 'dashicons-image-rotate');
};

$variables_note = View::note(
    __('Variables', $d),
    '<p>' . esc_html__('Elles sont remplacées à l\'envoi. Une ligne dont les variables sont toutes vides disparaît. Un texte vidé reprend le texte d\'origine.', $d) . '</p><ul class="tomo-variables">' . $variables . '</ul>',
    'dashicons-editor-code'
);

$test_note = View::note(
    __('Essayer', $d),
    '<p>' . esc_html__("Enregistrez d'abord, puis envoyez un e-mail et un SMS d'essai à l'adresse et au téléphone de test (à défaut, ceux du contact).", $d) . '</p>'
    . '<p><a class="button" href="' . esc_url(View::actionUrl(Actions::TEST_SEND, array('id' => 0))) . '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> ' . esc_html__("Envoyer un essai", $d) . '</a> '
    . '<a class="button" href="' . esc_url(View::url('tomo_envoi')) . '"><span class="dashicons dashicons-list-view" aria-hidden="true"></span> ' . esc_html__('Voir le journal des envois', $d) . '</a></p>',
    'dashicons-controls-play'
);

/**
 * Réglages › Vocabulaire : un choix par notion, et la saisie libre quand on choisit « Autre ».
 * Les libellés passent le mot d'origine en argument (sprintf) : sinon ils seraient réécrits eux aussi.
 */
$vocab_terms = \Smbb\Tomo\Support\Vocabulary::terms();
$vocab_field = function ($base, $help, $example) use ($form, $d, $vocab_terms) {
    $term = $vocab_terms[$base];
    $setting = $term['setting'];
    $source = $term['sources'][0];

    return array(
        $form->select(sprintf(__('Mot pour « %s »', $d), $base === 'réservation' ? $source[0] . ' / ' . $term['sources'][1][0] : $source[0]))
            ->setName($setting)
            ->setOptions(\Smbb\Tomo\Support\Vocabulary::options($term['choices']))
            ->setHelp($help),
        $form->row(
            $form->text(__('Au singulier', $d))->setName($setting . '_singular')->setHelp(sprintf(__('Exemple : %s.', $d), $example)),
            $form->text(__('Au pluriel', $d))->setName($setting . '_plural')->setHelp(__('Vide = singulier + « s ».', $d)),
            $form->toggle(__('Mot féminin', $d))->setName($setting . '_feminine')->setHelp(__('Pour accorder « la », « cette », « annulée »…', $d))
        )->showIf($setting, 'custom'),
    );
};

$vocabulary_tab = $form->tab(__('Vocabulaire', $d), __("Le même outil sert à des séances de cuisine, des stages, des trackdays, des formations ou des packs VIP. Choisissez les mots affichés partout : back-office, site, billets, e-mails et SMS par défaut. Les textes de messages déjà personnalisés ne changent pas. Préférez un mot du même genre que l'original : les déterminants et adjectifs collés au mot s'accordent, pas un accord plus loin dans la phrase.", $d), array(
    $form->section(__('Ce que vous proposez', $d), __('Ce qui se vend, ses dates et les formules sur plusieurs dates.', $d), array_merge(
        $vocab_field('atelier', __('Ce que vous proposez : le nom générique des fiches.', $d), __('pack VIP, baptême, circuit', $d)),
        $vocab_field('créneau', __('Chaque date proposée.', $d), __('soirée, run, manche', $d)),
        $vocab_field('pass', __('Une place vendue en une fois sur plusieurs dates (week-end, cycle).', $d), __('carnet, parcours', $d)),
        array($form->row(
            $form->text(__('Exemple de nom proposé', $d))->setName('vocabulary_example_name')->setHelp(__('Aide affichée sous le champ Nom, par exemple « Stage de pilotage ». Vide = pas d\'exemple.', $d)),
            $form->text(__('Exemple de lieu', $d))->setName('vocabulary_example_location')->setHelp(__('Aide affichée sous le champ Lieu, par exemple « Circuit de Magny-Cours ».', $d))
        ))
    ))->setIcon('dashicons-calendar-alt'),
    $form->section(__('Les personnes', $d), __('Qui anime, et qui vient.', $d), array_merge(
        $vocab_field('intervenant', __('Qui anime : comptes avec leur espace, appel, disponibilités.', $d), __('pilote, guide', $d)),
        $vocab_field('participant', __('Qui vient : chaque personne inscrite, avec ses documents.', $d), __('cavalier, joueur', $d))
    ))->setIcon('dashicons-groups'),
    $form->section(__('Ventes', $d), __('Ce que reçoit la personne qui achète.', $d), array_merge(
        $vocab_field('réservation', __('Une place prise. À l\'origine, les écrans disent l\'un ou l\'autre selon le cas ; un mot choisi remplace les deux.', $d), __('participation', $d)),
        $vocab_field('billet', __('Le PDF avec QR code, aussi dans Apple et Google Wallet.', $d), __('laissez-passer, accès', $d)),
        $vocab_field('bon cadeau', __('Les bons vendus à offrir. Les bons d\'achat (remboursements, caisse) gardent leur nom.', $d), __('coffret cadeau', $d))
    ))->setIcon('dashicons-cart'),
));

$fields = array(
    $form->tabs(
        $form->tab(__('Général', $d), '', array(
            $form->section(__('Rappels', $d), __('Envoyés automatiquement avant chaque atelier (vérification toutes les 15 minutes).', $d), array(
                $form->row(
                    $form->number(__('Rappel participants (heures avant)', $d), array('min' => 1, 'step' => 1))->setName('reminder_participant_hours')
                        ->setHelp(__('24 = la veille.', $d)),
                    $form->number(__('Rappel intervenants (jours avant)', $d), array('min' => 1, 'step' => 1))->setName('reminder_intervenant_days')
                        ->setHelp(__('7 = une semaine avant, avec la liste des inscrits.', $d))
                ),
            ))->setIcon('dashicons-bell'),
            $form->section(__("Liste d'attente", $d), '', array(
                $form->toggle(__("Proposer une liste d'attente sur les dates complètes", $d))->setName('waitlist_enabled')
                    ->setHelp(__("Désactivée : une date complète s'affiche « Complet », sans formulaire ni relance. Les demandes déjà enregistrées restent visibles mais personne n'est plus prévenu.", $d)),
                $form->row(
                    $form->number(__('Place gardée pendant (heures)', $d), array('min' => 1, 'step' => 1))->setName('waitlist_hold_hours')
                        ->setHelp(__("Quand une place se libère, la première personne en attente est prévenue et a ce délai pour réserver. Ensuite, c'est au tour de la suivante.", $d)),
                    $form->text(__('Lien de secours pour réserver (facultatif)', $d))->setName('waitlist_booking_url')
                        ->setHelp(__('Le message contient le lien direct vers la date sur la fiche produit. Ce lien ne sert que pour un atelier sans produit en ligne (à défaut : la page d\'accueil).', $d))
                )->showIf('waitlist_enabled', '1'),
            ))->setIcon('dashicons-list-view'),
            $form->section(__('Prix', $d), __('Comment vous saisissez les prix des ateliers et des dates. Le panier et l\'affichage suivent ensuite les réglages de taxes de WooCommerce.', $d), array(
                $form->row(
                    $form->select(__('Prix saisis', $d))->setName('price_entry')->setOptions(array(
                        __('TTC (taxes comprises)', $d) => 'ttc',
                        __('HT (hors taxes)', $d) => 'ht',
                    )),
                    $form->select(__('Taux de TVA (classe de taxe WooCommerce)', $d))->setName('price_tax_class')->setOptions(\Smbb\Tomo\Support\Prices::classOptions())
                        ->setHelp(\Smbb\Tomo\Support\Prices::taxesOn() ? __('Les taux se règlent dans WooCommerce › Réglages › TVA.', $d) : __('Les taxes sont désactivées dans WooCommerce : ce choix ne change rien tant qu\'elles le restent.', $d))
                ),
            ))->setIcon('dashicons-money-alt'),
            $form->section(__('Informations demandées aux participants', $d), '', array(
                $form->row(
                    $form->toggle(__('Allergies', $d))->setName('ask_allergies'),
                    $form->toggle(__('Niveau', $d))->setName('ask_level'),
                    $form->toggle(__('Âge', $d))->setName('ask_age')
                ),
                $form->select(__('E-mail et téléphone de chaque participant', $d))->setName('ask_contact')->setOptions(array(
                    __('Demandés, facultatifs (sinon ceux de l\'acheteur)', $d) => 'optional',
                    __('Demandés et obligatoires', $d) => 'required',
                    __('Non demandés (tout part à l\'acheteur)', $d) => 'off',
                ))
                    ->setHelp(__('Billet, rappels et documents à signer partent à chaque participant sur ses propres coordonnées. Un champ laissé vide reprend celles de l\'acheteur.', $d)),
            ))->setIcon('dashicons-id'),
            $form->section(__('Contact', $d), __('Repris dans la signature des messages ; les réponses aux e-mails arrivent sur cette adresse.', $d), array(
                $form->row(
                    $form->text(__('E-mail', $d))->setName('contact_email'),
                    $form->text(__('Téléphone', $d))->setName('contact_phone')
                ),
            ))->setIcon('dashicons-phone'),
            $form->section(__('Apparence du back-office', $d), __("Couleur d'accent des écrans Tomo : icônes, chiffres clés, liens, date du jour, bouton « atelier du jour » et page de paiement client. Rechargez la page après l'enregistrement.", $d), array(
                $form->row(
                    $form->select(__("Couleur d'accent", $d))->setName('admin_theme')->setOptions(\Smbb\Tomo\Support\Theme::options()),
                    $form->color(__('Couleur personnalisée', $d))->setName('admin_accent')->showIf('admin_theme', 'custom')
                ),
                $form->toggle(__('Boutons principaux à cette couleur aussi', $d))->setName('admin_buttons')
                    ->setHelp(__('Sinon, les boutons gardent le bleu de WordPress.', $d)),
            ))->setIcon('dashicons-admin-appearance'),
            $form->section(__("Bons d'achat", $d), __("Bons cadeaux, bons de remboursement et bons créés en caisse. Les bons déjà émis gardent leur code.", $d), array(
                $form->text(__('Préfixe des codes', $d))->setName('gift_prefix')
                    ->setHelp(__('Lettres et chiffres, 12 au plus. Exemple : TOMO donne TOMO-7KQ4-M2XP. Vide = code sans préfixe (7KQ4-M2XP).', $d)),
                $form->toggle(__('Utiliser les bons cadeaux en boutique', $d))->setName('gift_instore')
                    ->setHelp(__("Ajoute « Utiliser un bon en boutique » (code ou QR code) en haut de Bons cadeaux et un bouton sur le tableau de bord. Sans, un bon ne s'utilise qu'en ligne, à la réservation.", $d)),
            ))->setIcon('dashicons-tickets-alt'),
        )),
        $vocabulary_tab,
        $form->tab(__('Envoi', $d), '', array(
            $form->section(__('Expéditeur des e-mails', $d), __('Les e-mails partent par le serveur d\'envoi du site (wp_mail / SMTP).', $d), array(
                $form->row(
                    $form->text(__("Nom de l'expéditeur", $d))->setName('sender_name')
                        ->setHelp(__('Aussi en tête des SMS.', $d)),
                    $form->text(__("Adresse de l'expéditeur", $d))->setName('sender_email')
                        ->setHelp(__('Vide = adresse par défaut du site. Prenez une adresse du domaine du site pour éviter les spams.', $d))
                ),
            ))->setIcon('dashicons-email'),
            $form->section(__('SMS (Twilio)', $d), __('Identifiants dans la console Twilio : Account SID, Auth Token et Messaging Service SID (qui porte le nom d\'expéditeur).', $d), array(
                $form->row(
                    $form->text(__('Account SID', $d))->setName('twilio_sid'),
                    $form->secret(__('Auth Token', $d))->setName('twilio_token')
                        ->setHelp(__('Vide = on garde le jeton enregistré.', $d))
                ),
                $form->text(__('Messaging Service SID', $d))->setName('twilio_service'),
            ))->setIcon('dashicons-smartphone'),
            $form->section(__('Mode test', $d), __('Tous les messages partent vers l\'e-mail et le téléphone ci-dessous au lieu des vrais destinataires. Idéal pour essayer sans déranger personne.', $d), array(
                $form->toggle(__('Activer le mode test', $d))->setName('test_mode'),
                $form->row(
                    $form->text(__('E-mail de test', $d))->setName('test_email'),
                    $form->text(__('Téléphone de test', $d))->setName('test_phone')
                ),
            ))->setIcon('dashicons-hammer'),
            $test_note,
        )),
        $form->tab(__('Billets', $d), __("Chaque participant télécharge son billet depuis Mon compte › Mes ateliers. Le QR code du billet ouvre l'écran de pointage pour l'équipe.", $d), array(
            View::note(
                __('État', $d),
                '<ul class="tomo-variables">'
                . '<li>' . esc_html__('Billet PDF : toujours disponible.', $d) . '</li>'
                . '<li>' . esc_html(\Smbb\Tomo\Service\Tickets::appleReady() ? __('Apple Wallet : prêt.', $d) : __('Apple Wallet : à configurer ci-dessous.', $d)) . '</li>'
                . (function () use ($d) {
                    $expiry = \Smbb\Tomo\Service\Tickets::appleExpiry();
                    $warning = \Smbb\Tomo\Service\Tickets::appleExpiryWarning();

                    if (!$expiry) {
                        return '';
                    }

                    $line = isset($expiry['certificate']) ? sprintf(__('Certificat Apple Wallet valable jusqu\'au %s', $d), date_i18n('j F Y', $expiry['certificate'])) : '';
                    $line .= isset($expiry['wwdr']) ? ($line !== '' ? ' · ' : '') . sprintf(__('WWDR jusqu\'au %s', $d), date_i18n('j F Y', $expiry['wwdr'])) : '';

                    return '<li>' . esc_html($line) . '</li>' . ($warning !== '' ? '<li class="tomo-allergy">' . esc_html($warning) . '</li>' : '');
                })()
                . '<li>' . esc_html(\Smbb\Tomo\Service\Tickets::googleReady() ? __('Google Wallet : prêt.', $d) : __('Google Wallet : à configurer ci-dessous.', $d)) . '</li>'
                . (function () use ($d) {
                    if (!\Smbb\Tomo\Service\Tickets::googleReady()) {
                        return '';
                    }

                    \Smbb\Tomo\Service\CertificateAlerts::checkGoogle();
                    $last = \Smbb\Tomo\Service\CertificateAlerts::googleLast();

                    if (!$last) {
                        return '<li class="tomo-muted">' . esc_html__('Clé Google pas encore vérifiée (Google injoignable).', $d) . '</li>';
                    }

                    return $last['ok']
                        ? '<li>' . esc_html(sprintf(__('Clé Google acceptée par Google (vérifiée le %s).', $d), \Smbb\Tomo\Admin\View::datetime($last['at']))) . '</li>'
                        : '<li class="tomo-allergy">' . esc_html($last['message']) . '</li>';
                })()
                . '</ul>',
                'dashicons-tickets-alt'
            ),
            $form->section(__('Apparence des billets', $d), __('Pour le PDF, Apple Wallet et Google Wallet. La couleur du texte (blanc ou foncé) s\'adapte au fond.', $d), array(
                $form->row(
                    $form->select(__('Couleur du billet', $d))->setName('ticket_color_mode')->setOptions(array(
                        __("Celle de l'atelier (couleur du calendrier)", $d) => 'atelier',
                        __('Une seule couleur pour tous', $d) => 'fixed',
                    )),
                    $form->color(__('Couleur', $d))->setName('ticket_color')->showIf('ticket_color_mode', 'fixed')
                ),
                $form->image(__('Logo', $d))->setName('ticket_logo_id')->setHelp(__('PNG de préférence, fond transparent, format horizontal. Sans logo, le nom de l\'expéditeur est écrit à la place.', $d)),
                $form->textarea(__("Conditions d'annulation", $d), array('rows' => 3))->setName('ticket_policy')
                    ->setHelp(__('Au dos des billets Apple Wallet et Google Wallet. Vide = rien affiché.', $d)),
            ))->setIcon('dashicons-art'),
            $form->section(__('Apple Wallet', $d), __("Compte Apple Developer requis. Dans Certificates, Identifiers & Profiles : créez un Pass Type ID (pass.fr.votre-domaine.ateliers), puis son certificat. Exportez le certificat et sa clé en PEM, et collez aussi le certificat intermédiaire Apple WWDR (G4).", $d), array(
                $form->row(
                    $form->text(__('Pass Type ID', $d))->setName('apple_pass_type_id')->setHelp(__('Par exemple pass.fr.votre-domaine.reservations', $d)),
                    $form->text(__('Team ID', $d))->setName('apple_team_id')->setHelp(__('10 caractères, visible en haut à droite du compte développeur.', $d))
                ),
                $form->textarea(__('Certificat du Pass Type ID (PEM)', $d), array('rows' => 4))->setName('apple_certificate'),
                $form->textarea(__('Clé privée (PEM)', $d), array('rows' => 4, 'autocomplete' => 'off'))->setName('apple_private_key')
                    ->setHelp(__('Chiffrée en base. Vide = on garde la clé enregistrée.', $d)),
                $form->secret(__('Mot de passe de la clé (si elle en a un)', $d))->setName('apple_key_password')
                    ->setHelp(__('Vide = on garde celui enregistré.', $d)),
                $form->textarea(__('Certificat Apple WWDR G4 (PEM)', $d), array('rows' => 4))->setName('apple_wwdr'),
                $form->toggle(__("Suivre l'état du billet dans Apple Wallet", $d))->setName('wallet_sync_apple')
                    ->setHelp(trim(__("Présent, absent, annulé, nouvelle date : l'iPhone reçoit le billet à jour (notification Apple). Concerne les billets ajoutés après l'activation. Le serveur doit accepter HTTP/2 en sortie.", $d) . ' ' . \Smbb\Tomo\Service\WalletSync::status('apple'))),
            ))->setIcon('dashicons-smartphone'),
            $form->section(__('Google Wallet', $d), __("Compte émetteur Google Wallet (pay.google.com/business/console) et compte de service Google Cloud avec l'API Google Wallet activée. Ajoutez l'e-mail du compte de service comme utilisateur du compte émetteur.", $d), array(
                $form->text(__('Issuer ID', $d))->setName('google_issuer_id')->setHelp(__('Le numéro du compte émetteur.', $d)),
                $form->textarea(__('Clé du compte de service (JSON)', $d), array('rows' => 4, 'autocomplete' => 'off'))->setName('google_service_account')
                    ->setHelp(__('Le fichier JSON téléchargé depuis Google Cloud, collé tel quel. Chiffré en base. Vide = on garde la clé enregistrée.', $d)),
                $form->toggle(__("Suivre l'état du billet dans Google Wallet", $d))->setName('wallet_sync_google')
                    ->setHelp(trim(__('Présent, absent, annulé, nouvelle date : le billet est mis à jour dans le téléphone du client, y compris ceux déjà ajoutés.', $d) . ' ' . \Smbb\Tomo\Service\WalletSync::status('google'))),
            ))->setIcon('dashicons-google'),
        )),
        $form->tab(__('Factures', $d), __("Factures et avoirs des commandes WooCommerce. Une facture émise ne se modifie plus : une correction passe par un avoir puis une nouvelle facture.", $d), array(
            $form->section(__('Identité légale', $d), __('Elle figure en haut de chaque facture.', $d), array(
                $form->row(
                    $form->text(__('Raison sociale', $d))->setName('invoice_company')->setHelp(__('Vide = le nom de l\'expéditeur.', $d)),
                    $form->text(__('Forme et capital', $d))->setName('invoice_legal')->setHelp(__('Par exemple : SARL au capital de 5 000 €', $d))
                ),
                $form->textarea(__('Adresse', $d), array('rows' => 2))->setName('invoice_address'),
                $form->row(
                    $form->text(__('SIRET', $d))->setName('invoice_siret'),
                    $form->text(__('RCS ou RM', $d))->setName('invoice_rcs')->setHelp(__('Par exemple : RCS Clermont-Ferrand 123 456 789', $d)),
                    $form->text(__('N° de TVA intracommunautaire', $d))->setName('invoice_vat_number')
                ),
                $form->select(__('TVA', $d))->setName('invoice_vat_mode')->setOptions(array(
                    __('Assujetti à la TVA (HT, TVA par taux, TTC)', $d) => 'vat',
                    __('Franchise en base (mention article 293 B du CGI)', $d) => 'franchise',
                )),
                $form->textarea(__('Mentions de bas de page', $d), array('rows' => 3))->setName('invoice_footer')
                    ->setHelp(__('Conditions de paiement, pénalités de retard, indemnité forfaitaire de 40 € (clients professionnels), escompte.', $d)),
            ))->setIcon('dashicons-building'),
            $form->section(__('Numérotation', $d), __('Jetons : {AAAA} année, {AA} année sur 2 chiffres, {MM} mois, {N:5} compteur sur 5 chiffres. Le numéro est pris à l\'émission, sans trou ni doublon.', $d), array(
                $form->row(
                    $form->text(__('Modèle des factures', $d))->setName('invoice_pattern')->setHelp(sprintf(__('Prochain numéro : %s', $d), \Smbb\Tomo\Support\Schema::ready() ? \Smbb\Tomo\WooCommerce\Invoices::nextPreview('invoice') : '—')),
                    $form->text(__('Modèle des avoirs', $d))->setName('credit_pattern')->setHelp(sprintf(__('Prochain numéro : %s', $d), \Smbb\Tomo\Support\Schema::ready() ? \Smbb\Tomo\WooCommerce\Invoices::nextPreview('credit') : '—'))
                ),
                $form->row(
                    $form->select(__('Le compteur repart à 1', $d))->setName('invoice_reset')->setOptions(array(
                        __('Chaque année', $d) => 'yearly',
                        __('Jamais', $d) => 'never',
                    )),
                    $form->number(__('Reprendre les factures à', $d), array('min' => 1, 'step' => 1))->setName('invoice_next')->setHelp(__("Pour suivre la numérotation d'un ancien logiciel. Le compteur ne peut qu'avancer.", $d)),
                    $form->number(__('Reprendre les avoirs à', $d), array('min' => 1, 'step' => 1))->setName('credit_next')
                ),
                '<p><a class="button" target="_blank" href="' . esc_url(View::actionUrl(\Smbb\Tomo\WooCommerce\InvoiceScreen::PREVIEW, array('id' => 0))) . '"><span class="dashicons dashicons-pdf" aria-hidden="true"></span> ' . esc_html__('Aperçu PDF', $d) . '</a> <span class="description">' . esc_html__("Enregistrez d'abord. Un exemple, sans rien émettre ni consommer de numéro.", $d) . '</span></p>',
            ))->setIcon('dashicons-editor-ol'),
            $form->section(__('Émission et envoi', $d), '', array(
                $form->select(__('Quand facturer', $d))->setName('invoice_auto')->setOptions(array(
                    __('À la demande, et automatiquement pour les commandes avec un SIREN', $d) => 'siren',
                    __('À la demande seulement', $d) => 'manual',
                    __('Automatiquement à chaque commande payée', $d) => 'all',
                ))->setHelp(__('Une facture automatique reprend les coordonnées de la commande. Le client peut ensuite demander une correction (avoir puis nouvelle facture).', $d)),
                $form->toggle(__('Les clients établissent eux-mêmes leurs factures dans Mon compte', $d))->setName('invoice_customer'),
                $form->toggle(__("Mettre l'acheteur en copie quand la facture part à une autre adresse", $d))->setName('invoice_cc_buyer'),
                $form->text(__('Copie à la comptabilité (e-mail)', $d))->setName('invoice_accounting_email')->setHelp(__('Facultatif. Plusieurs adresses : séparez-les par des virgules. Copie cachée, pas en mode test.', $d)),
            ))->setIcon('dashicons-email-alt'),
        )),
        $form->tab(__('Messages aux participants', $d), __("Choisissez les canaux de chaque message et ajustez les textes.", $d), array_merge(array($reset_note('participant')), $participants, array($variables_note))),
        $form->tab(__('Messages aux intervenants', $d), '', array_merge(array($reset_note('intervenant')), $intervenants, array($variables_note))),
        $form->tab(__('Configuration', $d), __('Sauvegardez les réglages dans un fichier, ou reprenez ceux d\'un autre site.', $d), array(\Smbb\Tomo\Admin\ConfigTransfer::tabHtml()))
    ),
);

$html = '<div class="form_container">' . $form->save(__('Enregistrer', $d))->setFields($fields) . '</div>'
    . \Smbb\Tomo\Admin\ConfigTransfer::importForm(); // Hors du formulaire des réglages : un formulaire n'en contient pas un autre.

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Réglages Tomo', $d));
