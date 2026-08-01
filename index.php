<?php

#
#
# Obsidian-Viewer
# https://github.com/cfranz/obsidian-viewer/
#
# (c) Carsten Franz
# https://carsten-franz.eu
#
# For the full license information, view the LICENSE file that was distributed
# with this source code.
#
#

const VERSION = '1.0.0';   // Programmversion (bei Updates erhoehen)

require __DIR__ . '/Parsedown.php';

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s) { return strtolower((string)$s); }
}

// ---------- CONFIG LADEN ---------------------------------------------
$CFG = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
define('SITE_TITLE',   isset($CFG['SITE_TITLE']) ? $CFG['SITE_TITLE'] : 'Vault');
define('HOME',         isset($CFG['HOME'])       ? $CFG['HOME']       : '');
define('REWRITE_URLS', !empty($CFG['REWRITE_URLS']));

// Vault-Pfad: relativ zum Viewer-Ordner (z.B. 'vault') oder absolut (fuehrendes '/')
$vaultCfg = (isset($CFG['VAULT_DIR']) && $CFG['VAULT_DIR'] !== '') ? $CFG['VAULT_DIR'] : 'vault';
define('VAULT_DIR', strncmp($vaultCfg, '/', 1) === 0 ? $vaultCfg : __DIR__ . '/' . $vaultCfg);

// Zugriffsschutz erfolgt VOR der App via .htaccess (Basic Auth) - siehe README.

// ---------- HELPERS ---------------------------------------------------
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Build a URL for a vault-relative path (clean or ?p= depending on config). */
function u($rel) {
    global $BASE;
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $enc = implode('/', array_map('rawurlencode', explode('/', $rel)));
    return REWRITE_URLS ? $BASE . $enc : $BASE . 'index.php?p=' . $enc;
}

function safe_path($rel) {
    $base = realpath(VAULT_DIR);
    if ($base === false) return false;
    $full = realpath($base . '/' . ltrim($rel, '/'));
    if ($full === false) return false;
    // Muss exakt der vault-Ordner sein oder darunter liegen (mit Trenner,
    // sonst wuerde ein Nachbarordner "vault-xyz" faelschlich durchgehen).
    if ($full !== $base && strncmp($full, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) return false;
    return $full;
}

/* Split on top-level separator, ignoring quotes and (), [] nesting. */
function split_top($s, $sep = ',') {
    $res = []; $depth = 0; $q = false; $cur = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === '"') { $q = !$q; $cur .= $c; continue; }
        if (!$q && ($c === '(' || $c === '[')) { $depth++; $cur .= $c; continue; }
        if (!$q && ($c === ')' || $c === ']')) { $depth--; $cur .= $c; continue; }
        if (!$q && $depth === 0 && $c === $sep) { $res[] = trim($cur); $cur = ''; continue; }
        $cur .= $c;
    }
    if (trim($cur) !== '') $res[] = trim($cur);
    return $res;
}

/* Minimal, flat YAML frontmatter parser (key: value, inline [..] arrays, - lists). */
function parse_frontmatter($raw) {
    $fm = [];
    if (substr($raw, 0, 4) !== "---\n" && substr($raw, 0, 4) !== "---\r") {
        return [$fm, $raw];
    }
    $lines = preg_split('/\r\n|\n|\r/', $raw);
    array_shift($lines); // opening ---
    $body_start = null; $pending = null;
    foreach ($lines as $idx => $line) {
        if (rtrim($line) === '---') { $body_start = $idx + 1; break; }
        if (preg_match('/^\s*-\s+(.*)$/', $line, $m) && $pending !== null) {
            $fm[$pending][] = unquote(trim($m[1]));
            continue;
        }
        if (preg_match('/^([A-Za-z0-9_\-]+)\s*:\s*(.*)$/', $line, $m)) {
            $key = $m[1]; $val = trim($m[2]);
            if ($val === '') { $fm[$key] = []; $pending = $key; }
            elseif (preg_match('/^\[(.*)\]$/', $val, $mm)) {
                $fm[$key] = array_map('unquote', array_map('trim', $mm[1] === '' ? [] : explode(',', $mm[1])));
                $pending = null;
            } else { $fm[$key] = unquote($val); $pending = null; }
        }
    }
    $body = $body_start !== null ? implode("\n", array_slice($lines, $body_start)) : $raw;
    return [$fm, $body];
}
function unquote($s) {
    $s = trim($s);
    if (preg_match('/^"(.*)"$/s', $s, $m)) return $m[1];
    if (preg_match("/^'(.*)'\$/s", $s, $m)) return $m[1];
    return $s;
}

/* Build an index of all files in the vault (md + attachments). */
function build_index() {
    $base = realpath(VAULT_DIR);
    $md = []; $byBase = []; $byBaseCI = []; $allFiles = []; $allBaseCI = [];
    if ($base === false) return [$md, $byBase, $byBaseCI, $allFiles, $allBaseCI];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) continue;
        $full = $file->getPathname();
        $rel  = ltrim(str_replace($base, '', $full), '/\\');
        $rel  = str_replace('\\', '/', $rel);
        if (strpos($rel, '.obsidian/') === 0 || strpos($rel, '.git/') === 0) continue;
        $name = $file->getBasename();
        $allFiles[$rel] = true;
        $allBaseCI[mb_strtolower($name)] = $rel;
        if (strtolower($file->getExtension()) !== 'md') continue;

        $raw = @file_get_contents($full);
        if ($raw === false) $raw = '';
        list($fm, $body) = parse_frontmatter($raw);
        $tags = [];
        if (preg_match_all('/(?:^|\s)#([A-Za-z0-9_\/\-]+)/', $body, $mm)) $tags = $mm[1];
        if (isset($fm['tags'])) $tags = array_merge($tags, (array)$fm['tags']);

        $baseName = $file->getBasename('.md');
        $entry = [
            'path'   => $rel,
            'name'   => $baseName,
            'folder' => trim(dirname($rel) === '.' ? '' : dirname($rel), '/'),
            'mtime'  => $file->getMTime(),
            'ctime'  => $file->getCTime(),
            'size'   => $file->getSize(),
            'fm'     => $fm,
            'tags'   => array_values(array_unique($tags)),
        ];
        $md[$rel] = $entry;
        $byBase[$baseName] = $rel;
        $byBaseCI[mb_strtolower($baseName)] = $rel;
    }
    return [$md, $byBase, $byBaseCI, $allFiles, $allBaseCI];
}

/* Resolve a [[wikilink]] target (without #heading / |alias) to a relpath. */
function resolve_link($target) {
    global $BYBASE, $BYBASE_CI, $MD;
    $target = trim($target);
    if ($target === '') return null;
    $cand = $target;
    if (substr($cand, -3) !== '.md') $cand .= '.md';
    if (isset($MD[$cand])) return $cand;
    if (isset($MD[$target])) return $target;
    $bn = pathinfo($target, PATHINFO_FILENAME);
    if (isset($BYBASE[$bn])) return $BYBASE[$bn];
    if (isset($BYBASE_CI[mb_strtolower($bn)])) return $BYBASE_CI[mb_strtolower($bn)];
    return null;
}
function resolve_any($name) {
    global $ALLFILES, $ALLBASE_CI;
    $name = trim($name);
    if (isset($ALLFILES[$name])) return $name;
    $bn = basename($name);
    if (isset($ALLBASE_CI[mb_strtolower($bn)])) return $ALLBASE_CI[mb_strtolower($bn)];
    return null;
}

/* Convert ![[embeds]] and [[wikilinks]] to Markdown before Parsedown. */
function convert_wikilinks($body) {
    // Embeds: ![[file]] -> image if it's an image, else a link.
    $body = preg_replace_callback('/!\[\[([^\]]+)\]\]/', function ($m) {
        $inner = explode('|', $m[1]);
        $target = trim($inner[0]);
        $rel = resolve_any($target);
        if ($rel && preg_match('/\.(png|jpe?g|gif|webp|svg|bmp)$/i', $rel)) {
            return '<img src="' . u($rel) . '" alt="' . h($target) . '" style="max-width:100%">';
        }
        $mdrel = resolve_link($target);
        if ($mdrel) return '[' . h($target) . '](' . u($mdrel) . ')';
        return '<span class="wl-missing">' . h($target) . '</span>';
    }, $body);

    // Links: [[target#heading|alias]]
    $body = preg_replace_callback('/\[\[([^\]]+)\]\]/', function ($m) {
        $raw = $m[1];
        $alias = null;
        if (strpos($raw, '|') !== false) { list($raw, $alias) = explode('|', $raw, 2); }
        $heading = null;
        if (strpos($raw, '#') !== false) { list($raw, $heading) = explode('#', $raw, 2); }
        $target = trim($raw);
        $text = $alias !== null ? trim($alias) : ($target . ($heading !== null ? ' › ' . trim($heading) : ''));
        $rel = resolve_link($target);
        if ($rel) return '[' . $text . '](' . u($rel) . ')';
        return '<span class="wl-missing">' . h($text) . '</span>';
    }, $body);

    return $body;
}

// ---------- DATAVIEW (minimal DQL) -----------------------------------
function luxon_fmt($ts, $luxon) {
    if (!$ts) return '';
    $map = ['yyyy'=>'Y','yy'=>'y','MMMM'=>'F','MMM'=>'M','MM'=>'m','M'=>'n',
            'dd'=>'d','d'=>'j','HH'=>'H','H'=>'G','mm'=>'i','ss'=>'s',
            'cccc'=>'l','ccc'=>'D','EEEE'=>'l'];
    $keys = array_keys($map);
    usort($keys, function ($a, $b) { return strlen($b) - strlen($a); });
    $out = ''; $i = 0; $n = strlen($luxon);
    while ($i < $n) {
        $matched = false;
        foreach ($keys as $k) {
            if (substr($luxon, $i, strlen($k)) === $k) { $out .= $map[$k]; $i += strlen($k); $matched = true; break; }
        }
        if (!$matched) {
            $ch = $luxon[$i];
            $out .= ctype_alpha($ch) ? '\\' . $ch : $ch;
            $i++;
        }
    }
    return date($out, $ts);
}
function to_date($x) {
    if ($x === null || $x === '') return null;
    if (is_int($x)) return date('Y-m-d', $x);
    $ts = strtotime((string)$x);
    return $ts === false ? null : date('Y-m-d', $ts);
}
function dv_field($tok, $page, $curName) {
    $tok = trim($tok);
    if (strpos($tok, 'this.file.') === 0) {
        $f = substr($tok, 10);
        if ($f === 'name') return $curName;
        return $curName;
    }
    if (strpos($tok, 'file.') === 0) {
        switch (substr($tok, 5)) {
            case 'name':   return $page['name'];
            case 'path':   return $page['path'];
            case 'folder': return $page['folder'];
            case 'link':   return $page['name'];
            case 'mtime':  return $page['mtime'];
            case 'ctime':  return $page['ctime'];
            case 'size':   return $page['size'];
            case 'tags':   return $page['tags'];
            default:       return null;
        }
    }
    if (isset($page['fm'][$tok])) return $page['fm'][$tok];
    return null;
}
function dv_value($tok, $page, $curName) {
    $tok = trim($tok);
    if ($tok === '') return null;
    if (strtolower($tok) === 'today') return date('Y-m-d');
    if (preg_match('/^"(.*)"$/s', $tok, $m)) return $m[1];
    if (preg_match('/^-?\d+(\.\d+)?$/', $tok)) return $tok + 0;
    if (preg_match('/^date\((.+)\)$/i', $tok, $m)) return to_date(dv_value($m[1], $page, $curName));
    if (preg_match('/^dateformat\(\s*(.+?)\s*,\s*"([^"]*)"\s*\)$/i', $tok, $m)) {
        $v = dv_field($m[1], $page, $curName);
        $ts = is_int($v) ? $v : strtotime((string)$v);
        return luxon_fmt($ts ?: 0, $m[2]);
    }
    return dv_field($tok, $page, $curName);
}
function dv_term($t, $page, $curName) {
    $t = trim($t);
    if (preg_match('/^(!?)contains\((.*)\)$/i', $t, $m)) {
        $args = split_top($m[2], ',');
        $hay = dv_value($args[0] ?? '', $page, $curName);
        $needle = dv_value($args[1] ?? '', $page, $curName);
        $res = false;
        if (is_array($hay)) {
            foreach ($hay as $hv) if (stripos((string)$hv, (string)$needle) !== false) { $res = true; break; }
        } else {
            $res = ($needle !== null && stripos((string)$hay, (string)$needle) !== false);
        }
        return $m[1] === '!' ? !$res : $res;
    }
    if (preg_match('/^(.*?)(==|!=|>=|<=|=|>|<)(.*)$/', $t, $m)) {
        $l = dv_value(trim($m[1]), $page, $curName);
        $op = $m[2];
        $r = dv_value(trim($m[3]), $page, $curName);
        if (is_array($l)) $l = implode(',', $l);
        switch ($op) {
            case '=': case '==': return (string)$l === (string)$r;
            case '!=': return (string)$l !== (string)$r;
            case '>':  return $l >  $r;
            case '<':  return $l <  $r;
            case '>=': return $l >= $r;
            case '<=': return $l <= $r;
        }
    }
    // bare truthiness of a field
    $v = dv_value($t, $page, $curName);
    return !($v === null || $v === '' || $v === false || (is_array($v) && !$v));
}
function dv_where($where, $page, $curName) {
    $parts = preg_split('/\s+(AND|OR)\s+/i', trim($where), -1, PREG_SPLIT_DELIM_CAPTURE);
    $acc = dv_term($parts[0], $page, $curName);
    for ($i = 1; $i < count($parts); $i += 2) {
        $op = strtoupper($parts[$i]); $rhs = dv_term($parts[$i + 1], $page, $curName);
        $acc = ($op === 'OR') ? ($acc || $rhs) : ($acc && $rhs);
    }
    return $acc;
}
function dv_cell($expr, $page, $curName) {
    $expr = trim($expr);
    if (strtolower($expr) === 'file.link' || strtolower($expr) === 'link') {
        return '<a href="' . u($page['path']) . '">' . h($page['name']) . '</a>';
    }
    $v = dv_value($expr, $page, $curName);
    if (is_array($v)) $v = implode(', ', $v);
    return h($v);
}
function run_dataview($query, $curName) {
    global $MD;
    if (preg_match('/\b(GROUP\s+BY|FLATTEN)\b/i', $query)) throw new Exception('unsupported');

    if (!preg_match('/^(TABLE|LIST|TASK)\b\s*(WITHOUT\s+ID)?\s*(.*?)\s*(?=\bFROM\b|\bWHERE\b|\bSORT\b|\bLIMIT\b|$)/is', $query, $cm)) {
        throw new Exception('no command');
    }
    $type = strtoupper($cm[1]);
    if ($type === 'TASK') throw new Exception('task unsupported');
    $withoutId = !empty($cm[2]);
    $colspec = trim($cm[3]);

    $rest = substr($query, strlen($cm[0]));
    $from = null; $where = null; $sort = null; $limit = null;
    $seg = preg_split('/\b(FROM|WHERE|SORT|LIMIT)\b/i', $rest, -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($i = 1; $i < count($seg); $i += 2) {
        $kw = strtoupper(trim($seg[$i])); $val = trim($seg[$i + 1] ?? '');
        if ($kw === 'FROM') $from = $val;
        elseif ($kw === 'WHERE') $where = $val;
        elseif ($kw === 'SORT') $sort = $val;
        elseif ($kw === 'LIMIT') $limit = (int)$val;
    }

    // Source selection
    $pages = [];
    $from = trim((string)$from);
    if ($from === '' || $from === '""') {
        $pages = array_values($MD);
    } elseif (preg_match('/^"(.*)"$/', $from, $m)) {
        $folder = trim($m[1], '/');
        foreach ($MD as $p) if ($folder === '' || strpos($p['path'], $folder . '/') === 0) $pages[] = $p;
    } elseif (preg_match('/^#([A-Za-z0-9_\/\-]+)/', $from, $m)) {
        $tag = $m[1];
        foreach ($MD as $p) if (in_array($tag, $p['tags'], true)) $pages[] = $p;
    } else {
        throw new Exception('unsupported FROM');
    }

    // WHERE
    if ($where !== null && $where !== '') {
        $pages = array_values(array_filter($pages, function ($p) use ($where, $curName) {
            return dv_where($where, $p, $curName);
        }));
    }

    // SORT
    if ($sort !== null && $sort !== '') {
        $cols = split_top($sort, ',');
        $first = $cols[0];
        $dir = 'ASC';
        if (preg_match('/\s+(ASC|DESC)\s*$/i', $first, $dm)) { $dir = strtoupper($dm[1]); $first = trim(preg_replace('/\s+(ASC|DESC)\s*$/i', '', $first)); }
        usort($pages, function ($a, $b) use ($first, $curName) {
            $va = dv_value($first, $a, $curName); $vb = dv_value($first, $b, $curName);
            if (is_array($va)) $va = implode(',', $va);
            if (is_array($vb)) $vb = implode(',', $vb);
            return $va <=> $vb;
        });
        if ($dir === 'DESC') $pages = array_reverse($pages);
    }

    if ($limit !== null) $pages = array_slice($pages, 0, $limit);

    // Render
    if ($type === 'LIST') {
        $expr = $colspec !== '' ? $colspec : 'file.link';
        $out = '<ul class="dv-list">';
        foreach ($pages as $p) $out .= '<li>' . dv_cell($expr, $p, $curName) . '</li>';
        return $out . '</ul>';
    }

    // TABLE
    $cols = $colspec === '' ? [] : split_top($colspec, ',');
    $headers = []; $exprs = [];
    foreach ($cols as $c) {
        if (preg_match('/^(.*)\s+AS\s+"?([^"]+)"?\s*$/is', $c, $am)) { $exprs[] = trim($am[1]); $headers[] = trim($am[2]); }
        else { $exprs[] = trim($c); $headers[] = trim($c); }
    }
    $out = '<table class="dv-table"><thead><tr>';
    if (!$withoutId) $out .= '<th>File</th>';
    foreach ($headers as $hd) $out .= '<th>' . h($hd) . '</th>';
    $out .= '</tr></thead><tbody>';
    foreach ($pages as $p) {
        $out .= '<tr>';
        if (!$withoutId) $out .= '<td><a href="' . u($p['path']) . '">' . h($p['name']) . '</a></td>';
        foreach ($exprs as $e) $out .= '<td>' . dv_cell($e, $p, $curName) . '</td>';
        $out .= '</tr>';
    }
    return $out . '</tbody></table>';
}

function dv_fallback($query, $js = false) {
    $label = $js ? 'dataviewjs – nur in Obsidian ausführbar' : 'Dataview – nicht unterstützte Abfrage';
    return '<div class="dv-fallback"><div class="dv-fallback-h">⧉ ' . h($label) . '</div><pre>' . h($query) . '</pre></div>';
}

// ---------- RENDER A NOTE --------------------------------------------
function render_note($relpath) {
    $full = safe_path($relpath);
    if ($full === false || !is_file($full)) return '<p class="err">Datei nicht gefunden.</p>';
    $raw = file_get_contents($full);
    list($fm, $body) = parse_frontmatter($raw);
    $curName = pathinfo($relpath, PATHINFO_FILENAME);

    // Extract dataview blocks -> placeholders
    $blocks = [];
    $body = preg_replace_callback('/```dataview(js)?\s*\n(.*?)\n```/is', function ($m) use (&$blocks, $curName) {
        $token = "\x02DVBLOCK" . count($blocks) . "\x03";
        $isJs = ($m[1] === 'js' || $m[1] === 'JS');
        if ($isJs) { $blocks[$token] = dv_fallback(trim($m[2]), true); }
        else {
            try { $blocks[$token] = run_dataview(trim($m[2]), $curName); }
            catch (Exception $e) { $blocks[$token] = dv_fallback(trim($m[2]), false); }
        }
        return "\n\n" . $token . "\n\n";
    }, $body);

    $body = convert_wikilinks($body);

    $pd = new Parsedown();
    $pd->setSafeMode(false); // we already control input; allows our inline <img>/<span>
    $html = $pd->text($body);

    // Reinsert dataview HTML
    foreach ($blocks as $token => $rendered) {
        $html = str_replace('<p>' . $token . '</p>', $rendered, $html);
        $html = str_replace($token, $rendered, $html);
    }

    // Frontmatter box (if any)
    $fmHtml = '';
    if (!empty($fm)) {
        $fmHtml = '<details class="fm"><summary>frontmatter</summary><table>';
        foreach ($fm as $k => $v) {
            $fmHtml .= '<tr><th>' . h($k) . '</th><td>' . h(is_array($v) ? implode(', ', $v) : $v) . '</td></tr>';
        }
        $fmHtml .= '</table></details>';
    }
    return $fmHtml . $html;
}

// ---------- SIDEBAR TREE ---------------------------------------------
function build_tree($md) {
    $tree = [];
    foreach ($md as $rel => $e) {
        $parts = explode('/', $rel);
        $node =& $tree;
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $p = $parts[$i];
            if (!isset($node['_dirs'][$p])) $node['_dirs'][$p] = [];
            $node =& $node['_dirs'][$p];
        }
        $node['_files'][] = ['name' => end($parts), 'rel' => $rel, 'label' => $e['name']];
        unset($node);
    }
    return $tree;
}
function render_tree($node, $cur, $prefix = '') {
    $out = '';
    if (!empty($node['_dirs'])) {
        ksort($node['_dirs']);
        foreach ($node['_dirs'] as $name => $child) {
            $full = ($prefix === '') ? $name : $prefix . '/' . $name;
            $open = (strpos($cur, $full . '/') === 0) ? ' open' : '';
            $out .= '<details' . $open . '><summary class="dir">' . h($name) . '</summary><div class="ind">'
                  . render_tree($child, $cur, $full) . '</div></details>';
        }
    }
    if (!empty($node['_files'])) {
        usort($node['_files'], function ($a, $b) { return strcmp($a['label'], $b['label']); });
        foreach ($node['_files'] as $f) {
            $active = ($f['rel'] === $cur) ? ' class="active"' : '';
            $out .= '<a href="' . u($f['rel']) . '"' . $active . ' data-name="' . h(mb_strtolower($f['label'])) . '">' . h($f['label']) . '</a>';
        }
    }
    return $out;
}

// ---------- BOOTSTRAP INDEX ------------------------------------------
list($MD, $BYBASE, $BYBASE_CI, $ALLFILES, $ALLBASE_CI) = build_index();

// Basis-URL (Verzeichnis, in dem index.php liegt), z.B. "/obsidian-viewer/"
$BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';

// Angeforderter Pfad: bevorzugt ?p= (direkter index.php-Aufruf),
// sonst aus der sauberen URL via REQUEST_URI (robust bei Leerzeichen/Slashes).
if (isset($_GET['p'])) {
    $req = (string)$_GET['p'];
} else {
    $uriPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if (strpos($uriPath, $BASE) === 0) $uriPath = substr($uriPath, strlen($BASE));
    $req = $uriPath;
}
$req = ltrim(str_replace('\\', '/', $req), '/');
if ($req === 'index.php') $req = '';

// ---------- RAW FILE ROUTE (Bilder/Anhaenge, alles ausser .md) -------
if ($req !== '' && !preg_match('/\.md$/i', $req)) {
    $full = safe_path($req);
    if ($full === false || !is_file($full)) { http_response_code(404); exit('not found'); }
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $types = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
              'webp'=>'image/webp','svg'=>'image/svg+xml','bmp'=>'image/bmp','pdf'=>'application/pdf'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($full));
    readfile($full); exit;
}

// ---------- PICK CURRENT PAGE ----------------------------------------
$cur = $req;
if ($cur === '' || !isset($MD[$cur])) {
    $cur = '';
    if (HOME !== '' && isset($MD[HOME])) {
        $cur = HOME;
    } else {
        foreach (['🏡 Home.md', 'Home.md', 'Startseite.md', 'INDEX.md', 'README.md', '🏠 Start Here.md'] as $cand) {
            if (isset($MD[$cand])) { $cur = $cand; break; }
        }
    }
    if ($cur === '' && !empty($MD)) { $cur = array_key_first($MD); }
}
$content = $cur !== '' ? render_note($cur) : '<p>Kein Markdown im Vault gefunden.</p>';
$tree = build_tree($MD);

// ---------- PAGE ------------------------------------------------------
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($cur !== '' ? pathinfo($cur, PATHINFO_FILENAME) : SITE_TITLE) ?> · <?= h(SITE_TITLE) ?></title>
<style>
:root{--bg:#1e1e24;--panel:#25252c;--fg:#d7d7dc;--muted:#8a8a94;--acc:#7c9cf0;--line:#35353f;--miss:#e0688a}
*{box-sizing:border-box}
body{margin:0;font:15px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--fg);display:flex;height:100vh;overflow:hidden}
#side{width:300px;flex:0 0 300px;background:var(--panel);border-right:1px solid var(--line);display:flex;flex-direction:column}
#side header{padding:12px 14px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
#side header b{font-size:14px;letter-spacing:.3px}
#side header a.home{color:inherit;text-decoration:none}
#side header a{color:var(--muted);text-decoration:none;font-size:12px}
#filter{margin:10px;padding:7px 9px;background:var(--bg);border:1px solid var(--line);border-radius:6px;color:var(--fg)}
#tree{flex:1;min-height:0;overflow:auto;padding:4px 8px 12px}
#sidefoot{border-top:1px solid var(--line);padding:8px 12px;font-size:11.5px;color:var(--muted);display:flex;justify-content:space-between;align-items:center;gap:8px}
#sidefoot a{color:var(--muted);text-decoration:none}
#sidefoot a:hover{color:var(--acc)}
#tree a{display:block;padding:3px 6px;color:var(--fg);text-decoration:none;border-radius:5px;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#tree a:hover{background:var(--bg)}
#tree a.active{background:var(--acc);color:#12121a;font-weight:600}
#tree details{margin:1px 0}
#tree summary.dir{cursor:pointer;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.4px;padding:3px 4px;list-style:none}
#tree summary.dir::-webkit-details-marker{display:none}
#tree summary.dir::before{content:"▸ ";font-size:10px}
#tree details[open]>summary.dir::before{content:"▾ "}
#tree .ind{margin-left:12px;border-left:1px solid var(--line);padding-left:5px}
#main{flex:1;overflow:auto;padding:38px 54px 120px;max-width:900px}
#main h1,#main h2,#main h3{line-height:1.25;margin:1.4em 0 .5em}
#main h1{font-size:1.9em;border-bottom:1px solid var(--line);padding-bottom:.2em}
#main h2{font-size:1.45em;border-bottom:1px solid var(--line);padding-bottom:.2em}
#main a{color:var(--acc);text-decoration:none}#main a:hover{text-decoration:underline}
#main code{background:var(--panel);padding:.15em .4em;border-radius:4px;font-size:.9em}
#main pre{background:var(--panel);padding:14px 16px;border-radius:8px;overflow:auto;border:1px solid var(--line)}
#main pre code{background:none;padding:0}
#main table{border-collapse:collapse;margin:1em 0;font-size:.94em}
#main th,#main td{border:1px solid var(--line);padding:6px 11px;text-align:left}
#main th{background:var(--panel)}
#main blockquote{border-left:3px solid var(--acc);margin:1em 0;padding:.2em 1em;color:var(--muted)}
#main img{border-radius:6px}
.wl-missing{color:var(--miss);border-bottom:1px dotted var(--miss)}
.fm{margin:0 0 20px;background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:6px 12px}
.fm summary{cursor:pointer;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.4px}
.fm table{margin:8px 0 2px;font-size:.88em}.fm th{color:var(--muted);font-weight:500}
.dv-table{width:100%}
.dv-fallback{border:1px dashed var(--line);border-radius:8px;margin:1em 0;background:var(--panel)}
.dv-fallback-h{padding:6px 12px;color:var(--muted);font-size:12px;border-bottom:1px dashed var(--line)}
.dv-fallback pre{margin:0;border:none;background:none}
.err{color:var(--miss)}
@media(max-width:760px){#side{position:fixed;z-index:9;height:100vh;height:100dvh;transform:translateX(-100%);transition:.2s}body.nav #side{transform:none}#main{padding:60px 20px 100px}#burger{display:block!important;left:auto;right:10px}}
#burger{display:none;position:fixed;top:10px;left:10px;z-index:10;background:var(--panel);border:1px solid var(--line);color:var(--fg);border-radius:6px;padding:6px 10px;cursor:pointer}
</style>
</head>
<body>
<button id="burger" onclick="document.body.classList.toggle('nav')">☰</button>
<nav id="side">
  <header><a href="<?= h($BASE) ?>" class="home"><b><?= h(SITE_TITLE) ?></b></a></header>
  <input id="filter" placeholder="Filtern…" oninput="filterTree(this.value)">
  <div id="tree"><?= render_tree($tree, $cur) ?></div>
  <div id="sidefoot">
    <span><?= VERSION !== '' ? 'v' . h(VERSION) : '' ?></span>
    <a href="https://github.com/cfranz/obsidian-viewer/" target="_blank" rel="noopener">GitHub ↗</a>
  </div>
</nav>
<article id="main"><?= $content ?></article>
<script>
function filterTree(q){
  q=q.toLowerCase();
  document.querySelectorAll('#tree a').forEach(function(a){
    a.style.display = !q || a.dataset.name.indexOf(q)>-1 ? '' : 'none';
  });
  document.querySelectorAll('#tree details').forEach(function(d){
    var any=[].some.call(d.querySelectorAll('a'),function(a){return a.style.display!=='none'});
    d.style.display = !q || any ? '' : 'none';
    if(q&&any)d.open=true;
  });
}
</script>
</body>
</html>
