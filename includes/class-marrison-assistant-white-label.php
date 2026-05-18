<?php
/**
 * Gestione branding white-label.
 *
 * Legge white-label.json dalla root del plugin.
 * I campi vuoti ("") usano i valori predefiniti Marrison.
 * La versione, lo slug, il meccanismo di aggiornamento restano invariati.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marrison_Assistant_White_Label {

    private static $config  = null;
    private static $loaded  = false;

    // ── Lettura config ────────────────────────────────────────────────

    /**
     * Catena di caricamento (in ordine di priorità):
     *   1. Transient breve (1h) — evita richieste ripetute
     *   2. Fetch dal Commander REST API → aggiorna option persistente
     *   3. Option persistente `marrison_assistant_white_label_cache` (sopravvive agli update del plugin)
     *   4. File legacy `white-label.json` nella root del plugin (backward compat)
     *   5. Array vuoto → si usano i default
     */
    private static function load() {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        // 1. Transient breve in memoria della richiesta corrente
        $transient = get_transient('marrison_assistant_white_label');
        if (is_array($transient)) {
            self::$config = $transient;
            return;
        }

        // 2. Tenta fetch dal Commander
        $remote = self::fetch_from_commander();
        if (is_array($remote)) {
            self::$config = $remote;
            update_option('marrison_assistant_white_label_cache', $remote, false);
            set_transient('marrison_assistant_white_label', $remote, HOUR_IN_SECONDS);
            return;
        }

        // 3. Fallback: option persistente (ultima copia ricevuta dal Commander)
        $cached = get_option('marrison_assistant_white_label_cache', null);
        if (is_array($cached)) {
            self::$config = $cached;
            // Cache breve per evitare di rifare il fetch ad ogni request
            set_transient('marrison_assistant_white_label', $cached, 15 * MINUTE_IN_SECONDS);
            return;
        }

        // 4. Fallback: file legacy nella cartella plugin (sovrascritto agli update — solo backward compat)
        $file = MARRISON_ASSISTANT_PLUGIN_DIR . 'white-label.json';
        if (file_exists($file)) {
            $raw  = file_get_contents($file);
            $data = ($raw !== false) ? json_decode($raw, true) : null;
            if (is_array($data)) {
                self::$config = $data;
                // Migra automaticamente il file legacy verso l'option persistente
                update_option('marrison_assistant_white_label_cache', $data, false);
                return;
            }
        }

        // 5. Nessuna config → si usano i default
        self::$config = array();
    }

    /**
     * Fetch white-label config dal Commander.
     * Ritorna array se ok, null se fetch fallito (timeout, 404, parsing error).
     */
    private static function fetch_from_commander() {
        $endpoint = 'https://marrisonlab.com/wp-json/marrison-commander/v1/white-label';
        $url      = add_query_arg('site_url', get_site_url(), $endpoint);

        $resp = wp_remote_get($url, array(
            'timeout'   => 4,
            'sslverify' => false,
            'headers'   => array('Accept' => 'application/json'),
        ));

        if (is_wp_error($resp)) return null;
        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) return null;

        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['success'])) return null;

        return isset($data['white_label']) && is_array($data['white_label'])
            ? $data['white_label']
            : array();
    }

    /**
     * Forza l'invalidazione delle cache white-label (es. dopo aggiornamento manuale).
     */
    public static function flush_cache() {
        delete_transient('marrison_assistant_white_label');
        delete_option('marrison_assistant_white_label_cache');
        self::$loaded = false;
        self::$config = null;
    }

    /**
     * Restituisce un valore dal file white-label, o $default se assente/vuoto.
     */
    public static function get($key, $default = '') {
        self::load();
        $val = isset(self::$config[$key]) ? trim((string) self::$config[$key]) : '';
        return ($val !== '' && $key !== '_note') ? $val : $default;
    }

    // ── Accessori tipizzati ───────────────────────────────────────────

    public static function plugin_name() {
        return self::get('plugin_name', 'MA Condominio');
    }

    public static function author() {
        return self::get('author', 'Marrisonlab');
    }

    public static function author_url() {
        return self::get('author_url', 'https://marrisonlab.com');
    }

    public static function powered_by_text() {
        return self::get('powered_by_text', 'Powered by Marrisonlab');
    }

    public static function powered_by_url() {
        return self::get('powered_by_url', 'https://marrisonlab.com');
    }

    /**
     * URL assoluto del logo white-label, oppure '' se non configurato.
     * Il campo "logo" può essere:
     *   - URL completo (https://...)
     *   - nome file relativo alla cartella white-label/ del plugin (es. "logo.png")
     */
    public static function logo_url() {
        $logo = self::get('logo', '');
        if ($logo === '') {
            return '';
        }
        if (filter_var($logo, FILTER_VALIDATE_URL)) {
            return $logo;
        }
        return plugins_url('white-label/' . ltrim($logo, '/'), MARRISON_ASSISTANT_PLUGIN_DIR . 'marrison-assistant.php');
    }

    /**
     * True se almeno un campo branding è stato personalizzato.
     */
    public static function is_active() {
        self::load();
        foreach (array('plugin_name', 'author', 'powered_by_text', 'logo') as $k) {
            if (self::get($k, '') !== '') {
                return true;
            }
        }
        return false;
    }
}
