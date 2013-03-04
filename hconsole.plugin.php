<?php

//namespace Habari;

class HConsole extends Plugin
{
	private $code = array();
	private $sql = false;
	private $htmlspecial = false;

	public function alias()
	{
		return array (
			'template_footer' => array( 'action_admin_footer', 'action_template_footer' )
		);
	}

	/**
	 * Early as possible, let's define DEBUG so we get DEBUG output and turn on error display;
	 * But only if we have code to execute.
	 */
	public function action_plugins_loaded()
	{
		if ( !empty($_POST['hconsole_code']) ) {
			if ( !defined( 'Habari\DEBUG') ) {
				define('Habari\DEBUG', true);
			}
			ini_set('display_errors', 'on');
		}
	}

	public function action_init()
	{
		if ( User::identify()->loggedin ) {
			Stack::add( 'template_header_javascript', Site::get_url('scripts') . '/jquery.js', 'jquery' );
		}
		if ( $_POST->raw('hconsole_code') ) {
			$wsse = Utils::WSSE( $_POST['nonce'], $_POST['timestamp'] );
			if ( $_POST['PasswordDigest'] == $wsse['digest'] ) {
				if ( isset($_POST['sql']) && $_POST['sql'] == 'RUN SQL' ) {
					require "texttable.php";
					$this->sql = rawurldecode($_POST->raw('hconsole_code'));
					return;
				}
				if ( isset($_POST['htmlspecial']) && $_POST['htmlspecial'] == 'true' ) {
					$this->htmlspecial = true;
				}
				$this->code = $this->parse_code(rawurldecode($_POST->raw('hconsole_code')));
				foreach( $this->code['hooks'] as $i => $hook ) {
					$functions = $this->get_functions($hook['code']);
					if ( empty($functions) ) {
						trigger_error( "Parse Error in $i. No function to register.", E_USER_WARNING );
					}
					else {
						eval($hook['code']);
						foreach ( $functions as $function ) {
							if ( $i == 'action_init' ) {
								call_user_func($function);
							}
							else {
								Plugins::register($function, $hook['type'], $hook['hook']);
							}
						}
					}
				}
			}
		}
	}

	public function action_hconsole_debug()
	{
		if ( isset($this->code['debug']) ) {
			ob_start();
			$res = eval( $this->code['debug'] );
			$dat = ob_get_contents();
			ob_end_clean();
			if ( $res === false ) {
				throw Error::raise($dat, E_COMPILE_ERROR);
			}
			else {
				if ( $this->htmlspecial ) {
					echo htmlspecialchars($dat);
				}
				else {
					echo $dat;
				}
			}
		}
		if ( $this->sql ) {
			$itemlist = array();
			if (preg_match('#^\s*select.*#i', $this->sql)) {
				$d = DB::get_results($this->sql);
				if (is_array($d) && count($d)) {
					$itemlist = array_map( function ($r) { return $r->to_array(); }, $d);
				}
				else {
					$itemlist[] = array('result' => 'empty set');
				}
			}
			else {
				$d = DB::query($this->sql);
				$itemlist[] = array('result' => (string) $d);
			}
			if (DB::has_errors()) {
				$itemlist = array(DB::get_last_error());
			}
			$renderer = new ArrayToTextTable($itemlist);
			$renderer->showHeaders(true);
			echo htmlspecialchars($renderer->render(true));
		}
	}

	/**
	 * @TODO clean up this html and code here.
	 */
	public function template_footer()
	{
		if ( User::identify()->loggedin ) {
			$wsse = Utils::wsse();
			$code = $_POST->raw('hconsole_code');
			$display = empty($_POST['hconsole_code']) ? 'display:none;' : '';
			$htmlspecial = isset($_POST['htmlspecial']) ? 'checked="true"' : '';
			$sql = isset($_POST['sql']) ? 'checked="true"' : '';
			echo <<<GOO

			<div >
			<a href="#" style="width:80px; padding:2px; background:#c00; text-align:center; position:fixed; bottom:0; right:0; font-size:11px; z-index:999; color:white; display:block;" onclick="jQuery('#hconsole').toggle('slow'); return false;">^ HConsole</a>
			</div>
			<div  id="hconsole" style='$display position:fixed; width:100%; bottom:0; left:0; padding:0; margin:0; background:#222; z-index:998;'>
			<pre class="resizable" style="font-family:monospace; font-size:11px; padding:1em 2em; margin:1em; background:#333; color:#93C763; border:1px solid #000; overflow:auto; max-height:400px;">
GOO;
			try {
				Plugins::act('hconsole_debug');
			}
			catch ( \Exception $e ) {
				Error::exception_handler($e);
			}
			echo <<<MOO
			</pre>
			<form method='post' action='' style="padding:1em 2em; margin:0; text-align:left; color:#eee; position:relative">
				<textarea cols='100' rows='7' name='hconsole_code'>{$code}</textarea><br>
				<div id="editor-filler" style="width:100%; position:realtive; height:180px; margin:0; padding:0;">
				<div id="hconsole_edit" style="position:absolute; left:0; top:0; height:180px; width:100%;"></div>
				</div>
				<input type='submit' value='RUN' style="clear:both" />
				<input type='checkbox' name='htmlspecial' value='true' $htmlspecial />htmlspecialchars
				<input type='checkbox' name='sql' value="RUN SQL" $sql />SQL
				<input type="hidden" id="nonce" name="nonce" value="{$wsse['nonce']}">
				<input type="hidden" id="timestamp" name="timestamp" value="{$wsse['timestamp']}">
				<input type="hidden" id="PasswordDigest" name="PasswordDigest" value="{$wsse['digest']}">
			</form>
			<script src="http://d1n0x3qji82z53.cloudfront.net/src-min-noconflict/ace.js" type="text/javascript" charset="utf-8"></script>
			<script>
			var editor = ace.edit("hconsole_edit");
			var textarea = $('textarea[name="hconsole_code"]').hide();
			$('input[name="sql"]').on('click', sqlCheck);
			function sqlCheck (){
			  if ($('input[name="sql"]').attr('checked')) {
			    editor.getSession().setMode('ace/mode/sql');
			  }
			  else {
			    editor.getSession().setMode('ace/mode/php');
			  }
			}
			$(document).ready(function(){sqlCheck();});
			editor.getSession().setValue(textarea.val());
			editor.getSession().on('change', function(){
			  textarea.val(editor.getSession().getValue());
			});
			editor.setTheme("ace/theme/twilight");
			editor.getSession().setMode("ace/mode/php");
			</script></div>
MOO;


		}
	}

	private function get_functions ( $code ) {
		$tokens = token_get_all( "<?php $code ?>");
		$functions = array();
		foreach ( $tokens as $i => $token ) {
			if ( is_array($token) && $token[0] == T_FUNCTION ) {
				if ( $tokens[$i+1][0] == T_STRING) {
					$functions[] = $tokens[$i+1][1];
				}
				elseif ( $tokens[$i+1][0] == T_WHITESPACE && $tokens[$i+2][0] == T_STRING) {
					$functions[] = $tokens[$i+2][1];
				}
			}
		}
		return $functions;
	}

	private function parse_code( $code )
	{
		$tokens = token_get_all( "<?php $code ?>");
		$hooks = array();
		$debug = array();
		$flag = false;
		$braket = 1;

		for ( $i = 0; $i < count($tokens); $i++ ) {
			$token = $tokens[$i];
			if ( $flag ) {
				if ( $braket == 0 ) {
					$hooks[$flag]['end'] = $i-1;
					$flag = false;
					$braket = 1;
				}
				if ( $token == '}' ) {
					$braket--;
				}
				elseif ( $token == '{' ) {
					$braket++;
				}
				continue;
			}
			if ( is_array($token) && $token[0] == T_STRING && preg_match('@^(action|filter|theme|xmlrpc)_(.+)$@i', $token[1], $m) ) {
				$hooks[$m[0]]['hook'] = $m[2];
				$hooks[$m[0]]['type'] = $m[1];
				$flag = $m[0];
				if ($tokens[$i+1] == '{') {
					$hooks[$m[0]]['start'] = $i+2;
					$i+=3;
				}
				elseif ($tokens[$i+1][0] == T_WHITESPACE && $tokens[$i+2] == '{') {
					$hooks[$m[0]]['start'] = $i+3;
					$i+=2;
				}
				else {
					trigger_error( "Parse Error in $flag", E_USER_ERROR );
				}
			}
			elseif ( is_array($token) && ($token[0] == T_CLOSE_TAG || $token[0] == T_OPEN_TAG) ) {
				continue;
			}
			else {
				$debug[] = $token;
			}
		}

		foreach ( $hooks as $i => $hook ) {
			if ( empty($hook['end']) ) {
				trigger_error( "Parse Error in $i. No closing braket", E_USER_ERROR );
				unset($hooks[$i]);
				continue;
			}
			$toks = array_slice( $tokens, $hook['start'], $hook['end']-$hook['start']);
			$hooks[$i]['code'] = '';
			foreach ( $toks as $token ) {
				$hooks[$i]['code'] .= is_array($token) ? $token[1] : $token;
			}
		}
		return array(
			'hooks' => $hooks,
			'debug' => implode(array_map(create_function('$a', 'return is_array($a)?$a[1] : $a;'), $debug))
		);
	}
}
?>
