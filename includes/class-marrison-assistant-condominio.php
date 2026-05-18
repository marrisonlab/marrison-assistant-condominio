<?php
/**
 * Gestione condomini: ricerca CPT, relazioni JetEngine, classificazione AI, invio email.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marrison_Assistant_Condominio {

    /**
     * Scrive nel file wp-content/marrison-debug.log (visibile via FTP, indipendente da WP_DEBUG).
     */
    private function mlog($msg) {
        $file = WP_CONTENT_DIR . '/marrison-debug.log';
        file_put_contents($file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Cerca condomini per nome (post_title) o indirizzo (meta: indirizzo_condominio).
     * La ricerca è tokenizzata: ogni parola significativa viene cercata separatamente
     * per gestire input parziali o in ordine diverso (es. "via franceschini").
     *
     * @param  string $query Testo libero inserito dall'utente.
     * @return array  Array di ['id', 'nome', 'indirizzo', 'label'], ordinati per punteggio.
     */
    public function search($query) {
        $query = sanitize_text_field(trim($query));
        if ($query === '') return array();

        // Tokenizza: parole >= 3 caratteri (evita "di", "il", "la", "via" è mantenuto)
        $words = array_values(array_unique(array_filter(
            preg_split('/[\s,]+/', mb_strtolower($query)),
            function ($w) { return mb_strlen($w) >= 2; }
        )));
        if (empty($words)) {
            $words = array(mb_strtolower($query));
        }

        $scored = array(); // post_id => punteggio

        // ── Strategia A: ricerca frase esatta WordPress (max affidabilità) ────
        $phrase = get_posts(array(
            'post_type'      => 'condominio',
            'posts_per_page' => 10,
            's'              => $query,
            'post_status'    => 'publish',
        ));
        foreach ($phrase as $p) {
            $scored[$p->ID] = ($scored[$p->ID] ?? 0) + count($words) + 5;
        }

        // ── Strategia B: LIKE per singola parola su titolo e meta ────────────
        $word_ids = $this->search_by_words($words);
        foreach ($word_ids as $item) {
            $id    = $item['id'];
            $score = $item['score'];
            $scored[$id] = ($scored[$id] ?? 0) + $score;
        }

        if (empty($scored)) return array();

        arsort($scored);
        $ordered_ids = array_slice(array_keys($scored), 0, 5);

        $posts = get_posts(array(
            'post_type'      => 'condominio',
            'post__in'       => $ordered_ids,
            'posts_per_page' => 5,
            'post_status'    => 'publish',
            'orderby'        => 'post__in',
        ));

        // Reindizza per ID per rispettare l'ordinamento per punteggio
        $post_map = array();
        foreach ($posts as $p) {
            $post_map[$p->ID] = $p;
        }

        $results = array();
        foreach ($ordered_ids as $id) {
            if (!isset($post_map[$id])) continue;
            $p         = $post_map[$id];
            $indirizzo = get_post_meta($id, 'indirizzo_condominio', true);
            $results[] = array(
                'id'        => $id,
                'nome'      => $p->post_title,
                'indirizzo' => $indirizzo ?: '',
                'label'     => $p->post_title . ($indirizzo ? ' — ' . $indirizzo : ''),
            );
        }
        return $results;
    }

    /**
     * Cerca condomini word-by-word via SQL LIKE su titolo e indirizzo_condominio.
     * Restituisce array di ['id' => int, 'score' => int] dove score = num. parole trovate.
     */
    private function search_by_words(array $words) {
        global $wpdb;

        // Costruisce condizioni LIKE per titolo e meta
        $title_conds = array();
        $meta_conds  = array();
        $title_params = array();
        $meta_params  = array();

        foreach ($words as $word) {
            $like           = '%' . $wpdb->esc_like($word) . '%';
            $title_conds[]  = 'LOWER(p.post_title) LIKE %s';
            $title_params[] = $like;
            $meta_conds[]   = 'LOWER(pm.meta_value) LIKE %s';
            $meta_params[]  = $like;
        }

        $found = array(); // id => score

        // Ricerca nel titolo
        if (!empty($title_conds)) {
            $sql = $wpdb->prepare(
                "SELECT p.ID, p.post_title
                 FROM {$wpdb->posts} p
                 WHERE p.post_type = 'condominio'
                 AND p.post_status = 'publish'
                 AND (" . implode(' OR ', $title_conds) . ")
                 LIMIT 20",
                ...$title_params
            );
            $rows = $wpdb->get_results($sql);
            foreach ($rows as $row) {
                $title_lower = mb_strtolower($row->post_title);
                $hits = 0;
                foreach ($words as $w) {
                    if (mb_strpos($title_lower, $w) !== false) $hits++;
                }
                $found[(int)$row->ID] = ($found[(int)$row->ID] ?? 0) + $hits * 2;
            }
        }

        // Ricerca nell'indirizzo (meta)
        if (!empty($meta_conds)) {
            $sql = $wpdb->prepare(
                "SELECT p.ID, pm.meta_value
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'condominio'
                 AND p.post_status = 'publish'
                 AND pm.meta_key = 'indirizzo_condominio'
                 AND (" . implode(' OR ', $meta_conds) . ")
                 LIMIT 20",
                ...$meta_params
            );
            $rows = $wpdb->get_results($sql);
            foreach ($rows as $row) {
                $meta_lower = mb_strtolower($row->meta_value);
                $hits = 0;
                foreach ($words as $w) {
                    if (mb_strpos($meta_lower, $w) !== false) $hits++;
                }
                $found[(int)$row->ID] = ($found[(int)$row->ID] ?? 0) + $hits;
            }
        }

        $result = array();
        foreach ($found as $id => $score) {
            $result[] = array('id' => $id, 'score' => $score);
        }
        return $result;
    }

    /**
     * Restituisce i fornitori associati al condominio tramite JetEngine Relations.
     * Se non ci sono relazioni, restituisce tutti i fornitori pubblicati.
     *
     * @param  int   $condominio_id
     * @return array Array di ['id', 'nome', 'mail', 'tel']
     */
    public function get_fornitori($condominio_id) {
        $ids = $this->get_fornitori_ids($condominio_id);
        $this->mlog('get_fornitori(' . $condominio_id . ') IDs trovati: ' . (empty($ids) ? 'nessuno' : implode(', ', $ids)));

        if (empty($ids)) {
            return array();
        }

        $posts = get_posts(array(
            'post_type'      => 'fornitore',
            'post__in'       => $ids,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'post__in',
        ));

        $result = array();
        foreach ($posts as $p) {
            $result[] = array(
                'id'         => $p->ID,
                'nome'       => $p->post_title,
                'mail'       => get_post_meta($p->ID, 'mail_fornitore',       true) ?: '',
                'tel'        => get_post_meta($p->ID, 'tel_fornitore',        true) ?: '',
                'tipologia'  => $this->decode_meta_value(get_post_meta($p->ID, 'tipologia_fornitore', true)),
                'operazioni' => (string)(get_post_meta($p->ID, 'operazioni_fornitore', true) ?: ''),
            );
        }
        return $result;
    }

    /**
     * Decodifica un valore meta JetEngine checkboxlist.
     *
     * JetEngine checkboxlist (con glossary) serializza i dati come:
     *   array( 'Idraulico' => 'true', 'Elettricista' => 'false', ... )
     * oppure (glossary con ID numerici):
     *   array( '3' => 'true', '5' => 'false', ... )
     *
     * In entrambi i casi si restituiscono solo le CHIAVI con valore truthy.
     * Se le chiavi sono numeriche (ID glossary) si restituiscono comunque
     * per non perdere informazione; l'amministratore può passare a un campo text.
     */
    private function decode_meta_value($value) {
        $is_selected = function ($v) {
            if (is_bool($v))   return $v;
            if (is_int($v))    return $v === 1;
            if (is_string($v)) return strtolower(trim($v)) === 'true' || $v === '1';
            return false;
        };

        if (is_array($value)) {
            // Associativo: chiavi = nomi/ID opzione, valori = true/false
            if (array_keys($value) !== range(0, count($value) - 1)) {
                $selected = array_keys(array_filter($value, $is_selected));
                return implode(', ', $selected);
            }
            // Indicizzato: ['Idraulico', 'Elettricista']
            return implode(', ', array_filter($value));
        }

        if (is_string($value) && $value !== '') {
            $uns = @unserialize($value);
            if ($uns !== false && is_array($uns)) {
                if (array_keys($uns) !== range(0, count($uns) - 1)) {
                    $selected = array_keys(array_filter($uns, $is_selected));
                    return implode(', ', $selected);
                }
                return implode(', ', array_filter($uns));
            }
            $json = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                if (array_keys($json) !== range(0, count($json) - 1)) {
                    $selected = array_keys(array_filter($json, $is_selected));
                    return implode(', ', $selected);
                }
                return implode(', ', array_filter($json));
            }
        }

        return (string) $value;
    }

    /**
     * Recupera gli ID dei fornitori collegati tramite JetEngine (API + fallback DB).
     */
    private function get_fornitori_ids($condominio_id) {
        // ── Tentativo 1: JetEngine Relations public API ──────────────────────
        if (function_exists('jet_engine') && isset(jet_engine()->relations)) {
            try {
                $relations = jet_engine()->relations->get_active_relations();
                if (!empty($relations)) {
                    foreach ($relations as $relation) {
                        $args = method_exists($relation, 'get_args') ? $relation->get_args() : array();

                        // Compatibilità JetEngine 2.x (parent_post_type) e 3.x (parent_object)
                        $parent_type = isset($args['parent_object'])    ? $args['parent_object']
                                     : (isset($args['parent_post_type']) ? $args['parent_post_type'] : '');
                        $child_type  = isset($args['child_object'])     ? $args['child_object']
                                     : (isset($args['child_post_type'])  ? $args['child_post_type']  : '');

                        // JetEngine 3.x usa prefisso "posts::" → estrai solo lo slug CPT
                        $parent_slug = strpos($parent_type, '::') !== false ? explode('::', $parent_type)[1] : $parent_type;
                        $child_slug  = strpos($child_type,  '::') !== false ? explode('::', $child_type)[1]  : $child_type;

                        $this->mlog('relazione — parent=' . $parent_type . ' (' . $parent_slug . ') child=' . $child_type . ' (' . $child_slug . ')');

                        if (!in_array('condominio', array($parent_slug, $child_slug), true) ||
                            !in_array('fornitore',  array($parent_slug, $child_slug), true)) {
                            continue;
                        }

                        $rel_id = method_exists($relation, 'get_id') ? $relation->get_id() : null;
                        // 'from' deve essere 'parent' o 'child', non il nome del CPT
                        $from   = ($parent_slug === 'condominio') ? 'parent' : 'child';

                        $this->mlog('chiamo get_related_posts rel_id=' . $rel_id . ' from=' . $from . ' post_id=' . $condominio_id);

                        $related = jet_engine()->relations->get_related_posts(array(
                            'post_id' => $condominio_id,
                            'rel_id'  => $rel_id,
                            'from'    => $from,
                        ));

                        $this->mlog('get_related_posts result=' . print_r($related, true));

                        if (!empty($related)) {
                            if (is_object(reset($related))) {
                                return wp_list_pluck($related, 'ID');
                            }
                            if (is_array(reset($related)) && isset(reset($related)['id'])) {
                                return array_column($related, 'id');
                            }
                            return array_map('intval', $related);
                        }
                    }
                }
            } catch (Exception $e) {
                $this->mlog('JetEngine API error: ' . $e->getMessage());
            }
        }

        // ── Tentativo 2: query diretta tabella wp_jet_rel_items ──────────────
        return $this->get_fornitori_ids_db($condominio_id);
    }

    /**
     * Query diretta sulla tabella JetEngine wp_jet_rel_items.
     */
    private function get_fornitori_ids_db($condominio_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'jet_rel_items';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            $this->mlog('tabella ' . $table . ' non trovata');
            return array();
        }

        // Rileva le colonne disponibili per compatibilita' con diverse versioni JetEngine
        $cols = $wpdb->get_col('DESCRIBE ' . $table, 0);
        $this->mlog('colonne ' . $table . ': ' . implode(', ', $cols));

        if (in_array('parent_object_id', $cols, true) && in_array('child_object_id', $cols, true)) {
            $pc = 'parent_object_id';
            $cc = 'child_object_id';
        } elseif (in_array('object_1', $cols, true) && in_array('object_2', $cols, true)) {
            $pc = 'object_1';
            $cc = 'object_2';
        } else {
            $this->mlog('struttura tabella JetEngine non riconosciuta');
            return array();
        }

        // Conta righe totali per diagnostica
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
        $this->mlog('righe totali in ' . $table . ': ' . $total);

        // Legge in entrambe le direzioni (parent->child e child->parent)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cc} AS fid FROM {$table} WHERE {$pc} = %d
             UNION
             SELECT {$pc} AS fid FROM {$table} WHERE {$cc} = %d",
            $condominio_id,
            $condominio_id
        ));

        $this->mlog('DB query per condominio_id=' . $condominio_id . ' restituisce ' . count($rows) . ' righe');

        if (empty($rows)) {
            return array();
        }

        $all_ids = array_map(function ($r) { return (int) $r->fid; }, $rows);
        $this->mlog('IDs relazionati (pre-filtro): ' . implode(', ', $all_ids));

        // Filtra per post_type = fornitore
        $forn_ids = get_posts(array(
            'post_type'      => 'fornitore',
            'post__in'       => $all_ids,
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ));
        $this->mlog('IDs fornitore dopo filtro: ' . (empty($forn_ids) ? 'nessuno' : implode(', ', $forn_ids)));
        return $forn_ids;
    }

    /**
     * Usa Gemini per identificare il fornitore più adatto al problema descritto.
     *
     * @param  string $problema    Descrizione del problema.
     * @param  array  $fornitori   Array di ['id','nome','mail','tel'].
     * @return array|null          Fornitore scelto o null.
     */
    public function classify_problem($problema, $fornitori) {
        if (empty($fornitori)) {
            return null;
        }
        if (count($fornitori) === 1) {
            return $fornitori[0];
        }

        $gemini = new Marrison_Assistant_Gemini();

        $sections = array_map(function ($f) {
            $s  = "---\n";
            $s .= 'ID: '    . $f['id']   . "\n";
            $s .= 'Nome: '  . $f['nome'] . "\n";
            if (!empty($f['tipologia']))  $s .= 'Tipologia: ' . $f['tipologia']  . "\n";
            if (!empty($f['operazioni'])) $s .= 'Operazioni coperte: ' . $f['operazioni'] . "\n";
            return $s;
        }, $fornitori);

        $list = implode("\n", $sections) . '---';

        $prompt =
            "Sei un esperto di gestione condominiale. Devi identificare il fornitore corretto per un problema segnalato.\n\n" .
            "PROBLEMA SEGNALATO: \"" . $problema . "\"\n\n" .
            "PASSO 1 — Classifica il problema:\n" .
            "Leggi il problema e determina che tipo di intervento richiede. Esempi:\n" .
            "- Acqua, allagamento, perdita, umidità, tubature, scarichi, caldaia, riscaldamento → IDRAULICO\n" .
            "- Luce, corrente, interruttore, corto circuito, citofono, cancello elettrico, cancello automatico, cancello motorizzato, motore cancello, videocitofono, quadro elettrico → ELETTRICISTA\n" .
            "- Portone, serratura, cancello bloccato meccanicamente, chiave, lucchetto → FABBRO\n" .
            "- Pareti, crepe, infiltrazioni murarie, intonaco, pavimento → MURATORE/IMPRESA EDILE\n" .
            "- Legno, porta in legno, infissi, scale in legno → FALEGNAME\n\n" .
            "PASSO 2 — Confronta con i fornitori disponibili:\n" .
            $list . "\n\n" .
            "PASSO 3 — Scegli il fornitore la cui TIPOLOGIA e OPERAZIONI COPERTE corrispondono al tipo di intervento identificato.\n\n" .
            "RISPOSTA FINALE: Rispondi con ESATTAMENTE due righe nel formato seguente (nessun altro testo):\n" .
            "ID: <numero_intero>\n" .
            "MOTIVO: <spiegazione breve in italiano>";

        $response = $gemini->query($prompt, 'condominio_classify');

        if ($response) {
            $found_id     = null;
            $found_motivo = '';

            // Estrai ID: cerca un numero dopo "ID:" (case-insensitive, anche con spazi)
            if (preg_match('/\bID\s*:\s*(\d+)/i', $response, $m)) {
                $found_id = (int) $m[1];
            }
            // Estrai MOTIVO: tutto ciò che segue "MOTIVO:"
            if (preg_match('/\bMOTIVO\s*:\s*(.+)/i', $response, $mm)) {
                $found_motivo = trim($mm[1]);
            }

            if ($found_id !== null) {
                foreach ($fornitori as $f) {
                    if ((int) $f['id'] === $found_id) {
                        $f['motivo'] = $found_motivo;
                        return $f;
                    }
                }
            }

            // Fallback: cerca qualsiasi numero presente negli ID fornitori
            $valid_ids = array_column($fornitori, 'id');
            if (preg_match_all('/(\d+)/', $response, $matches)) {
                foreach ($matches[1] as $num) {
                    $num = (int) $num;
                    if (in_array($num, $valid_ids, false)) {
                        foreach ($fornitori as $f) {
                            if ((int) $f['id'] === $num) {
                                return $f;
                            }
                        }
                    }
                }
            }
        }

        // Nessuna corrispondenza trovata: restituisce null per attivare selezione manuale
        return null;
    }

    /**
     * Invia le tre email: fornitore, amministratore, condòmino.
     *
     * @param  int    $condominio_id
     * @param  int    $fornitore_id
     * @param  string $problema
     * @param  string $inquilino_email
     * @return array  Risultati wp_mail per chiave 'fornitore','admin','inquilino'.
     */
    public function send_emails($condominio_id, $fornitore_id, $problema, $inquilino_email) {
        $condominio  = get_post($condominio_id);
        $fornitore   = get_post($fornitore_id);
        $indirizzo   = get_post_meta($condominio_id, 'indirizzo_condominio', true);
        $mail_forn   = get_post_meta($fornitore_id, 'mail_fornitore', true);
        $tel_forn    = get_post_meta($fornitore_id, 'tel_fornitore', true);
        $admin_email = get_option('admin_email');
        $site_name   = get_bloginfo('name');
        $headers     = array('Content-Type: text/plain; charset=UTF-8');
        $sent        = array();

        $cond_nome = $condominio ? $condominio->post_title : "Condominio #{$condominio_id}";
        $forn_nome = $fornitore  ? $fornitore->post_title  : "Fornitore #{$fornitore_id}";

        // ── Email al fornitore ───────────────────────────────────────────────
        if ($mail_forn) {
            $subj  = "[{$site_name}] Nuova segnalazione — {$cond_nome}";
            $body  = "Gentile {$forn_nome},\n\n";
            $body .= "è pervenuta una segnalazione da un condòmino.\n\n";
            $body .= "Condominio: {$cond_nome}\n";
            if ($indirizzo) $body .= "Indirizzo:   {$indirizzo}\n";
            $body .= "\nProblema segnalato:\n{$problema}\n\n";
            $body .= "Contatto condòmino: {$inquilino_email}\n\n";
            $body .= "Si prega di prendere in carico la richiesta il prima possibile.\n\n";
            $body .= "Cordiali saluti,\n{$site_name}";
            $sent['fornitore'] = wp_mail($mail_forn, $subj, $body, $headers);
        } else {
            $sent['fornitore'] = false;
        }

        // ── Email all'amministratore ─────────────────────────────────────────
        $subj_a  = "[{$site_name}] Segnalazione inoltrata — {$cond_nome}";
        $body_a  = "Una segnalazione è stata inoltrata automaticamente al fornitore.\n\n";
        $body_a .= "Condominio:  {$cond_nome}\n";
        if ($indirizzo) $body_a .= "Indirizzo:   {$indirizzo}\n";
        $body_a .= "Problema:    {$problema}\n";
        $body_a .= "Fornitore:   {$forn_nome}";
        if ($mail_forn) $body_a .= " <{$mail_forn}>";
        if ($tel_forn)  $body_a .= " — Tel: {$tel_forn}";
        $body_a .= "\nCondòmino:   {$inquilino_email}\n\n";
        $body_a .= "Cordiali saluti,\n{$site_name}";
        $sent['admin'] = wp_mail($admin_email, $subj_a, $body_a, $headers);

        // ── Email al condòmino ───────────────────────────────────────────────
        $subj_i  = "Conferma segnalazione — {$cond_nome}";
        $body_i  = "La sua segnalazione è stata registrata e inoltrata al fornitore competente.\n\n";
        $body_i .= "Condominio:  {$cond_nome}\n";
        if ($indirizzo) $body_i .= "Indirizzo:   {$indirizzo}\n";
        $body_i .= "Problema:    {$problema}\n";
        $body_i .= "Fornitore:   {$forn_nome}\n";
        if ($tel_forn) $body_i .= "Tel:         {$tel_forn}\n";
        $body_i .= "\nSarà contattato al più presto.\n\n";
        $body_i .= "Cordiali saluti,\n{$site_name}";
        $sent['inquilino'] = wp_mail($inquilino_email, $subj_i, $body_i, $headers);

        return $sent;
    }
}
