<?php
/**
 * Plugin Name: MA Condominio
 * Plugin URI: https://github.com/marrisonlab/marrison-assistant-condominio
 * Description: Asssistente professionale AI per i tuoi clienti
 * Version: 1.0.0
 * Author: Marrisonlab
 * Author URI: https://marrisonlab.com
 * Text Domain: marrison-assistant
 */

// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// Previeni caricamento multiplo (solo controllo versione)
if (defined('MARRISON_ASSISTANT_VERSION')) {
    error_log('Marrison Assistant: Plugin already loaded, skipping');
    return;
}

// Definisci costanti del plugin
define('MARRISON_ASSISTANT_VERSION', '1.0.0');
define('MARRISON_ASSISTANT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MARRISON_ASSISTANT_PLUGIN_URL', plugin_dir_url(__FILE__));

error_log('Marrison Assistant: Loading plugin v' . MARRISON_ASSISTANT_VERSION . ' from ' . MARRISON_ASSISTANT_PLUGIN_DIR);

// Carica i file necessari
require_once MARRISON_ASSISTANT_PLUGIN_DIR . 'includes/class-marrison-assistant-admin.php';
require_once MARRISON_ASSISTANT_PLUGIN_DIR . 'includes/class-marrison-assistant-api.php';
require_once MARRISON_ASSISTANT_PLUGIN_DIR . 'includes/class-marrison-assistant-gemini.php';
require_once MARRISON_ASSISTANT_PLUGIN_DIR . 'includes/class-marrison-assistant-condominio.php';
require_once MARRISON_ASSISTANT_PLUGIN_DIR . 'includes/class-marrison-assistant-site-agent.php';
require_once MARRISON_ASSISTANT_PLUGIN_DIR . 'includes/class-marrison-assistant-requests.php';
require_once MARRISON_ASSISTANT_PLUGIN_DIR . 'includes/class-marrison-assistant-auth.php';
require_once MARRISON_ASSISTANT_PLUGIN_DIR . 'includes/class-marrison-assistant-updater.php';

/**
 * Classe principale del plugin
 */
class Marrison_Assistant {
    
    private $admin;
    private $api;
    private $gemini;
    private $auth;
    private $site_agent;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Link impostazioni nella lista plugin
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
    }

    public function init() {
        // Inizializza tutte le classi
        $this->admin = new Marrison_Assistant_Admin();
        $this->api = new Marrison_Assistant_API();
        $this->gemini = new Marrison_Assistant_Gemini();
        $this->site_agent = new Marrison_Assistant_Site_Agent();
        $this->auth = new Marrison_Assistant_Auth();
        
        // Carica le opzioni di default
        $this->set_default_options();

        // Migrazione 1.4.0: sostituisce il vecchio messaggio di benvenuto generico
        // Aggiorna placeholder obsoleto
        $old_placeholders = array('Scrivi un messaggio...', 'Es: Via Roma 10', 'Es: Giella 29, oppure Via Roma 10');
        $cur_ph = get_option('marrison_assistant_site_agent_placeholder', '');
        if (in_array($cur_ph, $old_placeholders, true)) {
            update_option('marrison_assistant_site_agent_placeholder', 'Es: Condominio Primavera, oppure Via Roma 10');
        }

        $old_messages = array(
            'Ciao! Come posso aiutarti oggi?',
            'Ciao, sono {name}, il tuo assistente virtuale, come posso aiutarti?',
        );
        $current = get_option('marrison_assistant_site_agent_welcome', '');
        if (in_array($current, $old_messages, true)) {
            update_option(
                'marrison_assistant_site_agent_welcome',
                'Ciao! Sono {name}, il tuo assistente condominiale. Per iniziare una segnalazione, dimmi il nome o l\'indirizzo del tuo condominio.'
            );
        }
    }
    
    /**
     * Imposta le opzioni di default all'attivazione
     */
    private function set_default_options() {
        $default_options = array(
            'gemini_api_key' => '',
            'custom_prompt' => 'Sei un assistente AI per questo sito web. Rispondi in modo professionale e utile basandoti sui contenuti del sito.',
            'logged_only' => false,
            // Opzioni agente sito
            'enable_site_agent' => false,
            'site_agent_position' => 'bottom-right',
            'site_agent_color' => '#0073aa',
            'site_agent_title' => 'Assistente AI',
            'site_agent_name' => 'Assistente',
            'site_agent_welcome' => 'Ciao! Sono {name}, il tuo assistente condominiale. Dimmi il nome o l\'indirizzo del tuo condominio per iniziare una segnalazione.',
            'site_agent_placeholder' => 'Es: Condominio Primavera, oppure Via Roma 10',
            'site_agent_avatar'      => '',
            'site_agent_logged_only' => false,
            'condominio_admin_email' => '',
            // Colori personalizzabili
            'site_agent_icon_color' => '#667eea',      // Colore icona fluttuante
            'site_agent_header_color' => '#667eea',    // Colore testata chat
            'site_agent_button_color' => '#667eea',    // Colore pulsante invio
            // Risposte ai bottoni di categoria (personalizzabili per tipo di sito)
            'site_agent_response_products' => 'Perfetto! Dimmi cosa stai cercando tra i nostri prodotti.',
            'site_agent_response_orders'   => 'Certo! Dimmi il numero ordine o cosa vorresti sapere sul tuo acquisto.',
            'site_agent_response_info'     => 'Con piacere! Su cosa vorresti informazioni? Azienda, contatti, servizi?',
            'site_agent_response_events'   => 'Ottimo! Stai cercando un evento specifico o vuoi vedere il calendario?'
        );
        
        foreach ($default_options as $option => $value) {
            if (get_option('marrison_assistant_' . $option) === false) {
                update_option('marrison_assistant_' . $option, $value);
            }
        }
    }
    
    /**
     * Attivazione del plugin
     */
    public function activate() {
        $this->set_default_options();
        Marrison_Assistant_Requests::create_table();
        flush_rewrite_rules();
    }
    
    /**
     * Disattivazione del plugin
     */
    public function deactivate() {
        flush_rewrite_rules();
        wp_clear_scheduled_hook('marrison_assistant_auto_scan');
    }
    
    /**
     * Aggiunge il link alle impostazioni nella pagina dei plugin
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=marrison-assistant') . '">Impostazioni</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Ottiene le impostazioni del plugin
     */
    public static function get_settings() {
        return array(
            'gemini_api_key' => get_option('marrison_assistant_gemini_api_key'),
            'custom_prompt' => get_option('marrison_assistant_custom_prompt'),
            'last_content_scan' => get_option('marrison_assistant_last_content_scan')
        );
    }
}

// Inizializza il plugin
error_log('Marrison Assistant: Initializing main plugin class');
new Marrison_Assistant();

// Aggiorna la tabella DB se necessario (dopo aggiornamenti plugin)
add_action('plugins_loaded', function() {
    Marrison_Assistant_Requests::create_table();
});

// ── Token conferma intervento (nessun login richiesto) ──────────────────────
add_action('init', function() {
    if (!isset($_GET['marrison_confirm'])) return;
    $token = sanitize_text_field(wp_unslash($_GET['marrison_confirm']));
    if (!$token || strlen($token) < 10) return;

    $result = Marrison_Assistant_Requests::confirm_by_token($token);
    $site   = get_bloginfo('name');

    if (!$result) {
        $icon  = '✗';
        $color = '#ef4444';
        $bg    = '#fef2f2';
        $title = 'Link non valido';
        $msg   = 'Il link utilizzato non è valido o è già scaduto.';
    } elseif (!empty($result->_already)) {
        $icon  = '✓';
        $color = '#f59e0b';
        $bg    = '#fffbeb';
        $title = 'Intervento già confermato';
        $msg   = 'Questo intervento risulta già confermato come completato.';
    } else {
        $icon  = '✓';
        $color = '#22c55e';
        $bg    = '#f0fdf4';
        $title = 'Intervento completato';
        $msg   = 'Grazie! L\'intervento per il condominio <strong>' . esc_html($result->condominio_name) . '</strong> è stato confermato come completato.';

        // ── Notifiche di chiusura intervento ────────────────────────────────
        Marrison_Assistant_Requests::send_completion_emails($result);
    }

    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo esc_html($title . ' — ' . $site); ?></title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                   background: #f8fafc; display: flex; align-items: center; justify-content: center;
                   min-height: 100vh; padding: 24px; }
            .card { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.10);
                    max-width: 480px; width: 100%; padding: 40px 36px; text-align: center; }
            .icon { width: 72px; height: 72px; border-radius: 50%; background: <?php echo $bg; ?>;
                    color: <?php echo $color; ?>; font-size: 36px; line-height: 72px;
                    margin: 0 auto 24px; border: 3px solid <?php echo $color; ?>; }
            h1 { font-size: 22px; color: #1e293b; margin-bottom: 12px; }
            p  { font-size: 15px; color: #475569; line-height: 1.6; }
            .site { margin-top: 32px; font-size: 12px; color: #94a3b8; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon"><?php echo $icon; ?></div>
            <h1><?php echo esc_html($title); ?></h1>
            <p><?php echo $msg; ?></p>
            <p class="site"><?php echo esc_html($site); ?></p>
        </div>
    </body>
    </html>
    <?php
    exit;
});


