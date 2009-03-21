<?php

class HConsole extends Plugin
{
	private $code = array();
	
	public function info()
	{
		return array (
			'name' => 'HConsole',
			'version' => '0.1',
			'author' => 'Habari Community',
			'license' => 'Apache License 2.0',
			'description' => 'A live Habari Console.',
		);
	}
	
	public function alias()
	{
		return array (
			'template_footer' => array( 'action_admin_footer', 'action_template_footer' )
		);
	}
	
	public function action_init()
	{
		Stack::add( 'template_header_javascript', Site::get_url('scripts') . '/jquery.js', 'jquery' );
		if ( User::identify()->loggedin && $_POST->raw('hconsole_code') ) {
			$wsse = Utils::WSSE( $_POST['nonce'], $_POST['timestamp'] );
			if ( $_POST['PasswordDigest'] == $wsse['digest'] ) {
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
			eval( $this->code['debug'] );
		}
	}
	
	public function template_footer()
	{
		if ( User::identify()->loggedin ) {
			$wsse = Utils::wsse();
			$code = $_POST->raw('hconsole_code');
			$display = empty($_POST['hconsole_code']) ? 'display:none;' : '';
			echo <<<GOO
			
			<div >
			<a href="#" style="width:80px; padding:2px; background:#c00; text-align:center; position:fixed; bottom:0; right:0; font-size:11px; z-index:99; color:white; display:block;" onclick="jQuery('#hconsole').toggle('slow'); return false;">^ HConsole</a>
			</div>
			<div  id="hconsole" style='$display position:fixed; width:100%; bottom:0; left:0; padding:0; margin:0; background:#ccc; z-index:98;'>
			<form method='post' action='' style="padding:1em 2em; margin:0">
				<textarea cols='100' rows='7' name='hconsole_code'>{$code}</textarea>
				<input type='submit' value='run' />
				<input type="hidden" id="nonce" name="nonce" value="{$wsse['nonce']}">
				<input type="hidden" id="timestamp" name="timestamp" value="{$wsse['timestamp']}">
				<input type="hidden" id="PasswordDigest" name="PasswordDigest" value="{$wsse['digest']}">
			</form>
			<pre style="border:none; padding:1em 2em; margin:0; background:none; overflow:auto; max-height:150px;">
GOO;
			Plugins::act('hconsole_debug');
			echo "</pre></div></div>";
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
