<?php
if (!defined('ABSPATH')) exit;

/**
 * Gestione della tabella delle segnalazioni e token di conferma intervento.
 */
class Marrison_Assistant_Requests {

    const TABLE = 'marrison_requests';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Crea la tabella DB (chiamato in attivazione plugin e su dbDelta).
     */
    public static function create_table() {
        global $wpdb;
        $t  = self::table_name();
        $cc = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$t} (
            id              bigint(20) UNSIGNED   NOT NULL AUTO_INCREMENT,
            created_at      datetime              NOT NULL,
            condominio_id   bigint(20) UNSIGNED   NOT NULL DEFAULT 0,
            condominio_name varchar(255)          NOT NULL DEFAULT '',
            indirizzo       varchar(255)          NOT NULL DEFAULT '',
            fornitore_id    bigint(20) UNSIGNED   NOT NULL DEFAULT 0,
            fornitore_name  varchar(255)          NOT NULL DEFAULT '',
            problema        text                  NOT NULL,
            foto_ids        text                  NOT NULL DEFAULT '',
            inquilino_email varchar(255)          NOT NULL DEFAULT '',
            admin_only      tinyint(1)            NOT NULL DEFAULT 0,
            status          varchar(20)           NOT NULL DEFAULT 'pending',
            token           char(40)             NOT NULL DEFAULT '',
            completion_token char(40)             NOT NULL DEFAULT '',
            completed_at    datetime                       DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   token    (token),
            UNIQUE KEY   completion_token (completion_token),
            KEY          cond_idx (condominio_id),
            KEY          forn_idx (fornitore_id),
            KEY          stat_idx (status)
        ) {$cc}";;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Aggiunge colonne mancanti per retrocompatibilità
        $col_indirizzo = $wpdb->get_results("SHOW COLUMNS FROM {$t} LIKE 'indirizzo'");
        if (empty($col_indirizzo)) {
            $wpdb->query("ALTER TABLE {$t} ADD COLUMN indirizzo varchar(255) NOT NULL DEFAULT '' AFTER condominio_name");
        }

        $col_foto_ids = $wpdb->get_results("SHOW COLUMNS FROM {$t} LIKE 'foto_ids'");
        if (empty($col_foto_ids)) {
            $wpdb->query("ALTER TABLE {$t} ADD COLUMN foto_ids text NOT NULL DEFAULT '' AFTER problema");
        }

        $col_token = $wpdb->get_results("SHOW COLUMNS FROM {$t} LIKE 'token'");
        if (empty($col_token)) {
            $wpdb->query("ALTER TABLE {$t} ADD COLUMN token char(40) NOT NULL DEFAULT '' AFTER admin_only");
        }
    }

    /**
     * Inserisce una nuova segnalazione e restituisce id e token.
     */
    public static function insert($data) {
        global $wpdb;
        $token = bin2hex(random_bytes(20)); // 40 hex chars per pagina temporanea
        $completion_token = bin2hex(random_bytes(20)); // 40 hex chars per conferma
        error_log('Marrison Insert: token=' . $token . ' completion_token=' . $completion_token);
        $wpdb->insert(
            self::table_name(),
            [
                'created_at'       => current_time('mysql'),
                'condominio_id'    => (int) ($data['condominio_id']   ?? 0),
                'condominio_name'  => (string) ($data['condominio_name'] ?? ''),
                'indirizzo'        => (string) ($data['indirizzo']       ?? ''),
                'fornitore_id'     => (int) ($data['fornitore_id']    ?? 0),
                'fornitore_name'   => (string) ($data['fornitore_name'] ?? ''),
                'problema'         => (string) ($data['problema']       ?? ''),
                'foto_ids'         => (string) ($data['foto_ids']       ?? ''),
                'inquilino_email'  => (string) ($data['inquilino_email'] ?? ''),
                'admin_only'       => (int) !empty($data['admin_only']),
                'status'           => 'pending',
                'token'            => $token,
                'completion_token' => $completion_token,
            ],
            ['%s','%d','%s','%s','%d','%s','%s','%s','%s','%d','%s','%s','%s']
        );
        error_log('Marrison Insert: insert_id=' . $wpdb->insert_id . ' error=' . $wpdb->last_error);
        return ['id' => (int) $wpdb->insert_id, 'token' => $token, 'completion_token' => $completion_token];
    }

    /**
     * Marca una segnalazione come completata tramite token.
     * Ritorna null se il token non esiste, l'oggetto row altrimenti.
     * Aggiunge la proprietà _already=true se era già completata.
     */
    public static function confirm_by_token($token) {
        global $wpdb;
        $t   = self::table_name();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t} WHERE completion_token = %s", $token
        ));
        if (!$row) return null;
        if ($row->status === 'completed') {
            $row->_already = true;
            return $row;
        }
        $wpdb->update(
            $t,
            ['status' => 'completed', 'completed_at' => current_time('mysql')],
            ['completion_token' => $token],
            ['%s','%s'], ['%s']
        );
        $row->status       = 'completed';
        $row->completed_at = current_time('mysql');

        // Pulisci i file temporanei associati alla richiesta
        if (!empty($row->foto_ids)) {
            $upload_dir = wp_upload_dir();
            $temp_dir   = $upload_dir['basedir'] . '/marrison-temp/';
            $foto_files = explode(',', $row->foto_ids);
            foreach ($foto_files as $file) {
                $file = trim($file);
                if (preg_match('/^marr_[a-f0-9]+\.(jpg|jpeg|png|gif|webp|heic)$/i', $file)) {
                    $path = $temp_dir . $file;
                    if (file_exists($path) && strpos(realpath($path), realpath($temp_dir)) === 0) {
                        @unlink($path);
                    }
                }
            }
        }

        return $row;
    }

    /**
     * Restituisce l'elenco delle segnalazioni con filtri e paginazione.
     */
    public static function get_list($filters = [], $page = 1, $per_page = 30) {
        global $wpdb;
        $t     = self::table_name();
        $where = '1=1';
        $args  = [];

        if (!empty($filters['condominio_id'])) {
            $where .= ' AND condominio_id = %d';
            $args[] = (int) $filters['condominio_id'];
        }
        if (!empty($filters['fornitore_id'])) {
            $where .= ' AND fornitore_id = %d';
            $args[] = (int) $filters['fornitore_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = %s';
            $args[] = $filters['status'];
        }

        $offset    = ($page - 1) * $per_page;
        $count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
        $list_sql  = "SELECT * FROM {$t} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";

        $count_args = $args;
        $list_args  = array_merge($args, [$per_page, $offset]);

        $total = empty($count_args)
            ? (int) $wpdb->get_var($count_sql)
            : (int) $wpdb->get_var($wpdb->prepare($count_sql, $count_args));

        $rows = empty($list_args)
            ? $wpdb->get_results($list_sql)
            : $wpdb->get_results($wpdb->prepare($list_sql, $list_args));

        return ['rows' => $rows ?: [], 'total' => $total];
    }

    public static function get_unique_condominios() {
        global $wpdb;
        $t = self::table_name();
        return $wpdb->get_results(
            "SELECT DISTINCT condominio_id, condominio_name FROM {$t} WHERE condominio_id > 0 ORDER BY condominio_name"
        ) ?: [];
    }

    public static function get_unique_fornitori() {
        global $wpdb;
        $t = self::table_name();
        return $wpdb->get_results(
            "SELECT DISTINCT fornitore_id, fornitore_name FROM {$t} WHERE fornitore_id > 0 ORDER BY fornitore_name"
        ) ?: [];
    }

    /**
     * Marca come completata per ID (dal backend, senza token).
     * Ritorna la riga aggiornata o null se non trovata / già completata.
     */
    public static function complete_by_id($id) {
        global $wpdb;
        $t   = self::table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id));
        if (!$row || $row->status === 'completed') return null;
        $wpdb->update(
            $t,
            ['status' => 'completed', 'completed_at' => current_time('mysql')],
            ['id' => (int) $id],
            ['%s','%s'], ['%d']
        );
        $row->status       = 'completed';
        $row->completed_at = current_time('mysql');
        return $row;
    }

    /**
     * Invia le email di notifica chiusura intervento (admin + condòmino).
     */
    public static function send_completion_emails($row) {
        $site         = get_bloginfo('name');
        $headers      = ['Content-Type: text/plain; charset=UTF-8'];
        $custom_admin = get_option('marrison_assistant_condominio_admin_email', '');
        $admin_email  = ($custom_admin && is_email($custom_admin)) ? $custom_admin : get_option('admin_email');
        $data_ora     = date_i18n('d/m/Y \a\l\l\e H:i', current_time('timestamp'));
        $mail_host    = parse_url(get_site_url(), PHP_URL_HOST);
        if (substr($mail_host, 0, 4) === 'www.') $mail_host = substr($mail_host, 4);
        $cb_from      = function() use ($mail_host) { return 'segnalazioni@' . $mail_host; };
        $cb_name      = function() use ($site) { return $site; };
        add_filter('wp_mail_from',      $cb_from);
        add_filter('wp_mail_from_name', $cb_name);

        $subj_a  = "[{$site}] Intervento completato — {$row->condominio_name}";
        $body_a  = "L'intervento è stato confermato come completato.\n\n";
        $body_a .= "Condominio: {$row->condominio_name}\n";
        if (!empty($row->fornitore_name)) $body_a .= "Fornitore:  {$row->fornitore_name}\n";
        $body_a .= "Problema:   {$row->problema}\n";
        $body_a .= "Data segnalazione: " . date_i18n('d/m/Y H:i', strtotime($row->created_at)) . "\n";
        $body_a .= "Completato il: {$data_ora}\n\n";
        $body_a .= "Cordiali saluti,\n{$site}";
        wp_mail($admin_email, $subj_a, $body_a, $headers);

        if (!empty($row->inquilino_email) && is_email($row->inquilino_email)) {
            $subj_i  = "Intervento completato — {$row->condominio_name}";
            $body_i  = "La informiamo che l'intervento relativo alla sua segnalazione è stato completato.\n\n";
            $body_i .= "Condominio: {$row->condominio_name}\n";
            $body_i .= "Problema:   {$row->problema}\n";
            $body_i .= "Completato il: {$data_ora}\n\n";
            $body_i .= "Per qualsiasi necessità, non esiti a contattarci.\n\n";
            $body_i .= "Cordiali saluti,\n{$site}";
            wp_mail($row->inquilino_email, $subj_i, $body_i, $headers);
        }

        remove_filter('wp_mail_from',      $cb_from);
        remove_filter('wp_mail_from_name', $cb_name);
    }

    /**
     * Elimina una segnalazione per ID.
     */
    public static function delete($id) {
        global $wpdb;
        return $wpdb->delete(self::table_name(), ['id' => (int) $id], ['%d']);
    }

    /**
     * URL pubblica per la conferma intervento (senza login).
     */
    public static function confirm_url($token) {
        return add_query_arg('marrison_confirm', $token, home_url('/'));
    }

    /**
     * URL pubblica per la pagina temporanea con dettagli richiesta (per SMS).
     */
    public static function details_url($token) {
        return add_query_arg('marrison_req', $token, home_url('/'));
    }

    /**
     * Ottiene i dettagli di una richiesta tramite token.
     */
    public static function get_by_token($token) {
        global $wpdb;
        $t = self::table_name();
        // Prima cerca per token (nuovo), poi per completion_token (vecchio per compatibilità)
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t} WHERE token = %s AND status != 'completed'", $token
        ));
        if (!$row) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t} WHERE completion_token = %s AND status != 'completed'", $token
            ));
        }
        return $row;
    }
}
