<?php

namespace Smbb\SignConnectProxima\Support;

defined('ABSPATH') || exit;

/**
 * Les trois niveaux de signature proposés par SignaConnect.
 */
final class Levels
{
    const INTERNAL = 'internal';
    const SIMPLE = 'simple';
    const QUALIFIED = 'qualified';

    public static function all()
    {
        return array(self::INTERNAL, self::SIMPLE, self::QUALIFIED);
    }

    /**
     * Libellés affichés (les clés internes restent internal / simple / qualified) :
     * Simple = lien SignaConnect et signature sur notre page ; Vérifiée = Universign level1 avec code
     * SMS ou email ; Qualifiée = Universign level4 (eIDAS qualifiée, identité vérifiée).
     */
    public static function labels()
    {
        return array(
            self::INTERNAL => __('Simple', 'smbb-signaconnect-proxima'),
            self::SIMPLE => __('Vérifiée', 'smbb-signaconnect-proxima'),
            self::QUALIFIED => __('Qualifiée eIDAS', 'smbb-signaconnect-proxima'),
        );
    }

    /**
     * Ce que garantit chaque niveau, en une ligne.
     */
    public static function descriptions()
    {
        return array(
            self::INTERNAL => __('Signature image apposée sur le PDF, sans certification par un tiers de confiance.', 'smbb-signaconnect-proxima'),
            self::SIMPLE => __('Code par SMS ou email, certifiée par Universign.', 'smbb-signaconnect-proxima'),
            self::QUALIFIED => __('Identité vérifiée, signature qualifiée eIDAS par Universign.', 'smbb-signaconnect-proxima'),
        );
    }

    /**
     * Normes respectées par le prestataire de confiance ('' pour le niveau Simple).
     */
    public static function norms()
    {
        return array(
            self::INTERNAL => '',
            self::SIMPLE => 'ETSI EN 319 401',
            self::QUALIFIED => 'ETSI EN 319 411-1 et 411-2 (QCP-n-qscd), ETSI EN 319 421, ETSI EN 319 102-1',
        );
    }

    public static function description($level)
    {
        $descriptions = self::descriptions();

        return isset($descriptions[$level]) ? $descriptions[$level] : '';
    }

    public static function norm($level)
    {
        $norms = self::norms();

        return isset($norms[$level]) ? $norms[$level] : '';
    }

    /**
     * Niveau Universign correspondant (null pour la signature interne).
     */
    public static function universignLevel($level)
    {
        switch ($level) {
            case self::SIMPLE:
                return 'level1';
            case self::QUALIFIED:
                return self::qualifiedUniversignLevel();
            default:
                return null;
        }
    }

    /**
     * Niveau Universign du palier « Qualifiée », réglable dans Réglages > Universign
     * (level4 = qualifiée eIDAS, level3 = avancée certificat qualifié PVID, level2 = avancée certificat LCP),
     * selon les options activées sur le compte Universign.
     */
    public static function qualifiedUniversignLevel()
    {
        $settings = get_option('smbb_scp_settings', array());
        $level = is_array($settings) && isset($settings['universign_qualified_level']) ? (string) $settings['universign_qualified_level'] : 'level4';

        return in_array($level, array('level2', 'level3', 'level4'), true) ? $level : 'level4';
    }

    /**
     * Ordre de force : interne < simple < qualifiée.
     */
    public static function rank($level)
    {
        $index = array_search((string) $level, self::all(), true);

        return $index === false ? 0 : (int) $index;
    }

    public static function label($level)
    {
        $labels = self::labels();

        return isset($labels[$level]) ? $labels[$level] : (string) $level;
    }

    public static function shortLabel($level)
    {
        $labels = array(
            self::INTERNAL => __('Simple', 'smbb-signaconnect-proxima'),
            self::SIMPLE => __('Vérifiée', 'smbb-signaconnect-proxima'),
            self::QUALIFIED => __('Qualifiée eIDAS', 'smbb-signaconnect-proxima'),
        );

        return isset($labels[$level]) ? $labels[$level] : (string) $level;
    }

    public static function normalize($level, $default = self::INTERNAL)
    {
        $level = sanitize_key((string) $level);

        return in_array($level, self::all(), true) ? $level : $default;
    }

    /**
     * Options pour Form::select() / checkbox() : libellé => valeur.
     */
    public static function options()
    {
        return array_flip(self::labels());
    }
}
