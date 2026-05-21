<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// ── WP_List_Table per le segnalazioni ────────────────────────────────────────
class Marrison_Segnalazioni_List_Table extends WP_List_Table {

    private $f_cond   = 0;
    private $f_forn   = 0;
    private $f_status = '';

    public function __construct() {
        parent::__construct([
            'singular' => 'segnalazione',
            'plural'   => 'segnalazioni',
            'ajax'     => false,
        ]);
    }

    public function set_filters($cond, $forn, $status) {
        $this->f_cond   = (int)    $cond;
        $this->f_forn   = (int)    $forn;
        $this->f_status = (string) $status;
    }

    public function get_columns() {
        return [
            'cb'             => '<input type="checkbox">',
            'created_at'     => 'Data',
            'condominio'     => 'Condominio',
            'fornitore'      => 'Fornitore / Modalità',
            'problema'       => 'Problema',
            'inquilino'      => 'Condòmino',
            'status'         => 'Stato',
        ];
    }

    public function get_sortable_columns() {
        return [
            'created_at' => ['created_at', true],
            'status'     => ['status', false],
        ];
    }

    public function get_bulk_actions() {
        return [
            'bulk_delete'   => 'Elimina',
            'bulk_complete' => 'Segna come completato',
        ];
    }

    protected function column_default($item, $column_name) {
        return esc_html($item->$column_name ?? '—');
    }

    protected function column_cb($item) {
        return '<input type="checkbox" name="seg_ids[]" value="' . (int)$item->id . '">';
    }

    protected function column_created_at($item) {
        $date = esc_html(date_i18n('d/m/Y H:i', strtotime($item->created_at)));
        $base = admin_url('admin.php?page=marrison-segnalazioni');

        $del_url = wp_nonce_url(add_query_arg(['marrison_action' => 'delete', 'id' => $item->id], $base), 'marrison_seg_' . $item->id);
        $actions = [
            'delete' => '<a href="' . esc_url($del_url) . '" onclick="return confirm(\'Eliminare questa segnalazione?\')" style="color:#b91c1c;">Elimina</a>',
        ];
        if ($item->status !== 'completed') {
            $cmp_url = wp_nonce_url(add_query_arg(['marrison_action' => 'complete', 'id' => $item->id], $base), 'marrison_seg_' . $item->id);
            $actions['complete'] = '<a href="' . esc_url($cmp_url) . '" onclick="return confirm(\'Segnare come completato e inviare notifiche?\')" style="color:#166534;">Completa</a>';
        }
        return $date . $this->row_actions($actions);
    }

    protected function column_condominio($item) {
        return esc_html($item->condominio_name ?: '—');
    }

    protected function column_fornitore($item) {
        if ($item->admin_only) {
            return '<span style="color:#6366f1;font-style:italic;">Solo amministratore</span>';
        }
        return esc_html($item->fornitore_name ?: '—');
    }

    protected function column_problema($item) {
        $short = mb_strimwidth($item->problema, 0, 120, '…');
        return '<span title="' . esc_attr($item->problema) . '">' . esc_html($short) . '</span>';
    }

    protected function column_inquilino($item) {
        return esc_html($item->inquilino_email ?: '—');
    }

    protected function column_status($item) {
        if ($item->status === 'completed') {
            $badge = '<span style="background:#dcfce7;color:#166534;padding:3px 8px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap;">✓ Completato</span>';
            if ($item->completed_at) {
                $badge .= '<br><small style="color:#666;">' . esc_html(date_i18n('d/m/Y', strtotime($item->completed_at))) . '</small>';
            }
            return $badge;
        }
        return '<span style="background:#fef9c3;color:#713f12;padding:3px 8px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap;">⏳ In attesa</span>';
    }

    public function prepare_items() {
        $per_page     = 25;
        $current_page = $this->get_pagenum();

        $filters = array_filter([
            'condominio_id' => $this->f_cond,
            'fornitore_id'  => $this->f_forn,
            'status'        => $this->f_status,
        ]);

        $data  = Marrison_Assistant_Requests::get_list($filters, $current_page, $per_page);
        $this->items = $data['rows'];

        $this->set_pagination_args([
            'total_items' => $data['total'],
            'per_page'    => $per_page,
            'total_pages' => ceil($data['total'] / $per_page),
        ]);

        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];
    }
}

// ── Controller dashboard ─────────────────────────────────────────────────────
class Marrison_Assistant_Dashboard {

    public function __construct() {
        // admin_init gira prima di qualsiasi output: sicuro per i redirect
        add_action('admin_init', [$this, 'process_actions']);
    }

    public function add_menu() {
        add_submenu_page(
            'marrison-assistant',
            'Segnalazioni',
            'Segnalazioni',
            'manage_options',
            'marrison-segnalazioni',
            [$this, 'render']
        );
    }

    // ── Gestione azioni (singole e bulk) ─────────────────────────────────────
    public function process_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'marrison-segnalazioni') return;
        if (!current_user_can('manage_options')) return;

        $base = admin_url('admin.php?page=marrison-segnalazioni');

        // ── Azione singola da row action ─────────────────────────────────────
        if (!empty($_GET['marrison_action']) && !empty($_GET['id'])) {
            $act = sanitize_key($_GET['marrison_action']);
            $id  = (int) $_GET['id'];
            $non = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (wp_verify_nonce($non, 'marrison_seg_' . $id)) {
                if ($act === 'delete') {
                    Marrison_Assistant_Requests::delete($id);
                } elseif ($act === 'complete') {
                    $row = Marrison_Assistant_Requests::complete_by_id($id);
                    if ($row) Marrison_Assistant_Requests::send_completion_emails($row);
                }
            }
            wp_safe_redirect($base);
            exit;
        }

        // ── Azioni bulk (POST dal form WP_List_Table) ─────────────────────────
        $bulk = isset($_POST['action']) && $_POST['action'] !== '-1'
              ? sanitize_key($_POST['action'])
              : (isset($_POST['action2']) && $_POST['action2'] !== '-1' ? sanitize_key($_POST['action2']) : '');

        if ($bulk && !empty($_POST['seg_ids']) && check_admin_referer('bulk-segnalazioni')) {
            $ids = array_map('intval', (array) $_POST['seg_ids']);
            if ($bulk === 'bulk_delete') {
                foreach ($ids as $id) Marrison_Assistant_Requests::delete($id);
            } elseif ($bulk === 'bulk_complete') {
                foreach ($ids as $id) {
                    $row = Marrison_Assistant_Requests::complete_by_id($id);
                    if ($row) Marrison_Assistant_Requests::send_completion_emails($row);
                }
            }
            wp_safe_redirect($base);
            exit;
        }
    }

    // ── Rendering pagina ─────────────────────────────────────────────────────
    public function render() {
        if (!current_user_can('manage_options')) wp_die(__('Non autorizzato.'));

        $f_cond   = isset($_GET['cond'])   ? (int) $_GET['cond']           : 0;
        $f_forn   = isset($_GET['forn'])   ? (int) $_GET['forn']           : 0;
        $f_status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';

        $condominios = Marrison_Assistant_Requests::get_unique_condominios();
        $fornitori   = Marrison_Assistant_Requests::get_unique_fornitori();
        $base_url    = admin_url('admin.php?page=marrison-segnalazioni');

        $table = new Marrison_Segnalazioni_List_Table();
        $table->set_filters($f_cond, $f_forn, $f_status);
        $table->prepare_items();
        ?>
        <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            <span class="dashicons dashicons-list-view" style="font-size:28px;width:28px;height:28px;"></span>
            Segnalazioni ricevute
        </h1>

        <!-- Filtri -->
        <form method="get" style="margin:16px 0 10px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
            <input type="hidden" name="page" value="marrison-segnalazioni">
            <select name="cond" style="min-width:200px;">
                <option value="">— Tutti i condomini —</option>
                <?php foreach ($condominios as $c): ?>
                    <option value="<?php echo (int)$c->condominio_id; ?>" <?php selected($f_cond, $c->condominio_id); ?>>
                        <?php echo esc_html($c->condominio_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="forn" style="min-width:200px;">
                <option value="">— Tutti i fornitori —</option>
                <?php foreach ($fornitori as $f): ?>
                    <option value="<?php echo (int)$f->fornitore_id; ?>" <?php selected($f_forn, $f->fornitore_id); ?>>
                        <?php echo esc_html($f->fornitore_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value="">— Tutti gli stati —</option>
                <option value="pending"   <?php selected($f_status,'pending'); ?>>In attesa</option>
                <option value="completed" <?php selected($f_status,'completed'); ?>>Completato</option>
            </select>
            <button type="submit" class="button">Filtra</button>
            <?php if ($f_cond || $f_forn || $f_status): ?>
                <a href="<?php echo esc_url($base_url); ?>" class="button">Azzera</a>
            <?php endif; ?>
        </form>

        <!-- Tabella WP_List_Table (il form e il nonce bulk sono gestiti da display()) -->
        <form method="post">
            <?php $table->display(); ?>
        </form>

        </div>
        <?php
    }
}
