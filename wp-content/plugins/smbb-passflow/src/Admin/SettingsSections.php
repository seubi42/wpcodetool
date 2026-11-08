<?php

namespace Smbb\Passflow\Admin;

defined('ABSPATH') || exit;

/**
 * Sections des Réglages, écrites une seule fois : l'écran Réglages et l'assistant de démarrage
 * (étapes Entreprise et TVA, Vente, Image de marque) affichent exactement les mêmes champs,
 * enregistrés dans la même option.
 */
final class SettingsSections
{
    /**
     * Général › Rappels.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    public static function reminders($form)
    {
        $d = 'smbb-passflow';

        return $form->section(__('Rappels', $d), __('Envoyés automatiquement avant chaque atelier (vérification toutes les 15 minutes).', $d), array(
            $form->row(
                $form->number(__('Rappel participants (heures avant)', $d), array('min' => 1, 'step' => 1))->setName('reminder_participant_hours')
                    ->setHelp(__('24 = la veille.', $d)),
                $form->number(__('Rappel intervenants (jours avant)', $d), array('min' => 1, 'step' => 1))->setName('reminder_host_days')
                    ->setHelp(__('7 = une semaine avant, avec la liste des inscrits.', $d))
            ),
            $form->select(__('Rappel participants le jour même', $d))->setName('reminder_day_mode')->setOptions(array(
                __('Non', $d) => 'off',
                __('Le matin, à une heure fixe', $d) => 'morning',
                __('Quelques heures avant le début', $d) => 'hours',
            ))->setHelp(__('Un deuxième rappel, en plus de celui de la veille. Canaux (SMS, e-mail) et textes : onglet Messages, « Rappel participant (le jour même) ».', $d)),
            $form->time(__('Heure du rappel du matin', $d))->setName('reminder_day_time')
                ->setHelp(__("Part à cette heure le jour de l'atelier. Un atelier qui commence avant cette heure n'a pas de rappel du jour même.", $d))
                ->showIf('reminder_day_mode', 'morning'),
            $form->number(__('Rappel du jour même (heures avant)', $d), array('min' => 1, 'max' => 23, 'step' => 1))->setName('reminder_day_hours')
                ->setHelp(__('3 = trois heures avant le début.', $d))
                ->showIf('reminder_day_mode', 'hours'),
        ))->setIcon('dashicons-bell');
    }

    /**
     * Général › Liste d'attente.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    public static function waitlist($form)
    {
        $d = 'smbb-passflow';

        return $form->section(__("Liste d'attente", $d), '', array(
            $form->toggle(__("Proposer une liste d'attente sur les dates complètes", $d))->setName('waitlist_enabled')
                ->setHelp(__("Désactivée : une date complète s'affiche « Complet », sans formulaire ni relance. Les demandes déjà enregistrées restent visibles mais personne n'est plus prévenu.", $d)),
            $form->row(
                $form->number(__('Place gardée pendant (heures)', $d), array('min' => 1, 'step' => 1))->setName('waitlist_hold_hours')
                    ->setHelp(__("Quand une place se libère, la première personne en attente est prévenue et a ce délai pour réserver. Ensuite, c'est au tour de la suivante.", $d)),
                $form->text(__('Lien de secours pour réserver (facultatif)', $d))->setName('waitlist_booking_url')
                    ->setHelp(__('Le message contient le lien direct vers la date sur la fiche produit. Ce lien ne sert que pour un atelier sans produit en ligne (à défaut : la page d\'accueil).', $d))
            )->showIf('waitlist_enabled', '1'),
        ))->setIcon('dashicons-list-view');
    }

    /**
     * Général › Prix (HT ou TTC, classe de TVA).
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    public static function prices($form)
    {
        $d = 'smbb-passflow';

        return $form->section(__('Prix', $d), __('Comment vous saisissez les prix des ateliers et des dates. Le panier et l\'affichage suivent ensuite les réglages de taxes de WooCommerce.', $d), array(
            $form->row(
                $form->select(__('Prix saisis', $d))->setName('price_entry')->setOptions(array(
                    __('TTC (taxes comprises)', $d) => 'ttc',
                    __('HT (hors taxes)', $d) => 'ht',
                )),
                $form->select(__('Taux de TVA (classe de taxe WooCommerce)', $d))->setName('price_tax_class')->setOptions(\Smbb\Passflow\Support\Prices::classOptions())
                    ->setHelp(\Smbb\Passflow\Support\Prices::taxesOn() ? __('Les taux se règlent dans WooCommerce › Réglages › TVA.', $d) : __('Les taxes sont désactivées dans WooCommerce : ce choix ne change rien tant qu\'elles le restent.', $d))
            ),
        ))->setIcon('dashicons-money-alt');
    }

    /**
     * Général › Informations demandées aux participants.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    public static function participantInfo($form)
    {
        $d = 'smbb-passflow';

        return $form->section(__('Informations demandées aux participants', $d), '', array(
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
        ))->setIcon('dashicons-id');
    }

    /**
     * Général › Contact.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    public static function contact($form)
    {
        $d = 'smbb-passflow';

        return $form->section(__('Contact', $d), __('Repris dans la signature des messages ; les réponses aux e-mails arrivent sur cette adresse.', $d), array(
            $form->row(
                $form->text(__('E-mail', $d))->setName('contact_email'),
                $form->text(__('Téléphone', $d))->setName('contact_phone')
            ),
        ))->setIcon('dashicons-phone');
    }

    /**
     * Général › Bons d'achat.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    public static function vouchers($form)
    {
        $d = 'smbb-passflow';

        return $form->section(__("Bons d'achat", $d), __("Bons cadeaux, bons de remboursement et bons créés en caisse. Les bons déjà émis gardent leur code.", $d), array(
            self::companyCode($form),
            $form->toggle(__('Utiliser les bons cadeaux en boutique', $d))->setName('gift_instore')
                ->setHelp(__("Ajoute « Utiliser un bon en boutique » (code ou QR code) en haut de Bons cadeaux et un bouton sur le tableau de bord. Sans, un bon ne s'utilise qu'en ligne, à la réservation.", $d)),
        ))->setIcon('dashicons-tickets-alt');
    }

    /**
     * Envoi › Expéditeur des e-mails.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    public static function sender($form)
    {
        $d = 'smbb-passflow';

        return $form->section(__('Expéditeur des e-mails', $d), __('Les e-mails partent par le serveur d\'envoi du site (wp_mail / SMTP).', $d), array(
            $form->row(
                $form->text(__("Nom de l'expéditeur", $d))->setName('sender_name')
                    ->setHelp(__('Aussi en tête des SMS.', $d)),
                $form->text(__("Adresse de l'expéditeur", $d))->setName('sender_email')
                    ->setHelp(__('Vide = adresse par défaut du site. Prenez une adresse du domaine du site pour éviter les spams.', $d))
            ),
        ))->setIcon('dashicons-email');
    }

    /**
     * Envoi › Habillage des e-mails.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    public static function emailLook($form)
    {
        $d = 'smbb-passflow';

        return $form->section(__('Habillage des e-mails', $d), __("L'image de marque du site dans les e-mails : logo, couleur, pied de page. Les e-mails de Passflow l'ont toujours ; ceux de WordPress et de WooCommerce seulement si vous cochez ci-dessous. Décocher remet leur apparence d'origine.", $d), array(
            $form->row(
                $form->select(__('Couleur de marque', $d))->setName('email_color_mode')->setOptions(array(
                    __('Celle des billets', $d) => 'ticket',
                    __('Une couleur propre aux e-mails', $d) => 'fixed',
                )),
                $form->color(__('Couleur', $d))->setName('email_color')->showIf('email_color_mode', 'fixed'),
                $form->color(__('Fond autour du message', $d))->setName('email_background')
            ),
            $form->image(__('Logo', $d))->setName('email_logo_id')->setHelp(__('Vide = le logo des billets. PNG ou JPG (les SVG ne s\'affichent pas dans Gmail ni Outlook), format horizontal.', $d)),
            $form->textarea(__('Pied de page', $d), array('rows' => 2))->setName('email_footer')
                ->setHelp(__("Vide = nom de l'expéditeur, e-mail et téléphone de contact.", $d)),
            $form->toggle(__('Habiller aussi les e-mails de WordPress', $d))->setName('email_wp')
                ->setHelp(__('Mot de passe oublié, nouveau compte, alertes… Les e-mails déjà mis en forme par une autre extension ne sont pas touchés.', $d)),
            $form->toggle(__('Appliquer aux e-mails WooCommerce', $d))->setName('email_wc')
                ->setHelp(__('Commandes, factures, comptes clients : WooCommerce prend ce logo, cette couleur et ce pied de page à la place de ses propres réglages d\'e-mails.', $d)),
        ))->setIcon('dashicons-art');
    }

    /**
     * Billets › Apparence des billets.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    public static function ticketLook($form)
    {
        $d = 'smbb-passflow';

        return $form->section(__('Apparence des billets', $d), __('Pour le PDF, Apple Wallet et Google Wallet. La couleur du texte (blanc ou foncé) s\'adapte au fond.', $d), array(
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
        ))->setIcon('dashicons-art');
    }

    /**
     * Factures › Identité légale.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    /**
     * Sigle de l'entreprise (réglage gift_prefix) : Bons d'achat, et étape Entreprise de l'assistant.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    public static function companyCode($form)
    {
        $d = 'smbb-passflow';

        return $form->text(__('Sigle de l\'entreprise', $d), array('maxlength' => 4, 'placeholder' => self::suggestedCode(), 'style' => 'text-transform:uppercase'))->setName('gift_prefix')
            ->setHelp(__('2 à 4 lettres ou chiffres, vos initiales par exemple (ABC). Il préfixe les codes des bons cadeaux (ABC-7KQ4-M2XP), les références des billets et badges (ABC-T-00042) et le jeton {SIGLE} des numéros de facture. Vide = rien n\'est ajouté.', $d));
    }

    /**
     * Initiales proposées pour le sigle : premières lettres de la raison sociale (ou du nom de l'activité).
     */
    private static function suggestedCode()
    {
        $name = trim((string) \Smbb\Passflow\Support\Settings::get('invoice_company'));
        $name = $name !== '' ? $name : \Smbb\Passflow\Support\Settings::businessName();
        $name = function_exists('remove_accents') ? remove_accents($name) : $name;
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter($words, function ($w) {
            return !in_array(strtolower($w), array('le', 'la', 'les', 'l', 'de', 'du', 'des', 'd', 'et', 'sas', 'sarl', 'eurl', 'sa', 'sasu', 'ei'), true);
        }));

        if (count($words) === 1) {
            return strtoupper(substr($words[0], 0, 3));
        }

        $code = '';

        foreach (array_slice($words, 0, 4) as $word) {
            $code .= strtoupper(substr($word, 0, 1));
        }

        return strlen($code) >= 2 ? $code : '';
    }

    public static function invoiceIdentity($form)
    {
        $d = 'smbb-passflow';

        return $form->section(__('Identité légale', $d), __('Elle figure en haut de chaque facture.', $d), array(
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
        ))->setIcon('dashicons-building');
    }

    /**
     * Factures › Émission et envoi.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    public static function invoiceEmission($form)
    {
        $d = 'smbb-passflow';

        return $form->section(__('Émission et envoi', $d), '', array(
            $form->select(__('Quand facturer', $d))->setName('invoice_auto')->setOptions(array(
                __('À la demande, et automatiquement pour les commandes avec un SIREN', $d) => 'siren',
                __('À la demande seulement', $d) => 'manual',
                __('Automatiquement à chaque commande payée', $d) => 'all',
            ))->setHelp(__('Une facture automatique reprend les coordonnées de la commande. Le client peut ensuite demander une correction (avoir puis nouvelle facture).', $d)),
            $form->toggle(__('Les clients établissent eux-mêmes leurs factures dans Mon compte', $d))->setName('invoice_customer'),
            $form->number(__('Facture globale automatique après (jours)', $d), array('min' => 0, 'max' => 365, 'step' => 1))->setName('invoice_delay')
                ->setHelp(__("Passé ce délai après le paiement, ce qui n'est pas encore facturé l'est en une seule facture aux coordonnées de la commande, envoyée par e-mail. 0 : jamais. Compte à partir de la mise en route : les commandes plus anciennes ne sont pas touchées. Une correction en cours (avoir sans nouvelle facture) est laissée à l'équipe.", $d)),
            $form->toggle(__("Mettre l'acheteur en copie quand la facture part à une autre adresse", $d))->setName('invoice_cc_buyer'),
            $form->text(__('Copie à la comptabilité (e-mail)', $d))->setName('invoice_accounting_email')->setHelp(__('Facultatif. Plusieurs adresses : séparez-les par des virgules. Copie cachée, pas en mode test.', $d)),
        ))->setIcon('dashicons-email-alt');
    }

    /**
     * Factures › Mode démo : série DEMO- à part, sans toucher à la vraie numérotation.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     */
    public static function invoiceDemo($form)
    {
        $d = 'smbb-passflow';
        $count = \Smbb\Passflow\WooCommerce\Invoices::demoCount();
        $purge = $count ? '<p>' . esc_html(sprintf(_n('%d facture ou avoir de démo.', '%d factures et avoirs de démo.', $count, $d), $count)) . ' '
            . '<a class="button" href="' . esc_url(View::actionUrl(Onboarding::PURGE_INVOICES)) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__('Supprimer toutes les factures et avoirs de démo ? Les vraies factures ne sont pas touchées.', $d))) . ');">'
            . '<span class="dashicons dashicons-trash" aria-hidden="true"></span> ' . esc_html__('Supprimer les factures de démo', $d) . '</a></p>' : '';

        return $form->section(__('Mode démo', $d), __("Pour montrer les factures sans consommer de vrais numéros.", $d), array(
            $form->toggle(__('Factures en mode démo', $d))->setName('invoice_demo')
                ->setHelp(__('Les factures et avoirs émis vont dans une série à part (DEMO-F-2026-00001…), avec un filigrane « DÉMO · sans valeur ». Ils ne partent pas à la comptabilité, ne sortent pas dans les exports et se suppriment. Toujours actif tant que les données de démo existent.', $d)),
            $purge,
        ))->setIcon('dashicons-welcome-learn-more');
    }
}
