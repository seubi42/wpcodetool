<?php
/**
 * Formulaire d'une organisation (un client).
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\SignConnectProxima\Admin\ConnectionTests;
use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';
$level_about = array();

foreach (Levels::labels() as $level_key => $level_label) {
    $level_about[$level_label] = esc_html(Levels::description($level_key))
        . (Levels::norm($level_key) !== '' ? '<br><span class="scp-muted">' . esc_html(Levels::norm($level_key)) . '</span>' : '');
}
$item = isset($item) && is_array($item) ? $item : array();
$is_new = empty($item['id']);
$money = array('min' => 0, 'step' => '0.01');
$percent = array('min' => 0, 'max' => 100, 'step' => '0.01');

$webhook_html = $is_new
    ? '<p class="description">' . esc_html__("L'URL du webhook sera générée à l'enregistrement.", $d) . '</p>'
    : View::definitionList(array(
        __('URL du webhook', $d) => '<code class="scp-code">' . esc_html(rest_url('signconnect/v1/universign/webhook/' . (isset($item['webhook_key']) ? $item['webhook_key'] : ''))) . '</code>',
        __('Dernier appel reçu', $d) => View::datetime(isset($item['webhook_last_received_at']) ? $item['webhook_last_received_at'] : ''),
    )) . '<p class="description">' . esc_html__("À déclarer dans Universign (Developer > Webhook endpoints), en HTTPS. Sans webhook, les statuts sont relus toutes les heures.", $d) . '</p>';

$fields = array(
    $form->tabs(
        $form->tab(__('Général', $d), '', array(
            $form->section(__('Identité', $d), '', array(
                $form->row(
                    $form->text(__('Nom', $d))->setName('name')->required(),
                    $form->text(__('Ville', $d))->setName('city')
                        ->setHelp(__('Écrite dans les champs « Lieu » des documents. Vide : ville lue dans l\'adresse de facturation.', $d)),
                    $form->select(__('Statut', $d))->setName('status')->setOptions(Choices::options('organizationStatuses'))
                ),
                $form->row(
                    $form->image(__('Logo', $d))->setName('logo_attachment_id'),
                    $form->image(__("Tampon de l'organisation", $d))->setName('stamp_attachment_id')
                        ->setHelp(__("Apposé avec le nom de l'organisation dans les champs « tampon ». Le tampon d'une équipe, s'il existe, passe avant.", $d)),
                    $form->color(__('Couleur', $d), array('default' => '#db1f26'))->setName('brand_color')
                ),
            ))->setIcon('dashicons-building'),
        )),
        $form->tab(__('Signature', $d), '', array(
            $form->section(__('Niveaux', $d), __('Les niveaux proposés aux utilisateurs de cette organisation.', $d), array(
                '<div class="scp-admin">' . View::definitionList($level_about) . '</div>',
                $form->checkbox(__('Niveaux autorisés', $d))->setName('allowed_levels')->setOptions(Levels::options())->required(),
                $form->select(__('Niveau proposé par défaut', $d))->setName('default_level')->setOptions(Levels::options())
                    ->setHelp(__("Utilisé quand aucun modèle n'est reconnu.", $d)),
                $form->text(__("Phrase d'usage : Simple", $d))->setName('level_usage_internal')
                    ->setHelp(__('Ex. « Pour les documents internes et bons de commande ».', $d)),
                $form->text(__("Phrase d'usage : Vérifiée", $d))->setName('level_usage_simple')
                    ->setHelp(__('Ex. « Pour les contrats clients courants ».', $d)),
                $form->text(__("Phrase d'usage : Qualifiée eIDAS", $d))->setName('level_usage_qualified')
                    ->setHelp(__('Ex. « Pour les contrats de plus de 5 000 € ».', $d)),
            ))->setIcon('dashicons-yes-alt'),
            $form->section(__('Envoi', $d), '', array(
                $form->row(
                    $form->select(__('Code Universign par défaut', $d))->setName('simple_auth_means')->setOptions(Choices::options('authMeans'))
                        ->setHelp(__('Niveau Vérifiée. Le code par email doit être activé sur le compte Universign.', $d)),
                    $form->number(__("Validité d'un envoi (jours)", $d), array('min' => 1, 'max' => 180, 'step' => 1))->setName('send_validity_days'),
                    $form->text(__('Relances automatiques (jours)', $d))->setName('reminder_days')
                        ->setHelp(__('Ex. « 2, 5, 10 » = relance à J+2, J+5 et J+10.', $d))
                ),
                $form->checkbox(__('Canaux du niveau Simple', $d))->setName('internal_channels')->setOptions(Choices::options('channels')),
                $form->textarea(__("Message d'envoi par défaut", $d), array('rows' => 4))->setName('default_send_message'),
                $form->toggle(__('Détection IA des zones de signature', $d))->setName('ai_detection_enabled')
                    ->setHelp(__("Nécessite la clé OpenAI de l'onglet Services.", $d)),
            ))->setIcon('dashicons-email-alt'),
        )),
        $form->tab(__('Universign', $d), '', array(
            $form->section(__('Connexion', $d), __('Chaque organisation porte sa propre clé : il n\'y a aucune clé par défaut.', $d), array(
                $form->row(
                    $form->select(__('Mode', $d))->setName('universign_mode')->setOptions(Choices::options('universignModes')),
                    $form->select(__('Environnement', $d))->setName('universign_environment')->setOptions(Choices::options('universignEnvironments'))
                ),
                $form->row(
                    $form->secret(__('Clé API', $d))->setName('universign_api_key'),
                    $form->text(__("ID de l'espace de travail", $d))->setName('universign_workspace_id')->setHelp(__('Ex. wsp_…', $d))
                ),
                ConnectionTests::button(isset($item['id']) ? $item['id'] : 0, 'universign', __('Tester l\'API Universign', $d)),
            ))->setIcon('dashicons-admin-network'),
            View::note(__('Webhook', $d), $webhook_html, 'dashicons-rss'),
        )),
        $form->tab(__('Services', $d), '', array(
            $form->section(__('IA (OpenAI)', $d), __('Détection des zones et suggestion de message.', $d), array(
                $form->row(
                    $form->secret(__('Clé OpenAI', $d))->setName('openai_api_key'),
                    $form->text(__('Modèle', $d))->setName('openai_model')->setHelp(__('Ex. gpt-4.1-mini', $d))
                ),
                ConnectionTests::button(isset($item['id']) ? $item['id'] : 0, 'openai', __('Tester OpenAI', $d)),
            ))->setIcon('dashicons-lightbulb'),
            $form->section(__('SMS (Twilio)', $d), __('Utilisé pour envoyer le lien du niveau Simple par SMS.', $d), array(
                $form->row(
                    $form->text(__('Account SID', $d))->setName('twilio_sid'),
                    $form->secret(__('Auth token', $d))->setName('twilio_token'),
                    $form->text(__("Numéro ou service d'envoi", $d))->setName('twilio_from')->setHelp(__('Numéro +33… ou Messaging Service MG…', $d))
                ),
                ConnectionTests::twilioButton(isset($item['id']) ? $item['id'] : 0),
            ))->setIcon('dashicons-smartphone'),
        )),
        $form->tab(__('Stockage', $d), '', array(
            $form->section(__('S3', $d), __('Stockage des documents du niveau Simple, et copie optionnelle des PDF Universign.', $d), array(
                $form->row(
                    $form->text(__('Endpoint', $d))->setName('s3_endpoint'),
                    $form->text(__('Région', $d))->setName('s3_region'),
                    $form->text(__('Bucket', $d))->setName('s3_bucket')
                ),
                $form->row(
                    $form->text(__("Clé d'accès", $d))->setName('s3_access_key'),
                    $form->secret(__('Clé secrète', $d))->setName('s3_secret_key'),
                    $form->text(__('Préfixe', $d))->setName('s3_prefix')
                ),
                $form->toggle(__('Endpoint en path-style', $d))->setName('s3_path_style'),
                ConnectionTests::button(isset($item['id']) ? $item['id'] : 0, 's3', __('Tester la connexion S3', $d)),
            ))->setIcon('dashicons-cloud'),
            $form->section(__('Conservation', $d), '', array(
                $form->row(
                    $form->toggle(__('Copier les PDF Universign sur S3', $d))->setName('copy_universign_to_s3')
                        ->setHelp(__('PDF signé et fichier de preuve, copiés à la fin de la transaction.', $d)),
                    $form->number(__('Durée de conservation (mois)', $d), array('min' => 1, 'step' => 1))->setName('retention_months')
                        ->setHelp(__('Ensuite : suppression des fichiers et des données personnelles, ligne anonymisée conservée.', $d))
                ),
            ))->setIcon('dashicons-backup'),
        )),
        $form->tab(__('Facturation', $d), '', array(
            $form->section(__('Coordonnées', $d), __('En-tête de la facture proforma.', $d), array(
                $form->row(
                    $form->text(__('Raison sociale', $d))->setName('billing_company_name'),
                    $form->text(__('Référence client', $d))->setName('customer_reference')
                ),
                $form->textarea(__('Adresse', $d), array('rows' => 3))->setName('billing_address'),
                $form->row(
                    $form->text(__('SIRET', $d))->setName('billing_siret'),
                    $form->text(__('N° de TVA', $d))->setName('billing_vat_number'),
                    $form->text(__('Email de facturation', $d))->setName('billing_email')
                ),
            ))->setIcon('dashicons-id'),
            $form->section(__('Tarification', $d), __('Montants en € HT. Toute modification s\'applique immédiatement au mois en cours.', $d), array(
                $form->row(
                    $form->number(__('Abonnement fixe mensuel', $d), $money)->setName('fixed_monthly_fee'),
                    $form->select(__('Forfait par défaut des nouveaux utilisateurs', $d))->setName('default_plan_id')
                        ->setOptions(Lookup::planOptions(__('— Aucun —', $d), true))
                ),
                $form->row(
                    $form->number(__("Remise sur l'abonnement des forfaits (%)", $d), $percent)->setName('discount_subscription_pct'),
                    $form->number(__('Remise sur les dépassements (%)', $d), $percent)->setName('discount_overage_pct')
                ),
            ))->setIcon('dashicons-money-alt'),
        ))
    ),
);

$html = '<div class="form_container">' . $form->save($is_new ? __("Créer l'organisation", $d) : __('Enregistrer', $d))->setFields($fields) . '</div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Organisation', $d));
