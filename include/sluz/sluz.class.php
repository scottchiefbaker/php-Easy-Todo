<?php

////////////////////////////////////////////////////////

define('SLUZ_INLINE', 987123654); // Just a random number

class sluz {
	public $version      = '0.6';
	public $tpl_file     = null;
	public $debug        = 0;
	public $in_unit_test = false;
	public $tpl_vars     = [];
	public $tpl_path     = null;

	private $php_file     = null;
	private $var_prefix   = "sluz_pfx";
	private $simple_mode  = false;
	private $fetch_called = false;

	public function __construct() { }
	public function __destruct()  {
		// In simple mode we auto print the output
		if ($this->simple_mode && !$this->fetch_called) {
			print $this->fetch();
		}
	}

	public function assign($key, $val = null) {
		// Single item call (assign array at once)
		if (is_null($val) && is_array($key)) {
			$this->tpl_vars = array_merge($this->tpl_vars, $key);
		} else {
			$this->tpl_vars[$key] = $val;
		}
	}

	// Convert template blocks in to output strings
	public function process_block(string $str) {
		$ret = '';

		// Micro-optimization for "" input
		if (strlen($str) === 0) {
			return '';
		}

		// If it doesn't start with a '{' it's plain text so we just return it
		if ($str[0] !== "{") {
			$ret = $str;
		// Simple variable replacement {$foo} or {$foo|default:"123"}
		} elseif (preg_match('/^\{\$(\w[\w\|\.\'":]*)\s*\}$/', $str, $m)) {
			$ret = $this->variable_block($m[1]);
		// If statement {if $foo}{/if}
		} elseif (preg_match('/^\{if (.+?)\}(.+)\{\/if\}$/s', $str, $m)) {
			$ret = $this->if_block($str, $m);
		// Foreach {foreach $foo as $x}{/foreach}
		} elseif (preg_match('/^\{foreach (\$\w[\w.]+) as \$(\w+)( => \$(\w+))?\}(.+)\{\/foreach\}$/s', $str, $m)) {
			$ret = $this->foreach_block($m);
		// Include {include file='my.stpl' number='99'}
		} elseif (preg_match('/^\{include.+?\}$/s', $str, $m)) {
			$ret = $this->include_block($str);
		// Liternal {literal}Stuff here{/literal}
		} elseif (preg_match('/^\{literal\}(.+)\{\/literal\}$/s', $str, $m)) {
			$ret = $m[1];
		// Comment {* info here *}
		} elseif (preg_match('/^{\*.*\*\}/s', $str, $m)) {
			$ret = '';
		// Catch all for other { $num + 3 } type of blocks
		} elseif (preg_match('/^\{(.+)}$/s', $str, $m)) {
			$ret = $this->expression_block($str, $m);
		// Something went WAY wrong
		} else {
			$ret = $str;
		}

		return $ret;
	}

	// Break the text up in to tokens/blocks to process by process_block()
	public function get_blocks($str) {
		$start  = 0;
		$blocks = [];
		$slen   = strlen($str);

		for ($i = 0; $i < $slen; $i++) {
			$char = $str[$i];

			$is_open    = $char === "{";
			$is_closed  = $char === "}";
			$has_len    = $start != $i;
			$is_comment = false;

			// Check to see if it's a real {} block
			if ($is_open) {
				$prev_c = $str[$i - 1];
				$next_c = $str[$i + 1];
				$chunk  = $prev_c . $char . $next_c;

				// If the { is surrounded by whitespace it's not a block
				if (preg_match("/\s[\{\}]\s/", $chunk)) {
					$is_open = false;
				}

				if ($next_c === "*") {
					$is_comment = true;
				}
			}

			// if it's a "{" then the block is every from the last $start to here
			if ($is_open && $has_len) {
				$len   = $i - $start;
				$block = substr($str, $start, $len);

				$blocks[] = $block;
				$start    = $i;
			// If it's a "}" it's a closing block that starts at $start
			} elseif ($is_closed) {
				$len         = $i - $start + 1;
				$block       = substr($str, $start, $len);
				$is_function = preg_match("/^\{\w+/", $block);

				// If we're in a function, loop until we find the closing tag
				if ($is_function) {
					for ($j = $i + 1; $j < strlen($str); $j++) {
						$closed = ($str[$j] === "}");

						// If we find a close tag we check to see if it's the final closed tag
						if ($closed) {
							$len = $j - $start + 1;
							$tmp = substr($str, $start, $len);

							// Count the number of open functions
							$of = preg_match_all("/\{(if|foreach|literal)/", $tmp);
							// Count the number of closed functions
							$cf = preg_match_all("/{\/\w+/", $tmp);

							// If the open and closed are the same number we found the final tag
							if ($of === $cf) {
								$block = $tmp;
								break;
							}
						}
					}
				}

				$blocks[]  = $block;
				$start    += strlen($block);
				$i         = $start;
			}

			// If it's a comment we slurp all the chars until the first '*}' and make that the block
			if ($is_comment) {
				$end = strpos($str, "*}", $start);
				if ($end === false) {
					$this->error_out("Missing closing \"*}\" for comment", 48724);
				}

				$end_rel   = $end + 2 - $start;
				$block     = substr($str, $start, $end_rel);
				$blocks[]  = $block;
				$start    += $end_rel;
				$i         = $start;
			}
		}

		// If we're not at the end of the string, add the last block
		if ($start < $slen) {
			$blocks[] = substr($str, $start);
		}

		return $blocks;
	}

	// This is just a wrapper function because early versions of Sluz used parse() instead of fetch()
	public function parse($tpl_file = "") {
		$ret = $this->fetch($tpl_file);

		return $ret;
	}

	// Wrapper function to make us more compatible with Smarty
	public function display($tpl_file = "") {
		print $this->fetch($tpl_file);
	}

	// Specify a path to the .stpl file, or pass nothing to let sluz 'guess'
	// Guess is 'tpls/[scriptname_minus_dot_php].stpl
	public function fetch($tpl_file = "") {
		$cur = error_reporting(); // Save current level so we can restore it
		error_reporting(E_ALL & ~E_NOTICE); // Disable E_NOTICE

		$tf             = $this->get_tpl_file($tpl_file);
		$this->tpl_file = $tf;

		// If we're in simple mode and we have a __halt_compiler() we can assume inline mode
		$inline_simple = $this->simple_mode && !$tpl_file && $this->get_inline_content($this->php_file);

		if ($tpl_file === SLUZ_INLINE || $inline_simple) {
			$str = $this->get_inline_content($this->php_file);
		} elseif (!is_readable($tf)) {
			$this->error_out("Unable to load template file <code>$tf</code>",42280);
		} else {
			$str = file_get_contents($tf);
		}

		if ($this->debug) { print nl2br(htmlentities($str)) . "<hr>"; }

		$blocks = $this->get_blocks($str);
		$html   = '';
		foreach ($blocks as $block) {
			$html .= $this->process_block($block);
		}

		$this->fetch_called = true;

		error_reporting($cur); // Reset error reporting level

		return $html;
	}

	// Get the text after __halt_compiler()
	private function get_inline_content($file) {
		$str    = file_get_contents($file);
		$offset = stripos($str, '__halt_compiler();');

		if ($offset === false) {
			return null;
		}

		$str = substr($str, $offset + 19);

		return $str;
	}

	// The callback to do the include string replacement stuff
	private function include_callback(array $m) {
		$str  = $m[0];
		$file = '';
		if (preg_match("/(file=)?'(.+?)'/", $str, $m)) {
			$file = $m[2];
		} else {
			$this->error_out("Unable to find a template in include block <code>$str</code>", 18488);
		}

		// Extra variables to include sub templates
		if (preg_match_all("/(\w+)='(.+?)'/", $str, $m)) {
			for ($i = 0; $i < count($m[0]); $i++) {
				$key = $m[1][$i] ?? "";
				$val = $m[2][$i] ?? "";

				$this->assign($key, $val);
			}
		}

		$inc_tpl = ($this->tpl_path ?? "tpls/") . $file;

		if ($file && is_readable($inc_tpl)) {
			$ext_str = file_get_contents($inc_tpl);
			return $ext_str;
		} else {
			$this->error_out("Unable to load include template <code>$inc_tpl</code>", 18485);
		}
	}

	// If there is not template specified we "guess" based on the PHP filename
	private function get_tpl_file($tpl_file) {
		$x         = debug_backtrace();
		$last      = count($x) - 1;
		$orig_file = basename($x[$last]['file'] ?? "");

		if (!$this->php_file) {
			$this->php_file = $orig_file;
		}

		if ($tpl_file === "INLINE") {
			$tpl_file = null;
		} elseif (!$tpl_file) {
			$tpl_file = $this->guess_tpl_file($orig_file);
		}

		return $tpl_file;
	}

	public function guess_tpl_file(string $php_file) {
		if ($this->simple_mode && !$this->tpl_file) {
			$php_file = $this->php_file;
		}

		$php_file = preg_replace("/.php$/", '', basename($php_file));
		$dir      = $this->tpl_path ?? "tpls/";
		$tpl_file = $dir . $php_file . ".stpl";

		return $tpl_file;
	}

	// Extract data from an array in the form of $foo.key.baz
	public function array_dive(string $needle, array $haystack) {
		// Split at the periods
		$parts = explode(".", $needle);

		// Loop through each level of the hash looking for elem
		$arr = $haystack;
		foreach ($parts as $elem) {
			$arr = $arr[$elem] ?? null;

			// If we don't find anything stop looking
			if ($arr === null) {
				break;
			}
		}

		// If we find a scalar it's the end of the line, anything else is just
		// another branch, so it doesn't cound as finding something
		if (is_scalar($arr) || is_array($arr)) {
			$ret = $arr;
		} else {
			$ret = null;
		}

		return $ret;
	}

	// Convert $cust.name.first -> $cust['name']['first'] and $num.0.3 -> $num[0][3]
	private function convert_variables_in_string($str) {
		$dot_to_bracket_callback = function($m) {
			$str   = $m[1];
			$parts = explode(".", $str);

			$ret = array_shift($parts);
			$ret = "$" . $this->var_prefix . '_' . substr($ret,1);
			foreach ($parts as $x) {
				if (is_numeric($x)) {
					$ret .= "[" . $x . "]";
				} else {
					$ret .= "['" . $x . "']";
				}
			}

			return $ret;
		};

		// Process flat arrays in the test like $cust.name or $array[3]
		$str = preg_replace_callback('/(\$\w[\w\.]*)/', $dot_to_bracket_callback, $str);

		return $str;
	}

	public function error_out($msg, int $err_num) {
		$out = "<style>
			.s_error {
				font-family: sans;
				border: 1px solid;
				padding: 6px;
				border-radius: 4px;
				margin-bottom: 8px;
			}

			.s_error_head { margin-top: 0; }
			.s_error_num { margin-top: 1em; }
			.s_error_file {
				margin-top: 1em;
				padding-top: 0.5em;
				font-size: .8em;
				border-top: 1px solid;
			}

			.s_error code {
				padding: .2rem .4rem;
				font-size: 1.1em;
				color: #fff;
				background-color: #212529;
				border-radius: .2rem;
			}
		</style>";

		if ($this->in_unit_test) {
			return "ERROR-$err_num";
		}

		$d    = debug_backtrace();
		$file = $d[1]['file'] ?? "";
		$line = $d[1]['line'] ?? 0;

		$out .= "<div class=\"s_error\">\n";
		$out .= "<h1 class=\"s_error_head\">Sluz Fatal Error</h1>";
		$out .= "<div class=\"s_error_desc\"><b>Description:</b> $msg</div>";
		$out .= "<div class=\"s_error_num\"><b>Number</b> #$err_num</div>";
		if ($file && $line) {
			$out .= "<div class=\"s_error_file\">Source: <code>$file</code> #$line</div>";
		}
		$out .= "</div>\n";

		print $out;

		exit;
	}

	private function peval($str) {
		extract($this->tpl_vars, EXTR_PREFIX_ALL, $this->var_prefix);

		$ret = '';
		$cmd = '$ret = (' . $str. ");";
		@eval($cmd);

		return $ret;
	}

	public function enable_simple_mode($php_file) {
		$this->php_file    = $php_file;
		$this->tpl_path    = realpath(dirname($this->php_file) . "/tpls/") . "/";
		$this->simple_mode = true;
	}

	///////////////////////////////////////////////////////////////////////////////
	///////////////////////////////////////////////////////////////////////////////

	// parse a simple variable
	private function variable_block($str) {

		// If it has a '|' it's either a function call or 'default'
		if (preg_match("/(.+?)\|(.+?)(:|$)/", $str, $m)) {
			$key = $m[1];
			$mod = $m[2];

			$tmp        = $this->array_dive($key, $this->tpl_vars) ?? "";
			$is_nothing = ($tmp === null || $tmp === "");

			// Empty with a default value
			if ($is_nothing && $mod === "default") {
				$p    = explode("default:", $str, 2);
				$dval = $p[1] ?? "";
				$ret  = $this->peval($dval);
			// Non-empty, but has a default value
			} elseif (!$is_nothing && $mod === "default") {
				$ret = $this->array_dive($key, $this->tpl_vars) ?? "";
			// User function
			} else {
				$pre = $this->array_dive($key, $this->tpl_vars) ?? "";
				$ret = call_user_func($mod, $pre);
			}
		} else {
			$ret = $this->array_dive($str, $this->tpl_vars) ?? "";
		}

		// Array used as a scalar should silently convert to a string
		if (is_array($ret)) {
			return 'Array';
		}

		return $ret;
	}

	// parse an if statement
	private function if_block($str, $m) {
		// Put the tpl_vars in the current scope so if works against them
		extract($this->tpl_vars, EXTR_PREFIX_ALL, $this->var_prefix);

		$cond[]  = $m[1];
		$payload = $m[2];

		// We build a list of tests and their output value if true in $rules
		// We extract the conditions in $cond and the true values in $parts

		// This is the number of if/elseif/else blocks we need to find tests for
		$part_count = preg_match_all("/\{(if|elseif|else\})/", $str, $m);

		// The middle conditions are the {elseif XXXX} stuff
		preg_match_all("/\{elseif (.+?)\}/", $payload, $m);
		foreach ($m[1] as $i) {
			$cond[] = $i;
		}

		// The last condition is the else and it's always true
		$cond[] = 1;

		// This gets us all the payload elements
		$parts  = preg_split("/(\{elseif (.+?)\}|\{else\})/", $payload);

		// Build all the rules and associated values
		$rules  = [];
		for ($i = 0; $i < $part_count; $i++) {
			$rules[] = [$cond[$i] ?? null,$parts[$i] ?? null];
		}

		$ret = "";
		foreach ($rules as $x) {
			$test    = $x[0];
			$payload = $x[1];
			$testp   = $this->convert_variables_in_string($test);

			if ($this->peval($testp)) {
				$blocks = $this->get_blocks($payload);

				foreach ($blocks as $block) {
					$ret .= $this->process_block($block);
				}

				// One of the tests was true so we stop processing
				break;
			}
		}

		return $ret;
	}

	// Parse an include block
	private function include_block($str) {
		$callback = [$this, 'include_callback']; // Object callback syntax
		$str      = preg_replace_callback("/\{include.+?\}/", $callback, $str);
		$blocks   = $this->get_blocks($str);

		$ret = '';
		foreach ($blocks as $block) {
			$ret .= $this->process_block($block);
		}

		return $ret;
	}

	// Parse a foreach block
	private function foreach_block($m) {
		$src     = $this->convert_variables_in_string($m[1]); // src array
		$okey    = $m[2]; // orig key
		$oval    = $m[4]; // orig val
		$payload = $m[5]; // code block to parse on iteration
		$blocks  = $this->get_blocks($payload);

		$src = $this->peval($src);

		// If $src isn't an array we convert it to one so foreach doesn't barf
		if (isset($src) && !is_array($src)) {
			$src = [$src];
		// This prevents an E_WARNING on null (but doesn't output anything)
		} elseif (is_null($src)) {
			$src = [];
		}

		$ret = '';
		// Temp set a key/val so when we process this section it's correct
		foreach ($src as $key => $val) {
			// Save the current values so we can restore them later
			$prevk = $this->tpl_vars[$okey] ?? null;
			$prevv = $this->tpl_vars[$oval] ?? null;

			// This is a key/val pair: foreach $key => $val
			if ($oval) {
				$this->tpl_vars[$okey] = $key;
				$this->tpl_vars[$oval] = $val;
			// This is: foreach $array as $item
			} else {
				$this->tpl_vars[$okey] = $val;
			}

			foreach ($blocks as $block) {
				$ret .= $this->process_block($block);
			}

			// Restore the previous value
			$this->tpl_vars[$okey] = $prevk;
			$this->tpl_vars[$oval] = $prevv;
		}

		return $ret;
	}

	// Parse a simple expression block
	private function expression_block($str, $m) {
		$ret = "";

		// Make sure the block has something parseble... at least a $ or "
		if (!preg_match("/[\"\d$]/", $str)) {
			return $this->error_out("Unknown block type '$str'", 73467);
		}

		$blk   = $m[1];
		$after = $this->convert_variables_in_string($blk);
		$ret   = $this->peval($after);

		if (!$ret) {
			$ret = $str;
			return $this->error_out("Unknown tag '$str'", 18933);
		}

		return $ret;
	}
}

// This function is *OUTSIDE* of the class so it can be called separately without
// instantiating the class
function sluz($one, $two = null) {
	static $s;

	if (!$s) {
		$s = new sluz();
		$d    = debug_backtrace();
		$last = array_shift($d);
		$file = $last['file'];

		$s->enable_simple_mode($file);
	}

	$s->assign($one, $two);

	return $s;
}

// vim: tabstop=4 shiftwidth=4 noexpandtab autoindent softtabstop=4
