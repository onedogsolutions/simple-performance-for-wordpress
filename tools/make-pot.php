<?php
/**
 * Minimal `wp i18n make-pot` replacement.
 *
 * WP-CLI is not installed in this environment, and the plugin's i18n surface is
 * narrow (only __, _e, _n, esc_html__, esc_html_e in PHP; __ in JS), so a small
 * purpose-built extractor is safer than hand-editing 1200 lines of .pot.
 *
 * Output is validated by diffing against the WP-CLI-generated file it replaces.
 */

$root = $argv[1];
$domain = 'simple-performance-for-wordpress';

/** entries: msgid => ['plural'=>, 'refs'=>[file:line], 'comment'=>, 'flags'=>[] ] */
$entries = array();
$header_refs = array(); // msgid => 'Plugin Name of the plugin' etc.

function add_entry(&$entries, $msgid, $ref, $comment = '', $plural = '', $php_format = false) {
	if ($msgid === '' || $msgid === null) {
		return;
	}
	if (!isset($entries[$msgid])) {
		$entries[$msgid] = array('plural' => $plural, 'refs' => array(), 'comment' => '', 'flags' => array());
	}
	if ($ref !== '' && !in_array($ref, $entries[$msgid]['refs'], true)) {
		$entries[$msgid]['refs'][] = $ref;
	}
	if ($comment !== '' && $entries[$msgid]['comment'] === '') {
		$entries[$msgid]['comment'] = $comment;
	}
	if ($plural !== '' && $entries[$msgid]['plural'] === '') {
		$entries[$msgid]['plural'] = $plural;
	}
	if ($php_format && !in_array('php-format', $entries[$msgid]['flags'], true)) {
		$entries[$msgid]['flags'][] = 'php-format';
	}
}

function has_placeholders($s) {
	return 1 === preg_match('/%(?:\d+\$)?[-+ #0]?(?:\d+|\*)?(?:\.(?:\d+|\*))?[sdfuoxXeEgGcbl%]/', $s);
}

/** Decode a PHP single/double-quoted literal (already including quotes). */
function php_literal($raw) {
	$q = $raw[0];
	$body = substr($raw, 1, -1);
	if ($q === "'") {
		return str_replace(array("\\'", '\\\\'), array("'", '\\'), $body);
	}
	// Double-quoted: we only ever see literals without interpolation here
	// (token_get_all splits those into multiple tokens, handled by the caller).
	return str_replace(
		array('\\"', '\\\\', '\\n', '\\t', '\\r', '\\$'),
		array('"', '\\', "\n", "\t", "\r", '$'),
		$body
	);
}

/**
 * Collect a function call's argument strings via the PHP tokenizer.
 * Returns array of (string|null) per top-level argument; null = non-literal.
 */
function parse_args(array $tokens, $i, &$next) {
	$args = array();
	$depth = 0;
	$cur = '';
	$literal = true; // stays true only if the whole arg is string literals + '.'
	$seen = false;
	$n = count($tokens);

	for (; $i < $n; $i++) {
		$t = $tokens[$i];
		$text = is_array($t) ? $t[1] : $t;

		if ($text === '(') {
			$depth++;
			if ($depth === 1) {
				continue;
			}
		}
		if ($text === ')') {
			$depth--;
			if ($depth === 0) {
				$args[] = $seen ? ($literal ? $cur : null) : null;
				$next = $i + 1;
				return $args;
			}
		}
		if ($depth < 1) {
			continue;
		}

		if ($text === ',' && $depth === 1) {
			$args[] = $seen ? ($literal ? $cur : null) : null;
			$cur = '';
			$literal = true;
			$seen = false;
			continue;
		}

		if (is_array($t)) {
			if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
				$cur .= php_literal($text);
				$seen = true;
			} elseif ($t[0] === T_WHITESPACE) {
				continue;
			} elseif (in_array($t[0], array(T_ENCAPSED_AND_WHITESPACE, T_STRING_VARNAME), true)) {
				$literal = false;
				$seen = true;
			} else {
				$literal = false;
				$seen = true;
			}
		} else {
			if ($text === '.') {
				continue; // concatenation operator
			}
			$literal = false;
			$seen = true;
		}
	}
	$next = $n;
	return $args;
}

/** Look backwards from token index $i for a `translators:` comment. */
function translators_comment(array $tokens, $i) {
	for ($j = $i - 1; $j >= 0; $j--) {
		$t = $tokens[$j];
		if (is_array($t) && $t[0] === T_WHITESPACE) {
			continue;
		}
		if (is_array($t) && in_array($t[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
			if (preg_match('/translators:\s*(.*)/i', $t[1], $m)) {
				$c = trim(preg_replace('/\s*\*\/$/', '', $m[1]));
				$c = trim(preg_replace('/^\s*\*\s?/m', ' ', $c));
				return trim(preg_replace('/\s+/', ' ', $c));
			}
		}
		return '';
	}
	return '';
}

function extract_php($path, $rel, $domain, &$entries) {
	$src = file_get_contents($path);
	$tokens = token_get_all($src);
	$funcs = array(
		'__' => array('msg' => 0, 'plural' => null, 'domain' => 1),
		'_e' => array('msg' => 0, 'plural' => null, 'domain' => 1),
		'esc_html__' => array('msg' => 0, 'plural' => null, 'domain' => 1),
		'esc_html_e' => array('msg' => 0, 'plural' => null, 'domain' => 1),
		'esc_attr__' => array('msg' => 0, 'plural' => null, 'domain' => 1),
		'esc_attr_e' => array('msg' => 0, 'plural' => null, 'domain' => 1),
		'_x' => array('msg' => 0, 'plural' => null, 'domain' => 2),
		'_n' => array('msg' => 0, 'plural' => 1, 'domain' => 3),
		'_nx' => array('msg' => 0, 'plural' => 1, 'domain' => 4),
	);
	$n = count($tokens);
	for ($i = 0; $i < $n; $i++) {
		$t = $tokens[$i];
		if (!is_array($t) || $t[0] !== T_STRING || !isset($funcs[$t[1]])) {
			continue;
		}
		// Must be a bare call, not a method ($obj->__(...)) or static.
		for ($j = $i - 1; $j >= 0; $j--) {
			if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
				continue;
			}
			if (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), true)) {
				continue 2;
			}
			break;
		}
		$next = $i;
		$open = $i + 1;
		while ($open < $n && is_array($tokens[$open]) && $tokens[$open][0] === T_WHITESPACE) {
			$open++;
		}
		if ($open >= $n || $tokens[$open] !== '(') {
			continue;
		}
		$spec = $funcs[$t[1]];
		$args = parse_args($tokens, $open - 1, $next);
		if (count($args) <= $spec['msg']) {
			continue;
		}
		$dom = isset($args[$spec['domain']]) ? $args[$spec['domain']] : null;
		if ($dom !== $domain) {
			continue;
		}
		$msg = $args[$spec['msg']];
		if ($msg === null || $msg === '') {
			continue;
		}
		$plural = $spec['plural'] !== null && isset($args[$spec['plural']]) ? $args[$spec['plural']] : '';
		$line = $t[2];
		add_entry($entries, $msg, $rel . ':' . $line, translators_comment($tokens, $i), (string) $plural, has_placeholders($msg));
		$i = $next;
	}
}

/**
 * JS/JSX scanner. Tracks string state so `__(` inside a string is not matched,
 * then parses args with a string-aware walk.
 */
function extract_js($path, $rel, $domain, &$entries) {
	$src = file_get_contents($path);
	$len = strlen($src);
	$funcs = array('__' => array('msg' => 0, 'plural' => null, 'domain' => 1), '_n' => array('msg' => 0, 'plural' => 1, 'domain' => 3));
	$line = 1;
	$pending_comment = '';
	$i = 0;
	while ($i < $len) {
		$c = $src[$i];
		if ($c === "\n") {
			$line++;
			$i++;
			continue;
		}
		// Comments: capture `translators:` notes, skip the rest.
		if ($c === '/' && $i + 1 < $len) {
			if ($src[$i + 1] === '/') {
				$end = strpos($src, "\n", $i);
				if ($end === false) {
					break;
				}
				$body = substr($src, $i, $end - $i);
				if (preg_match('/translators:\s*(.*)/i', $body, $m)) {
					$pending_comment = trim($m[1]);
				}
				$i = $end;
				continue;
			}
			if ($src[$i + 1] === '*') {
				$end = strpos($src, '*/', $i + 2);
				if ($end === false) {
					break;
				}
				$body = substr($src, $i, $end + 2 - $i);
				if (preg_match('/translators:\s*(.*?)\s*(?:\*\/)?$/is', $body, $m)) {
					$pending_comment = trim(preg_replace('/\s*\*\/$/', '', $m[1]));
					$pending_comment = trim(preg_replace('/\s+/', ' ', $pending_comment));
				}
				$line += substr_count($body, "\n");
				$i = $end + 2;
				continue;
			}
		}
		// Skip string literals.
		if ($c === '"' || $c === "'" || $c === '`') {
			$q = $c;
			$i++;
			while ($i < $len) {
				if ($src[$i] === '\\') {
					$i += 2;
					continue;
				}
				if ($src[$i] === $q) {
					$i++;
					break;
				}
				if ($src[$i] === "\n") {
					$line++;
				}
				$i++;
			}
			continue;
		}
		// Identifier?
		if (preg_match('/[A-Za-z_$]/', $c)) {
			preg_match('/[A-Za-z0-9_$]+/', $src, $m, 0, $i);
			$word = $m[0];
			$after = $i + strlen($word);
			if (isset($funcs[$word])) {
				$before_ok = ($i === 0) || !preg_match('/[A-Za-z0-9_$.]/', $src[$i - 1]);
				$k = $after;
				while ($k < $len && preg_match('/\s/', $src[$k])) {
					$k++;
				}
				if ($before_ok && $k < $len && $src[$k] === '(') {
					$call_line = $line + substr_count(substr($src, $i, $k - $i), "\n");
					$res = js_parse_args($src, $k, $line);
					if ($res !== null) {
						list($args, $endpos) = $res;
						$spec = $funcs[$word];
						$dom = isset($args[$spec['domain']]) ? $args[$spec['domain']] : null;
						if ($dom === $domain && isset($args[$spec['msg']]) && $args[$spec['msg']] !== null && $args[$spec['msg']] !== '') {
							$plural = $spec['plural'] !== null && isset($args[$spec['plural']]) ? $args[$spec['plural']] : '';
							add_entry($entries, $args[$spec['msg']], $rel . ':' . $call_line, $pending_comment, (string) $plural, has_placeholders($args[$spec['msg']]));
						}
						$pending_comment = '';
						$line += substr_count(substr($src, $i, $endpos - $i), "\n");
						$i = $endpos;
						continue;
					}
				}
			}
			$line += substr_count($word, "\n");
			$i = $after;
			$pending_comment = ($word === '' ) ? $pending_comment : '';
			continue;
		}
		$i++;
	}
}

/** Parse a JS argument list starting at the `(` index. Returns [args, endpos]. */
function js_parse_args($src, $open, $line) {
	$len = strlen($src);
	$depth = 0;
	$args = array();
	$cur = '';
	$literal = false;
	$seen = false;
	for ($i = $open; $i < $len; $i++) {
		$c = $src[$i];
		if ($c === '(') {
			$depth++;
			if ($depth === 1) {
				continue;
			}
			$literal = false;
			$seen = true;
			continue;
		}
		if ($c === ')') {
			$depth--;
			if ($depth === 0) {
				$args[] = ($seen && $literal) ? $cur : null;
				return array($args, $i + 1);
			}
			continue;
		}
		if ($depth < 1) {
			continue;
		}
		if ($c === ',' && $depth === 1) {
			$args[] = ($seen && $literal) ? $cur : null;
			$cur = '';
			$literal = false;
			$seen = false;
			continue;
		}
		if ($c === '"' || $c === "'" || $c === '`') {
			$q = $c;
			$val = '';
			$i++;
			while ($i < $len) {
				if ($src[$i] === '\\') {
					$nx = $i + 1 < $len ? $src[$i + 1] : '';
					$map = array('n' => "\n", 't' => "\t", 'r' => "\r", '\\' => '\\', '"' => '"', "'" => "'", '`' => '`');
					$val .= isset($map[$nx]) ? $map[$nx] : $nx;
					$i += 2;
					continue;
				}
				if ($src[$i] === $q) {
					break;
				}
				$val .= $src[$i];
				$i++;
			}
			$cur .= $val;
			$literal = true;
			$seen = true;
			continue;
		}
		if (preg_match('/\s/', $c)) {
			continue;
		}
		if ($c === '+') {
			continue; // string concatenation
		}
		$literal = false;
		$seen = true;
	}
	return null;
}

function po_escape($s) {
	$s = str_replace('\\', '\\\\', $s);
	$s = str_replace('"', '\\"', $s);
	$s = str_replace("\t", '\\t', $s);
	$s = str_replace("\r", '\\r', $s);
	$s = str_replace("\n", '\\n', $s);
	return $s;
}

// ---- Plugin header metadata ------------------------------------------------
$main = file_get_contents($root . '/simple-performance-for-wordpress.php');
$meta_map = array(
	'Plugin Name' => 'Plugin Name of the plugin',
	'Description' => 'Description of the plugin',
	'Author' => 'Author of the plugin',
	'Author URI' => 'Author URI of the plugin',
);
foreach ($meta_map as $field => $label) {
	if (preg_match('/^\s*\*\s*' . preg_quote($field, '/') . ':\s*(.+?)\s*$/m', $main, $m)) {
		$header_refs[$m[1]] = $label;
		add_entry($entries, $m[1], 'simple-performance-for-wordpress.php', $label, '', false);
	}
}

// ---- Walk the source tree --------------------------------------------------
$php_files = array('simple-performance-for-wordpress.php', 'uninstall.php');
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/includes'));
foreach ($rii as $f) {
	if ($f->isFile() && $f->getExtension() === 'php') {
		$php_files[] = substr($f->getPathname(), strlen($root) + 1);
	}
}
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/admin'));
foreach ($rii as $f) {
	if ($f->isFile() && $f->getExtension() === 'php') {
		$php_files[] = substr($f->getPathname(), strlen($root) + 1);
	}
}
sort($php_files);

$js_files = array();
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
foreach ($rii as $f) {
	if ($f->isFile() && in_array($f->getExtension(), array('js', 'jsx'), true)) {
		$js_files[] = substr($f->getPathname(), strlen($root) + 1);
	}
}
sort($js_files);

foreach ($php_files as $rel) {
	extract_php($root . '/' . $rel, $rel, $domain, $entries);
}
foreach ($js_files as $rel) {
	extract_js($root . '/' . $rel, $rel, $domain, $entries);
}

// ---- Sort refs inside each entry, then order entries -----------------------
foreach ($entries as $msgid => $e) {
	usort($entries[$msgid]['refs'], function ($a, $b) {
		$pa = explode(':', $a);
		$pb = explode(':', $b);
		$fa = $pa[0];
		$fb = $pb[0];
		$la = isset($pa[1]) ? (int) $pa[1] : -1;
		$lb = isset($pb[1]) ? (int) $pb[1] : -1;
		return $fa === $fb ? ($la - $lb) : strcmp($fa, $fb);
	});
}

// Header entries first, in the plugin-header order.
$header_order = array_values($meta_map);
$ordered = array();
foreach ($header_order as $label) {
	foreach ($entries as $msgid => $e) {
		if ($e['comment'] === $label) {
			$ordered[$msgid] = $e;
			unset($entries[$msgid]);
			break;
		}
	}
}
// Remaining: alphabetical by first ref.
uasort($entries, function ($a, $b) {
	return strcmp($a['refs'][0] ?? '', $b['refs'][0] ?? '');
});
foreach ($entries as $msgid => $e) {
	$ordered[$msgid] = $e;
}

// ---- Emit ------------------------------------------------------------------
$version = '2.6.0';
if (preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $main, $m)) {
	$version = $m[1];
}
$out = '';
$out .= "# Copyright (C) " . gmdate('Y') . " Ryan Waterbury\n";
$out .= "# This file is distributed under the GPL-3.0-or-later.\n";
$out .= "msgid \"\"\n";
$out .= "msgstr \"\"\n";
$out .= "\"Project-Id-Version: Simple Performance for WordPress " . $version . "\\n\"\n";
$out .= "\"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/simple-performance-for-wordpress\\n\"\n";
$out .= "\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n\"\n";
$out .= "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"POT-Creation-Date: " . gmdate('Y-m-d\TH:i:s+00:00') . "\\n\"\n";
$out .= "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n";
$out .= "\"X-Generator: SPFW make-pot 1.0\\n\"\n";
$out .= "\"X-Domain: simple-performance-for-wordpress\\n\"\n";

foreach ($ordered as $msgid => $e) {
	$out .= "\n";
	if ($e['comment'] !== '') {
		$out .= (isset($header_refs[$msgid]) ? '#. ' : '#. translators: ') . $e['comment'] . "\n";
	}
	foreach ($e['refs'] as $ref) {
		$out .= '#: ' . $ref . "\n";
	}
	if ($e['flags']) {
		$out .= '#, ' . implode(', ', $e['flags']) . "\n";
	}
	$out .= 'msgid "' . po_escape($msgid) . "\"\n";
	if ($e['plural'] !== '') {
		$out .= 'msgid_plural "' . po_escape($e['plural']) . "\"\n";
		$out .= "msgstr[0] \"\"\n";
		$out .= "msgstr[1] \"\"\n";
	} else {
		$out .= "msgstr \"\"\n";
	}
}

file_put_contents($argv[2], $out);
echo "wrote " . count($ordered) . " entries to " . $argv[2] . "\n";
