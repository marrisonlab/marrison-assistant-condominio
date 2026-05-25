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

        // Stopwords: prefissi stradali e articoli italiani che non aiutano la ricerca
        $stopwords = array(
            'via', 'viale', 'piazza', 'p.zza', 'corso', 'c.so', 'vicolo', 'strada',
            'largo', 'borgo', 'contrada', 'c.da', 'loc', 'localita', 'località',
            'del', 'della', 'delle', 'dei', 'degli', 'di', 'da', 'in', 'su',
            'il', 'la', 'lo', 'le', 'gli', 'un', 'una', 'al', 'alla',
        );

        // Tokenizza: parole >= 2 caratteri, escluse stopwords
        $all_words = array_values(array_unique(array_filter(
            preg_split('/[\s,\.]+/', mb_strtolower($query)),
            function ($w) { return mb_strlen($w) >= 2; }
        )));
        $words = array_values(array_filter($all_words, function($w) use ($stopwords) {
            return !in_array($w, $stopwords, true);
        }));
        // Se tutti i token erano stopwords, usa i token originali
        if (empty($words)) {
            $words = $all_words ?: array(mb_strtolower($query));
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

        // Soglia minima: richiedere che ogni token significativo abbia contribuito almeno 1 punto.
        // Score minimo atteso = numero di parole significative (1 pt per match meta, 2 per titolo).
        $min_score = count($words);
        $scored = array_filter($scored, function($s) use ($min_score) { return $s >= $min_score; });
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
            $display = $indirizzo ? 'Condominio di ' . $indirizzo : $p->post_title;
            $results[] = array(
                'id'        => $id,
                'nome'      => $p->post_title,
                'indirizzo' => $indirizzo ?: '',
                'label'     => $display,
            );
        }

        // Post-filtro: ogni parola significativa deve apparire nel titolo o nell'indirizzo
        // Questo elimina falsi positivi causati dal boost della ricerca frase (Strategia A)
        $results = array_values(array_filter($results, function ($r) use ($words) {
            $haystack = mb_strtolower($r['nome'] . ' ' . $r['indirizzo']);
            foreach ($words as $w) {
                if (mb_strpos($haystack, $w) === false) return false;
            }
            return true;
        }));

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

    // ── Cache JSON fornitori ─────────────────────────────────────────────────

    private function cache_dir() {
        return WP_CONTENT_DIR . '/marrison-cache';
    }

    private function cache_file($condominio_id) {
        return $this->cache_dir() . '/fornitori-' . (int) $condominio_id . '.json';
    }

    /**
     * Legge la cache JSON per un condominio; restituisce null se assente o corrotta.
     */
    private function read_cache($condominio_id) {
        $file = $this->cache_file($condominio_id);
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Salva l'array fornitori come JSON ottimizzato per un condominio.
     */
    private function write_cache($condominio_id, array $fornitori) {
        $dir = $this->cache_dir();
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            // Proteggi la directory da accesso web diretto
            file_put_contents($dir . '/.htaccess', "Deny from all\n");
        }
        file_put_contents(
            $this->cache_file($condominio_id),
            json_encode($fornitori, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    /**
     * Elimina il file cache di uno o tutti i condominii.
     * Chiamato dai hook WordPress su save_post.
     *
     * @param  int|null $condominio_id  Se null, elimina tutto il cache.
     */
    public static function invalidate_cache($condominio_id = null) {
        $dir = WP_CONTENT_DIR . '/marrison-cache';
        if (!file_exists($dir)) return;
        if ($condominio_id) {
            $file = $dir . '/fornitori-' . (int) $condominio_id . '.json';
            if (file_exists($file)) @unlink($file);
        } else {
            foreach (glob($dir . '/fornitori-*.json') ?: array() as $f) {
                @unlink($f);
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Restituisce i fornitori associati al condominio tramite JetEngine Relations.
     * Usa il cache JSON se disponibile; altrimenti esegue la query DB e salva la cache.
     *
     * @param  int   $condominio_id
     * @return array Array di ['id', 'nome', 'mail', 'tel', 'tipologia', 'operazioni']
     */
    public function get_fornitori($condominio_id) {
        // ── Leggi cache ──────────────────────────────────────────────────────
        $cached = $this->read_cache($condominio_id);
        if ($cached !== null) {
            $this->mlog('get_fornitori(' . $condominio_id . ') — cache hit (' . count($cached) . ' fornitori)');
            return $cached;
        }

        // ── Query DB ─────────────────────────────────────────────────────────
        $ids = $this->get_fornitori_ids($condominio_id);
        $this->mlog('get_fornitori(' . $condominio_id . ') — cache miss, IDs: ' . (empty($ids) ? 'nessuno' : implode(', ', $ids)));

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

        // ── Salva cache ──────────────────────────────────────────────────────
        $this->write_cache($condominio_id, $result);

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

        // Supporta valori multipli separati da | (es. "Elettricista|Fabbro|Manutenzione cancello")
        $str = (string) $value;
        if (strpos($str, '|') !== false) {
            $parts = array_map('trim', explode('|', $str));
            $parts = array_filter($parts);
            return implode(', ', $parts);
        }
        return $str;
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
        $alt_table = $wpdb->prefix . 'jet_rel_default';

        // Usa ma_jet_rel_default se esiste (altrimenti fallback a standard)
        if ($wpdb->get_var("SHOW TABLES LIKE '{$alt_table}'") === $alt_table) {
            $this->mlog('uso tabella ma_jet_rel_default');
            $table = $alt_table;
        } elseif ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            $this->mlog('tabella ' . $table . ' non trovata');
            return array();
        }

        // Colnote note: _ID, created, rel_id, parent_rel, parent_object_id, child_object_id
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
    public function classify_problem($problema, $fornitori, $no_question = false) {
        if (empty($fornitori)) {
            return null;
        }
        $gemini = new Marrison_Assistant_Gemini();

        $sections = array_map(function ($f) {
            $s  = "---\n";
            $s .= 'ID: '    . $f['id']   . "\n";
            $s .= 'Nome: '  . $f['nome'] . "\n";
            if (!empty($f['tipologia']))  $s .= 'Tipologia: ' . $f['tipologia']  . "\n";
            if (!empty($f['operazioni'])) {
                $op = mb_substr($f['operazioni'], 0, 200);
                if (mb_strlen($f['operazioni']) > 200) $op .= '...';
                $s .= 'Operazioni: ' . $op . "\n";
            }
            return $s;
        }, $fornitori);

        $list = implode("\n", $sections) . '---';

        $prompt =
            "Sei un assistente per la gestione condominiale. Identifica il fornitore più adatto al problema segnalato.\n\n" .
            "PROBLEMA: \"" . $problema . "\"\n\n" .
            "FORNITORI DISPONIBILI:\n" .
            $list . "\n\n" .
            "REGOLE:\n" .
            "- Scegli il fornitore la cui Tipologia o Operazioni copre il problema.\n" .
            "- I titoli professionali coprono il loro settore: 'Ascensorista' → ascensori, 'Elettricista' → impianti elettrici, 'Idraulico' → impianti idrici, 'Fabbro' → serrature e cancelli meccanici, ecc.\n" .
            "- NON scegliere un fornitore con Tipologia incompatibile.\n" .
            "- Se il problema è troppo generico per capire il tipo di intervento, aggiungi la riga DOMANDA con una domanda di chiarimento.\n\n" .
            "RISPOSTA (solo queste righe, nessun altro testo):\n" .
            "ID: <numero del fornitore adatto, oppure 0 se nessuno è compatibile>\n" .
            "RUOLO: <copia ESATTAMENTE uno dei valori dalla Tipologia del fornitore scelto — ometti se ID è 0>\n" .
            "MOTIVO: <spiegazione breve in italiano>\n" .
            "DOMANDA: <domanda breve — aggiungi solo se ID è 0 e il problema è troppo vago>";

        $this->mlog('classify_problem input: "' . $problema . '" — lista fornitori: ' . json_encode(array_map(fn($f) => ['id'=>$f['id'],'nome'=>$f['nome'],'tipologia'=>$f['tipologia']??'','operazioni'=>$f['operazioni']??''], $fornitori)));
        $response = $gemini->query($prompt, 'condominio_classify');
        if ($response === false) {
            $this->mlog('classify_problem: Commander fallito, retry tra 2s...');
            sleep(2);
            $response = $gemini->query($prompt, 'condominio_classify');
        }
        $this->mlog('classify_problem AI raw: ' . json_encode($response));

        if ($response) {
            $found_id       = null;
            $found_motivo   = '';
            $found_ruolo    = '';
            $found_domanda  = '';

            // Estrai ID: cerca un numero dopo "ID:" (case-insensitive, anche con spazi)
            if (preg_match('/\bID\s*:\s*(\d+)/i', $response, $m)) {
                $found_id = (int) $m[1];
            }
            // Estrai RUOLO: ruolo specifico matchato
            if (preg_match('/\bRUOLO\s*:\s*(.+)/i', $response, $mr)) {
                $found_ruolo = trim($mr[1]);
            }
            // Estrai DOMANDA: domanda di chiarimento
            if (preg_match('/\bDOMANDA\s*:\s*(.+)/i', $response, $mq)) {
                $found_domanda = trim($mq[1]);
            }
            // Estrai MOTIVO: tutto ciò che segue "MOTIVO:"
            if (preg_match('/\bMOTIVO\s*:\s*(.+)/i', $response, $mm)) {
                $found_motivo = trim($mm[1]);
            }

            // ID: 0 — controlla se l'AI vuole fare una domanda di chiarimento
            if ($found_id === 0) {
                if (!empty($found_domanda) && !$no_question) {
                    $this->mlog('classify_problem: AI chiede chiarimento — ' . $found_domanda);
                    return array('question' => $found_domanda);
                }
                $this->mlog('classify_problem: AI risponde ID:0 (nessun fornitore adatto) — ' . $found_motivo);
                return null;
            }

            if ($found_id !== null) {
                foreach ($fornitori as $f) {
                    if ((int) $f['id'] === $found_id) {
                        // Validazione PHP: RUOLO deve essere compatibile con la Tipologia del fornitore
                        if (!empty($found_ruolo) && !empty($f['tipologia'])) {
                            $tip_lower   = mb_strtolower($f['tipologia']);
                            $ruolo_lower = mb_strtolower($found_ruolo);
                            $tip_parts   = array_map('trim', explode(',', $tip_lower));
                            $exact_match = in_array($ruolo_lower, $tip_parts);
                            $substr_match = strpos($tip_lower, $ruolo_lower) !== false;
                            if (!$exact_match && !$substr_match) {
                                $this->mlog('classify_problem: RUOLO AI "' . $found_ruolo . '" incompatibile con tipologia "' . $f['tipologia'] . '" — fornitore scartato, selezione manuale');
                                return null;
                            }
                        }
                        $f['motivo']       = $found_motivo;
                        $f['matched_role'] = $found_ruolo;
                        return $f;
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
     * @param  array  $photo_ids    Array di ID allegati foto
     * @param  bool   $admin_only
     * @return array  Risultati wp_mail per chiave 'fornitore','admin','inquilino'.
     */
    public function send_emails($condominio_id, $fornitore_id, $problema, $inquilino_email, $photo_ids = array(), $admin_only = false) {
        $condominio  = get_post($condominio_id);
        $fornitore   = get_post($fornitore_id);
        $indirizzo   = get_post_meta($condominio_id, 'indirizzo_condominio', true);
        $mail_forn   = get_post_meta($fornitore_id, 'mail_fornitore', true);
        $tel_forn    = get_post_meta($fornitore_id, 'tel_fornitore', true);
        $custom_admin = get_option('marrison_assistant_condominio_admin_email', '');
        $admin_email = ($custom_admin && is_email($custom_admin)) ? $custom_admin : get_option('admin_email');
        $site_name   = get_bloginfo('name');
        $headers     = array('Content-Type: text/plain; charset=UTF-8');
        $sent        = array();

        // Imposta mittente segnalazioni@ per tutte le email del plugin
        $mail_host = parse_url(get_site_url(), PHP_URL_HOST);
        if (substr($mail_host, 0, 4) === 'www.') $mail_host = substr($mail_host, 4);
        $mail_from_cb      = function() use ($mail_host) { return 'segnalazioni@' . $mail_host; };
        $mail_from_name_cb = function() use ($site_name) { return $site_name; };
        add_filter('wp_mail_from',      $mail_from_cb);
        add_filter('wp_mail_from_name', $mail_from_name_cb);

        // Converti $photo_ids in percorsi file per allegati email
        $upload_dir  = wp_upload_dir();
        $temp_dir    = $upload_dir['basedir'] . '/marrison-temp/';
        $attachments = array();
        foreach ($photo_ids as $pid) {
            if (preg_match('/^marr_[a-f0-9]+\.(jpg|jpeg|png|gif|webp|heic)$/i', $pid)) {
                $path = $temp_dir . $pid;
                if (file_exists($path) && strpos(realpath($path), realpath($temp_dir)) === 0) {
                    $attachments[] = $path;
                }
            }
        }

        $cond_nome       = $condominio ? $condominio->post_title : "Condominio #{$condominio_id}";
        $cond_nome_admin = $cond_nome . ($indirizzo ? ' — ' . $indirizzo : '');
        $forn_nome       = $fornitore ? $fornitore->post_title : "Fornitore #{$fornitore_id}";

        // ── Salva segnalazione nel DB e genera token ─────────────────────
        // Salva nomi file delle foto temporanee per la pagina SMS
        $foto_files = array();
        foreach ($photo_ids as $pid) {
            if (preg_match('/^marr_[a-f0-9]+\.(jpg|jpeg|png|gif|webp|heic)$/i', $pid)) {
                $foto_files[] = $pid;
            }
        }
        $foto_ids_str = implode(',', $foto_files);
        $req = Marrison_Assistant_Requests::insert([
            'condominio_id'   => $condominio_id,
            'condominio_name' => $cond_nome,
            'indirizzo'       => $indirizzo,
            'fornitore_id'    => $fornitore_id,
            'fornitore_name'  => $admin_only ? '' : $forn_nome,
            'problema'        => $problema,
            'foto_ids'        => $foto_ids_str,
            'inquilino_email' => $inquilino_email,
            'admin_only'      => $admin_only,
        ]);
        $this->mlog('Richiesta salvata: id=' . $req['id'] . ' token=' . $req['token']);
        $confirm_url = Marrison_Assistant_Requests::confirm_url($req['token']);
        $details_url = Marrison_Assistant_Requests::details_url($req['token']);
        $this->mlog('Details URL: ' . $details_url);

        // ── Email al fornitore (skip in admin_only mode) ──────────────────
        if (!$admin_only && $mail_forn) {
            $subj  = "[{$site_name}] Nuova segnalazione — {$cond_nome}";
            $body  = "Gentile {$forn_nome},\n\n";
            $body .= "è pervenuta una segnalazione da un condòmino.\n\n";
            $body .= "Condominio: {$cond_nome}\n";
            if ($indirizzo) $body .= "Indirizzo:   {$indirizzo}\n";
            $body .= "\nProblema segnalato:\n{$problema}\n\n";
            $body .= "Si prega di prendere in carico la richiesta il prima possibile.\n\n";
            $n_att = count($attachments);
            if ($n_att > 0) $body .= "Foto allegate: {$n_att}\n\n";
            $body .= "──────────────────────────────────────\n";
            $body .= "Una volta completato l'intervento, clicchi il link sottostante per confermarne la conclusione:\n\n";
            $body .= $confirm_url . "\n";
            $body .= "──────────────────────────────────────\n\n";
            $body .= "Cordiali saluti,\n{$site_name}";
            $sent['fornitore'] = wp_mail($mail_forn, $subj, $body, $headers, $attachments);
            if (!$sent['fornitore']) $this->mlog('mail FORNITORE fallita: to=' . $mail_forn);

            // SMS al fornitore (solo se abilita_sms è attivo nel CPT)
            $sms_flag = get_post_meta($fornitore_id, 'abilita_sms', true);
            $this->mlog('SMS check — forn_id=' . $fornitore_id . ' tel=' . var_export($tel_forn, true) . ' abilita_sms=' . var_export($sms_flag, true));
            if ($tel_forn && $sms_flag && $sms_flag !== 'false') {
                $this->send_sms($tel_forn, $details_url);
            }
        } else {
            $sent['fornitore'] = false;
        }

        // ── Email all'amministratore ─────────────────────────────────────────
        if ($admin_only) {
            $subj_a  = "[{$site_name}] Segnalazione diretta — {$cond_nome_admin}";
            $body_a  = "Un condòmino ha inviato una segnalazione direttamente all'amministratore.\n\n";
        } else {
            $subj_a  = "[{$site_name}] Segnalazione inoltrata — {$cond_nome_admin}";
            $body_a  = "Una segnalazione è stata inoltrata automaticamente al fornitore.\n\n";
        }
        $body_a .= "Condominio:  {$cond_nome_admin}\n";
        $body_a .= "Problema:    {$problema}\n";
        if (!$admin_only) {
            $body_a .= "Fornitore:   {$forn_nome}";
            if ($mail_forn) $body_a .= " <{$mail_forn}>";
            if ($tel_forn)  $body_a .= " — Tel: {$tel_forn}";
            $body_a .= "\n";
        }
        if (!empty($inquilino_email)) $body_a .= "Condòmino:   {$inquilino_email}\n";
        $body_a .= "\nCordiali saluti,\n{$site_name}";
        $n_att = count($attachments);
        if ($n_att > 0) $body_a .= "\nFoto allegate: {$n_att}\n";
        $sent['admin'] = wp_mail($admin_email, $subj_a, $body_a, $headers, $attachments);
        if (!$sent['admin']) $this->mlog('mail ADMIN fallita: to=' . $admin_email);

        // ── Email al condòmino ───────────────────────────────────────────────
        $subj_i  = "Conferma segnalazione — {$cond_nome}";
        if ($admin_only) {
            $body_i  = "La sua segnalazione è stata registrata e trasmessa all'amministratore di condominio.\n\n";
            $body_i .= "Condominio:  {$cond_nome}\n";
            if ($indirizzo) $body_i .= "Indirizzo:   {$indirizzo}\n";
            $body_i .= "Problema:    {$problema}\n";
            $body_i .= "\nL'amministratore la contatterà al più presto.\n\n";
        } else {
            $body_i  = "La sua segnalazione è stata registrata e inoltrata al fornitore competente.\n\n";
            $body_i .= "Condominio:  {$cond_nome}\n";
            if ($indirizzo) $body_i .= "Indirizzo:   {$indirizzo}\n";
            $body_i .= "Problema:    {$problema}\n";
            $body_i .= "Fornitore:   {$forn_nome}\n";
            if ($tel_forn) $body_i .= "Tel:         {$tel_forn}\n";
            $body_i .= "\nSarà contattato al più presto.\n\n";
        }
        $body_i .= "Cordiali saluti,\n{$site_name}";
        if (!empty($inquilino_email) && is_email($inquilino_email)) {
            $sent['inquilino'] = wp_mail($inquilino_email, $subj_i, $body_i, $headers);
            if (!$sent['inquilino']) $this->mlog('mail INQUILINO fallita: to=' . $inquilino_email);
        } else {
            $sent['inquilino'] = false;
        }

        // Rimuovi filtri mittente dopo l'invio
        remove_filter('wp_mail_from',      $mail_from_cb);
        remove_filter('wp_mail_from_name', $mail_from_name_cb);

        // NOTA: I file temporanei NON vengono cancellati qui perché devono rimanere
        // disponibili per la pagina temporanea (SMS). Verranno cancellati quando
        // la richiesta viene marcata come completata.

        return $sent;
    }

    /**
     * Invia un SMS tramite il provider configurato (Aruba o SMS Tools).
     *
     * @param string $phone Numero destinatario (qualsiasi formato italiano).
     * @param string $url   URL della pagina dettagli richiesta.
     */
    private function send_sms($phone, $url) {
        // Costruisci messaggio con limite 130 caratteri
        $label = 'Richiesta intervento: ';
        $with_label = $label . $url;
        if (mb_strlen($with_label) <= 130) {
            $message = $with_label;
        } else {
            $message = $url;
        }

        $provider = get_option('marrison_sms_provider', 'aruba');
        if ($provider === 'smstools') {
            return $this->send_sms_smstools($phone, $message);
        }
        return $this->send_sms_aruba($phone, $message);
    }

    /**
     * Invia un SMS tramite Aruba SMS API.
     */
    private function send_sms_aruba($phone, $message) {
        $email    = get_option('marrison_aruba_email', '');
        $password = get_option('marrison_aruba_password', '');
        $sender   = get_option('marrison_aruba_sender', '');

        if (!$email || !$password) {
            $this->mlog('SMS: credenziali Aruba non configurate.');
            return false;
        }

        // Normalizza numero: rimuove spazi, trattini, punti, parentesi
        $phone = preg_replace('/[\s\-\.\(\)\/]+/', '', $phone);
        if (preg_match('/^\+/', $phone)) {
            // già in formato internazionale (+39...) → nessuna modifica
        } elseif (preg_match('/^00/', $phone)) {
            $phone = '+' . substr($phone, 2);       // 0039... → +39...
        } elseif (preg_match('/^39[0-9]/', $phone)) {
            $phone = '+' . $phone;                  // 393xx... → +393xx...
        } elseif (preg_match('/^[03]/', $phone)) {
            $phone = '+39' . $phone;                // 3xx... o 0... → +393xx...
        }
        if (!$phone) return false;

        // ── Step 1: ottieni access token (cache 10 min) ──────────────────────
        $cached = get_transient('marrison_aruba_token');
        if ($cached && strpos($cached, ';') !== false) {
            [$user_key, $access_token] = explode(';', $cached, 2);
        } else {
            $tok = wp_remote_get('https://smspanel.aruba.it/API/v1.0/REST/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($email . ':' . $password),
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 15,
            ]);
            if (is_wp_error($tok)) {
                $this->mlog('SMS Aruba token error: ' . $tok->get_error_message());
                return false;
            }
            $tok_code = wp_remote_retrieve_response_code($tok);
            $tok_body = wp_remote_retrieve_body($tok);
            if ($tok_code !== 200 || strpos($tok_body, ';') === false) {
                $this->mlog('SMS Aruba token HTTP ' . $tok_code . ': ' . $tok_body);
                return false;
            }
            set_transient('marrison_aruba_token', $tok_body, 10 * MINUTE_IN_SECONDS);
            [$user_key, $access_token] = explode(';', $tok_body, 2);
        }

        // ── Step 2: invia SMS ────────────────────────────────────────────────
        $sms_body = [
            'message_type' => 'GP',
            'message'      => mb_substr($message, 0, 160),
            'recipient'    => [$phone],
        ];
        if ($sender) $sms_body['sender'] = mb_substr($sender, 0, 11);

        $response = wp_remote_post('https://smspanel.aruba.it/API/v1.0/REST/sms', [
            'headers' => [
                'Content-Type' => 'application/json',
                'user_key'     => trim($user_key),
                'Access_token' => trim($access_token),
            ],
            'body'    => json_encode($sms_body),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $this->mlog('SMS Aruba send error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 201) {
            $this->mlog('SMS Aruba HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
            if ($code === 401) delete_transient('marrison_aruba_token');
            return false;
        }

        $this->mlog('SMS Aruba inviato OK a ' . $phone);
        return true;
    }

    /**
     * Invia un SMS tramite SMS Tools API (api.smsgatewayapi.com).
     */
    private function send_sms_smstools($phone, $message) {
        $client_id     = get_option('marrison_smstools_client_id', '');
        $client_secret = get_option('marrison_smstools_client_secret', '');
        $sender        = get_option('marrison_smstools_sender', '');

        if (!$client_id || !$client_secret) {
            $this->mlog('SMS Tools: credenziali non configurate.');
            return false;
        }

        // Normalizza numero in formato internazionale
        $phone = preg_replace('/[\s\-\.\(\)\/]+/', '', $phone);
        if (preg_match('/^\+/', $phone)) {
            $phone = ltrim($phone, '+');        // +39333... → 39333...
        } elseif (preg_match('/^00/', $phone)) {
            $phone = substr($phone, 2);         // 0039... → 39...
        } elseif (preg_match('/^[03]/', $phone)) {
            $phone = '39' . $phone;             // 333... o 06... → 39333...
        }
        if (!$phone) return false;

        $body = ['message' => mb_substr($message, 0, 160), 'to' => $phone];
        if ($sender) $body['sender'] = mb_substr($sender, 0, 11);

        $response = wp_remote_post('https://api.smsgatewayapi.com/v1/message/send', [
            'headers' => [
                'X-Client-Id'     => $client_id,
                'X-Client-Secret' => $client_secret,
                'Content-Type'    => 'application/json',
            ],
            'body'    => json_encode($body),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $this->mlog('SMS Tools send error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $this->mlog('SMS Tools HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
            return false;
        }

        $this->mlog('SMS Tools inviato OK a ' . $phone);
        return true;
    }
}
