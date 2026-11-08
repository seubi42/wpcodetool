<?php

namespace Smbb\Passflow\Support;

defined('ABSPATH') || exit;

/**
 * Catalogue des messages envoyés (e-mail et SMS) et leurs textes par défaut.
 *
 * Chaque message a une clé, un destinataire (participant, intervenant, attente) et deux canaux
 * activables dans les réglages. Les textes sont modifiables dans les réglages ; un champ vidé
 * reprend le texte par défaut.
 *
 * Variables : entre accolades, par exemple {prenom}. Une ligne dont toutes les variables sont vides
 * disparaît (pratique pour {adresse} ou {motif}).
 */
final class Templates
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function messages()
    {
        $d = 'smbb-passflow';

        return array(
            'confirmed' => array(
                'label' => __('Réservation payée (place confirmée)', $d),
                'help' => __('Quand une réservation passe « payée » : commande réglée, ajout manuel, bouton « Marquer payée », inscription depuis la liste d\'attente.', $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 1,
                'subject' => __('Votre billet personnel pour {atelier} (1 pièce jointe)', $d),
                'body' => __("Bonjour {prenom},\n\nVotre place est confirmée :\n\n{atelier}\n{date}, {heure}\n{lieu}\n{adresse}\n\nVotre billet personnel est joint à ce message (1 pièce jointe). Présentez-le à l'accueil, sur votre téléphone ou imprimé.\n\nVotre billet en ligne : {lien_billet}\n{boutons_wallet}\nDans votre agenda : {lien_agenda}\nDocuments à remplir avant l'atelier : {lien_documents}\n\nSi vous avez une allergie ou une intolérance, dites-le-nous avant l'atelier.\n\nÀ très bientôt,\n{signature}", $d),
                'text' => __('{expediteur} : votre place pour {atelier} le {date_courte} à {heure} est confirmée. Votre billet : {lien_billet}', $d),
            ),
            'order_tickets' => array(
                'label' => __("Commande payée : tous les billets à l'acheteur", $d),
                'help' => __("Quand une commande en ligne est payée, à l'acheteur : tous les billets de la commande en pièces jointes, pour les imprimer ou les transmettre. Pas envoyé pour une commande d'un seul billet à son propre nom (le billet personnel suffit).", $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 0,
                'subject' => __('Les billets de votre commande {commande} ({nb_billets})', $d),
                'body' => __("Bonjour {prenom},\n\nMerci pour votre commande {commande}. Ce message regroupe tous ses billets, un par participant ({nb_billets}) :\n\n{liens_billets}\n\nVous pouvez les imprimer ou les transmettre vous-même. Pour plus de facilité, chaque participant dont nous avons l'adresse e-mail reçoit aussi un second message avec seulement son billet personnel.\n\nÀ très bientôt,\n{signature}", $d),
                'text' => __('{expediteur} : votre commande {commande} est confirmée, les billets vous ont été envoyés par e-mail.', $d),
            ),
            'transfer_received' => array(
                'label' => __('Billet transféré : au nouveau participant', $d),
                'help' => __("Quand un participant transfère son billet à quelqu'un d'autre (page du billet, Mon compte) ou que l'équipe le fait : à la personne qui reçoit la place, avec son billet en pièce jointe.", $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 1,
                'subject' => __('{ancien_participant} vous transfère son billet pour {atelier} (1 pièce jointe)', $d),
                'body' => __("Bonjour {prenom},\n\n{ancien_participant} vous a transféré sa place :\n\n{atelier}\n{date}, {heure}\n{lieu}\n{adresse}\n\nVotre billet personnel est joint à ce message (1 pièce jointe). Présentez-le à l'accueil, sur votre téléphone ou imprimé.\n\nVotre billet en ligne : {lien_billet}\n{boutons_wallet}\nDans votre agenda : {lien_agenda}\nDocuments à remplir avant l'atelier : {lien_documents}\n\nPour recevoir aussi votre billet et les rappels par SMS, confirmez votre numéro de téléphone sur votre billet en ligne (un code vous est envoyé).\n\nSi vous avez une allergie ou une intolérance, dites-le-nous avant l'atelier.\n\nÀ très bientôt,\n{signature}", $d),
                'text' => __('{expediteur} : {ancien_participant} vous a transféré sa place pour {atelier} le {date_courte} à {heure}. Votre billet : {lien_billet}', $d),
            ),
            'transfer_buyer' => array(
                'label' => __("Billet transféré : confirmation à l'acheteur", $d),
                'help' => __("Quand un billet d'une commande est transféré : l'acheteur est prévenu (sauf s'il reçoit lui-même la place). Sa commande est à jour dans Mon compte.", $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 0,
                'subject' => __('Commande {commande} : billet transféré à {nouveau_participant}', $d),
                'body' => __("Bonjour {prenom},\n\nPour information, un billet de votre commande {commande} vient d'être transféré :\n\n{atelier}\n{date}, {heure}\n\nAncien participant : {ancien_participant}\nNouveau participant : {nouveau_participant}\n\nL'ancien billet n'est plus valable (PDF, QR code et Wallet). Le nouveau a été envoyé à {nouveau_participant}.\n\nLe nouveau billet : {lien_nouveau_billet}\nVotre commande à jour : {lien_compte}\n\nSi vous n'êtes pas à l'origine de ce changement, contactez-nous vite : {contact_email} {contact_telephone}\n\n{signature}", $d),
                'text' => __('{expediteur} : le billet de {ancien_participant} pour {atelier} le {date_courte} (commande {commande}) a été transféré à {nouveau_participant}.', $d),
            ),
            'transfer_previous' => array(
                'label' => __("Billet transféré : à l'ancien participant", $d),
                'help' => __("Quand un billet est transféré : la personne qui avait la place reçoit une confirmation (pas envoyée si c'est aussi l'acheteur, déjà prévenu).", $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 0,
                'subject' => __('Votre billet pour {atelier} a été transféré', $d),
                'body' => __("Bonjour {prenom},\n\nVotre billet pour {atelier} du {date} a bien été transféré à {nouveau_participant}, qui a reçu son nouveau billet.\n\nVotre ancien billet n'est plus valable : son QR code est désactivé et il apparaît barré dans votre Wallet.\n\nSi vous n'êtes pas à l'origine de ce changement, contactez-nous vite : {contact_email} {contact_telephone}\n\n{signature}", $d),
                'text' => __('{expediteur} : votre billet pour {atelier} le {date_courte} a été transféré à {nouveau_participant}. Votre ancien billet n\'est plus valable.', $d),
            ),
            'moved' => array(
                'label' => __('Nouvelle date', $d),
                'help' => __('Quand une réservation est déplacée sur un autre créneau, ou qu\'une réservation reportée reçoit sa nouvelle date.', $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 1,
                'subject' => __('{atelier} : nouvelle date', $d),
                'body' => __("Bonjour {prenom},\n\nVotre réservation a une nouvelle date :\n\n{atelier}\n{date}, {heure}\n{lieu}\n{adresse}\n\nAncienne date : {ancienne_date}\n\nÀ très bientôt,\n{signature}", $d),
                'text' => __('{expediteur} : nouvelle date pour {atelier}, le {date_courte} à {heure}. À bientôt !', $d),
            ),
            'postponed' => array(
                'label' => __('Réservation reportée', $d),
                'help' => __('Quand une réservation est retirée de son créneau en attendant une autre date.', $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 1,
                'subject' => __('{atelier} : votre réservation est reportée', $d),
                'body' => __("Bonjour {prenom},\n\nVotre réservation pour {atelier} du {ancienne_date} est reportée.\nMotif : {motif}\n\nVotre place est conservée : nous revenons vers vous très vite avec une nouvelle date.\n\n{signature}", $d),
                'text' => __('{expediteur} : votre réservation {atelier} du {ancienne_date_courte} est reportée. Votre place est conservée, nous revenons vers vous avec une nouvelle date.', $d),
            ),
            'cancelled' => array(
                'label' => __('Réservation annulée', $d),
                'help' => __('Quand une réservation est annulée définitivement.', $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 1,
                'subject' => __('Annulation de votre réservation {atelier}', $d),
                'body' => __("Bonjour {prenom},\n\nVotre réservation pour {atelier} du {ancienne_date} est annulée.\nMotif : {motif}\n\nPour toute question, n'hésitez pas à nous contacter.\n\n{signature}", $d),
                'text' => __('{expediteur} : votre réservation pour {atelier} du {ancienne_date_courte} est annulée. Contact : {contact_telephone}', $d),
            ),
            'slot_cancelled' => array(
                'label' => __('Atelier annulé (participants)', $d),
                'help' => __('Quand un créneau est annulé : à chacun de ses participants.', $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 1,
                'subject' => __("{atelier} du {ancienne_date_courte} : annulé", $d),
                'body' => __("Bonjour {prenom},\n\nNous sommes désolés : {atelier}, prévu le {ancienne_date}, est annulé.\nMotif : {motif}\n\nVotre place est conservée : nous revenons vers vous très vite avec une nouvelle date.\n\n{signature}", $d),
                'text' => __("{expediteur} : désolés, {atelier} du {ancienne_date_courte} est annulé. Votre place est conservée, nous revenons vers vous avec une nouvelle date.", $d),
            ),
            'slot_cancelled_host' => array(
                'label' => __('Atelier annulé (intervenants)', $d),
                'help' => __('Quand un créneau est annulé : à ses intervenants.', $d),
                'to' => 'intervenant',
                'email' => 1,
                'sms' => 1,
                'subject' => __('Atelier annulé : {atelier} du {date_courte}', $d),
                'body' => __("Bonjour {prenom},\n\n{atelier} du {date} ({heure}) est annulé.\nMotif : {motif}\n\nMerci et à bientôt,\n{signature}", $d),
                'text' => __('{expediteur} : {atelier} du {date_courte} à {heure} est annulé.', $d),
            ),
            'payment_link' => array(
                'label' => __('Lien de paiement (vente sur place)', $d),
                'help' => __("Quand l'intervenant enregistre une vente sur place à payer en ligne : part tout de suite par SMS et e-mail.", $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 1,
                'subject' => __('Votre commande n° {commande} : {montant} à régler', $d),
                'body' => __("Bonjour {prenom},\n\nMerci pour votre achat :\n{produits}\n\nTotal : {montant}\nPour régler en ligne : {lien_paiement}\n\n{signature}", $d),
                'text' => __('{expediteur} : merci pour votre achat, commande n° {commande} ({montant}). Pour régler : {lien_paiement}', $d),
            ),
            'refund_voucher' => array(
                'label' => __("Remboursement en bon d'achat (vente sur place)", $d),
                'help' => __("Quand une ligne d'une vente sur place est remboursée en bon d'achat (retour produit) : le code et le lien pour l'utiliser.", $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 1,
                'subject' => __("Votre bon d'achat de {montant_bon} (remboursement commande n° {commande})", $d),
                'body' => __("Bonjour {prenom},\n\nNous avons remboursé {produits} de votre commande n° {commande} par un bon d'achat de {montant_bon}.\n\nVotre code : {code_bon}\nIl s'utilise une fois, sur votre prochaine commande sur notre site, avec votre compte : {lien_bon}\n\n{signature}", $d),
                'text' => __("{expediteur} : remboursement commande n° {commande}, bon d'achat de {montant_bon}. Code {code_bon}, à utiliser sur le site avec votre compte : {lien_bon}", $d),
            ),
            'account_created' => array(
                'label' => __('Compte client créé (vente sur place, imprévu)', $d),
                'help' => __("Quand un compte client est créé depuis la caisse ou un imprévu : le lien pour choisir son mot de passe et retrouver ses commandes.", $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 0,
                'subject' => __('Votre compte {expediteur} est prêt', $d),
                'body' => __("Bonjour {prenom},\n\nNous avons créé votre compte client pour que vous retrouviez vos achats et vos ateliers.\n\nVotre identifiant : {identifiant}\nChoisissez votre mot de passe ici : {lien_mot_de_passe}\n\nEnsuite, votre compte : {lien_compte}\n\n{signature}", $d),
                'text' => __("{expediteur} : votre compte client est prêt. Choisissez votre mot de passe : {lien_mot_de_passe}", $d),
            ),
            'invoice' => array(
                'label' => __('Facture émise', $d),
                'help' => __("Part avec le PDF à l'adresse des coordonnées de la facture (l'acheteur en copie s'il est différent, réglable dans Réglages › Factures).", $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 0,
                'subject' => __('Votre facture {numero_facture} ({expediteur})', $d),
                'body' => __("Bonjour {prenom},\n\nVoici votre facture {numero_facture} d'un montant de {montant}, pour la commande n° {commande}. Elle est jointe à ce message au format PDF.\n\nVous pouvez aussi la télécharger ici : {lien_facture}\n\nMerci pour votre confiance,\n{signature}", $d),
                'text' => '',
            ),
            'credit_note' => array(
                'label' => __('Avoir émis', $d),
                'help' => __("Part avec le PDF quand une facture est corrigée ou qu'une ligne facturée est remboursée.", $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 0,
                'subject' => __('Votre avoir {numero_facture} sur la facture {numero_origine}', $d),
                'body' => __("Bonjour {prenom},\n\nVoici l'avoir {numero_facture} de {montant} sur la facture {numero_origine} (commande n° {commande}). Il est joint à ce message au format PDF.\n\nVous pouvez aussi le télécharger ici : {lien_facture}\n\n{signature}", $d),
                'text' => '',
            ),
            'invoice_request' => array(
                'label' => __('Demande de correction de facture (alerte pour vous)', $d),
                'help' => __("Quand un client demande une correction depuis Mon compte. Part à l'e-mail de contact (Réglages › Général).", $d),
                'to' => 'admin',
                'email' => 1,
                'sms' => 0,
                'subject' => __('Demande de correction : facture {numero_facture}', $d),
                'body' => __("{client} demande une correction de la facture {numero_facture} (commande n° {commande}) :\n\n{demande}\n\nPour la corriger (avoir puis nouvelle facture) : {lien_commande_admin}", $d),
                'text' => '',
            ),
            'waitlist_offer' => array(
                'label' => __("Place libérée (liste d'attente)", $d),
                'help' => __("Quand une place se libère : à la première personne de la liste d'attente, qui a le délai réglé pour la réserver.", $d),
                'to' => 'attente',
                'email' => 1,
                'sms' => 1,
                'subject' => __("Une place s'est libérée : {atelier} le {date_courte}", $d),
                'body' => __("Bonjour {prenom},\n\nBonne nouvelle : une place s'est libérée pour {atelier} le {date} à {heure}.\nNous vous la gardons {delai}.\n\nPour la réserver : {lien_reservation}\nOu répondez simplement à ce message, ou appelez-nous au {contact_telephone}.\n\n{signature}", $d),
                'text' => __("{expediteur} : une place s'est libérée pour {atelier} le {date_courte} à {heure}. Gardée {delai}. Réservez : {lien_reservation} {contact_telephone}", $d),
            ),
            'assigned' => array(
                'label' => __('Nouvel atelier (intervenant)', $d),
                'help' => __("Quand un intervenant est ajouté sur un créneau.", $d),
                'to' => 'intervenant',
                'email' => 1,
                'sms' => 0,
                'subject' => __('Nouvel atelier : {atelier} le {date_courte}', $d),
                'body' => __("Bonjour {prenom},\n\nVous animez un nouvel atelier :\n\n{atelier}\n{date}, {heure}\n{lieu}\n{adresse}\nPlaces en vente : {places}. Inscrits pour l'instant : {nb_inscrits}.\n\nVous retrouvez vos ateliers et leurs participants dans votre espace : {lien_espace}\n\nMerci,\n{signature}", $d),
                'text' => __('{expediteur} : vous animez {atelier} le {date_courte} à {heure}.', $d),
            ),
            'availability_request' => array(
                'label' => __('Demande de disponibilités (intervenant)', $d),
                'help' => __('Bouton « Demander les dispos » : un seul message par intervenant, avec toutes ses dates sans réponse.', $d),
                'to' => 'intervenant',
                'email' => 1,
                'sms' => 1,
                'subject' => __('Vos disponibilités pour nos prochains ateliers', $d),
                'body' => __("Bonjour {prenom},\n\nPouvez-vous nous dire si vous êtes disponible pour ces dates ?\n\n{dates}\n\nRépondez en un clic, en liste ou sur le calendrier : {lien_dispos}\n\nMerci,\n{signature}", $d),
                'text' => __('{expediteur} : merci d\'indiquer vos disponibilités pour nos prochains ateliers : {lien_dispos}', $d),
            ),
            'availability_campaign' => array(
                'label' => __('Demande de dispo groupée (intervenant)', $d),
                'help' => __('Menu « Demandes de dispo » : les dates cochées et votre message, avec un lien direct vers la demande.', $d),
                'to' => 'intervenant',
                'email' => 1,
                'sms' => 1,
                'subject' => __('Vos disponibilités pour nos prochains ateliers', $d),
                'body' => __("Bonjour {prenom},\n\n{message_demande}\n\nPouvez-vous nous dire si vous êtes disponible pour ces dates ?\n\n{dates}\n\nRépondez en un clic : {lien_dispos}\n\nMerci,\n{signature}", $d),
                'text' => __('{expediteur} : merci d\'indiquer vos disponibilités pour nos prochaines dates : {lien_dispos}', $d),
            ),
            'availability_reply' => array(
                'label' => __("Message d'un intervenant sur une demande de dispo (alerte pour vous)", $d),
                'help' => __("Quand un intervenant écrit un message en réponse à une demande. Part à l'e-mail et au téléphone de contact (Réglages › Général).", $d),
                'to' => 'admin',
                'email' => 1,
                'sms' => 0,
                'subject' => __('{intervenant_nom} a répondu à votre demande de dispo', $d),
                'body' => __("{intervenant_nom} vous écrit :\n\n{message_intervenant}\n\nToutes les réponses : {lien_demande}", $d),
                'text' => __('{intervenant_nom} : {message_intervenant}', $d),
            ),
            'availability_answer' => array(
                'label' => __('Réponse de disponibilité (alerte pour vous)', $d),
                'help' => __("Quand un intervenant répond depuis son espace. Part à l'e-mail et au téléphone de contact (Réglages › Général).", $d),
                'to' => 'admin',
                'email' => 0,
                'sms' => 1,
                'subject' => __('{intervenant_nom} : {reponse} le {date_courte}', $d),
                'body' => __("{intervenant_nom} a répondu pour {atelier} le {date} à {heure} : {reponse}.\n\nToutes les réponses : {lien_dispos_admin}", $d),
                'text' => __('{intervenant_nom} : {reponse} pour {atelier} le {date_courte}.', $d),
            ),
            'reminder_host' => array(
                'label' => __('Rappel intervenant (une semaine avant)', $d),
                'help' => __('Envoyé automatiquement avant chaque atelier, selon le délai réglé, avec la liste des inscrits.', $d),
                'to' => 'intervenant',
                'email' => 1,
                'sms' => 0,
                'subject' => __('Rappel : {atelier} le {date_courte}', $d),
                'body' => __("Bonjour {prenom},\n\nPetit rappel : vous animez {atelier} le {date} à {heure}.\n{lieu}\n{adresse}\n\nInscrits : {nb_inscrits} sur {places} places\n{participants}\n\nLa liste à jour est toujours dans votre espace : {lien_espace}\n\nMerci,\n{signature}", $d),
                'text' => __('{expediteur} : rappel, vous animez {atelier} le {date_courte} à {heure} (inscrits : {nb_inscrits}).', $d),
            ),
            'reminder_participant' => array(
                'label' => __('Rappel participant (la veille)', $d),
                'help' => __("Envoyé automatiquement avant chaque atelier, selon le délai réglé.", $d),
                'to' => 'participant',
                'email' => 0,
                'sms' => 1,
                'subject' => __('Rappel : {atelier} le {date_courte}', $d),
                'body' => __("Bonjour {prenom},\n\nPetit rappel : {atelier}, c'est le {date} à {heure}.\n{lieu}\n{adresse}\n\nVotre billet : {lien_billet}\nDocuments à remplir avant l'atelier : {lien_documents}\n\nÀ très bientôt,\n{signature}", $d),
                'text' => __('{expediteur} : rappel, {atelier} le {date_courte} à {heure}, {lieu}. À bientôt !', $d),
            ),
            'reminder_participant_day' => array(
                'label' => __('Rappel participant (le jour même)', $d),
                'help' => __("Deuxième rappel, le jour de l'atelier : à activer dans Réglages › Général › Rappels (le matin à heure fixe, ou quelques heures avant).", $d),
                'to' => 'participant',
                'email' => 0,
                'sms' => 1,
                'subject' => __("C'est aujourd'hui : {atelier} à {heure}", $d),
                'body' => __("Bonjour {prenom},\n\nC'est aujourd'hui ! {atelier} à {heure}.\n{lieu}\n{adresse}\n\nVotre billet : {lien_billet}\n\nÀ tout à l'heure,\n{signature}", $d),
                'text' => __("{expediteur} : c'est aujourd'hui ! {atelier} à {heure}, {lieu}. Votre billet : {lien_billet}", $d),
            ),
            'documents_request' => array(
                'label' => __('Demande de documents', $d),
                'help' => __("Envoyée depuis la fiche d'une inscription (bouton « Envoyer la demande de documents »), seulement s'il reste un document à remplir.", $d),
                'to' => 'participant',
                'email' => 1,
                'sms' => 1,
                'subject' => __('{atelier} : documents à remplir avant votre venue', $d),
                'body' => __("Bonjour {prenom},\n\nAvant votre venue à {atelier} le {date} à {heure}, merci de remplir et signer vos documents en ligne (deux minutes suffisent) :\n\n{lien_documents}\n\nÀ très bientôt,\n{signature}", $d),
                'text' => __('{expediteur} : avant {atelier} le {date_courte}, merci de remplir vos documents : {lien_documents}', $d),
            ),
            'reminder_host_eve' => array(
                'label' => __('Rappel intervenant (la veille)', $d),
                'help' => __('Envoyé en même temps que le rappel des participants.', $d),
                'to' => 'intervenant',
                'email' => 0,
                'sms' => 1,
                'subject' => __('Rappel : {atelier} le {date_courte}', $d),
                'body' => __("Bonjour {prenom},\n\nRappel : vous animez {atelier} le {date} à {heure} (inscrits : {nb_inscrits}).\n\n{signature}", $d),
                'text' => __('{expediteur} : rappel, vous animez {atelier} le {date_courte} à {heure} (inscrits : {nb_inscrits}).', $d),
            ),
        );
    }

    /**
     * Textes d'un message modifiés par rapport à ceux d'origine ?
     */
    public static function customized($key)
    {
        $message = self::message($key);
        $stored = get_option(Settings::OPTION, array());

        if (!$message || !is_array($stored)) {
            return false;
        }

        foreach (array('subject', 'body', 'text') as $part) {
            $name = 'tpl_' . $key . '_' . $part;

            if (isset($stored[$name]) && trim((string) $stored[$name]) !== '' && trim(str_replace("\r\n", "\n", (string) $stored[$name])) !== trim($message[$part])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remet les textes d'origine (objet, e-mail, SMS) ; les canaux choisis restent.
     *
     * @param string $scope participant, intervenant ou la clé d'un message
     * @return int nombre de messages remis à zéro
     */
    public static function reset($scope)
    {
        $stored = get_option(Settings::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $count = 0;

        foreach (self::messages() as $key => $message) {
            $group = in_array($message['to'], array('intervenant', 'admin'), true) ? 'intervenant' : 'participant';

            if ($scope !== $key && $scope !== $group) {
                continue;
            }

            $count += self::customized($key) ? 1 : 0;

            foreach (array('subject', 'body', 'text') as $part) {
                unset($stored['tpl_' . $key . '_' . $part]);
            }
        }

        update_option(Settings::OPTION, $stored, false);

        return $count;
    }

    public static function message($key)
    {
        $messages = self::messages();

        return isset($messages[$key]) ? $messages[$key] : null;
    }

    /**
     * Réglages par défaut liés aux messages : canaux actifs et textes.
     *
     * @return array<string,mixed>
     */
    public static function defaults()
    {
        $defaults = array();

        foreach (self::messages() as $key => $message) {
            $defaults['notify_' . $key . '_email'] = $message['email'];
            $defaults['notify_' . $key . '_sms'] = $message['sms'];
            $defaults['tpl_' . $key . '_subject'] = $message['subject'];
            $defaults['tpl_' . $key . '_body'] = $message['body'];
            $defaults['tpl_' . $key . '_text'] = $message['text'];
        }

        return $defaults;
    }

    /**
     * Variables proposées dans l'aide des réglages.
     *
     * @return array<string,string>
     */
    public static function variables()
    {
        $d = 'smbb-passflow';

        return array(
            'prenom' => __('prénom du destinataire', $d),
            'nom' => __('nom du destinataire', $d),
            'atelier' => __("nom de l'atelier", $d),
            'date' => __('date complète (samedi 6 décembre 2026)', $d),
            'date_courte' => __('date courte (sam. 6 déc.)', $d),
            'heure' => __('heure de début', $d),
            'heure_fin' => __('heure de fin', $d),
            'lieu' => __("lieu de l'atelier", $d),
            'adresse' => __("adresse de l'atelier", $d),
            'intervenants' => __('noms des intervenants', $d),
            'places' => __('places en vente', $d),
            'nb_inscrits' => __("nombre d'inscrits", $d),
            'participants' => __('liste des inscrits (intervenants)', $d),
            'ancienne_date' => __('date avant report ou annulation', $d),
            'ancienne_date_courte' => __('date courte avant report ou annulation', $d),
            'motif' => __("motif d'annulation ou de report", $d),
            'delai' => __("délai de réservation (liste d'attente)", $d),
            'lien_reservation' => __('lien pour réserver (liste d\'attente)', $d),
            'lien_compte' => __('lien vers le compte client', $d),
            'lien_espace' => __('lien vers « Mes ateliers » (intervenants)', $d),
            'lien_dispos' => __('lien vers « Mes disponibilités » (intervenants)', $d),
            'dates' => __('dates sans réponse (demande de disponibilités)', $d),
            'intervenant_nom' => __("nom de l'intervenant qui répond (alerte de disponibilité)", $d),
            'reponse' => __('« disponible » ou « pas disponible » (alerte de disponibilité)', $d),
            'lien_dispos_admin' => __('lien vers la date dans le back-office (alerte de disponibilité)', $d),
            'message_demande' => __('message de la demande de dispo groupée', $d),
            'message_intervenant' => __("message écrit par l'intervenant (réponse à une demande de dispo)", $d),
            'lien_demande' => __('lien vers le suivi de la demande de dispo (back-office)', $d),
            'lien_billet' => __('lien de la page du billet du participant (PDF, Apple Wallet, Google Wallet, agenda)', $d),
            'lien_billet_pdf' => __('lien direct du billet PDF du participant', $d),
            'boutons_wallet' => __("boutons « Ajouter à Apple Wallet / Google Wallet » (e-mail seulement, s'ils sont configurés)", $d),
            'liens_billets' => __("liste des billets de la commande avec leur lien (e-mail à l'acheteur)", $d),
            'ancien_participant' => __('personne qui avait le billet (transfert de billet)', $d),
            'nouveau_participant' => __('personne qui reçoit le billet (transfert de billet)', $d),
            'lien_nouveau_billet' => __("lien du nouveau billet (transfert, message à l'acheteur)", $d),
            'nb_billets' => __("nombre de pièces jointes, ex. « 3 pièces jointes » (e-mail à l'acheteur)", $d),
            'lien_agenda' => __("lien pour ajouter la réservation à l'agenda du téléphone (.ics)", $d),
            'lien_documents' => __('lien vers les documents à remplir (vide quand tout est fait)', $d),
            'lien_avis' => __('lien personnel du questionnaire de satisfaction (Réglages › Avis)', $d),
            'lien_paiement' => __('lien de paiement (vente sur place)', $d),
            'montant' => __('montant de la commande (vente sur place)', $d),
            'produits' => __('produits achetés (vente sur place)', $d),
            'commande' => __('numéro de commande', $d),
            'code_bon' => __("code du bon d'achat (remboursement en bon)", $d),
            'montant_bon' => __("montant du bon d'achat (remboursement en bon)", $d),
            'lien_bon' => __("lien qui applique le bon d'achat sur le site (remboursement en bon)", $d),
            'lien_mot_de_passe' => __('lien pour choisir son mot de passe (compte client créé)', $d),
            'identifiant' => __('identifiant du compte, son e-mail (compte client créé)', $d),
            'lien_compte' => __('lien vers « Mon compte » (compte client créé)', $d),
            'contact_email' => __('e-mail de contact', $d),
            'numero_facture' => __('numéro de la facture ou de l\'avoir', $d),
            'numero_origine' => __("numéro de la facture d'origine (avoir)", $d),
            'montant' => __('montant TTC', $d),
            'lien_facture' => __('lien de téléchargement du PDF', $d),
            'infos_pratiques' => __("infos pratiques de l'atelier (à apporter, à savoir)", $d),
            'contact_telephone' => __('téléphone de contact', $d),
            'expediteur' => __("nom de l'expéditeur", $d),
            'signature' => __('signature (nom et contacts)', $d),
        );
    }

    /**
     * Remplace les variables. Une ligne dont toutes les variables sont vides est retirée.
     *
     * @param array<string,string> $vars
     */
    public static function render($text, array $vars)
    {
        // Pas d'heure (date « toute la journée ») : on retire aussi « , {heure} », « à {heure} », « ({heure}) ».
        if (array_key_exists('heure', $vars) && trim((string) $vars['heure']) === '') {
            $text = preg_replace(array('/,\s*\{heure\}/u', '/\s+à\s+\{heure\}/u', '/\s*\(\{heure\}\)/u'), '', (string) $text);
        }

        if (array_key_exists('heure_fin', $vars) && trim((string) $vars['heure_fin']) === '') {
            $text = preg_replace(array('/\s*-\s*\{heure_fin\}/u', "/\\s+(jusqu'à|à)\\s+\\{heure_fin\\}/u"), '', (string) $text);
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        $out = array();

        foreach ($lines as $line) {
            $found = 0;
            $filled = 0;

            $rendered = preg_replace_callback('/\{([a-z_]+)\}/', function ($match) use ($vars, &$found, &$filled) {
                if (!array_key_exists($match[1], $vars)) {
                    return $match[0];
                }

                $found++;
                $value = trim((string) $vars[$match[1]]);
                $filled += $value !== '' ? 1 : 0;

                return $value;
            }, $line);

            if ($found > 0 && $filled === 0) {
                continue;
            }

            $out[] = rtrim($rendered);
        }

        // Pas plus d'une ligne vide d'affilée.
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
    }
}
