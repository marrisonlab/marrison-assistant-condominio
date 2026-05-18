<?php
/**
 * Pannello di controllo principale del plugin (singola pagina, no tab)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marrison_Assistant_Main_Page {

    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('in_admin_header', array($this, 'suppress_foreign_notices'));
    }

    /**
     * Rimuove le notifiche WP di terze parti (es. Elementor) dalla pagina del plugin
     */
    public function suppress_foreign_notices() {
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'marrison-assistant') !== false) {
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');
            remove_all_actions('network_admin_notices');
        }
    }

    /**
     * Registra tutte le impostazioni sotto un unico gruppo
     */
    public function register_settings() {
        $all = array(
            'marrison_assistant_gemini_api_key',
            'marrison_assistant_custom_prompt',
            'marrison_assistant_enable_site_agent',
            'marrison_assistant_site_agent_logged_only',
            'marrison_assistant_site_agent_position',
            'marrison_assistant_site_agent_title',
            'marrison_assistant_site_agent_name',
            'marrison_assistant_site_agent_welcome',
            'marrison_assistant_site_agent_placeholder',
            'marrison_assistant_site_agent_icon_color',
            'marrison_assistant_site_agent_header_color',
            'marrison_assistant_site_agent_button_color',
            'marrison_assistant_site_agent_response_products',
            'marrison_assistant_site_agent_response_orders',
            'marrison_assistant_site_agent_response_info',
            'marrison_assistant_site_agent_response_events',
            'marrison_assistant_enable_custom_prompt',
            // NOTA: marrison_assistant_gemini_api_key rimosso — API key gestita dal Commander
        );
        foreach ($all as $opt) {
            register_setting('marrison_assistant_panel', $opt);
        }
    }
    
    /**
     * Renderizza il pannello di controllo unico
     */
    public function render() {
        // ── Stato Servizio AI ──────────────────────────────────────────
        $cmd_transient = get_transient('marrison_commander_online');
        if ($cmd_transient === false) {
            $resp = wp_remote_get('https://marrisonlab.com/wp-json/marrison-commander/v1/', array(
                'timeout' => 4, 'sslverify' => false,
            ));
            $cmd_transient = (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) < 500) ? 'yes' : 'no';
            set_transient('marrison_commander_online', $cmd_transient, 5 * MINUTE_IN_SECONDS);
        }
        $cmd_ok = ($cmd_transient === 'yes');

        // ── Stato collegamento sito (aggiornato dai response reali di call_commander) ──
        $site_connected = get_transient('marrison_site_connected'); // 'yes' | 'no' | false
        // false = mai testato (nessuna chiamata AI ancora effettuata)

        $agent_on  = (bool) get_option('marrison_assistant_enable_site_agent');
        $last_scan = get_option('marrison_assistant_last_content_scan');

        // Colori LED
        $led_cmd  = $cmd_ok  ? 'led-green' : 'led-red';
        $led_site = ($site_connected === 'yes') ? 'led-green' : (($site_connected === 'no') ? 'led-red' : 'led-yellow');
        $led_wid  = $agent_on ? 'led-green' : 'led-grey';

        $txt_cmd  = $cmd_ok  ? 'Online' : 'Non raggiungibile';
        $txt_site = ($site_connected === 'yes') ? 'Collegato' : (($site_connected === 'no') ? 'Non collegato' : 'Non verificato');
        $txt_wid  = $agent_on ? 'Attivo' : 'Disattivo';

        $prompt_enabled = (bool) get_option('marrison_assistant_enable_custom_prompt', 0);
        ?>
        <div class="wrap" id="ma-panel">

        <style>
        #ma-panel { max-width:980px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }

        /* ── Top bar ── */
        #ma-topbar { display:flex; align-items:center; justify-content:space-between; padding:16px 0 20px; border-bottom:1px solid #dcdcde; margin-bottom:24px; }
        #ma-topbar h1 { margin:0; padding:0; font-size:20px; font-weight:700; color:#1d2327; }
        #ma-status-group { display:flex; gap:8px; flex-wrap:wrap; }
        .ma-pill { display:inline-flex; align-items:center; gap:7px; padding:6px 14px; border-radius:100px; background:#f6f7f7; border:1px solid #dcdcde; font-size:12px; font-weight:600; color:#3c434a; }
        .ma-pill .led { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
        .led-green  { background:#00a32a; box-shadow:0 0 0 3px #00a32a22; }
        .led-red    { background:#d63638; box-shadow:0 0 0 3px #d6363822; }
        .led-yellow { background:#dba617; box-shadow:0 0 0 3px #dba61722; }
        .led-grey   { background:#8c8f94; }

        /* ── Cards ── */
        .ma-section { margin-bottom:20px; }
        .ma-row { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
        .ma-row.thirds { grid-template-columns:1fr 1fr 1fr; }
        @media(max-width:780px){ .ma-row,.ma-row.thirds { grid-template-columns:1fr; } }
        .ma-card { background:#fff; border:1px solid #dcdcde; border-radius:8px; overflow:hidden; }
        .ma-card-title { display:flex; align-items:center; gap:9px; padding:13px 18px; background:#f6f7f7; border-bottom:1px solid #dcdcde; }
        .ma-card-title .dashicons { font-size:16px; width:16px; height:16px; color:#50575e; flex-shrink:0; }
        .ma-card-title strong { font-size:13px; color:#1d2327; }
        .ma-card-body { padding:18px; }

        /* ── Form fields ── */
        .maf { margin-bottom:14px; }
        .maf:last-child { margin-bottom:0; }
        .maf > label { display:block; font-size:12px; font-weight:600; color:#50575e; margin-bottom:5px; letter-spacing:.02em; }
        .maf input[type=text],.maf input[type=url],.maf input[type=password],.maf textarea,.maf select { width:100%; box-sizing:border-box; }
        .maf .hint { font-size:11px; color:#8c8f94; margin-top:4px; line-height:1.5; }

        /* ── Toggles ── */
        .ma-toggle { display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid #f0f0f1; }
        .ma-toggle:last-of-type { border-bottom:none; }
        .ma-toggle label { font-size:13px; color:#1d2327; flex:1; cursor:pointer; margin:0; }

        /* ── Color strip ── */
        .ma-colors { display:flex; gap:0; }
        .ma-color-item { flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; padding:12px 8px; border-right:1px solid #f0f0f1; }
        .ma-color-item:last-child { border-right:none; }
        .ma-color-item span { font-size:11px; color:#50575e; text-align:center; }
        .ma-color-item input[type=color] { width:44px; height:36px; padding:2px; border:1px solid #c3c4c7; border-radius:6px; cursor:pointer; }

        /* ── Category responses ── */
        .ma-cat-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:600px){ .ma-cat-grid { grid-template-columns:1fr; } }
        .ma-cat-item label { font-size:12px; font-weight:600; color:#50575e; display:block; margin-bottom:4px; }
        .ma-cat-item input { width:100%; box-sizing:border-box; }

        /* ── Scan bar ── */
        .ma-scan-row { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
        .ma-scan-meta { font-size:12px; color:#50575e; line-height:1.7; }
        .ma-scan-meta strong { color:#1d2327; }

        /* ── Save ── */
        .ma-save { padding:16px 0 4px; }
        </style>

        <!-- Top bar -->
        <div id="ma-topbar">
            <h1><?php echo esc_html(Marrison_Assistant_White_Label::plugin_name()); ?></h1>
            <div id="ma-status-group">
                <span class="ma-pill"><span class="led <?php echo $led_cmd; ?>"></span>Servizio: <?php echo $txt_cmd; ?></span>
                <span class="ma-pill"><span class="led <?php echo $led_site; ?>"></span>Sito: <?php echo $txt_site; ?></span>
                <span class="ma-pill"><span class="led <?php echo $led_wid; ?>"></span>Widget: <?php echo $txt_wid; ?></span>
            </div>
        </div>

        <?php if ($site_connected === false && $cmd_ok): ?>
        <div class="notice notice-info inline" style="margin-bottom:20px;"><p>
            <strong>Connessione non ancora verificata.</strong>
            Lo stato si aggiornerà automaticamente dopo la prima conversazione dell'assistente con un utente.
        </p></div>
        <?php elseif ($site_connected === 'no'): ?>
        <div class="notice notice-error inline" style="margin-bottom:20px;"><p>
            <strong>Sito non collegato al servizio AI.</strong>
            Contatta il supporto per verificare che questo sito sia autorizzato.
        </p></div>
        <?php endif; ?>

        <!-- Scansione contenuti — in cima, fuori dal form -->
        <div class="ma-card ma-section" style="margin-bottom:28px;">
            <div class="ma-card-title">
                <span class="dashicons dashicons-search"></span>
                <strong>Scansione Contenuti Sito</strong>
            </div>
            <div class="ma-card-body">
                <div class="ma-scan-row">
                    <button type="button" id="scan-content-btn" class="button button-primary">
                        <span class="dashicons dashicons-update" style="vertical-align:text-bottom;margin-right:4px;"></span>Scansiona ora
                    </button>
                    <span id="scan-status"></span>
                    <div class="ma-scan-meta">
                        Ultima scansione: <strong><?php echo $last_scan ? date_i18n('d/m/Y H:i', $last_scan) : '—'; ?></strong>
                        &nbsp;·&nbsp;
                        Prossima automatica: <strong><?php
                            $next = wp_next_scheduled('marrison_assistant_auto_scan');
                            echo $next ? 'tra ' . human_time_diff(time(), $next) : 'N/D';
                        ?></strong>
                    </div>
                </div>
                <div id="scan-results" style="display:none;margin-top:14px;">
                    <div id="scan-details"></div>
                </div>
            </div>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields('marrison_assistant_panel'); ?>

            <!-- Riga 1: Impostazioni Widget -->
            <div class="ma-row">

                <!-- Widget -->
                <div class="ma-card">
                    <div class="ma-card-title">
                        <span class="dashicons dashicons-format-chat"></span>
                        <strong>Impostazioni Chat</strong>
                    </div>
                    <div class="ma-card-body">
                        <div style="background:#f0f6fc;border:1px solid #c3d9f0;border-radius:8px;padding:12px 14px;margin-bottom:14px;">
                            <strong style="display:block;margin-bottom:6px;">📌 Shortcode</strong>
                            <p style="margin:0 0 8px;font-size:13px;">Inserisci la chat in qualsiasi pagina (Gutenberg, Elementor, ecc.) usando lo shortcode:</p>
                            <code style="display:block;background:#fff;border:1px solid #d0d7de;border-radius:4px;padding:6px 10px;font-size:13px;user-select:all;">[marrison_chat]</code>
                            <p style="margin:8px 0 0;font-size:12px;color:#555;">Attributi opzionali: <code>height="520px"</code> &nbsp; <code>width="100%"</code></p>
                        </div>
                        <div class="ma-toggle" style="margin-bottom:14px;">
                            <input type="checkbox" id="marrison_assistant_site_agent_logged_only"
                                   name="marrison_assistant_site_agent_logged_only" value="1"
                                   <?php checked(get_option('marrison_assistant_site_agent_logged_only'), 1); ?>>
                            <label for="marrison_assistant_site_agent_logged_only">Solo utenti registrati</label>
                        </div>
                        <div class="maf">
                            <label for="marrison_assistant_site_agent_title">Titolo finestra</label>
                            <input type="text" id="marrison_assistant_site_agent_title"
                                   name="marrison_assistant_site_agent_title"
                                   value="<?php echo esc_attr(get_option('marrison_assistant_site_agent_title','Assistente AI')); ?>">
                        </div>
                        <div class="maf">
                            <label for="marrison_assistant_site_agent_name">Nome assistente</label>
                            <input type="text" id="marrison_assistant_site_agent_name"
                                   name="marrison_assistant_site_agent_name"
                                   value="<?php echo esc_attr(get_option('marrison_assistant_site_agent_name','Marry')); ?>">
                        </div>
                    </div>
                </div>

            </div><!-- /ma-row -->

            <!-- Riga 2: Colori -->
            <div class="ma-row">

                <!-- Colori -->
                <div class="ma-card">
                    <div class="ma-card-title">
                        <span class="dashicons dashicons-art"></span>
                        <strong>Colori Chat</strong>
                    </div>
                    <div class="ma-colors">
                        <div class="ma-color-item">
                            <span>Accento<br>chat</span>
                            <input type="color" name="marrison_assistant_site_agent_icon_color"
                                   value="<?php echo esc_attr(get_option('marrison_assistant_site_agent_icon_color','#667eea')); ?>">
                        </div>
                        <div class="ma-color-item">
                            <span>Testata<br>chat</span>
                            <input type="color" name="marrison_assistant_site_agent_header_color"
                                   value="<?php echo esc_attr(get_option('marrison_assistant_site_agent_header_color','#667eea')); ?>">
                        </div>
                        <div class="ma-color-item">
                            <span>Pulsante<br>invio</span>
                            <input type="color" name="marrison_assistant_site_agent_button_color"
                                   value="<?php echo esc_attr(get_option('marrison_assistant_site_agent_button_color','#667eea')); ?>">
                        </div>
                    </div>
                </div>

            </div><!-- /ma-row -->

            <div class="ma-save">
                <?php submit_button('Salva impostazioni', 'primary', 'submit', false); ?>
            </div>

        </form>

        </div><!-- /wrap #ma-panel -->
        <?php
    }

}

// JavaScript per la scansione contenuti
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'marrison-assistant') === false) return;
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Toggle prompt personalizzato
        var $promptToggle   = $('#marrison_assistant_enable_custom_prompt');
        var $promptTextarea = $('#marrison_assistant_custom_prompt');

        $promptToggle.on('change', function() {
            if (this.checked) {
                var confirmed = window.confirm(
                    '⚠️ Attenzione\n\n' +
                    'Un prompt personalizzato aggiunge testo extra ad ogni richiesta, ' +
                    'aumentando il consumo di token.\n\n' +
                    'Questo può ridurre il numero di conversazioni disponibili nel tuo piano.\n\n' +
                    'Vuoi abilitarlo comunque?'
                );
                if (!confirmed) {
                    this.checked = false;
                    return;
                }
            }
            var isEnabled = this.checked;
            $promptTextarea.prop('disabled', !isEnabled).css('opacity', isEnabled ? '1' : '.45');
        });

        $('#scan-content-btn').on('click', function() {
            var $btn    = $(this);
            var $status = $('#scan-status');
            var $res    = $('#scan-results');
            $btn.prop('disabled', true);
            $status.html('<span style="color:#f0b429;">⏳ Scansione in corso…</span>');
            $res.hide();
            $.ajax({
                url: ajaxurl, method: 'POST',
                data: { action: 'marrison_scan_site_content', nonce: '<?php echo wp_create_nonce('marrison_nonce'); ?>' },
                success: function(r) {
                    if (r.success) {
                        $status.html('<span style="color:#00a32a;">✔ Completata</span>');
                        $('#scan-details').html(r.data);
                        $res.show();
                        setTimeout(function(){ location.reload(); }, 2000);
                    } else {
                        $status.html('<span style="color:#d63638;">✘ ' + r.data + '</span>');
                        $btn.prop('disabled', false);
                    }
                },
                error: function(xhr) {
                    $status.html('<span style="color:#d63638;">✘ Errore di connessione</span>');
                    $btn.prop('disabled', false);
                }
            });
        });
    });
    </script>
    <?php
});

/* end of file */
