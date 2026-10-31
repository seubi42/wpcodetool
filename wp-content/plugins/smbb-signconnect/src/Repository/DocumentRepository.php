<?php

namespace Smbb\SignConnect\Repository;

use Smbb\SignConnect\Support\CertificationMethod;
use Smbb\SignConnect\Support\DocumentCriticality;
use Smbb\SignConnect\Support\DocumentStatus;

defined('ABSPATH') || exit;

/**
 * Accès aux données de la table wp_signdocument.
 *
 * Toutes les lectures filtrent sur deleted_flag = 0. Le flag est posé par
 * markDeletedByCron() : on ne supprime jamais physiquement de lignes pour
 * conserver la piste d'audit et simplifier les rollbacks manuels.
 *
 * La colonne token_hash (ajoutée en v0.1.x) remplace la colonne token en clair.
 * Les méthodes rotatePublicToken / publicTokenMatches gèrent la compatibilité
 * ascendante : si token_hash n'existe pas, on retombe sur un hash_equals sur token.
 */
final class DocumentRepository
{
    private static $has_system_log_column = null;
    private static $column_cache = array();

    /**
     * Retourne un document par son identifiant, ou null s'il est introuvable / supprimé.
     *
     * @param int $document_id
     * @return array<string,mixed>|null
     */
    public function find($document_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND deleted_flag = 0 LIMIT 1",
            (int) $document_id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Retourne un document appartenant à un utilisateur précis, ou null.
     *
     * Utilisé pour les actions admin (édition, envoi, suppression) où on vérifie
     * à la fois l'existence et la propriété en une seule requête.
     *
     * @param int $document_id
     * @param int $user_id
     * @return array<string,mixed>|null
     */
    public function findOwnedByUser($document_id, $user_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND creation_by = %d AND deleted_flag = 0 LIMIT 1",
            (int) $document_id,
            (int) $user_id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Liste les documents d'un utilisateur, du plus récent au plus ancien.
     *
     * La limite est plafonnée à 200 pour éviter des résultats trop larges depuis
     * le dashboard frontend. Augmenter la limite nécessite une pagination.
     *
     * @param int $user_id
     * @param int $limit  Nombre max de résultats (1–200, défaut 50).
     * @return array<int,array<string,mixed>>
     */
    public function listOwnedByUser($user_id, $limit = 50)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $limit = max(1, min(200, (int) $limit));
        $sql = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE creation_by = %d
               AND deleted_flag = 0
             ORDER BY creation_date DESC, id DESC
             LIMIT %d",
            (int) $user_id,
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Retourne un document si le token fourni correspond au document_id, ou null.
     *
     * La vérification du token se fait en PHP via publicTokenMatches() plutôt qu'en
     * SQL pour deux raisons :
     * - token_hash est un hash bcrypt (wp_hash_password) : il ne peut pas être comparé
     *   directement en SQL sans stocker la valeur en clair ;
     * - hash_equals() garantit une comparaison en temps constant sur l'ancien token,
     *   ce qui résiste aux attaques timing.
     *
     * @param int    $document_id
     * @param string $token  Token brut reçu depuis l'URL publique.
     * @return array<string,mixed>|null
     */
    public function findByPublicToken($document_id, $token)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND deleted_flag = 0 LIMIT 1",
            (int) $document_id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        if ($this->publicTokenMatches($row, $token)) {
            return $row;
        }

        return null;
    }

    /**
     * Crée l'enregistrement métier lié à un PDF uploadé dans S3.
     *
     * @param array<string,mixed> $data  Doit contenir filename, storage_id, storage_path, file_size, token.
     * @return int|false  ID de la ligne insérée, ou false en cas d'erreur SQL.
     */
    public function createUploadedDocument(array $data)
    {
        global $wpdb;

        /*
         * Cette methode créé le pointeur metier vers le fichier S3.
         *
         * Le PDF lui-meme n\'est jamais stocke dans la base : on garde seulement
         * le stockage, le chemin S3, le nom original et quelques metadonnees
         * utiles au dashboard ou aux futurs traitements de signature.
         */
        $table = $wpdb->prefix . 'signdocument';
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        $insert_data = array(
            'filename' => isset($data['filename']) ? (string) $data['filename'] : '',
            'storage_id' => isset($data['storage_id']) ? (int) $data['storage_id'] : null,
            'storage_path' => isset($data['storage_path']) ? (string) $data['storage_path'] : '',
            'file_size' => isset($data['file_size']) ? max(0, (int) $data['file_size']) : 0,
            'document_status' => DocumentStatus::DRAFT,
            'creation_date' => $now,
            'creation_by' => $user_id,
            'token' => isset($data['token']) ? (string) $data['token'] : '',
        );
        $formats = array('%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s');

        if ($this->hasColumn('token_hash') && !empty($insert_data['token'])) {
            $insert_data['token_hash'] = wp_hash_password((string) $insert_data['token']);
            $formats[] = '%s';
        }

        $result = $wpdb->insert(
            $table,
            $insert_data,
            $formats
        );

        if ($result === false) {
            return false;
        }

        return isset($wpdb->insert_id) ? (int) $wpdb->insert_id : true;
    }

    /**
     * Retourne les documents expirés non signés éligibles à la purge automatique.
     *
     * La requête exclut les documents déjà signés ou refusés : l'expiration ne
     * concerne que le lien de signature, pas la conservation du document final.
     *
     * @param int $limit  Nombre max de résultats par passage cron (1–500).
     * @return array<int,array<string,mixed>>
     */
    public function findExpiredUnsigned($limit = 100)
    {
        global $wpdb;

        /*
         * La purge automatique ne touche pas aux documents déjà signes.
         * L'expiration concerne le lien de signature, pas la conservation du
         * document final.
         */
        $table = $wpdb->prefix . 'signdocument';
        $limit = max(1, min(500, (int) $limit));
        $now = current_time('mysql');
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE deleted_flag = 0
               AND link_expires_at IS NOT NULL
               AND link_expires_at <> ''
               AND link_expires_at <> '0000-00-00 00:00:00'
               AND link_expires_at <= %s
               AND (sign_date IS NULL OR sign_date = '' OR sign_date = '0000-00-00 00:00:00')
               AND (document_status IS NULL OR document_status NOT IN ('signed', 'refused'))
             ORDER BY link_expires_at ASC
             LIMIT %d",
            $now,
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Marque un document comme supprimé par la purge cron (soft delete).
     *
     * deleted_by = 0 indique une suppression automatique (pas un utilisateur).
     *
     * @param int $document_id
     * @return bool
     */
    public function markDeletedByCron($document_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $result = $wpdb->update(
            $table,
            array(
                'document_status' => DocumentStatus::EXPIRED_DELETED,
                'deleted_flag' => 1,
                'deleted_date' => current_time('mysql'),
                'deleted_by' => 0,
            ),
            array(
                'id' => (int) $document_id,
            ),
            array('%s', '%d', '%s', '%d'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Enregistre les paramètres d'envoi (destinataire, canal, expiration, etc.) et passe
     * le statut à READY_TO_SEND.
     *
     * Le signature_token est conservé s'il existe déjà : cela permet à l'expéditeur de
     * revenir corriger le formulaire sans invalider un lien déjà généré ou partagé.
     *
     * @param int                 $document_id
     * @param int                 $user_id
     * @param array<string,mixed> $data
     * @return array<string,mixed>|false  Document mis à jour, ou false si règle métier bloquante.
     */
    public function saveSendSettings($document_id, $user_id, array $data)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $document = $this->findOwnedByUser($document_id, $user_id);

        if (!$document) {
            return false;
        }

        if (!DocumentStatus::canPrepareSend(isset($document['document_status']) ? $document['document_status'] : '')) {
            return false;
        }

        $signature_token = !empty($document['signature_token'])
            ? (string) $document['signature_token']
            : wp_generate_password(48, false, false);

        /*
         * On conserve le token s'il existe déjà.
         * C'est important pour que l'utilisateur puisse revenir modifier le
         * formulaire d\'envoi sans invalider un lien de signature déjà genere.
         */
        $update_data = array(
            'document_status' => DocumentStatus::READY_TO_SEND,
            'send_channel' => isset($data['send_channel']) ? (string) $data['send_channel'] : 'email',
            'recipient_email' => isset($data['recipient_email']) ? (string) $data['recipient_email'] : '',
            'recipient_phone' => isset($data['recipient_phone']) ? (string) $data['recipient_phone'] : '',
            'send_message' => isset($data['send_message']) ? (string) $data['send_message'] : '',
            'require_identity_photo' => !empty($data['require_identity_photo']) ? 1 : 0,
            'return_expected' => isset($data['return_expected']) ? (string) $data['return_expected'] : 'signature',
            'link_expires_at' => isset($data['link_expires_at']) ? (string) $data['link_expires_at'] : null,
            'signature_token' => $signature_token,
        );
        $formats = array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s');

        if ($this->hasColumn('criticality') && empty($document['criticality_locked'])) {
            $update_data['criticality'] = DocumentCriticality::normalize(isset($data['criticality']) ? (string) $data['criticality'] : '');
            $formats[] = '%s';
        }

        if ($this->hasColumn('certification_method')) {
            $update_data['certification_method'] = CertificationMethod::normalize(isset($data['certification_method']) ? (string) $data['certification_method'] : '');
            $formats[] = '%s';
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            array(
                'id' => (int) $document_id,
                'creation_by' => (int) $user_id,
            ),
            $formats,
            array('%d', '%d')
        );

        if ($result === false) {
            return false;
        }

        return $this->findOwnedByUser($document_id, $user_id);
    }

    /**
     * Applique la criticité d'un template au document et verrouille la valeur.
     *
     * Le verrou (criticality_locked) empêche saveSendSettings() d'écraser la criticité
     * définie par le template, qui peut avoir des implications légales ou de traçabilité.
     *
     * @param int                 $document_id
     * @param int                 $user_id
     * @param array<string,mixed> $template
     * @return bool
     */
    public function applyTemplateCriticality($document_id, $user_id, array $template)
    {
        if (!$this->hasColumn('criticality') || empty($template['criticality'])) {
            return true;
        }

        global $wpdb;

        $criticality = DocumentCriticality::normalize((string) $template['criticality']);
        $update_data = array(
            'criticality' => $criticality,
        );
        $formats = array('%s');

        if ($this->hasColumn('criticality_locked')) {
            $update_data['criticality_locked'] = 1;
            $formats[] = '%d';
        }

        if ($this->hasColumn('criticality_source_template_id')) {
            $update_data['criticality_source_template_id'] = isset($template['id']) ? absint($template['id']) : null;
            $formats[] = '%d';
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'signdocument',
            $update_data,
            array(
                'id' => (int) $document_id,
                'creation_by' => (int) $user_id,
            ),
            $formats,
            array('%d', '%d')
        );

        return $result !== false;
    }

    /**
     * Passe le document en statut ZONE_READY après placement des zones de signature.
     *
     * N'écrase pas un statut plus avancé (READY_TO_SEND) si l'utilisateur retouche
     * les zones après avoir déjà configuré l'envoi.
     *
     * @param int $document_id
     * @param int $user_id
     * @return array<string,mixed>|false  Document mis à jour, ou false si transition interdite.
     */
    public function markZoneReady($document_id, $user_id)
    {
        global $wpdb;

        $document = $this->findOwnedByUser($document_id, $user_id);

        $status = DocumentStatus::normalize(isset($document['document_status']) ? $document['document_status'] : '');

        if (!$document || !in_array($status, array(DocumentStatus::DRAFT, DocumentStatus::ZONE_READY, DocumentStatus::READY_TO_SEND), true)) {
            return false;
        }

        $table = $wpdb->prefix . 'signdocument';
        $result = $wpdb->update(
            $table,
            array(
                'document_status' => DocumentStatus::ZONE_READY,
            ),
            array(
                'id' => (int) $document_id,
                'creation_by' => (int) $user_id,
            ),
            array('%s'),
            array('%d', '%d')
        );

        return $result !== false ? $this->findOwnedByUser($document_id, $user_id) : false;
    }

    /**
     * Génère un nouveau token public et invalide l'ancien.
     *
     * À appeler quand le lien de signature doit être régénéré (ex. : renvoi après
     * expiration). Si token_hash est disponible, le token en clair n'est jamais
     * écrit en base : on ne stocke que le hash bcrypt.
     *
     * @param int $document_id
     * @param int $user_id     0 = pas de restriction par propriétaire (usage cron/admin).
     * @return string  Nouveau token en clair, ou '' si la rotation est refusée.
     */
    public function rotatePublicToken($document_id, $user_id = 0)
    {
        global $wpdb;

        $document = $user_id > 0 ? $this->findOwnedByUser($document_id, $user_id) : $this->find($document_id);

        if (!$document || !DocumentStatus::canSend(isset($document['document_status']) ? $document['document_status'] : '')) {
            return '';
        }

        $token = wp_generate_password(48, false, false);
        $update_data = array(
            'token' => $this->hasColumn('token_hash') ? '' : $token,
        );
        $formats = array('%s');

        if ($this->hasColumn('token_hash')) {
            $update_data['token_hash'] = wp_hash_password($token);
            $formats[] = '%s';
        }

        $where = array('id' => (int) $document_id);
        $where_formats = array('%d');

        if ($user_id > 0) {
            $where['creation_by'] = (int) $user_id;
            $where_formats[] = '%d';
        }

        $result = $wpdb->update($wpdb->prefix . 'signdocument', $update_data, $where, $formats, $where_formats);

        return $result !== false ? $token : '';
    }

    /**
     * Pré-remplit le message d'envoi avec la suggestion IA, seulement si le champ est vide.
     *
     * Le double UPDATE (wpdb->update puis query manuel) est un contournement pour le cas
     * où wpdb->update refuse de générer la clause WHERE send_message = '' quand la valeur
     * attendue est une chaîne vide (certaines versions de wpdb sautent les valeurs falsy).
     *
     * @param int    $document_id
     * @param int    $user_id
     * @param string $message
     * @return bool
     */
    public function saveAiSuggestedMessage($document_id, $user_id, $message)
    {
        global $wpdb;

        $message = trim((string) $message);

        if ($message === '') {
            return false;
        }

        $table = $wpdb->prefix . 'signdocument';
        $result = $wpdb->update(
            $table,
            array(
                'send_message' => $message,
            ),
            array(
                'id' => (int) $document_id,
                'creation_by' => (int) $user_id,
                'send_message' => '',
            ),
            array('%s'),
            array('%d', '%d', '%s')
        );

        if ($result === false || $result === 0) {
            $sql = $wpdb->prepare(
                "UPDATE {$table}
                 SET send_message = %s
                 WHERE id = %d
                   AND creation_by = %d
                   AND (send_message IS NULL OR send_message = '')",
                $message,
                (int) $document_id,
                (int) $user_id
            );

            $result = $wpdb->query($sql);
        }

        return $result !== false;
    }

    /**
     * Ajoute une entrée JSON en fin de colonne system_log (format append-only).
     *
     * Chaque entrée est un objet JSON sur plusieurs lignes, séparé par une ligne vide.
     * La colonne est un TEXT libre : pas de parsing structuré en lecture, elle sert
     * uniquement à l'affichage dans l'écran admin pour les erreurs techniques.
     *
     * @param int                 $document_id
     * @param string              $title    Clé courte identifiant l'événement (ex. 'public_signature_finalize_error').
     * @param array<string,mixed> $context  Données libres associées à l'événement.
     * @return bool
     */
    public function appendSystemLog($document_id, $title, array $context = array())
    {
        global $wpdb;

        if (!$this->hasSystemLogColumn()) {
            return false;
        }

        $table = $wpdb->prefix . 'signdocument';
        $entry = array(
            'date' => current_time('mysql'),
            'title' => (string) $title,
            'context' => $context,
        );
        $line = wp_json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($line) || $line === '') {
            return false;
        }

        $sql = $wpdb->prepare(
            "UPDATE {$table}
             SET system_log = CONCAT(COALESCE(system_log, ''), %s)
             WHERE id = %d",
            "\n\n" . $line,
            (int) $document_id
        );

        return $wpdb->query($sql) !== false;
    }

    private function hasSystemLogColumn()
    {
        self::$has_system_log_column = $this->hasColumn('system_log');

        return self::$has_system_log_column;
    }

    /**
     * Enregistre une ouverture du lien de signature public (last_opened_date + opened_count).
     *
     * Silencieusement ignoré si les colonnes n'existent pas encore (déploiement progressif).
     *
     * @param int $document_id
     * @return bool
     */
    public function notePublicOpen($document_id)
    {
        global $wpdb;

        if (!$this->hasColumn('last_opened_date') || !$this->hasColumn('opened_count')) {
            return false;
        }

        $table = $wpdb->prefix . 'signdocument';
        $sql = $wpdb->prepare(
            "UPDATE {$table}
             SET last_opened_date = %s,
                 opened_count = COALESCE(opened_count, 0) + 1
             WHERE id = %d
               AND deleted_flag = 0",
            current_time('mysql'),
            (int) $document_id
        );

        return $wpdb->query($sql) !== false;
    }

    /**
     * Vérifie que le token fourni correspond à l'enregistrement en base.
     *
     * Deux modes selon la version du schéma :
     * - token_hash présent  → wp_check_password() (bcrypt, résistant aux fuites de BDD) ;
     * - token_hash absent   → hash_equals() sur le token en clair (temps constant,
     *   résistant aux attaques timing même si moins robuste qu'un hash).
     */
    private function publicTokenMatches(array $document, $token)
    {
        $token = (string) $token;

        if ($token === '') {
            return false;
        }

        if (!empty($document['token_hash']) && function_exists('wp_check_password')) {
            return wp_check_password($token, (string) $document['token_hash']);
        }

        $legacy_token = isset($document['token']) ? (string) $document['token'] : '';

        return $legacy_token !== '' && hash_equals($legacy_token, $token);
    }

    /**
     * Indique si une colonne existe dans la table (avec cache statique par requête).
     *
     * SHOW COLUMNS est utilisé plutôt qu'une introspection wpdb->get_col_info() car
     * WordPress ne fournit pas d'API publique fiable pour interroger le schéma d'une
     * table custom. Le cache static[] évite les SHOW COLUMNS répétés dans un même
     * cycle de requête (ex. : markSigned() appelle hasColumn() pour ~5 colonnes).
     */
    private function hasColumn($column)
    {
        global $wpdb;

        $column = (string) $column;

        if (array_key_exists($column, self::$column_cache)) {
            return self::$column_cache[$column];
        }

        $table = $wpdb->prefix . 'signdocument';
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
        self::$column_cache[$column] = !empty($found);

        return self::$column_cache[$column];
    }

    /**
     * Passe le document en statut SENT et incrémente sent_count si la colonne existe.
     *
     * L'incrémentation se fait via un UPDATE SQL plutôt que read+write pour éviter
     * les race conditions en cas de double-clic sur "Envoyer".
     *
     * @param int $document_id
     * @param int $user_id
     * @return array<string,mixed>|false  Document mis à jour, ou false si la transition est refusée.
     */
    public function markSent($document_id, $user_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $document = $this->findOwnedByUser($document_id, $user_id);

        if (!$document || !DocumentStatus::canSend(isset($document['document_status']) ? $document['document_status'] : '')) {
            return false;
        }

        if ($this->hasColumn('sent_count')) {
            $sql = $wpdb->prepare(
                "UPDATE {$table}
                 SET document_status = %s,
                     sent_date = %s,
                     sent_by = %d,
                     sent_count = COALESCE(sent_count, 0) + 1
                 WHERE id = %d
                   AND creation_by = %d",
                DocumentStatus::SENT,
                current_time('mysql'),
                (int) $user_id,
                (int) $document_id,
                (int) $user_id
            );
            $result = $wpdb->query($sql);
        } else {
            $result = $wpdb->update(
                $table,
                array(
                    'document_status' => DocumentStatus::SENT,
                    'sent_date' => current_time('mysql'),
                    'sent_by' => (int) $user_id,
                ),
                array(
                    'id' => (int) $document_id,
                    'creation_by' => (int) $user_id,
                ),
                array('%s', '%s', '%d'),
                array('%d', '%d')
            );
        }

        return $result !== false ? $this->findOwnedByUser($document_id, $user_id) : false;
    }

    /**
     * Enregistre la réponse du signataire et passe le statut à SIGNED ou REFUSED.
     *
     * Le token est re-vérifié ici pour garantir que l'UPDATE ne s'applique qu'à un
     * document dont le lien est encore valide (protection contre les replays après
     * rotation de token).
     *
     * Les colonnes d'intégrité (source_sha256, signed_sha256, certification_fingerprint,
     * cryptographic_signature_applied/status) sont écrites conditionnellement selon
     * la version du schéma déployé.
     *
     * @param int                 $document_id
     * @param string              $token  Token brut du lien public.
     * @param array<string,mixed> $data   Champs de signature à persister.
     * @return array<string,mixed>|false
     */
    public function markSigned($document_id, $token, array $data)
    {
        global $wpdb;

        $document = $this->findByPublicToken($document_id, $token);

        if (!$document || !DocumentStatus::canPublicAnswer(isset($document['document_status']) ? $document['document_status'] : '')) {
            return false;
        }

        $return_status = isset($data['signer_return_status']) ? (string) $data['signer_return_status'] : '';
        $document_status = $return_status === 'refused' ? DocumentStatus::REFUSED : DocumentStatus::SIGNED;
        $table = $wpdb->prefix . 'signdocument';
        $update_data = array(
            'document_status' => $document_status,
            'sign_date' => isset($data['sign_date']) ? (string) $data['sign_date'] : current_time('mysql'),
            'signer_contact' => isset($data['signer_contact']) ? (string) $data['signer_contact'] : '',
            'signer_first_name' => isset($data['signer_first_name']) ? (string) $data['signer_first_name'] : '',
            'signer_last_name' => isset($data['signer_last_name']) ? (string) $data['signer_last_name'] : '',
            'signer_place' => isset($data['signer_place']) ? (string) $data['signer_place'] : '',
            'signer_return_status' => isset($data['signer_return_status']) ? (string) $data['signer_return_status'] : '',
            'signer_return_message' => isset($data['signer_return_message']) ? (string) $data['signer_return_message'] : '',
            'signer_latitude' => isset($data['signer_latitude']) && $data['signer_latitude'] !== '' ? (string) $data['signer_latitude'] : null,
            'signer_longitude' => isset($data['signer_longitude']) && $data['signer_longitude'] !== '' ? (string) $data['signer_longitude'] : null,
            'signature_data' => isset($data['signature_data']) ? (string) $data['signature_data'] : '',
            'signer_ip' => isset($data['signer_ip']) ? (string) $data['signer_ip'] : '',
            'signer_user_agent' => isset($data['signer_user_agent']) ? (string) $data['signer_user_agent'] : '',
            'signed_storage_path' => isset($data['signed_storage_path']) ? (string) $data['signed_storage_path'] : '',
            'signed_file_size' => isset($data['signed_file_size']) ? max(0, (int) $data['signed_file_size']) : 0,
            'signed_pdf_date' => isset($data['signed_pdf_date']) ? (string) $data['signed_pdf_date'] : current_time('mysql'),
            'identity_photo_storage_path' => isset($data['identity_photo_storage_path']) ? (string) $data['identity_photo_storage_path'] : '',
            'identity_photo_file_size' => isset($data['identity_photo_file_size']) ? max(0, (int) $data['identity_photo_file_size']) : 0,
            'identity_photo_mime_type' => isset($data['identity_photo_mime_type']) ? (string) $data['identity_photo_mime_type'] : '',
            'identity_photo_filename' => isset($data['identity_photo_filename']) ? (string) $data['identity_photo_filename'] : '',
        );
        $formats = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s');

        foreach (array(
            'source_sha256' => '%s',
            'signed_sha256' => '%s',
            'certification_fingerprint' => '%s',
            'cryptographic_signature_applied' => '%d',
            'cryptographic_signature_status' => '%s',
        ) as $column => $format) {
            if ($this->hasColumn($column)) {
                $update_data[$column] = $format === '%d' ? (!empty($data[$column]) ? 1 : 0) : (isset($data[$column]) ? (string) $data[$column] : '');
                $formats[] = $format;
            }
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            array(
                'id' => (int) $document_id,
            ),
            $formats,
            array('%d')
        );

        return $result !== false ? $this->findByPublicToken($document_id, $token) : false;
    }
}
