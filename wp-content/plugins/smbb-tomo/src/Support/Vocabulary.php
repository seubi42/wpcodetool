<?php

namespace Smbb\Tomo\Support;

defined('ABSPATH') || exit;

/**
 * Vocabulaire : le plugin parle d'« atelier », d'« intervenant », de « participant », de « billet »…
 * mais le même outil sert à des séances de cuisine, des stages, des trackdays, des formations ou
 * des packs VIP. Les textes du domaine smbb-tomo (écrans, menus CodeTool, front, billets, messages
 * par défaut) sont réécrits à la volée avec les mots choisis dans Réglages › Vocabulaire.
 *
 * Chaque notion (terms()) a ses mots d'origine, avec leur genre, et une liste de mots proposés.
 * La réécriture refait l'élision (« l'atelier » → « la formation », « d'atelier » → « de stage »),
 * accorde les déterminants et adjectifs collés au mot (« ce créneau est complet » → « cette journée
 * est complète », « Inscription annulée » → « Billet annulé ») et garde la casse.
 * Un accord plus loin dans la phrase (« L'atelier du 6 décembre est annulé ») n'est pas corrigé :
 * c'est pourquoi les listes proposent surtout des mots du même genre que l'original.
 *
 * Les variables entre accolades ({atelier}) et les shortcodes entre crochets ne sont jamais touchés.
 */
final class Vocabulary
{
    const DOMAIN = 'smbb-tomo';

    /** @var array<string,array{singular:string,plural:string,feminine:bool}>|null */
    private static $words;

    /** @var array<string,string> */
    private static $cache = array();

    /**
     * Notions réglables. Pour chacune :
     * - setting : préfixe des réglages (clé choisie ; _singular, _plural, _feminine pour « Autre ») ;
     * - sources : mots d'origine présents dans les textes (singulier, pluriel, féminin) ;
     * - choices : mots proposés, clé => (singulier, pluriel, féminin) ; 'custom' = saisie libre ;
     * - default : clé d'origine (aucune réécriture) ;
     * - skip : motif qui, juste après le mot, empêche la réécriture (« Pass Type ID » d'Apple).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function terms()
    {
        return array(
            'atelier' => array(
                'setting' => 'vocabulary_event',
                'sources' => array(array('atelier', 'ateliers', false)),
                'default' => 'atelier',
                'choices' => self::eventChoices(),
            ),
            'créneau' => array(
                'setting' => 'vocabulary_slot',
                'sources' => array(array('créneau', 'créneaux', false)),
                'default' => 'creneau',
                'choices' => self::slotChoices(),
            ),
            'intervenant' => array(
                'setting' => 'vocabulary_staff',
                'sources' => array(array('intervenant', 'intervenants', false)),
                'default' => 'intervenant',
                'choices' => self::staffChoices(),
            ),
            'participant' => array(
                'setting' => 'vocabulary_participant',
                'sources' => array(array('participant', 'participants', false)),
                'default' => 'participant',
                'choices' => array(
                    'participant' => array('participant', 'participants', false),
                    'stagiaire' => array('stagiaire', 'stagiaires', false),
                    'eleve' => array('élève', 'élèves', false),
                    'pilote' => array('pilote', 'pilotes', false),
                    'client' => array('client', 'clients', false),
                    'invite' => array('invité', 'invités', false),
                    'apprenant' => array('apprenant', 'apprenants', false),
                    'custom' => array('', '', false),
                ),
            ),
            // Les écrans disent « inscription » ou « réservation » selon le contexte : un seul mot
            // choisi remplace les deux.
            'réservation' => array(
                'setting' => 'vocabulary_booking',
                'sources' => array(array('inscription', 'inscriptions', true), array('réservation', 'réservations', true)),
                'default' => 'origine',
                'choices' => array(
                    'origine' => array('', '', true),
                    'reservation' => array('réservation', 'réservations', true),
                    'inscription' => array('inscription', 'inscriptions', true),
                    'custom' => array('', '', true),
                ),
            ),
            'billet' => array(
                'setting' => 'vocabulary_ticket',
                'sources' => array(array('billet', 'billets', false)),
                'default' => 'billet',
                'choices' => array(
                    'billet' => array('billet', 'billets', false),
                    'ticket' => array('ticket', 'tickets', false),
                    'ebillet' => array('e-billet', 'e-billets', false),
                    'convocation' => array('convocation', 'convocations', true),
                    'badge' => array('badge', 'badges', false),
                    'custom' => array('', '', false),
                ),
            ),
            'pass' => array(
                'setting' => 'vocabulary_pass',
                'sources' => array(array('pass', 'pass', false)),
                'default' => 'pass',
                'skip' => '(?: Type|\.[a-z])',
                'choices' => array(
                    'pass' => array('pass', 'pass', false),
                    'forfait' => array('forfait', 'forfaits', false),
                    'cycle' => array('cycle', 'cycles', false),
                    'formule' => array('formule', 'formules', true),
                    'abonnement' => array('abonnement', 'abonnements', false),
                    'custom' => array('', '', false),
                ),
            ),
            'bon cadeau' => array(
                'setting' => 'vocabulary_voucher',
                'sources' => array(array('bon cadeau', 'bons cadeaux', false)),
                'default' => 'bon',
                'choices' => array(
                    'bon' => array('bon cadeau', 'bons cadeaux', false),
                    'carte' => array('carte cadeau', 'cartes cadeaux', true),
                    'cheque' => array('chèque cadeau', 'chèques cadeaux', false),
                    'custom' => array('', '', false),
                ),
            ),
        );
    }

    /**
     * Mots proposés pour « atelier ».
     *
     * @return array<string,array{0:string,1:string,2:bool}> clé => (singulier, pluriel, féminin)
     */
    public static function eventChoices()
    {
        return array(
            'atelier' => array('atelier', 'ateliers', false),
            'evenement' => array('événement', 'événements', false),
            'stage' => array('stage', 'stages', false),
            'cours' => array('cours', 'cours', false),
            'formation' => array('formation', 'formations', true),
            'trackday' => array('trackday', 'trackdays', false),
            'pack' => array('pack', 'packs', false),
            'custom' => array('', '', false),
        );
    }

    /**
     * Mots proposés pour « intervenant ».
     *
     * @return array<string,array{0:string,1:string,2:bool}>
     */
    public static function staffChoices()
    {
        return array(
            'intervenant' => array('intervenant', 'intervenants', false),
            'animateur' => array('animateur', 'animateurs', false),
            'chef' => array('chef', 'chefs', false),
            'formateur' => array('formateur', 'formateurs', false),
            'moniteur' => array('moniteur', 'moniteurs', false),
            'instructeur' => array('instructeur', 'instructeurs', false),
            'coach' => array('coach', 'coachs', false),
            'staff' => array('staff', 'staff', false),
            'custom' => array('', '', false),
        );
    }

    /**
     * Mots proposés pour « créneau » (une date d'atelier). Le genre compte : « la journée est complète ».
     *
     * @return array<string,array{0:string,1:string,2:bool}>
     */
    public static function slotChoices()
    {
        return array(
            'creneau' => array('créneau', 'créneaux', false),
            'journee' => array('journée', 'journées', true),
            'date' => array('date', 'dates', true),
            'seance' => array('séance', 'séances', true),
            'session' => array('session', 'sessions', true),
            'custom' => array('', '', false),
        );
    }

    /**
     * Options de select (libellé => clé) pour les réglages.
     */
    public static function options(array $choices)
    {
        $out = array();

        foreach ($choices as $key => $pair) {
            if ($key === 'custom') {
                $label = __('Autre (à préciser)', self::DOMAIN);
            } elseif ($key === 'origine') {
                $label = __('Comme à l\'origine (selon l\'écran)', self::DOMAIN);
            } else {
                $label = self::ucfirst($pair[0]) . ($pair[1] !== $pair[0] ? ' / ' . $pair[1] : '');
            }

            $out[$label] = $key;
        }

        return $out;
    }

    /**
     * Aide « Par exemple … » sous les champs Nom et Lieu, tirée des réglages (vide = pas d'aide).
     *
     * @param string $which name ou location
     */
    public static function example($which)
    {
        $value = trim((string) Settings::get('vocabulary_example_' . ($which === 'location' ? 'location' : 'name')));

        return $value !== '' ? sprintf(__('Par exemple « %s ».', self::DOMAIN), $value) : '';
    }

    public static function hooks()
    {
        if (!self::active()) {
            return;
        }

        add_filter('gettext', array(__CLASS__, 'gettext'), 20, 3);
        add_filter('ngettext', array(__CLASS__, 'ngettext'), 20, 5);
        add_filter('gettext_with_context', array(__CLASS__, 'gettextContext'), 20, 4);
        add_filter('ngettext_with_context', array(__CLASS__, 'ngettextContext'), 20, 6);
    }

    /**
     * Le vocabulaire diffère-t-il de celui d'origine ?
     */
    public static function active()
    {
        foreach (self::words() as $word) {
            if ($word !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mots choisis par notion ; null = mot d'origine gardé.
     *
     * @return array<string,array{singular:string,plural:string,feminine:bool}|null>
     */
    public static function words()
    {
        if (self::$words !== null) {
            return self::$words;
        }

        // Rien à réécrire pendant la lecture des réglages (qui peuvent eux-mêmes passer par gettext).
        $terms = self::terms();
        self::$words = array_fill_keys(array_keys($terms), null);
        $words = array();

        foreach ($terms as $base => $term) {
            $words[$base] = self::pick($term);
        }

        self::$words = $words;

        return self::$words;
    }

    /**
     * Mot choisi, au singulier ou au pluriel, pour les rares textes construits hors traduction.
     */
    public static function word($base = 'atelier', $plural = false, $capitalize = false)
    {
        $words = self::words();
        $terms = self::terms();

        if (!empty($words[$base])) {
            $word = $words[$base][$plural ? 'plural' : 'singular'];
        } elseif (isset($terms[$base])) {
            $word = $terms[$base]['sources'][0][$plural ? 1 : 0];
        } else {
            $word = $base;
        }

        return $capitalize ? self::ucfirst($word) : $word;
    }

    public static function gettext($translation, $text, $domain)
    {
        return $domain === self::DOMAIN ? self::apply($translation) : $translation;
    }

    public static function ngettext($translation, $single, $plural, $number, $domain)
    {
        return $domain === self::DOMAIN ? self::apply($translation) : $translation;
    }

    public static function gettextContext($translation, $text, $context, $domain)
    {
        return $domain === self::DOMAIN ? self::apply($translation) : $translation;
    }

    public static function ngettextContext($translation, $single, $plural, $number, $context, $domain)
    {
        return $domain === self::DOMAIN ? self::apply($translation) : $translation;
    }

    /**
     * Réécrit un texte avec le vocabulaire choisi.
     */
    public static function apply($text)
    {
        $text = (string) $text;

        if ($text === '') {
            return $text;
        }

        if (isset(self::$cache[$text])) {
            return self::$cache[$text];
        }

        $todo = array();
        $terms = self::terms();

        foreach (self::words() as $base => $word) {
            if ($word === null) {
                continue;
            }

            foreach ($terms[$base]['sources'] as $source) {
                $same = $source[0] === $word['singular'] && $source[2] === $word['feminine'];

                if (!$same && mb_stripos($text, $source[0]) !== false) {
                    $todo[] = array($source, $word, isset($terms[$base]['skip']) ? $terms[$base]['skip'] : '');
                }
            }
        }

        if (!$todo) {
            return $text;
        }

        // Variables {…} et shortcodes […] mis de côté ; chaque mot remplacé aussi, pour qu'une notion
        // suivante ne le réécrive pas une seconde fois.
        $kept = array();
        $out = preg_replace_callback('/\{[^{}]*\}|\[[^\[\]]*\]/u', function ($m) use (&$kept) {
            $kept[] = $m[0];

            return "\x01" . (count($kept) - 1) . "\x02";
        }, $text);

        foreach ($todo as $job) {
            $out = self::replace($out, $job[0], $job[1], $job[2], $kept);
        }

        // Les mots remplacés peuvent contenir des marques ; on restitue jusqu'à stabilité.
        for ($i = 0; $i < 3 && strpos($out, "\x01") !== false; $i++) {
            $out = preg_replace_callback("/\x01(\d+)\x02/", function ($m) use ($kept) {
                return $kept[(int) $m[1]];
            }, $out);
        }

        if (count(self::$cache) < 2000) {
            self::$cache[$text] = $out;
        }

        return $out;
    }

    /**
     * Déterminants et adjectifs placés avant le nom : notion => formes
     * (masculin devant consonne, masculin devant voyelle, féminin devant consonne, féminin devant voyelle).
     *
     * @return array<string,array{0:string,1:string,2:string,3:string}>
     */
    private static function determiners()
    {
        return array(
            'le' => array('le', "l'", 'la', "l'"),
            'au' => array('au', "à l'", 'à la', "à l'"),
            'du' => array('du', "de l'", 'de la', "de l'"),
            'de' => array('de', "d'", 'de', "d'"),
            'que' => array('que', "qu'", 'que', "qu'"),
            'jusque' => array('jusque', "jusqu'", 'jusque', "jusqu'"),
            'un' => array('un', 'un', 'une', 'une'),
            'ce' => array('ce', 'cet', 'cette', 'cette'),
            'son' => array('son', 'son', 'sa', 'son'),
            'mon' => array('mon', 'mon', 'ma', 'mon'),
            'ton' => array('ton', 'ton', 'ta', 'ton'),
            'aucun' => array('aucun', 'aucun', 'aucune', 'aucune'),
            'quel' => array('quel', 'quel', 'quelle', 'quelle'),
            'tout' => array('tout', 'tout', 'toute', 'toute'),
            'nouveau' => array('nouveau', 'nouvel', 'nouvelle', 'nouvelle'),
            'beau' => array('beau', 'bel', 'belle', 'belle'),
            'vieux' => array('vieux', 'vieil', 'vieille', 'vieille'),
            'prochain' => array('prochain', 'prochain', 'prochaine', 'prochaine'),
            'premier' => array('premier', 'premier', 'première', 'première'),
            'dernier' => array('dernier', 'dernier', 'dernière', 'dernière'),
            'seul' => array('seul', 'seul', 'seule', 'seule'),
            'certain' => array('certain', 'certain', 'certaine', 'certaine'),
            // Pluriel : le genre seul compte.
            'tous' => array('tous', 'tous', 'toutes', 'toutes'),
            'nouveaux' => array('nouveaux', 'nouveaux', 'nouvelles', 'nouvelles'),
            'prochains' => array('prochains', 'prochains', 'prochaines', 'prochaines'),
            'premiers' => array('premiers', 'premiers', 'premières', 'premières'),
            'derniers' => array('derniers', 'derniers', 'dernières', 'dernières'),
            'quels' => array('quels', 'quels', 'quelles', 'quelles'),
            'certains' => array('certains', 'certains', 'certaines', 'certaines'),
            'seuls' => array('seuls', 'seuls', 'seules', 'seules'),
        );
    }

    /**
     * Mots invariables admis dans la chaîne avant le nom (« tous les créneaux »).
     */
    private static function neutral()
    {
        return array('les', 'des', 'ces', 'aux', 'mes', 'tes', 'ses', 'nos', 'vos', 'leurs', 'leur', 'notre', 'votre', 'chaque', 'autre', 'autres', 'quelques', 'plusieurs', 'même', 'mêmes');
    }

    /**
     * Adjectifs et participes accordés après le nom (masculin singulier).
     */
    private static function adjectives()
    {
        return array(
            'annulé', 'complet', 'choisi', 'passé', 'inclus', 'mis', 'prévu', 'ouvert', 'fermé', 'terminé', 'suivant', 'précédent',
            'concerné', 'déplacé', 'réservé', 'libéré', 'créé', 'dupliqué', 'sélectionné', 'affiché', 'publié', 'confondu', 'seul',
            'plein', 'compris', 'retiré', 'lié', 'proposé', 'confirmé', 'payé', 'reporté', 'validé', 'signé', 'utilisé', 'expiré',
            'envoyé', 'remboursé', 'inconnu', 'pris', 'vendu', 'émis', 'enregistré', 'supprimé', 'modifié', 'offert', 'actif',
            'ajouté', 'rattaché', 'pointé', 'placé', 'principal', 'attendu', 'présent', 'absent', 'gratuit', 'prêt', 'perdu',
        );
    }

    /**
     * Formes accordées d'un adjectif : (masc. sing., masc. plur., fém. sing., fém. plur.).
     */
    private static function inflect($adj)
    {
        $special = array('complet' => 'complète', 'actif' => 'active', 'principal' => 'principale');
        $fem = isset($special[$adj]) ? $special[$adj] : (substr($adj, -1) === 'e' ? $adj : $adj . 'e');
        $plural = in_array(substr($adj, -1), array('s', 'x'), true) ? $adj : ($adj === 'principal' ? 'principaux' : $adj . 's');

        return array($adj, $plural, $fem, $fem . 's');
    }

    /**
     * Remplace un mot d'origine (et ce qui s'accorde autour) par le mot choisi.
     *
     * @param array{0:string,1:string,2:bool}                    $source
     * @param array{singular:string,plural:string,feminine:bool} $word
     * @param array<int,string>                                  $kept
     */
    private static function replace($text, array $source, array $word, $skip, array &$kept)
    {
        list($from, $from_plural, $from_fem) = $source;
        // Expression construite une fois par mot d'origine et par requête (c'est la partie coûteuse).
        static $built = array();
        $cache_key = $from . '|' . $from_plural . '|' . (int) $from_fem . '|' . $skip;

        if (!isset($built[$cache_key])) {
            $from_vowel = self::startsWithVowel($from);
            $apos = "['’]";

            // Formes des déterminants possibles devant le mot d'origine.
            $dets = array();
            $col = ($from_fem ? 2 : 0) + ($from_vowel ? 1 : 0);

            foreach (self::determiners() as $concept => $forms) {
                $dets[$forms[$col]] = $concept;
            }

            foreach (self::neutral() as $w) {
                $dets[$w] = '=';
            }

            uksort($dets, function ($a, $b) {
                return mb_strlen($b) - mb_strlen($a);
            });

            $det_alt = array();

            foreach (array_keys($dets) as $form) {
                $det_alt[] = substr($form, -1) === "'" ? preg_quote(substr($form, 0, -1), '/') . $apos : preg_quote($form, '/') . ' ';
            }

            $det_alt = '(?:' . str_replace(' ', '\s', implode('|', $det_alt)) . ')';

            // Adjectifs après le nom, toutes formes confondues.
            $adjs = array();

            foreach (self::adjectives() as $adj) {
                foreach (self::inflect($adj) as $i => $form) {
                    $adjs[$form] = array($adj, $i);
                }
            }

            uksort($adjs, function ($a, $b) {
                return mb_strlen($b) - mb_strlen($a);
            });

            $adj_alt = implode('|', array_map(function ($w) {
                return preg_quote($w, '/');
            }, array_keys($adjs)));

            $nouns = $from_plural !== $from ? preg_quote($from_plural, '/') . '|' . preg_quote($from, '/') : preg_quote($from, '/');
            $b = '(?<![\p{L}\p{N}_\-.\/])';
            $e = '(?![\p{L}\p{N}_\-])';
            $copula = "(?:\s(?:est|sont|reste|restent|n{$apos}est pas|n{$apos}est plus|devient|deviennent|a été|ont été|est déjà|sont déjà|est bien|sont bien)\s|\s)";
            $pattern = '/' . $b . '(' . $det_alt . ')?(' . $det_alt . ')?(' . $nouns . ')' . $e . ($skip !== '' ? '(?!' . $skip . ')' : '')
                . '(?:(' . $copula . ')(' . $adj_alt . ')' . $e . ')?/iu';
            $built[$cache_key] = array($dets, $adjs, $pattern);
        }

        list($dets, $adjs, $pattern) = $built[$cache_key];

        $determiners = self::determiners();
        $to_vowel = self::startsWithVowel($word['singular']);

        return preg_replace_callback($pattern, function ($m) use ($dets, $adjs, $determiners, $from, $from_plural, $word, &$kept) {
            $noun = $m[3];
            $adj = isset($m[5]) && $m[5] !== '' ? $m[5] : '';
            $adj_info = $adj !== '' ? $adjs[mb_strtolower($adj)] : null;

            // Pluriel : forme du nom, sinon (mot invariable comme « pass ») les mots qui l'entourent.
            $plural = mb_strtolower($noun) === mb_strtolower($from_plural) && $from_plural !== $from;

            if ($from_plural === $from) {
                $plural = $adj_info !== null && in_array($adj_info[1], array(1, 3), true);

                foreach (array(1, 2) as $i) {
                    $det = isset($m[$i]) ? mb_strtolower(rtrim(str_replace('’', "'", $m[$i]))) : '';

                    if (in_array($det, array('les', 'des', 'ces', 'aux', 'mes', 'tes', 'ses', 'nos', 'vos', 'leurs', 'quelques', 'plusieurs', 'autres'), true) || in_array($dets[$det] ?? '', array('tous', 'nouveaux', 'prochains', 'premiers', 'derniers', 'quels', 'certains', 'seuls'), true)) {
                        $plural = true;
                    }
                }
            }

            $target = $word[$plural ? 'plural' : 'singular'];
            $out = self::sameCase($noun, $target);
            $next = $target;

            // Déterminants de droite à gauche : l'élision dépend du mot qui suit.
            foreach (array(2, 1) as $i) {
                if (!isset($m[$i]) || $m[$i] === '') {
                    continue;
                }

                $raw = rtrim($m[$i]);
                $key = mb_strtolower(str_replace('’', "'", $raw));
                $concept = isset($dets[$key]) ? $dets[$key] : '=';

                if ($concept === '=') {
                    $form = $raw;
                    $out = $form . ' ' . $out;
                } else {
                    $form = $determiners[$concept][($word['feminine'] ? 2 : 0) + (self::startsWithVowel($next) ? 1 : 0)];
                    $form = self::sameCase($raw, $form);
                    $out = $form . (substr($form, -1) === "'" ? '' : ' ') . $out;
                }

                $next = $form;
            }

            if ($adj_info !== null) {
                $forms = self::inflect($adj_info[0]);
                $out .= $m[4] . self::sameCase($adj, $forms[($word['feminine'] ? 2 : 0) + ($plural ? 1 : 0)]);
            }

            $kept[] = $out;

            return "\x01" . (count($kept) - 1) . "\x02";
        }, $text);
    }

    /**
     * @param array<string,mixed> $term
     * @return array{singular:string,plural:string,feminine:bool}|null null = mot d'origine
     */
    private static function pick(array $term)
    {
        $choices = $term['choices'];
        $key = (string) Settings::get($term['setting']);
        $key = isset($choices[$key]) ? $key : $term['default'];

        if ($key === $term['default']) {
            return null;
        }

        if ($key === 'custom') {
            $singular = trim((string) Settings::get($term['setting'] . '_singular'));
            $plural = trim((string) Settings::get($term['setting'] . '_plural'));

            if ($singular === '') {
                return null;
            }

            return array(
                'singular' => self::lower($singular),
                'plural' => self::lower($plural !== '' ? $plural : $singular . 's'),
                'feminine' => (bool) Settings::get($term['setting'] . '_feminine'),
            );
        }

        return array('singular' => $choices[$key][0], 'plural' => $choices[$key][1], 'feminine' => (bool) $choices[$key][2]);
    }

    private static function startsWithVowel($word)
    {
        return (bool) preg_match('/^[aeiouyàâäéèêëîïôöùûüœh]/iu', $word);
    }

    /**
     * Recopie la casse du mot d'origine : atelier → stage, Atelier → Stage, ATELIER → STAGE.
     */
    private static function sameCase($original, $word)
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $original);

        if (function_exists('mb_strtoupper') && mb_strlen($letters) > 1 && $letters === mb_strtoupper($letters)) {
            return mb_strtoupper($word);
        }

        $first = function_exists('mb_substr') ? mb_substr($original, 0, 1) : $original[0];

        return $first !== (function_exists('mb_strtolower') ? mb_strtolower($first) : strtolower($first)) ? self::ucfirst($word) : $word;
    }

    private static function ucfirst($word)
    {
        if (!function_exists('mb_substr')) {
            return ucfirst($word);
        }

        return mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
    }

    /**
     * Première lettre en minuscule, le reste tel que saisi (« Pack VIP » → « pack VIP »).
     */
    private static function lower($word)
    {
        if (!function_exists('mb_substr')) {
            return lcfirst($word);
        }

        return mb_strtolower(mb_substr($word, 0, 1)) . mb_substr($word, 1);
    }
}
