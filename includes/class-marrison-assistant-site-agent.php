<?php
/**
 * Classe per l'agente AI sul sito (chat widget)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marrison_Assistant_Site_Agent {
    
    public function __construct() {
        add_shortcode('marrison_chat', array($this, 'render_shortcode'));
        // Nuovo endpoint step-based per il flusso condominiale
        add_action('wp_ajax_marrison_condominium_step',        array($this, 'handle_condominium_step'));
        add_action('wp_ajax_nopriv_marrison_condominium_step', array($this, 'handle_condominium_step'));
        // Endpoint legacy (conservato per compatibilità)
        add_action('wp_ajax_marrison_site_agent_chat',        array($this, 'handle_chat_request'));
        add_action('wp_ajax_nopriv_marrison_site_agent_chat', array($this, 'handle_chat_request'));
        add_action('wp_ajax_marrison_site_agent_ping',        array($this, 'handle_ping'));
        add_action('wp_ajax_nopriv_marrison_site_agent_ping', array($this, 'handle_ping'));
        add_action('wp_ajax_marrison_site_agent_track',       array($this, 'handle_track'));
        add_action('wp_ajax_nopriv_marrison_site_agent_track',array($this, 'handle_track'));
        add_action('wp_ajax_marrison_upload_photo',        array($this, 'handle_photo_upload'));
        add_action('wp_ajax_nopriv_marrison_upload_photo', array($this, 'handle_photo_upload'));
    }

    /**
     * Gestisce il flusso conversazionale step-based per le segnalazioni condominiali.
     *
     * Step previsti:
     *   find_condominio   → ricerca per nome/indirizzo
     *   select_condominio → selezione da lista (tramite bottoni)
     *   confirm_condominio→ conferma singolo risultato
     *   describe_problem  → descrizione problema + classificazione AI
     *   collect_email     → raccolta email condòmino + invio email
     */
    public function handle_condominium_step() {
        check_ajax_referer('marrison_agent_nonce', 'nonce');

        $rl = $this->check_rate_limit();
        if ($rl !== true) {
            wp_send_json_error(array('code' => 'rate_limited', 'message' => $rl['message'], 'wait' => $rl['wait']));
        }

        $step    = sanitize_text_field(isset($_POST['step'])    ? $_POST['step']    : 'find_condominio');
        $input   = sanitize_textarea_field(isset($_POST['input']) ? $_POST['input'] : '');
        $context = json_decode(stripslashes(isset($_POST['context']) ? $_POST['context'] : '{}'), true);
        if (!is_array($context)) $context = array();

        $logged_only = get_option('marrison_assistant_site_agent_logged_only', false);
        if ($logged_only && !is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Accesso riservato agli utenti registrati.'));
        }

        $condo = new Marrison_Assistant_Condominio();

        switch ($step) {

            // ── Passo 1: ricerca condominio ──────────────────────────────────
            case 'find_condominio':
                if (empty(trim($input))) {
                    wp_send_json_success(array(
                        'message'   => 'Inserisci il nome o l\'indirizzo del tuo condominio.',
                        'next_step' => 'find_condominio',
                    ));
                    break;
                }
                $results = $condo->search($input);
                if (empty($results)) {
                    wp_send_json_success(array(
                        'message'   => 'Non ho trovato condomini con questo nome o indirizzo. Prova con un termine diverso.',
                        'next_step' => 'find_condominio',
                    ));
                } elseif (count($results) === 1) {
                    $r = $results[0];
                    wp_send_json_success(array(
                        'message'      => 'Ho trovato: <strong>' . esc_html($r['label']) . '</strong>. È il tuo condominio?',
                        'next_step'    => 'confirm_condominio',
                        'condominio_id' => $r['id'],
                        'options'      => array(
                            array('value' => 'si',  'label' => '✅ Sì, corretto'),
                            array('value' => 'no',  'label' => '❌ No, cerca ancora'),
                        ),
                    ));
                } else {
                    $options = array_map(function ($r) {
                        return array('value' => 'select_' . $r['id'], 'label' => $r['label']);
                    }, $results);
                    wp_send_json_success(array(
                        'message'   => 'Ho trovato ' . count($results) . ' condomini. Seleziona il tuo:',
                        'next_step' => 'select_condominio',
                        'options'   => $options,
                    ));
                }
                break;

            // ── Passo 1b: selezione da lista ─────────────────────────────────
            case 'select_condominio':
                if (preg_match('/^select_(\d+)$/', $input, $m)) {
                    $cid  = (int) $m[1];
                    $post = get_post($cid);
                    $addr = get_post_meta($cid, 'indirizzo_condominio', true);
                    $label = $addr ? 'Condominio di ' . $addr : ($post ? $post->post_title : 'Condominio #' . $cid);
                    wp_send_json_success(array(
                        'message'      => 'Selezionato: <strong>' . esc_html($label) . '</strong>.<br>Descrivi il problema che hai riscontrato.',
                        'next_step'    => 'describe_problem',
                        'condominio_id' => $cid,
                    ));
                } else {
                    wp_send_json_success(array(
                        'message'   => 'Capito. Inserisci il nome o l\'indirizzo del tuo condominio.',
                        'next_step' => 'find_condominio',
                        'reset'     => true,
                    ));
                }
                break;

            // ── Passo 1c: conferma singolo risultato ─────────────────────────
            case 'confirm_condominio':
                $positive = preg_match('/\b(si|sì|yes|ok|esatto|corretto|giusto|confermo)\b/i', $input);
                if ($positive) {
                    $cid = (int) ($context['condominioId'] ?? 0);
                    wp_send_json_success(array(
                        'message'      => 'Perfetto! Descrivi il problema che hai riscontrato nel condominio.',
                        'next_step'    => 'describe_problem',
                        'condominio_id' => $cid,
                    ));
                } else {
                    wp_send_json_success(array(
                        'message'   => 'Capito. Inserisci il nome o l\'indirizzo del tuo condominio.',
                        'next_step' => 'find_condominio',
                        'reset'     => true,
                    ));
                }
                break;

            // ── Passo 2: classificazione problema ───────────────────────────
            case 'describe_problem':
                $cid = (int) ($context['condominioId'] ?? 0);
                if (!$cid) {
                    wp_send_json_success(array(
                        'message'   => 'Si è verificato un errore. Ricominciamo: qual è il tuo condominio?',
                        'next_step' => 'find_condominio',
                        'reset'     => true,
                    ));
                    break;
                }
                $fornitori = $condo->get_fornitori($cid);
                if (empty($fornitori)) {
                    wp_send_json_success(array(
                        'message'       => 'Non ho trovato fornitori associati a questo condominio.<br>Posso comunque inoltrarla all\'amministratore. Vuoi allegare delle foto?',
                        'next_step'     => 'upload_photos',
                        'photo_upload'  => true,
                        'admin_only'    => true,
                        'condominio_id' => $cid,
                        'problema'      => $input,
                    ));
                    break;
                }
                // Se c'è un solo fornitore, saltare l'AI e usarlo direttamente
                if (count($fornitori) === 1) {
                    $fornitore = $fornitori[0];
                } else {
                    $fornitore = $condo->classify_problem($input, $fornitori);
                }
                // AI chiede un chiarimento prima di decidere
                if (is_array($fornitore) && isset($fornitore['question'])) {
                    wp_send_json_success(array(
                        'message'      => $fornitore['question'],
                        'next_step'    => 'clarify_problem',
                        'problema'     => $input,
                        'condominio_id' => $cid,
                    ));
                    break;
                }
                if (!$fornitore) {
                    $options = array_map(function ($f) {
                        $lbl = $f['nome'];
                        if (!empty($f['tipologia'])) $lbl .= ' (' . $f['tipologia'] . ')';
                        return array('value' => 'select_forn_' . $f['id'], 'label' => $lbl);
                    }, $fornitori);
                    $options[] = array('value' => 'admin_only', 'label' => '📋 Invia solo all\'amministratore');
                    $options[] = array('value' => 'cancel',     'label' => '❌ Annulla segnalazione');
                    wp_send_json_success(array(
                        'message'       => 'Non riesco a identificare automaticamente il fornitore adatto. Scegli tu dalla lista:',
                        'next_step'     => 'manual_select_fornitore',
                        'options'       => $options,
                        'condominio_id' => $cid,
                        'problema'      => $input,
                    ));
                    break;
                }
                $ai_used = count($fornitori) > 1;
                $msg = $ai_used
                    ? 'Ho analizzato il problema e identificato il fornitore più adatto: <strong>' . esc_html($fornitore['nome']) . '</strong>'
                    : 'Il fornitore associato a questo condominio è: <strong>' . esc_html($fornitore['nome']) . '</strong>';
                $role_label = !empty($fornitore['matched_role']) ? $fornitore['matched_role'] : ($fornitore['tipologia'] ?? '');
                if ($role_label) $msg .= ' (' . esc_html($role_label) . ')';
                $msg .= '<br><br>Vuoi procedere con la segnalazione?';
                wp_send_json_success(array(
                    'message'      => $msg,
                    'next_step'    => 'confirm_fornitore',
                    'fornitore_id' => $fornitore['id'],
                    'problema'     => $input,
                    'condominio_id' => $cid,
                    'options'      => array(
                        array('value' => 'confirm',    'label' => '✅ Sì, procedi con il fornitore'),
                        array('value' => 'admin_only', 'label' => '📋 Invia solo all\'amministratore'),
                        array('value' => 'reject',     'label' => '🔄 Fornitore sbagliato'),
                        array('value' => 'cancel',     'label' => '❌ Annulla segnalazione'),
                    ),
                ));
                break;

            // ── Passo 2c: chiarimento richiesto dall'AI ──────────────────────────
            case 'clarify_problem':
                $cid          = (int) ($context['condominioId'] ?? 0);
                $prob_orig    = sanitize_textarea_field($context['problema'] ?? '');
                $prob_combined = $prob_orig . ' (chiarimento: ' . $input . ')';
                $fornitori    = $condo->get_fornitori($cid);
                // Secondo tentativo: nessuna ulteriore domanda consentita
                $fornitore = $condo->classify_problem($prob_combined, $fornitori, true);
                if (!$fornitore || (is_array($fornitore) && isset($fornitore['question']))) {
                    $options = array_map(function ($f) {
                        $lbl = $f['nome'];
                        if (!empty($f['tipologia'])) $lbl .= ' (' . $f['tipologia'] . ')';
                        return array('value' => 'select_forn_' . $f['id'], 'label' => $lbl);
                    }, $fornitori);
                    $options[] = array('value' => 'admin_only', 'label' => '📋 Invia solo all\'amministratore');
                    $options[] = array('value' => 'cancel',     'label' => '❌ Annulla segnalazione');
                    wp_send_json_success(array(
                        'message'      => 'Non riesco ancora a identificare il fornitore adatto. Scegli tu dalla lista:',
                        'next_step'    => 'manual_select_fornitore',
                        'options'      => $options,
                        'condominio_id' => $cid,
                        'problema'     => $prob_combined,
                    ));
                    break;
                }
                $msg = 'Ho analizzato il problema e identificato il fornitore più adatto: <strong>' . esc_html($fornitore['nome']) . '</strong>';
                $role_label = !empty($fornitore['matched_role']) ? $fornitore['matched_role'] : ($fornitore['tipologia'] ?? '');
                if ($role_label) $msg .= ' (' . esc_html($role_label) . ')';
                $msg .= '<br><br>Vuoi procedere con la segnalazione?';
                wp_send_json_success(array(
                    'message'      => $msg,
                    'next_step'    => 'confirm_fornitore',
                    'fornitore_id' => $fornitore['id'],
                    'problema'     => $prob_combined,
                    'condominio_id' => $cid,
                    'options'      => array(
                        array('value' => 'confirm',    'label' => '✅ Sì, procedi con il fornitore'),
                        array('value' => 'admin_only', 'label' => '📋 Invia solo all\'amministratore'),
                        array('value' => 'reject',     'label' => '🔄 Fornitore sbagliato'),
                        array('value' => 'cancel',     'label' => '❌ Annulla segnalazione'),
                    ),
                ));
                break;

            // ── Passo 2b: conferma fornitore identificato da AI ───────────────────
            case 'confirm_fornitore':
                $cid  = (int) ($context['condominioId'] ?? 0);
                $fid  = (int) ($context['fornitoreId']  ?? 0);
                $prob = sanitize_textarea_field($context['problema'] ?? '');

                if ($input === 'admin_only') {
                    wp_send_json_success(array(
                        'message'       => 'Perfetto! La segnalazione verrà inoltrata all\'amministratore. Vuoi allegare delle foto?',
                        'next_step'     => 'upload_photos',
                        'photo_upload'  => true,
                        'admin_only'    => true,
                        'condominio_id' => $cid,
                        'problema'      => $prob,
                    ));

                } elseif ($input === 'confirm' || preg_match('/\b(si|sì|yes|ok|confermo|esatto|giusto|corretto|procedi)\b/i', $input)) {
                    wp_send_json_success(array(
                        'message'        => 'Perfetto! Vuoi allegare delle foto alla segnalazione? Puoi aggiungerne fino a 5 (max 5MB ciascuna).',
                        'next_step'      => 'upload_photos',
                        'photo_upload'   => true,
                        'condominio_id'  => $cid,
                        'fornitore_id'   => $fid,
                        'problema'       => $prob,
                    ));

                } elseif ($input === 'reject' || preg_match('/\b(no|sbagliato|errato|altro|cambia|diverso|cambiare)\b/i', $input)) {
                    $fornitori = $condo->get_fornitori($cid);
                    $options   = array_map(function ($f) {
                        $lbl = $f['nome'];
                        if (!empty($f['tipologia'])) $lbl .= ' (' . $f['tipologia'] . ')';
                        return array('value' => 'select_forn_' . $f['id'], 'label' => $lbl);
                    }, $fornitori);
                    $options[] = array('value' => 'admin_only', 'label' => '📋 Invia solo all\'amministratore');
                    $options[] = array('value' => 'cancel',     'label' => '❌ Annulla segnalazione');
                    wp_send_json_success(array(
                        'message'   => 'Scegli tu il fornitore corretto:',
                        'next_step' => 'manual_select_fornitore',
                        'options'   => $options,
                        'condominio_id' => $cid,
                        'problema'      => $prob,
                    ));

                } else {
                    wp_send_json_success(array(
                        'message'   => 'Segnalazione annullata. Puoi iniziare una nuova segnalazione dicendomi il condominio.',
                        'next_step' => 'find_condominio',
                        'reset'     => true,
                    ));
                }
                break;

            // ── Passo 2c: selezione manuale del fornitore ──────────────────────
            case 'manual_select_fornitore':
                if ($input === 'cancel') {
                    wp_send_json_success(array(
                        'message'   => 'Segnalazione annullata. Puoi iniziare una nuova segnalazione dicendomi il condominio.',
                        'next_step' => 'find_condominio',
                        'reset'     => true,
                    ));
                    break;
                }
                if ($input === 'admin_only') {
                    $cid_ao  = (int) ($context['condominioId'] ?? 0);
                    $prob_ao = sanitize_textarea_field($context['problema'] ?? '');
                    wp_send_json_success(array(
                        'message'       => 'Perfetto! La segnalazione verrà inoltrata all\'amministratore. Vuoi allegare delle foto?',
                        'next_step'     => 'upload_photos',
                        'photo_upload'  => true,
                        'admin_only'    => true,
                        'condominio_id' => $cid_ao,
                        'problema'      => $prob_ao,
                    ));
                    break;
                }
                if (preg_match('/^select_forn_(\d+)$/', $input, $m)) {
                    $fid  = (int) $m[1];
                    $post = get_post($fid);
                    $nome = $post ? $post->post_title : "Fornitore #{$fid}";
                    $cid  = (int) ($context['condominioId'] ?? 0);
                    $prob = sanitize_textarea_field($context['problema'] ?? '');
                    wp_send_json_success(array(
                        'message'       => 'Selezionato: <strong>' . esc_html($nome) . '</strong>.<br><br>Vuoi allegare delle foto alla segnalazione? Puoi aggiungerne fino a 5 (max 5MB ciascuna).',
                        'next_step'     => 'upload_photos',
                        'photo_upload'  => true,
                        'fornitore_id'  => $fid,
                        'condominio_id' => $cid,
                        'problema'      => $prob,
                    ));
                } else {
                    wp_send_json_success(array(
                        'message'   => 'Scelta non riconosciuta. Riprova.',
                        'next_step' => 'find_condominio',
                        'reset'     => true,
                    ));
                }
                break;

            // ── Passo 2d: upload foto (opzionale) ────────────────────
            case 'upload_photos':
                $cid  = (int) ($context['condominioId'] ?? 0);
                $fid  = (int) ($context['fornitoreId']  ?? 0);
                $prob = sanitize_textarea_field($context['problema'] ?? '');

                // Valida e raccoglie gli ID delle foto già caricate via AJAX
                $photo_ids = array();
                if (!empty($context['photoIds']) && is_array($context['photoIds'])) {
                    foreach ($context['photoIds'] as $pid) {
                        $pid = basename(sanitize_file_name($pid));
                        if (preg_match('/^marr_[a-f0-9]+\.(jpg|jpeg|png|gif|webp|heic)$/i', $pid)) {
                            $photo_ids[] = $pid;
                        }
                    }
                    $photo_ids = array_slice($photo_ids, 0, 5);
                }

                $foto_note  = count($photo_ids) > 0 ? count($photo_ids) . ' foto allegate. ' : '';
                $admin_only = !empty($context['adminOnly']);

                wp_send_json_success(array(
                    'message'       => $foto_note . 'Inserisci la tua email per ricevere la conferma della segnalazione.',
                    'next_step'     => 'collect_email',
                    'condominio_id' => $cid,
                    'fornitore_id'  => $fid,
                    'problema'      => $prob,
                    'photo_ids'     => $photo_ids,
                    'admin_only'    => $admin_only,
                ));
                break;

            // ── Passo 3: raccolta email + invio ─────────────────
            case 'collect_email':
                $email = sanitize_email($input);
                if (!is_email($email)) {
                    wp_send_json_success(array(
                        'message'      => 'L\'indirizzo email non sembra valido. Riprova.',
                        'next_step'    => 'collect_email',
                        'fornitore_id' => (int) ($context['fornitoreId']  ?? 0),
                        'condominio_id' => (int) ($context['condominioId'] ?? 0),
                        'problema'     => $context['problema'] ?? '',
                    ));
                    break;
                }
                $cid        = (int) ($context['condominioId'] ?? 0);
                $fid        = (int) ($context['fornitoreId']  ?? 0);
                $prob       = sanitize_textarea_field($context['problema'] ?? '');
                $admin_only = !empty($context['adminOnly']);
                if (!$cid || empty($prob) || (!$admin_only && !$fid)) {
                    wp_send_json_success(array(
                        'message'   => 'Si è verificato un errore. Ricominciamo dall\'inizio.',
                        'next_step' => 'find_condominio',
                        'reset'     => true,
                    ));
                    break;
                }
                // Recupera gli ID delle foto dal contesto
                $photo_ids = array();
                if (!empty($context['photoIds']) && is_array($context['photoIds'])) {
                    foreach ($context['photoIds'] as $pid) {
                        $pid = basename(sanitize_file_name($pid));
                        if (preg_match('/^marr_[a-f0-9]+\.(jpg|jpeg|png|gif|webp|heic)$/i', $pid)) {
                            $photo_ids[] = $pid;
                        }
                    }
                    $photo_ids = array_slice($photo_ids, 0, 5);
                }
                $results     = $condo->send_emails($cid, $fid, $prob, $email, $photo_ids, $admin_only);
                $forn_post   = get_post($fid);
                $forn_nome   = $forn_post ? $forn_post->post_title : '';
                $success_cnt = count(array_filter($results));
                if ($success_cnt > 0) {
                    $msg  = '✅ <strong>Segnalazione inviata!</strong><br>';
                    if ($admin_only) {
                        $msg .= 'La segnalazione è stata inoltrata all\'<strong>amministratore</strong>.<br>';
                    } else {
                        $msg .= 'Il fornitore <strong>' . esc_html($forn_nome) . '</strong> è stato contattato.<br>';
                    }
                    $msg .= 'Riceverai una conferma a <strong>' . esc_html($email) . '</strong>.<br><br>';
                    $msg .= 'Vuoi effettuare un\'altra segnalazione?';
                    wp_send_json_success(array(
                        'message'   => $msg,
                        'next_step' => 'post_send',
                        'options'   => array(
                            array('value' => 'same_condo_' . $cid, 'label' => '🔄 Altra segnalazione'),
                            array('value' => 'change_condo',        'label' => '🏢 Cambia condominio'),
                            array('value' => 'no_grazie',           'label' => '👋 No, grazie'),
                        ),
                    ));
                } else {
                    wp_send_json_success(array(
                        'message'   => 'Si è verificato un errore nell\'invio delle email. Contatta direttamente l\'amministratore.',
                        'next_step' => 'find_condominio',
                        'reset'     => true,
                    ));
                }
                break;

            // ── Post-invio: stessa segnalazione o cambio condominio ──────────
            case 'post_send':
                if (preg_match('/^same_condo_(\d+)$/', $input, $m)) {
                    $cid = (int) $m[1];
                    wp_send_json_success(array(
                        'message'       => 'Descrivi il nuovo problema che hai riscontrato.',
                        'next_step'     => 'describe_problem',
                        'condominio_id' => $cid,
                    ));
                } elseif ($input === 'change_condo') {
                    wp_send_json_success(array(
                        'message'   => 'Inserisci il nome o l\'indirizzo del tuo condominio.',
                        'next_step' => 'find_condominio',
                        'reset'     => true,
                    ));
                } elseif ($input === 'no_grazie' || preg_match('/\b(no|grazie|niente|basta|fine|ok grazie|no grazie)\b/i', $input)) {
                    wp_send_json_success(array(
                        'message'   => 'Prego! Se hai bisogno di ulteriore assistenza, sono qui. Buona giornata! 👋',
                        'next_step' => 'find_condominio',
                        'reset'     => true,
                    ));
                } else {
                    wp_send_json_success(array(
                        'message'   => 'Inserisci il nome o l\'indirizzo del tuo condominio.',
                        'next_step' => 'find_condominio',
                        'reset'     => true,
                    ));
                }
                break;

            default:
                wp_send_json_error(array('message' => 'Step non riconosciuto.'));
        }
    }
    
    /**
     * Gestisce l'upload di una singola foto via AJAX.
     * Max 5MB, solo immagini. Salva in wp-content/uploads/marrison-temp/
     */
    public function handle_photo_upload() {
        check_ajax_referer('marrison_agent_nonce', 'nonce');

        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $err = $_FILES['photo']['error'] ?? 'nessun file';
            wp_send_json_error(array('message' => 'Errore upload: ' . $err));
        }

        $file = $_FILES['photo'];

        // Valida dimensione (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            wp_send_json_error(array('message' => 'Il file supera i 5MB consentiti.'));
        }

        // Valida tipo: solo immagini
        $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowed_types, true)) {
            wp_send_json_error(array('message' => 'Tipo file non consentito. Solo immagini JPG, PNG, GIF, WebP.'));
        }

        $filetype = wp_check_filetype($file['name']);
        $ext      = strtolower($filetype['ext'] ?: 'jpg');

        // Crea cartella temp protetta
        $upload_dir = wp_upload_dir();
        $temp_dir   = $upload_dir['basedir'] . '/marrison-temp/';
        if (!is_dir($temp_dir)) {
            wp_mkdir_p($temp_dir);
            file_put_contents($temp_dir . '.htaccess', "Options -Indexes\nDeny from all\n");
            file_put_contents($temp_dir . 'index.php', '<?php // Silence is golden');
        }

        $temp_name = 'marr_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $temp_path = $temp_dir . $temp_name;

        if (!move_uploaded_file($file['tmp_name'], $temp_path)) {
            wp_send_json_error(array('message' => 'Impossibile salvare il file. Riprova.'));
        }

        wp_send_json_success(array(
            'id'   => $temp_name,
            'name' => sanitize_file_name($file['name']),
            'size' => $file['size'],
        ));
    }

    /**
     * Carica script e stili per il widget.
     * Chiamato direttamente dallo shortcode per compatibilità con page builder.
     */
    public function enqueue_scripts() {
        if (wp_script_is('marrison-site-agent', 'enqueued')) {
            return;
        }

        $plugin_url = MARRISON_ASSISTANT_PLUGIN_URL;

        wp_enqueue_style(
            'marrison-site-agent',
            $plugin_url . 'assets/css/site-agent.css',
            array(),
            MARRISON_ASSISTANT_VERSION
        );

        wp_enqueue_script(
            'marrison-site-agent',
            $plugin_url . 'assets/js/site-agent.js',
            array('jquery'),
            MARRISON_ASSISTANT_VERSION,
            true
        );

        $assistant_name = get_option('marrison_assistant_site_agent_name', 'Marry');
        $welcome_msg    = get_option('marrison_assistant_site_agent_welcome', 'Ciao, sono {name}, il tuo assistente virtuale, come posso aiutarti?');
        $welcome_msg    = str_replace('{name}', $assistant_name, $welcome_msg);

        wp_localize_script('marrison-site-agent', 'marrisonAgent', array(
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('marrison_agent_nonce'),
            'welcome'     => $welcome_msg,
            'placeholder' => get_option('marrison_assistant_site_agent_placeholder', 'Es: Condominio Primavera, oppure Via Roma 10'),
            'title'       => get_option('marrison_assistant_site_agent_title', 'Assistente AI'),
            'name'        => $assistant_name,
            'isTyping'    => 'Sto scrivendo...',
            'mode'        => 'inline',
            'colors'      => array(
                'icon'   => get_option('marrison_assistant_site_agent_icon_color', '#667eea'),
                'header' => get_option('marrison_assistant_site_agent_header_color', '#667eea'),
                'button' => get_option('marrison_assistant_site_agent_button_color', '#667eea'),
            ),
            'intentResponses' => array(
                'products' => get_option('marrison_assistant_site_agent_response_products', 'Perfetto! Dimmi cosa stai cercando tra i nostri prodotti.'),
                'orders'   => get_option('marrison_assistant_site_agent_response_orders',   'Certo! Dimmi il numero ordine o cosa vorresti sapere sul tuo acquisto.'),
                'info'     => get_option('marrison_assistant_site_agent_response_info',     'Con piacere! Su cosa vorresti informazioni? Azienda, contatti, servizi?'),
                'events'   => get_option('marrison_assistant_site_agent_response_events',   'Ottimo! Stai cercando un evento specifico o vuoi vedere il calendario?'),
            ),
        ));
    }

    /**
     * Shortcode [marrison_chat] — renderizza la chat inline.
     * Compatibile con Gutenberg, Elementor e qualsiasi page builder.
     *
     * Attributi:
     *   height  (default: 520px) — altezza della finestra chat
     *   width   (default: 100%)  — larghezza del contenitore
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'height' => '600px',
            'width'  => '100%',
        ), $atts, 'marrison_chat');

        $this->enqueue_scripts();

        $logged_only = get_option('marrison_assistant_site_agent_logged_only', false);
        if ($logged_only && !is_user_logged_in()) {
            return '';
        }

        $title          = get_option('marrison_assistant_site_agent_title', 'Assistente AI');
        $assistant_name = get_option('marrison_assistant_site_agent_name', 'Marry');
        $welcome        = get_option('marrison_assistant_site_agent_welcome', 'Ciao! Sono {name}, il tuo assistente condominiale. Per iniziare una segnalazione, dimmi il nome o l\'indirizzo del tuo condominio.');
        $placeholder    = get_option('marrison_assistant_site_agent_placeholder', 'Scrivi un messaggio...');
        $icon_color     = get_option('marrison_assistant_site_agent_icon_color', '#667eea');
        $header_color   = get_option('marrison_assistant_site_agent_header_color', '#667eea');
        $button_color   = get_option('marrison_assistant_site_agent_button_color', '#667eea');

        $welcome = str_replace('{name}', $assistant_name, $welcome);
        if (empty($welcome)) {
            $welcome = 'Ciao! Sono il tuo assistente condominiale. Per iniziare una segnalazione, dimmi il nome o l\'indirizzo del tuo condominio.';
        }

        $height = esc_attr($atts['height']);
        $width  = esc_attr($atts['width']);

        $privacy_url = get_privacy_policy_url();
        $custom_avatar = get_option('marrison_assistant_site_agent_avatar', '');
        $favicon_url   = $custom_avatar ?: get_site_icon_url(64);

        ob_start();
        ?>
        <div id="marrison-chat-widget" class="marrison-chat-widget marrison-chat-inline" style="--marrison-icon-color: <?php echo esc_attr($icon_color); ?>; --marrison-header-color: <?php echo esc_attr($header_color); ?>; --marrison-button-color: <?php echo esc_attr($button_color); ?>; --marrison-button-color-hover: <?php echo esc_attr($button_color); ?>; --marrison-chat-height: <?php echo $height; ?>; width: <?php echo $width; ?>;">
            <!-- Chat Window (sempre visibile in modalità shortcode) -->
            <div class="marrison-chat-window open">
                <!-- Header -->
                <div class="marrison-chat-header">
                    <div class="marrison-header-left">
                        <div class="marrison-header-avatar">
                            <?php if ($favicon_url): ?>
                                <img src="<?php echo esc_url($favicon_url); ?>" alt="" class="marrison-avatar-img">
                            <?php else: ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="white" style="flex-shrink:0;"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                            <?php endif; ?>
                            <span class="marrison-avatar-dot"></span>
                        </div>
                        <div class="marrison-header-info">
                            <div class="marrison-header-name"><?php echo esc_html($assistant_name); ?></div>
                            <div class="marrison-header-title"><?php echo esc_html($title); ?></div>
                            <div class="marrison-header-status">
                                <span class="marrison-status-dot"></span>
                                Online<?php if (!is_user_logged_in()): ?> &middot; <span class="marrison-guest-label">Guest</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <div class="marrison-chat-messages" style="flex: 1; padding: 20px; overflow-y: auto; background: #f8fafc;">
                    <div class="marrison-message marrison-bot" style="margin-bottom: 16px; display: flex; flex-direction: column; align-items: flex-start;">
                        <div class="marrison-message-content" style="max-width: 85%; padding: 12px 16px; border-radius: 18px; background: #ffffff !important; color: #1e293b !important; border: 1px solid #e2e8f0; border-bottom-left-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); word-wrap: break-word; line-height: 1.4; font-size: 14px; display: block; min-height: 20px;">
                            <?php echo !empty($welcome) ? esc_html($welcome) : 'Ciao! Come posso aiutarti?'; ?>
                        </div>
                        <div class="marrison-message-time" style="font-size: 11px; color: #64748b; margin-top: 4px; padding: 0 4px;">Ora</div>
                    </div>

                </div>

                <!-- Input -->
                <div class="marrison-chat-input" style="padding: 12px 16px; background: #fff; border-top: 1px solid #e2e8f0; display: flex; align-items: center; gap: 10px; flex-shrink: 0;">
                    <textarea
                        id="marrison-chat-textarea"
                        placeholder="<?php echo esc_attr($placeholder); ?>"
                        rows="2"
                        style="flex: 1; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px 14px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5; color: #333; background: #f8fafc; resize: none; outline: none; min-height: 42px; box-sizing: border-box; width: 100%;"></textarea>
                    <button id="marrison-chat-send" class="marrison-send-button" style="width: 42px; height: 42px; border-radius: 50%; border: none; color: white; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; padding: 0;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="white">
                            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                        </svg>
                    </button>
                </div>
                <!-- Footer permanente: privacy + branding -->
                <div class="marrison-chat-footer">
                    <?php if ($privacy_url): ?>
                    <a href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener noreferrer" class="marrison-footer-privacy">Privacy Policy</a>
                    <?php else: ?><span></span><?php endif; ?>
                    <a href="https://marrisonlab.com" target="_blank" rel="noopener noreferrer" class="marrison-footer-branding">Powered by MarrisonLab</a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * @deprecated Usare lo shortcode [marrison_chat] al posto del widget flottante.
     */
    public function render_chat_widget() {
        return;
        
        // Verifica se l'utente deve essere loggato
        $logged_only = get_option('marrison_assistant_site_agent_logged_only', false);
        if ($logged_only && !is_user_logged_in()) {
            return;
        }
        
        $position = get_option('marrison_assistant_site_agent_position', 'bottom-right');
        $color = get_option('marrison_assistant_site_agent_color', '#0073aa');
        $title = get_option('marrison_assistant_site_agent_title', 'Assistente AI');
        $assistant_name = get_option('marrison_assistant_site_agent_name', 'Marry');
        $welcome = get_option('marrison_assistant_site_agent_welcome', 'Ciao, sono {name}, il tuo assistente virtuale, come posso aiutarti?');
        $placeholder = get_option('marrison_assistant_site_agent_placeholder', 'Scrivi un messaggio...');

        // Sostituisci {name} con il nome dell'assistente
        $welcome = str_replace('{name}', $assistant_name, $welcome);

        // Colori personalizzabili
        $icon_color = get_option('marrison_assistant_site_agent_icon_color', '#667eea');
        $header_color = get_option('marrison_assistant_site_agent_header_color', '#667eea');
        $button_color = get_option('marrison_assistant_site_agent_button_color', '#667eea');

        // Assicurati che il messaggio di benvenuto non sia mai vuoto
        if (empty($welcome)) {
            $welcome = 'Ciao! Come posso aiutarti oggi?';
        }
        
        ?>
        <div id="marrison-chat-widget" class="marrison-chat-widget marrison-<?php echo esc_attr($position); ?>" style="--marrison-icon-color: <?php echo esc_attr($icon_color); ?>; --marrison-header-color: <?php echo esc_attr($header_color); ?>; --marrison-button-color: <?php echo esc_attr($button_color); ?>; --marrison-button-color-hover: <?php echo esc_attr($button_color); ?>;">
            <!-- Chat Button -->
            <div class="marrison-chat-button">
                <svg width="32" height="32" viewBox="0 0 510 510" fill="white" xmlns="http://www.w3.org/2000/svg" clip-rule="evenodd" fill-rule="evenodd" stroke-linejoin="round" stroke-miterlimit="2">
                    <path d="m146.534 64.833c-1.709.358-3.479.547-5.294.547-14.192 0-25.714-11.523-25.714-25.715s11.522-25.714 25.714-25.714c14.193 0 25.715 11.522 25.715 25.714 0 6.592-2.486 12.607-6.57 17.16l49.867 86.372h-18.475zm216.932 0-45.243 78.364h-18.475l49.867-86.372c-4.084-4.553-6.57-10.568-6.57-17.16 0-14.192 11.522-25.714 25.715-25.714 14.192 0 25.714 11.522 25.714 25.714s-11.522 25.715-25.714 25.715c-1.815 0-3.585-.189-5.294-.547zm-44.901 399.044h145.576v-116.387h-14.234v-98.962h29.604c5.462 0 9.896 4.435 9.896 9.897v79.169c0 5.25-4.097 9.551-9.266 9.876v124.407c0 4.418-3.582 8-8 8h-153.576c-3.005 6.361-9.481 10.766-16.978 10.766h-33.982c-10.357 0-18.766-8.409-18.766-18.766s8.409-18.766 18.766-18.766h33.982c7.497 0 13.973 4.405 16.978 10.766zm-258.472-116.387h-29.604c-5.462 0-9.896-4.434-9.896-9.896v-79.169c0-5.462 4.434-9.897 9.896-9.897h29.604zm373.814 42.428c0 21.144-17.251 38.337-38.395 38.337h-173.447l-63.058 65.643c-1.979 2.06-5.012 2.711-7.662 1.645-2.65-1.067-4.386-3.637-4.386-6.494v-60.794h-32.471c-21.144 0-38.395-17.193-38.395-38.337v-192.325c0-21.144 17.251-38.396 38.395-38.396h281.024c21.144 0 38.395 17.252 38.395 38.396zm-124.783-63.836h-112.997c1.214 30.324 26.15 54.282 56.499 54.282 30.348 0 55.284-23.958 56.498-54.282zm-118.407-104.564c-13.421 0-24.317 10.896-24.317 24.317 0 13.422 10.896 24.318 24.317 24.318s24.318-10.896 24.318-24.318c0-13.421-10.897-24.317-24.318-24.317zm128.566 0c-13.421 0-24.318 10.896-24.318 24.317 0 13.422 10.897 24.318 24.318 24.318s24.317-10.896 24.317-24.318c0-13.421-10.896-24.317-24.317-24.317z"/>
                </svg>
                <span class="marrison-chat-badge">1</span>
            </div>
            
            <!-- Chat Window -->
            <div class="marrison-chat-window">
                <!-- Header -->
                <div class="marrison-chat-header">
                    <div class="marrison-header-left">
                        <div class="marrison-header-avatar">
                            <?php $favicon_url = get_option('marrison_assistant_site_agent_avatar', '') ?: get_site_icon_url(64); ?>
                            <?php if ($favicon_url): ?>
                                <img src="<?php echo esc_url($favicon_url); ?>" alt="" class="marrison-avatar-img">
                            <?php else: ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="white" style="flex-shrink:0;"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                            <?php endif; ?>
                            <span class="marrison-avatar-dot"></span>
                        </div>
                        <div class="marrison-header-info">
                            <div class="marrison-header-name"><?php echo esc_html($assistant_name); ?></div>
                            <div class="marrison-header-title"><?php echo esc_html($title); ?></div>
                            <div class="marrison-header-status">
                                <span class="marrison-status-dot"></span>
                                Online<?php if (!is_user_logged_in()): ?> &middot; <span class="marrison-guest-label">Guest</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <button class="marrison-chat-close" aria-label="Chiudi chat">&times;</button>
                </div>
                
                <!-- Messages -->
                <div class="marrison-chat-messages" style="flex: 1; padding: 20px; overflow-y: auto; background: #f8fafc;">
                    <div class="marrison-message marrison-bot" style="margin-bottom: 16px; display: flex; flex-direction: column; align-items: flex-start;">
                        <div class="marrison-message-content" style="max-width: 85%; padding: 12px 16px; border-radius: 18px; background: #ffffff !important; color: #1e293b !important; border: 1px solid #e2e8f0; border-bottom-left-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); word-wrap: break-word; line-height: 1.4; font-size: 14px; display: block; min-height: 20px;">
                            <?php echo !empty($welcome) ? esc_html($welcome) : 'Ciao! Come posso aiutarti?'; ?>
                        </div>
                        <div class="marrison-message-time" style="font-size: 11px; color: #64748b; margin-top: 4px; padding: 0 4px;">Ora</div>
                    </div>
                    
                    <!-- Bottoni di routing categoria (condizionali) -->
                    <?php
                    $scanner      = new Marrison_Assistant_Content_Scanner();
                    $show_products = $scanner->has_content('products') || class_exists('WooCommerce');
                    $show_events   = $scanner->has_content('events');
                    $show_orders   = is_user_logged_in() && class_exists('WooCommerce');
                    // Mostra i pulsanti solo se ce ne sono almeno due (altrimenti è un passaggio inutile)
                    $show_buttons  = ($show_products || $show_orders || $show_events);
                    ?>
                    <?php if ($show_buttons): ?>
                    <div id="marrison-intent-buttons" class="marrison-intent-buttons">
                        <?php if ($show_products): ?><button type="button" class="marrison-intent-btn" data-intent="products">🛍️ Prodotti</button><?php endif; ?>
                        <?php if ($show_orders):   ?><button type="button" class="marrison-intent-btn" data-intent="orders">📦 Ordini</button><?php endif; ?>
                        <button type="button" class="marrison-intent-btn" data-intent="info">ℹ️ Info</button>
                        <?php if ($show_events):   ?><button type="button" class="marrison-intent-btn" data-intent="events">📅 Eventi</button><?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!is_user_logged_in() && !$logged_only && class_exists('WooCommerce')): ?>
                    <div id="marrison-login-tip" class="marrison-message marrison-bot" style="display:none;">
                        <div class="marrison-message-content marrison-tip-message">
                            <strong>Tip:</strong> Effettua il login per accedere a funzionalità avanzate come tracking ordini e supporto personalizzato.
                        </div>
                        <div class="marrison-message-time">Ora</div>
                    </div>
                    <script>
                    (function(){
                        var hasCookie   = document.cookie.split(';').some(function(c){ return c.trim().indexOf('wordpress_logged_in_') === 0; });
                        var hasAdminBar = !!document.getElementById('wpadminbar');
                        var hasBodyCls  = !!(document.body && document.body.classList && document.body.classList.contains('logged-in'));
                        if (!hasCookie && !hasAdminBar && !hasBodyCls) {
                            var el = document.getElementById('marrison-login-tip');
                            if (el) el.style.display = '';
                        }
                    })();
                    </script>
                    <?php endif; ?>
                </div>
                
                <!-- Input -->
                <div class="marrison-chat-input" style="padding: 12px 16px; background: #fff; border-top: 1px solid #e2e8f0; display: flex; align-items: center; gap: 10px; flex-shrink: 0;">
                    <textarea 
                        id="marrison-chat-textarea" 
                        placeholder="<?php echo esc_attr($placeholder); ?>"
                        rows="2"
                        style="flex: 1; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px 14px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5; color: #333; background: #f8fafc; resize: none; outline: none; min-height: 42px; box-sizing: border-box; width: 100%;"></textarea>
                    <button id="marrison-chat-send" class="marrison-send-button" style="width: 42px; height: 42px; border-radius: 50%; border: none; color: white; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; padding: 0;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="white">
                            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                        </svg>
                    </button>
                </div>
                <!-- Footer permanente: privacy + branding -->
                <?php $privacy_url = get_privacy_policy_url(); ?>
                <div class="marrison-chat-footer">
                    <?php if ($privacy_url): ?>
                    <a href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener noreferrer" class="marrison-footer-privacy">Privacy Policy</a>
                    <?php else: ?><span></span><?php endif; ?>
                    <a href="https://marrisonlab.com" target="_blank" rel="noopener noreferrer" class="marrison-footer-branding">Powered by MarrisonLab</a>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Ping leggero — verifica che il canale AJAX funzioni senza chiamare Gemini
     */
    public function handle_ping() {
        check_ajax_referer('marrison_agent_nonce', 'nonce');
        wp_send_json_success(array('pong' => true, 'time' => current_time('H:i:s')));
    }

    /**
     * Tracking eventi client (apertura chat, sessione avviata) — risponde senza fare nulla
     */
    public function handle_track() {
        check_ajax_referer('marrison_agent_nonce', 'nonce');
        wp_send_json_success(array('ok' => true));
    }

    /**
     * Gestisce le richieste AJAX dal widget
     */
    public function handle_chat_request() {
        check_ajax_referer('marrison_agent_nonce', 'nonce');

        // Rate limiting
        $rl = $this->check_rate_limit();
        if ($rl !== true) {
            wp_send_json_error(array(
                'code'    => 'rate_limited',
                'message' => $rl['message'],
                'wait'    => $rl['wait'],
            ));
        }

        $message = sanitize_textarea_field($_POST['message']);
        $intent  = isset($_POST['intent']) ? sanitize_text_field($_POST['intent']) : 'general';
        $history_raw = isset($_POST['history']) ? stripslashes($_POST['history']) : '[]';
        $history = json_decode($history_raw, true);
        if (!is_array($history)) $history = array();

        if (empty($message)) {
            wp_send_json_error('Messaggio vuoto');
        }

        // Verifica se l'utente è loggato e se è richiesto
        $logged_only = get_option('marrison_assistant_site_agent_logged_only', false);
        if ($logged_only && !is_user_logged_in()) {
            wp_send_json_error('Accesso negato. Effettua il login per utilizzare l\'assistente.');
        }

        // SICUREZZA: gli ordini sono accessibili SOLO agli utenti loggati (solo se WooCommerce è attivo)
        if ($intent === 'orders' && !is_user_logged_in() && class_exists('WooCommerce')) {
            wp_send_json_success(array(
                'message'        => 'Per consultare i tuoi ordini devi prima effettuare il login.',
                'time'           => current_time('H:i'),
                'user_logged_in' => false,
                'intent'         => 'orders',
            ));
        }

        // Processa il messaggio con Gemini passando l'intento
        try {
            $gemini = new Marrison_Assistant_Gemini();

            if (!is_user_logged_in()) {
                $response = $gemini->process_message($message, $intent, $history, $message, '');
            } else {
                $current_user = wp_get_current_user();
                $user_email   = $current_user->user_email;
                $response = $gemini->process_message($message, $intent, $history, $message, $user_email);
            }

            if ($response) {
                wp_send_json_success(array(
                    'message' => $response,
                    'time' => current_time('H:i'),
                    'user_logged_in' => is_user_logged_in(),
                    'intent' => $intent
                ));
            } else {
                error_log('Marrison Assistant: process_message returned false per intent=' . $intent . ' message=' . substr($message, 0, 80));
                wp_send_json_success(array(
                    'message' => 'Mi dispiace, il servizio AI non è disponibile in questo momento. Riprova tra qualche minuto.',
                    'time' => current_time('H:i'),
                    'user_logged_in' => is_user_logged_in(),
                    'intent' => $intent
                ));
            }
        } catch (Exception $e) {
            error_log('Marrison Assistant: eccezione in handle_chat_request — ' . $e->getMessage());
            wp_send_json_error('Errore interno: ' . $e->getMessage());
        }
    }
    
    /**
     * Rate limiting per IP: max 10 req/minuto e 80 req/ora.
     * Usa WordPress transients (compatibile con object cache).
     * @return true|array  true se OK, array con 'wait' e 'message' se bloccato
     */
    private function check_rate_limit() {
        // Estrai IP in modo sicuro, anche dietro proxy/CDN
        $ip = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip    = trim($parts[0]);
        }
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        }
        $h = substr(md5($ip), 0, 16); // hash parziale — non loggare IP in chiaro

        // ── Finestra 1: max 30 richieste al minuto ──
        $key_min   = 'marrison_rl_m_' . $h;
        $count_min = (int) get_transient($key_min);
        if ($count_min >= 30) {
            return array('wait' => '60', 'message' => 'Stai inviando troppi messaggi. Attendi un momento prima di riprovare.');
        }
        // Incrementa; se il transient non esiste ancora, impostalo con TTL 60s
        if ($count_min === 0) {
            set_transient($key_min, 1, 60);
        } else {
            set_transient($key_min, $count_min + 1, 60);
        }

        // ── Finestra 2: max 200 richieste all'ora ──
        $key_hour   = 'marrison_rl_h_' . $h;
        $count_hour = (int) get_transient($key_hour);
        if ($count_hour >= 200) {
            return array('wait' => '3600', 'message' => 'Limite orario raggiunto. Riprova tra qualche minuto.');
        }
        if ($count_hour === 0) {
            set_transient($key_hour, 1, 3600);
        } else {
            set_transient($key_hour, $count_hour + 1, 3600);
        }

        return true;
    }

    /**
     * Ottiene le statistiche di utilizzo
     */
    public function get_usage_stats() {
        $stats = get_option('marrison_assistant_site_agent_stats', array(
            'chats_today' => 0,
            'messages_today' => 0,
            'last_reset' => date('Y-m-d')
        ));
        
        // Reset giornaliero
        if ($stats['last_reset'] !== date('Y-m-d')) {
            $stats = array(
                'chats_today' => 0,
                'messages_today' => 0,
                'last_reset' => date('Y-m-d')
            );
            update_option('marrison_assistant_site_agent_stats', $stats);
        }
        
        return $stats;
    }
    
    /**
     * Incrementa le statistiche
     */
    public function increment_stats($type) {
        $stats = $this->get_usage_stats();
        
        if ($type === 'chat') {
            $stats['chats_today']++;
        } elseif ($type === 'message') {
            $stats['messages_today']++;
        }
        
        update_option('marrison_assistant_site_agent_stats', $stats);
    }
}
