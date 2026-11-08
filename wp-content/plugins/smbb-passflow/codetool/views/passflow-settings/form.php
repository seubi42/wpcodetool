<?php
/**
 * Réglages Passflow : général, envoi (Twilio, expéditeur, mode test) et textes des messages.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\Passflow\Admin\Actions;
use Smbb\Passflow\Admin\SettingsSections as Sections;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Support\Settings;
use Smbb\Passflow\Support\Templates;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';

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
        $reset = '<p class="passflow-template-custom"><span class="passflow-badge passflow-badge--pending">' . esc_html__('Texte personnalisé', $d) . '</span> '
            . '<a href="' . esc_url(View::actionUrl(\Smbb\Passflow\Admin\Actions::TEMPLATES_RESET, array('scope' => $key))) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__('Remettre le texte d\'origine de ce message ? Vos modifications seront perdues.', $d))) . ');">'
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
$hosts = array();

foreach (Templates::messages() as $key => $message) {
    if (in_array($message['to'], array('intervenant', 'admin'), true)) {
        $hosts[] = $message_section($key, $message);
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
        . '<p><a class="button" href="' . esc_url(View::actionUrl(\Smbb\Passflow\Admin\Actions::TEMPLATES_RESET, array('scope' => $scope))) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__('Remettre le texte d\'origine de tous les messages de cet onglet ? Les canaux choisis (e-mail, SMS) ne changent pas.', $d))) . ');">'
        . '<span class="dashicons dashicons-image-rotate" aria-hidden="true"></span> ' . esc_html__('Remettre tous les textes d\'origine', $d) . '</a></p>', 'dashicons-image-rotate');
};

$variables_note = View::note(
    __('Variables', $d),
    '<p>' . esc_html__('Elles sont remplacées à l\'envoi. Une ligne dont les variables sont toutes vides disparaît. Un texte vidé reprend le texte d\'origine.', $d) . '</p><ul class="passflow-variables">' . $variables . '</ul>',
    'dashicons-editor-code'
);

$test_note = View::note(
    __('Essayer', $d),
    '<p>' . esc_html__("Enregistrez d'abord, puis envoyez un e-mail et un SMS d'essai à l'adresse et au téléphone de test (à défaut, ceux du contact).", $d) . '</p>'
    . '<p><a class="button" href="' . esc_url(View::actionUrl(Actions::TEST_SEND, array('id' => 0))) . '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> ' . esc_html__("Envoyer un essai", $d) . '</a> '
    . '<a class="button" href="' . esc_url(View::url('passflow_delivery')) . '"><span class="dashicons dashicons-list-view" aria-hidden="true"></span> ' . esc_html__('Voir le journal des envois', $d) . '</a></p>',
    'dashicons-controls-play'
);

/**
 * Réglages › Vocabulaire : un choix par notion, et la saisie libre quand on choisit « Autre ».
 * Les libellés passent le mot d'origine en argument (sprintf) : sinon ils seraient réécrits eux aussi.
 */
$vocab_terms = \Smbb\Passflow\Support\Vocabulary::terms();
$vocab_field = function ($base, $help, $example) use ($form, $d, $vocab_terms) {
    $term = $vocab_terms[$base];
    $setting = $term['setting'];
    $source = $term['sources'][0];

    return array(
        $form->select(sprintf(__('Mot pour « %s »', $d), $base === 'réservation' ? $source[0] . ' / ' . $term['sources'][1][0] : $source[0]))
            ->setName($setting)
            ->setOptions(\Smbb\Passflow\Support\Vocabulary::options($term['choices']))
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
            Sections::reminders($form),
            Sections::waitlist($form),
            Sections::prices($form),
            Sections::participantInfo($form),
            Sections::contact($form),
            Sections::vouchers($form),
        )),
        $vocabulary_tab,
        $form->tab(__('Envoi', $d), '', array(
            Sections::sender($form),
            Sections::emailLook($form),
            View::note(
                __('Aperçu', $d),
                '<p>' . esc_html__('Enregistrez, puis ouvrez l\'aperçu ou envoyez un essai (section Mode test).', $d) . '</p>'
                . '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url(\Smbb\Passflow\Service\EmailBrand::previewUrl()) . '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span> ' . esc_html__("Voir l'aperçu", $d) . '</a></p>',
                'dashicons-visibility'
            ),
            class_exists('Smbb\\WpCodeTool\\Sms\\Sms') && class_exists('Smbb\\WpCodeTool\\Admin\\Pages\\MailPage')
                // Les SMS partent par CodeTool › Communication › SMS (connecteur, journal, coûts, alertes communs) :
                // plus d'identifiants Twilio ici. Les anciens restent en base, CodeTool propose de les reprendre.
                ? View::note(
                    __('SMS', $d),
                    (function () use ($d) {
                        $ready = \Smbb\WpCodeTool\Sms\Sms::ready();
                        $url = \Smbb\WpCodeTool\Admin\Pages\MailPage::url('settings', array(), 'sms');

                        return '<p>' . esc_html__('Les SMS de Passflow partent par CodeTool › Communication › SMS, comme ceux des autres plugins : un seul compte Twilio, un seul journal, le suivi des coûts et les alertes.', $d) . '</p>'
                            . '<p>' . ($ready === true
                                ? '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__('Prêt à envoyer.', $d)
                                : '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html(sprintf(__('Pas encore prêt : %s', $d), $ready))) . '</p>'
                            . '<p><a class="button" href="' . esc_url($url) . '"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span> ' . esc_html($ready === true ? __('Réglages SMS de CodeTool', $d) : __('Configurer les SMS dans CodeTool', $d)) . '</a></p>';
                    })(),
                    'dashicons-smartphone'
                )
                : $form->section(__('SMS (Twilio)', $d), __('Identifiants dans la console Twilio : Account SID, Auth Token et Messaging Service SID (qui porte le nom d\'expéditeur).', $d), array(
                    $form->row(
                        $form->text(__('Account SID', $d))->setName('twilio_sid'),
                        $form->secret(__('Auth Token', $d))->setName('twilio_token')
                            ->setHelp(__('Vide = on garde le jeton enregistré.', $d))
                    ),
                    $form->text(__('Messaging Service SID', $d))->setName('twilio_service'),
                ))->setIcon('dashicons-smartphone'),
            class_exists('Smbb\\WpCodeTool\\Outbox\\Outbox') && class_exists('Smbb\\WpCodeTool\\Admin\\Pages\\OutboxPage')
                // La file d'envoi est commune à tous les plugins : CodeTool › Communication › File d'envoi
                // (cadence, suivi de chaque envoi, détail par destinataire). Plus de réglage de vitesse ici.
                ? View::note(
                    __('Communications aux inscrits', $d),
                    (function () use ($d) {
                        $settings = \Smbb\WpCodeTool\Outbox\Outbox::settings();

                        return '<p>' . esc_html__("Les communications envoyées depuis la tuile « Communication » d'une journée passent par la file d'envoi de CodeTool, commune à tous les plugins : la cadence de diffusion s'y règle et on y voit où en est chaque envoi.", $d) . '</p>'
                            . '<p><span class="dashicons dashicons-controls-forward" aria-hidden="true"></span> ' . esc_html(sprintf(__('Cadence actuelle : %1$d messages toutes les %2$d minute(s).', $d), $settings['batch'], $settings['every'])) . '</p>'
                            . (current_user_can(\Smbb\WpCodeTool\Outbox\Outbox::capability())
                                ? '<p><a class="button" href="' . esc_url(\Smbb\WpCodeTool\Admin\Pages\OutboxPage::url()) . '"><span class="dashicons dashicons-controls-forward" aria-hidden="true"></span> ' . esc_html__("Ouvrir la file d'envoi", $d) . '</a></p>'
                                : '');
                    })(),
                    'dashicons-megaphone'
                )
                : View::note(
                    __('Communications aux inscrits', $d),
                    '<p>' . esc_html__("Mettez à jour SMBB WP CodeTool (0.4.4 ou plus) : la file d'envoi des communications en fait partie.", $d) . '</p>',
                    'dashicons-warning'
                ),
            $form->section(__('Mode test', $d), __('Tous les messages partent vers l\'e-mail et le téléphone ci-dessous au lieu des vrais destinataires. Idéal pour essayer sans déranger personne.', $d), array(
                $form->toggle(__('Activer le mode test', $d))->setName('test_mode'),
                $form->row(
                    $form->text(__('E-mail de test', $d))->setName('test_email'),
                    $form->text(__('Téléphone de test', $d))->setName('test_phone')
                ),
            ))->setIcon('dashicons-hammer'),
            $test_note,
        )),
        $form->tab(__('Avis', $d), __("Questionnaire de satisfaction envoyé automatiquement après chaque journée, seulement aux participants qui ont donné leur consentement RGPD. Les réponses s'affichent dans le Bilan de la journée.", $d), array(
            View::note(__('État', $d), (function () use ($d) {
                $gdpr = \Smbb\Passflow\Service\Documents::gdprDoc();
                $review_form = \Smbb\Passflow\Service\Reviews::form();
                $ok = '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ';
                $ko = '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ';
                $html = '<ul class="passflow-variables">';
                $html .= '<li>' . ($gdpr
                    ? $ok . sprintf(esc_html__('Consentement RGPD : document %s.', $d), '<strong>' . esc_html($gdpr['title']) . '</strong>')
                    : $ko . esc_html__('Aucun document ne porte le rôle « Consentement RGPD » : rien ne partira.', $d) . ' <a href="' . esc_url(View::url('passflow_documents')) . '">' . esc_html__('Régler les documents', $d) . '</a>') . '</li>';

                if (!\Smbb\Passflow\Service\Documents::formsAvailable()) {
                    $html .= '<li>' . $ko . esc_html__('Le module Formulaires de CodeTool est désactivé : activez-le dans CodeTool pour choisir un questionnaire.', $d) . '</li>';
                } elseif ($review_form) {
                    $html .= '<li>' . $ok . sprintf(esc_html__('Questionnaire : %s.', $d), '<strong>' . esc_html($review_form['title']) . '</strong>')
                        . (class_exists('Smbb\\WpCodeTool\\Admin\\Pages\\FormsPage') ? ' <a href="' . esc_url(\Smbb\WpCodeTool\Admin\Pages\FormsPage::formUrl($review_form['id'])) . '">' . esc_html__('Modifier les questions', $d) . '</a>' : '') . '</li>';
                } else {
                    $html .= '<li>' . $ko . esc_html__('Pas encore de questionnaire choisi.', $d) . ' <a class="button button-small" href="' . esc_url(View::actionUrl(Actions::REVIEW_FORM, array('id' => 0))) . '">' . esc_html__('Créer le questionnaire type', $d) . '</a></li>';
                }

                return $html . '<li>' . esc_html__('Chaque participant reçoit un lien personnel ({lien_avis}) et ne peut répondre qu\'une fois. Les réponses sont aussi dans CodeTool › Formulaires › Entrées (origine « Passflow · Avis »).', $d) . '</li></ul>';
            })(), 'dashicons-star-filled'),
            $form->section(__('Questionnaire de satisfaction', $d), '', array(
                $form->toggle(__('Envoyer automatiquement après chaque journée', $d))->setName('review_enabled')
                    ->setHelp(__('Vérifié toutes les 15 minutes, pour les journées des 7 derniers jours. Une journée déjà passée au moment où vous activez reçoit aussi sa demande si elle date de moins de 7 jours. Le Bilan permet aussi d\'envoyer tout de suite.', $d)),
                $form->row(
                    $form->select(__('Questionnaire', $d))->setName('review_form_id')->setOptions((function () use ($d) {
                        $options = array(__('Aucun', $d) => '0');

                        foreach (\Smbb\Passflow\Service\Documents::formChoices() as $form_id => $form_title) {
                            $options[sprintf('%s (n° %d)', $form_title, $form_id)] = (string) $form_id;
                        }

                        return $options;
                    })())->setHelp(__('Un formulaire de CodeTool › Formulaires. Le questionnaire type propose une note en étoiles, une recommandation et deux champs libres.', $d)),
                    $form->number(__('Envoyé après la fin (heures)', $d), array('min' => 0, 'max' => 168, 'step' => 1))->setName('review_delay')
                        ->setHelp(__('3 = trois heures après l\'heure de fin. 0 = dès la fin.', $d))
                ),
                $form->select(__('Qui reçoit la demande', $d))->setName('review_target')->setOptions(array(
                    __('Présents et non pointés (tous sauf les absents)', $d) => 'attended',
                    __('Seulement les pointés présents', $d) => 'present',
                ))->setHelp(__('Toujours parmi les participants qui ont répondu « Oui » au consentement RGPD. Pass : une seule demande, après le dernier jour.', $d)),
            ))->setIcon('dashicons-star-filled'),
            $form->section(__('Message de la demande', $d), __('Variables utiles : {prenom}, {atelier}, {date_courte}, {lien_avis}, {expediteur}, {signature}.', $d), array(
                $form->row(
                    $form->toggle(__('Par e-mail', $d))->setName('review_email'),
                    $form->toggle(__('Par SMS', $d))->setName('review_sms')
                ),
                $form->text(__("Objet de l'e-mail", $d))->setName('review_subject')->showIf('review_email', '1'),
                $form->textarea(__("Texte de l'e-mail", $d), array('rows' => 8))->setName('review_body')->showIf('review_email', '1'),
                $form->textarea(__('Texte du SMS', $d), array('rows' => 2))->setName('review_sms_text')->showIf('review_sms', '1')
                    ->setHelp(__('160 caractères par SMS ; le lien compte pour une cinquantaine.', $d)),
            ))->setIcon('dashicons-email-alt'),
        )),
        $form->tab(__('Billets', $d), __("Chaque participant télécharge son billet depuis Mon compte › Mes ateliers. Le QR code du billet ouvre l'écran de pointage pour l'équipe.", $d), array(
            View::note(
                __('État', $d),
                '<ul class="passflow-variables">'
                . '<li>' . esc_html__('Billet PDF : toujours disponible.', $d) . '</li>'
                . '<li>' . esc_html(\Smbb\Passflow\Service\Tickets::appleReady() ? __('Apple Wallet : prêt.', $d) : __('Apple Wallet : à configurer ci-dessous.', $d)) . '</li>'
                . (function () use ($d) {
                    $expiry = \Smbb\Passflow\Service\Tickets::appleExpiry();
                    $warning = \Smbb\Passflow\Service\Tickets::appleExpiryWarning();

                    if (!$expiry) {
                        return '';
                    }

                    $line = isset($expiry['certificate']) ? sprintf(__('Certificat Apple Wallet valable jusqu\'au %s', $d), date_i18n('j F Y', $expiry['certificate'])) : '';
                    $line .= isset($expiry['wwdr']) ? ($line !== '' ? ' · ' : '') . sprintf(__('WWDR jusqu\'au %s', $d), date_i18n('j F Y', $expiry['wwdr'])) : '';

                    return '<li>' . esc_html($line) . '</li>' . ($warning !== '' ? '<li class="passflow-allergy">' . esc_html($warning) . '</li>' : '');
                })()
                . '<li>' . esc_html(\Smbb\Passflow\Service\Tickets::googleReady() ? __('Google Wallet : prêt.', $d) : __('Google Wallet : à configurer ci-dessous.', $d)) . '</li>'
                . (function () use ($d) {
                    if (!\Smbb\Passflow\Service\Tickets::googleReady()) {
                        return '';
                    }

                    \Smbb\Passflow\Service\CertificateAlerts::checkGoogle();
                    $last = \Smbb\Passflow\Service\CertificateAlerts::googleLast();

                    if (!$last) {
                        return '<li class="passflow-muted">' . esc_html__('Clé Google pas encore vérifiée (Google injoignable).', $d) . '</li>';
                    }

                    return $last['ok']
                        ? '<li>' . esc_html(sprintf(__('Clé Google acceptée par Google (vérifiée le %s).', $d), \Smbb\Passflow\Admin\View::datetime($last['at']))) . '</li>'
                        : '<li class="passflow-allergy">' . esc_html($last['message']) . '</li>';
                })()
                . '</ul>',
                'dashicons-tickets-alt'
            ),
            Sections::ticketLook($form),
            $form->section(__('Transfert des billets', $d), __("Un participant qui ne peut pas venir donne sa place depuis la page de son billet ou Mon compte : nouveau billet envoyé à la personne (e-mail, SMS, Wallet), ancien billet désactivé (QR code, PDF, Wallet barré), acheteur prévenu, commande à jour. Depuis la fiche d'une inscription, l'équipe peut toujours le faire.", $d), array(
                $form->row(
                    $form->toggle(__('Les participants peuvent transférer leur billet', $d))->setName('ticket_transfer'),
                    $form->number(__("Jusqu'à (heures avant le début)", $d), array('min' => 0, 'max' => 720, 'step' => 1))->setName('ticket_transfer_hours')
                        ->setHelp(__('0 = jusqu\'au début. Un billet déjà pointé ne se transfère plus.', $d))->showIf('ticket_transfer', '1')
                ),
            ))->setIcon('dashicons-randomize'),
            $form->section(__('Pointage à la douchette', $d), __("Écran d'appel (fenêtre Pointage badge) et suivi tablette jour J. Sans champ, la page écoute le lecteur directement : aucun clavier virtuel ne s'ouvre. Si un appareil ne pointe rien de cette façon (certains PDA n'envoient leur lecture qu'à un champ de saisie), revenez au champ.", $d), array(
                $form->select(__('Capture de la douchette', $d))->setName('scan_capture')->setOptions(array(
                    __('Sans champ : la page écoute le lecteur (recommandé)', $d) => 'keys',
                    __('Avec champ : le lecteur écrit dans un champ de saisie', $d) => 'field',
                ))->setHelp(__('La caméra et la saisie à la main du numéro marchent dans les deux cas.', $d)),
            ))->setIcon('dashicons-id-alt'),
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
                    ->setHelp(trim(__("Présent, absent, annulé, nouvelle date : l'iPhone reçoit le billet à jour (notification Apple). Concerne les billets ajoutés après l'activation. Le serveur doit accepter HTTP/2 en sortie.", $d) . ' ' . \Smbb\Passflow\Service\WalletSync::status('apple'))),
            ))->setIcon('dashicons-smartphone'),
            $form->section(__('Google Wallet', $d), __("Compte émetteur Google Wallet (pay.google.com/business/console) et compte de service Google Cloud avec l'API Google Wallet activée. Ajoutez l'e-mail du compte de service comme utilisateur du compte émetteur.", $d), array(
                $form->text(__('Issuer ID', $d))->setName('google_issuer_id')->setHelp(__('Le numéro du compte émetteur.', $d)),
                $form->textarea(__('Clé du compte de service (JSON)', $d), array('rows' => 4, 'autocomplete' => 'off'))->setName('google_service_account')
                    ->setHelp(__('Le fichier JSON téléchargé depuis Google Cloud, collé tel quel. Chiffré en base. Vide = on garde la clé enregistrée.', $d)),
                $form->toggle(__("Suivre l'état du billet dans Google Wallet", $d))->setName('wallet_sync_google')
                    ->setHelp(trim(__('Présent, absent, annulé, nouvelle date : le billet est mis à jour dans le téléphone du client, y compris ceux déjà ajoutés.', $d) . ' ' . \Smbb\Passflow\Service\WalletSync::status('google'))),
            ))->setIcon('dashicons-google'),
        )),
        $form->tab(__('Factures', $d), __("Factures et avoirs des commandes WooCommerce. Une facture émise ne se modifie plus : une correction passe par un avoir puis une nouvelle facture.", $d), array(
            Sections::invoiceIdentity($form),
            Sections::invoiceDemo($form),
            $form->section(__('Numérotation', $d), __('Jetons : {SIGLE} sigle de l\'entreprise, {AAAA} année, {AA} année sur 2 chiffres, {MM} mois, {N:5} compteur sur 5 chiffres. Le numéro est pris à l\'émission, sans trou ni doublon.', $d), array(
                $form->row(
                    $form->text(__('Modèle des factures', $d))->setName('invoice_pattern')->setHelp(sprintf(__('Prochain numéro : %s', $d), \Smbb\Passflow\Support\Schema::ready() ? \Smbb\Passflow\WooCommerce\Invoices::nextPreview('invoice') : '—')),
                    $form->text(__('Modèle des avoirs', $d))->setName('credit_pattern')->setHelp(sprintf(__('Prochain numéro : %s', $d), \Smbb\Passflow\Support\Schema::ready() ? \Smbb\Passflow\WooCommerce\Invoices::nextPreview('credit') : '—'))
                ),
                $form->row(
                    $form->select(__('Le compteur repart à 1', $d))->setName('invoice_reset')->setOptions(array(
                        __('Chaque année', $d) => 'yearly',
                        __('Jamais', $d) => 'never',
                    )),
                    $form->number(__('Reprendre les factures à', $d), array('min' => 1, 'step' => 1))->setName('invoice_next')->setHelp(__("Pour suivre la numérotation d'un ancien logiciel. Le compteur ne peut qu'avancer.", $d)),
                    $form->number(__('Reprendre les avoirs à', $d), array('min' => 1, 'step' => 1))->setName('credit_next')
                ),
                '<p><a class="button" target="_blank" href="' . esc_url(View::actionUrl(\Smbb\Passflow\WooCommerce\InvoiceScreen::PREVIEW, array('id' => 0))) . '"><span class="dashicons dashicons-pdf" aria-hidden="true"></span> ' . esc_html__('Aperçu PDF', $d) . '</a> <span class="description">' . esc_html__("Enregistrez d'abord. Un exemple, sans rien émettre ni consommer de numéro.", $d) . '</span></p>',
            ))->setIcon('dashicons-editor-ol'),
            Sections::invoiceEmission($form),
        )),
        $form->tab(__('Messages aux participants', $d), __("Choisissez les canaux de chaque message et ajustez les textes.", $d), array_merge(array($reset_note('participant')), $participants, array($variables_note))),
        $form->tab(__('Messages aux intervenants', $d), '', array_merge(array($reset_note('intervenant')), $hosts, array($variables_note))),
        $form->tab(__('Configuration', $d), __('Sauvegardez les réglages dans un fichier, ou reprenez ceux d\'un autre site.', $d), array(\Smbb\Passflow\Admin\Onboarding::tabHtml(), \Smbb\Passflow\Admin\ConfigTransfer::tabHtml()))
    ),
);

$html = '<div class="form_container">' . $form->save(__('Enregistrer', $d))->setFields($fields) . '</div>'
    . \Smbb\Passflow\Admin\ConfigTransfer::importForm(); // Hors du formulaire des réglages : un formulaire n'en contient pas un autre.

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Réglages Passflow', $d));
