<?php
if (!defined('ABSPATH')) exit;

/**
 * Dashboard delle segnalazioni nel pannello di amministrazione.
 */
class Marrison_Assistant_Dashboard {

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

    public function render() {
        if (!current_user_can('manage_options')) wp_die(__('Non autorizzato.'));

        // ── Gestione azioni (elimina / completa) ───────────────────────────
        $action  = isset($_GET['action'])   ? sanitize_key($_GET['action'])                       : '';
        $act_id  = isset($_GET['id'])       ? (int) $_GET['id']                                   : 0;
        $act_non = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce']))  : '';
        $clean_url = admin_url('admin.php?page=marrison-segnalazioni');

        if ($action === 'delete_seg' && $act_id && wp_verify_nonce($act_non, 'marrison_delete_seg')) {
            Marrison_Assistant_Requests::delete($act_id);
            wp_safe_redirect($clean_url);
            exit;
        }

        if ($action === 'complete_seg' && $act_id && wp_verify_nonce($act_non, 'marrison_complete_seg')) {
            $completed_row = Marrison_Assistant_Requests::complete_by_id($act_id);
            if ($completed_row) {
                Marrison_Assistant_Requests::send_completion_emails($completed_row);
            }
            wp_safe_redirect($clean_url);
            exit;
        }

        // Filtri dalla query string
        $f_cond   = isset($_GET['cond'])   ? (int) $_GET['cond']              : 0;
        $f_forn   = isset($_GET['forn'])   ? (int) $_GET['forn']              : 0;
        $f_status = isset($_GET['status']) ? sanitize_key($_GET['status'])    : '';
        $page     = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $per_page = 25;

        $filters = array_filter([
            'condominio_id' => $f_cond,
            'fornitore_id'  => $f_forn,
            'status'        => $f_status,
        ]);

        $data        = Marrison_Assistant_Requests::get_list($filters, $page, $per_page);
        $rows        = $data['rows'];
        $total       = $data['total'];
        $total_pages = max(1, ceil($total / $per_page));
        $condominios = Marrison_Assistant_Requests::get_unique_condominios();
        $fornitori   = Marrison_Assistant_Requests::get_unique_fornitori();

        $base_url = admin_url('admin.php?page=marrison-segnalazioni');
        ?>
        <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            <span class="dashicons dashicons-list-view" style="font-size:28px;width:28px;height:28px;"></span>
            Segnalazioni ricevute
        </h1>

        <!-- Filtri -->
        <form method="get" style="margin:16px 0 20px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
            <input type="hidden" name="page" value="marrison-segnalazioni">

            <select name="cond" style="min-width:200px;">
                <option value="">— Tutti i condomini —</option>
                <?php foreach ($condominios as $c): ?>
                    <option value="<?php echo (int)$c->condominio_id; ?>"
                        <?php selected($f_cond, $c->condominio_id); ?>>
                        <?php echo esc_html($c->condominio_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="forn" style="min-width:200px;">
                <option value="">— Tutti i fornitori —</option>
                <?php foreach ($fornitori as $f): ?>
                    <option value="<?php echo (int)$f->fornitore_id; ?>"
                        <?php selected($f_forn, $f->fornitore_id); ?>>
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
            <?php if ($filters): ?>
                <a href="<?php echo esc_url($base_url); ?>" class="button">Azzera</a>
            <?php endif; ?>

            <span style="margin-left:auto;color:#666;font-size:13px;">
                <?php echo $total; ?> segnalazion<?php echo $total == 1 ? 'e' : 'i'; ?>
            </span>
        </form>

        <!-- Tabella -->
        <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
            <thead>
                <tr>
                    <th style="width:130px;">Data</th>
                    <th style="width:180px;">Condominio</th>
                    <th style="width:180px;">Fornitore / Modalità</th>
                    <th>Problema</th>
                    <th style="width:180px;">Condòmino</th>
                    <th style="width:100px;">Stato</th>
                    <th style="width:130px;">Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#666;">Nessuna segnalazione trovata.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <span title="<?php echo esc_attr($r->created_at); ?>">
                            <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($r->created_at))); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($r->condominio_name ?: '—'); ?></td>
                    <td>
                        <?php if ($r->admin_only): ?>
                            <span style="color:#6366f1;font-style:italic;">Solo amministratore</span>
                        <?php else: ?>
                            <?php echo esc_html($r->fornitore_name ?: '—'); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span title="<?php echo esc_attr($r->problema); ?>">
                            <?php echo esc_html(mb_strimwidth($r->problema, 0, 120, '…')); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($r->inquilino_email ?: '—'); ?></td>
                    <td>
                        <?php if ($r->status === 'completed'): ?>
                            <span style="display:inline-block;white-space:nowrap;background:#dcfce7;color:#166534;padding:3px 8px;border-radius:20px;font-size:12px;font-weight:600;">✓ Completato</span>
                            <?php if ($r->completed_at): ?>
                                <br><small style="color:#666;"><?php echo esc_html(date_i18n('d/m/Y', strtotime($r->completed_at))); ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="display:inline-block;white-space:nowrap;background:#fef9c3;color:#713f12;padding:3px 8px;border-radius:20px;font-size:12px;font-weight:600;">⏳ In attesa</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <?php if ($r->status !== 'completed'): ?>
                        <?php $cmp_url = wp_nonce_url(add_query_arg(['action' => 'complete_seg', 'id' => $r->id], $base_url), 'marrison_complete_seg'); ?>
                        <a href="<?php echo esc_url($cmp_url); ?>"
                           onclick="return confirm('Segnare come completato e inviare notifiche?');"
                           style="color:#166534;text-decoration:none;font-size:12px;margin-right:8px;" title="Segna completato">✓ Completa</a>
                        <?php endif; ?>
                        <?php $del_url = wp_nonce_url(add_query_arg(['action' => 'delete_seg', 'id' => $r->id], $base_url), 'marrison_delete_seg'); ?>
                        <a href="<?php echo esc_url($del_url); ?>"
                           onclick="return confirm('Eliminare questa segnalazione?');"
                           style="color:#b91c1c;text-decoration:none;font-size:12px;" title="Elimina">✕ Elimina</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- Paginazione -->
        <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom" style="margin-top:12px;">
            <div class="tablenav-pages">
                <?php
                $page_links = paginate_links([
                    'base'      => add_query_arg('paged', '%#%', $base_url . '&' . http_build_query(array_filter([
                        'cond'   => $f_cond   ?: null,
                        'forn'   => $f_forn   ?: null,
                        'status' => $f_status ?: null,
                    ]))),
                    'format'    => '',
                    'current'   => $page,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ]);
                echo $page_links;
                ?>
            </div>
        </div>
        <?php endif; ?>

        </div><!-- .wrap -->
        <?php
    }
}
