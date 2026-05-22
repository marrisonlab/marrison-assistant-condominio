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
            // SMS provider + credenziali
            'marrison_sms_provider',
            'marrison_aruba_email',
            'marrison_aruba_password',
            'marrison_aruba_sender',
            'marrison_smstools_client_id',
            'marrison_smstools_client_secret',
            'marrison_smstools_sender',
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

        /* ── Save ── */
        .ma-save { padding:16px 0 4px; }
        </style>

        <!-- Top bar -->
        <div id="ma-topbar">
            <h1>MA Condominio</h1>
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

        <form method="post" action="options.php">
            <?php settings_fields('marrison_assistant_settings'); ?>

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
                        <div class="maf">
                            <label>Avatar assistente</label>
                            <?php $avatar_url = get_option('marrison_assistant_site_agent_avatar', ''); ?>
                            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                                <img id="ma-avatar-preview"
                                     src="<?php echo esc_url($avatar_url); ?>"
                                     alt=""
                                     style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;background:#f1f5f9;<?php echo $avatar_url ? '' : 'display:none;'; ?>">
                                <div style="display:flex;flex-direction:column;gap:6px;">
                                    <input type="hidden" id="marrison_assistant_site_agent_avatar"
                                           name="marrison_assistant_site_agent_avatar"
                                           value="<?php echo esc_attr($avatar_url); ?>">
                                    <button type="button" id="ma-avatar-upload-btn" class="button">Scegli immagine</button>
                                    <button type="button" id="ma-avatar-remove-btn" class="button button-link-delete"
                                            style="<?php echo $avatar_url ? '' : 'display:none;'; ?>">Rimuovi</button>
                                </div>
                            </div>
                            <p class="description" style="margin:4px 0 0;font-size:12px;color:#666;">Foto o logo che appare nell'intestazione della chat. Consigliato: immagine quadrata, min 120×120px.</p>
                        </div>
                        <div class="maf">
                            <label for="marrison_assistant_condominio_admin_email">Email amministratore condominio</label>
                            <input type="email" id="marrison_assistant_condominio_admin_email"
                                   name="marrison_assistant_condominio_admin_email"
                                   placeholder="amministratore@esempio.it"
                                   value="<?php echo esc_attr(get_option('marrison_assistant_condominio_admin_email','')); ?>">
                            <p class="description" style="margin:4px 0 0;font-size:12px;color:#666;">Le segnalazioni vengono inoltrate a questo indirizzo. Se vuoto, viene usato l'email admin WordPress.</p>
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

            <!-- Sezione SMS -->
            <?php $sms_provider = get_option('marrison_sms_provider', 'aruba'); ?>
            <div class="ma-row" style="margin-top:20px;">
                <div class="ma-card" style="flex:1;">
                    <div class="ma-card-title">
                        <span class="dashicons dashicons-phone"></span>
                        <strong>Notifiche SMS</strong>
                    </div>

                    <table class="form-table" style="margin:0 0 12px;">
                        <tr>
                            <th style="width:160px;"><label for="marrison_sms_provider">Provider SMS</label></th>
                            <td>
                                <select id="marrison_sms_provider" name="marrison_sms_provider" onchange="maToggleSmsProvider(this.value)">
                                    <option value="aruba"    <?php selected($sms_provider,'aruba'); ?>>Aruba SMS</option>
                                    <option value="smstools" <?php selected($sms_provider,'smstools'); ?>>SMS Tools</option>
                                </select>
                                <p class="description">L'SMS viene inviato solo ai fornitori con lo switch <em>abilita_sms</em> attivo.</p>
                            </td>
                        </tr>
                    </table>

                    <!-- Credenziali Aruba -->
                    <div id="ma-sms-aruba" style="<?php echo $sms_provider === 'smstools' ? 'display:none;' : ''; ?>">
                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th style="width:160px;"><label for="marrison_aruba_email">Email account</label></th>
                                <td><input type="text" id="marrison_aruba_email" name="marrison_aruba_email"
                                           value="<?php echo esc_attr(get_option('marrison_aruba_email','')); ?>"
                                           class="regular-text" placeholder="email@esempio.it"></td>
                            </tr>
                            <tr>
                                <th><label for="marrison_aruba_password">Password API</label></th>
                                <td><input type="password" id="marrison_aruba_password" name="marrison_aruba_password"
                                           value="<?php echo esc_attr(get_option('marrison_aruba_password','')); ?>"
                                           class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="marrison_aruba_sender">Mittente</label></th>
                                <td>
                                    <input type="text" id="marrison_aruba_sender" name="marrison_aruba_sender"
                                           value="<?php echo esc_attr(get_option('marrison_aruba_sender','')); ?>"
                                           class="regular-text" maxlength="11" placeholder="Es: Segnalaz">
                                    <p class="description">Opzionale. Max 11 caratteri alfanumerici.</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Credenziali SMS Tools -->
                    <div id="ma-sms-smstools" style="<?php echo $sms_provider === 'aruba' ? 'display:none;' : ''; ?>">
                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th style="width:160px;"><label for="marrison_smstools_client_id">Client ID (API Key)</label></th>
                                <td><input type="text" id="marrison_smstools_client_id" name="marrison_smstools_client_id"
                                           value="<?php echo esc_attr(get_option('marrison_smstools_client_id','')); ?>"
                                           class="regular-text" placeholder="X-Client-Id"></td>
                            </tr>
                            <tr>
                                <th><label for="marrison_smstools_client_secret">Client Secret</label></th>
                                <td><input type="password" id="marrison_smstools_client_secret" name="marrison_smstools_client_secret"
                                           value="<?php echo esc_attr(get_option('marrison_smstools_client_secret','')); ?>"
                                           class="regular-text" placeholder="X-Client-Secret"></td>
                            </tr>
                            <tr>
                                <th><label for="marrison_smstools_sender">Mittente</label></th>
                                <td>
                                    <input type="text" id="marrison_smstools_sender" name="marrison_smstools_sender"
                                           value="<?php echo esc_attr(get_option('marrison_smstools_sender','')); ?>"
                                           class="regular-text" maxlength="11" placeholder="Es: Segnalaz">
                                    <p class="description">Max 11 caratteri alfanumerici.</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                </div>
            </div>

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

        // ── Avatar media picker ──────────────────────────────────────────
        var avatarFrame;
        $('#ma-avatar-upload-btn').on('click', function(e) {
            e.preventDefault();
            if (avatarFrame) { avatarFrame.open(); return; }
            avatarFrame = wp.media({
                title:    'Scegli avatar assistente',
                button:   { text: 'Usa questa immagine' },
                multiple: false,
                library:  { type: 'image' },
            });
            avatarFrame.on('select', function() {
                var att = avatarFrame.state().get('selection').first().toJSON();
                var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                $('#marrison_assistant_site_agent_avatar').val(url);
                $('#ma-avatar-preview').attr('src', url).show();
                $('#ma-avatar-remove-btn').show();
            });
            avatarFrame.open();
        });
        $('#ma-avatar-remove-btn').on('click', function(e) {
            e.preventDefault();
            $('#marrison_assistant_site_agent_avatar').val('');
            $('#ma-avatar-preview').attr('src', '').hide();
            $(this).hide();
        });
    });

    function maToggleSmsProvider(val) {
        document.getElementById('ma-sms-aruba').style.display    = (val === 'aruba')    ? '' : 'none';
        document.getElementById('ma-sms-smstools').style.display = (val === 'smstools') ? '' : 'none';
    }
    </script>
<?php
});

/* end of file */
