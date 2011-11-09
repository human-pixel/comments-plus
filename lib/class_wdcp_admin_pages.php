<?php
/**
 * Handles generic Admin functionality and AJAX requests
 */
class Wdcp_AdminPages {

	var $model;
	var $data;

	function Wdcp_AdminPages () { $this->__construct(); }

	function __construct () {
		$this->model = new Wdcp_Model;
		$this->data = new Wdcp_Options;
	}

	/**
	 * Main entry point.
	 *
	 * @static
	 */
	function serve () {
		$me = new Wdcp_AdminPages;
		$me->add_hooks();
	}

	/**
	 * Registers settings.
	 */
	function register_settings () {
		register_setting('wdcp', 'wdcp_options');
		$form = new Wdcp_AdminFormRenderer;

		add_settings_section('wdcp_options', __('App settings', 'wdcp'), create_function('', ''), 'wdcp_options');
		add_settings_field('wdcp_facebook_app', __('Facebook App info', 'wdcp'), array($form, 'create_facebook_app_box'), 'wdcp_options', 'wdcp_options');
		add_settings_field('wdcp_facebook_skip', __('Skip loading Facebook javascript', 'wdcp'), array($form, 'create_skip_facebook_init_box'), 'wdcp_options', 'wdcp_options');
		add_settings_field('wdcp_twitter_app', __('Twitter App info', 'wdcp'), array($form, 'create_twitter_app_box'), 'wdcp_options', 'wdcp_options');
		add_settings_field('wdcp_twitter_skip', __('Skip loading Twitter javascript', 'wdcp'), array($form, 'create_skip_twitter_init_box'), 'wdcp_options', 'wdcp_options');

		add_settings_section('wdcp_general', __('General Settings', 'wdcp'), create_function('', ''), 'wdcp_options');
		add_settings_field('wdcp_wp_icon', __('WordPress branding &amp; options', 'wdcp'), array($form, 'create_wp_icon_box'), 'wdcp_options', 'wdcp_general');
		add_settings_field('wdcp_skip_services', __('Do not show &quot;Comment with&hellip;&quot;', 'wdcp'), array($form, 'create_skip_services_box'), 'wdcp_options', 'wdcp_general');
		add_settings_field('wdcp_style', __('Comments Plus Styling', 'wdcp'), array($form, 'create_style_box'), 'wdcp_options', 'wdcp_general');

		add_settings_section('wdcp_hooks', __('Hooks', 'wdcp'), array($form, 'create_hooks_section'), 'wdcp_options');
		add_settings_field('wdcp_start_hook', __('Start injection hook', 'wdcp'), array($form, 'create_start_hook_box'), 'wdcp_options', 'wdcp_hooks');
		add_settings_field('wdcp_end_hook', __('Finish injection hook', 'wdcp'), array($form, 'create_end_hook_box'), 'wdcp_options', 'wdcp_hooks');
	}

	/**
	 * Creates Admin menu entry.
	 */
	function create_admin_menu_entry () {
		if (@$_POST && isset($_POST['option_page']) && 'wdcp' == @$_POST['option_page']) {
			if (isset($_POST['wdcp_options'])) {
				$this->data->set_options($_POST['wdcp_options']);
			}
			$goback = add_query_arg('settings-updated', 'true',  wp_get_referer());
			wp_redirect($goback);
		}
		$page = WP_NETWORK_ADMIN ? 'settings.php' : 'options-general.php';
		$perms = WP_NETWORK_ADMIN ? 'manage_network_options' : 'manage_options';
		add_submenu_page($page, __('Comments Plus', 'wdcp'), __('Comments Plus', 'wdcp'), $perms, 'wdcp', array($this, 'create_admin_page'));
	}

	/**
	 * Creates Admin menu page.
	 */
	function create_admin_page () {
		include(WDCP_PLUGIN_BASE_DIR . '/lib/forms/plugin_settings.php');
	}

	function json_get_form () {
		$worker = new Wdcp_CommentsWorker;
		$provider = $_POST['provider'];
		$html = call_user_func(array($worker, "_prepare_{$provider}_comments"), $_POST['page']);
		header('Content-type: application/json');
		echo json_encode(array(
			'html' => $html,
		));
		exit();
	}

	function json_google_auth_url () {
		header('Content-type: application/json');
		echo json_encode(array(
			'url' => $this->model->get_google_auth_url($_POST['url']),
		));
		exit();
	}
	function json_google_logout () {
		$this->model->google_logout_user();
		header('Content-type: application/json');
		echo json_encode(array(
			'status' => 1,
		));
		exit();
	}

	function json_twitter_logout () {
		$this->model->twitter_logout_user();
		header('Content-type: application/json');
		echo json_encode(array(
			'status' => 1,
		));
		exit();
	}
	function json_twitter_auth_url () {
		header('Content-type: application/json');
		echo json_encode(array(
			'url' => $this->model->get_twitter_auth_url($_POST['url']),
		));
		exit();
	}

	function json_facebook_logout () {
		$this->model->facebook_logout_user();
		header('Content-type: application/json');
		echo json_encode(array(
			'status' => 1,
		));
		exit();
	}

	function json_post_facebook_comment () {
		if (!$this->model->current_user_logged_in('facebook')) return false;
		$fb_uid = $this->model->current_user_id('facebook');
		$username = $this->model->current_user_name('facebook');
		$email = $this->model->current_user_email('facebook');
		$url = $this->model->current_user_url('facebook');

		$data = array(
			'comment_post_ID' => @$_POST['post_id'],
			'comment_author' => $username,
			'comment_author_email' => $email,
			'comment_author_url' => $url,
			'comment_content' => @$_POST['comment'],
			'comment_type' => '',
			'comment_parent' => (int)@$_POST['comment_parent'],
		);

		$meta = array (
			'wdcp_fb_author_id' => $fb_uid,
		);
		$comment_id = wp_new_comment($data);
		add_comment_meta($comment_id, 'wdcp_comment', $meta) ;
		do_action('comment_post', $comment_id, $data['comment_approved']);
		$this->_postprocess_comment($comment_id);


		// Post comment to Facebook ...
		if ((int)$_POST['post_on_facebook']) {
			$this->model->post_to_facebook($_POST);
		}

		header('Content-type: application/json');
		echo json_encode(array(
			'status' => 1,
		));
		exit();
	}

	function json_post_twitter_comment () {
		if (!$this->model->current_user_logged_in('twitter')) return false;
		$tw_uid = $this->model->current_user_id('twitter');
		$username = $this->model->current_user_name('twitter');
		$email = $this->model->current_user_email('twitter');
		$url = $this->model->current_user_url('twitter');
		$avatar = $this->model->twitter_avatar();

		$data = array(
			'comment_post_ID' => @$_POST['post_id'],
			'comment_author' => $username,
			'comment_author_email' => $email,
			'comment_author_url' => $url,
			'comment_content' => @$_POST['comment'],
			'comment_type' => '',
			'comment_parent' => (int)@$_POST['comment_parent'],
		);

		$meta = array (
			'wdcp_tw_avatar' => $avatar,
		);
		//$comment_id = wp_insert_comment($data);
		$comment_id = wp_new_comment($data);
		add_comment_meta($comment_id, 'wdcp_comment', $meta) ;
		do_action('comment_post', $comment_id, $data['comment_approved']);
		$this->_postprocess_comment($comment_id);


		// Post comment to Facebook ...
		if ((int)$_POST['post_on_twitter']) {
			$this->model->post_to_twitter($_POST);
		}

		header('Content-type: application/json');
		echo json_encode(array(
			'status' => 1,
		));
		exit();
	}

	function json_post_google_comment () {
		if (!$this->model->current_user_logged_in('google')) return false;
		$guid = $this->model->current_user_id('google');
		$username = $this->model->current_user_name('google');
		$email = $this->model->current_user_email('google');

		$data = array(
			'comment_post_ID' => @$_POST['post_id'],
			'comment_author' => $username,
			'comment_author_email' => $email,
			'comment_content' => @$_POST['comment'],
			'comment_type' => '',
			'comment_parent' => (int)@$_POST['comment_parent'],
		);

		$meta = array (
			'wdcp_gg_author_id' => $guid,
		);
		//$comment_id = wp_insert_comment($data);
		$comment_id = wp_new_comment($data);
		add_comment_meta($comment_id, 'wdcp_comment', $meta) ;
		do_action('comment_post', $comment_id, $data['comment_approved']);
		$this->_postprocess_comment($comment_id);

		header('Content-type: application/json');
		echo json_encode(array(
			'status' => 1,
		));
		exit();
	}

	function _postprocess_comment ($comment_id) {
		$comment = get_comment($comment_id);
		if ( !get_current_user_id() ) {
			$comment_cookie_lifetime = apply_filters('comment_cookie_lifetime', 30000000);
			setcookie('comment_author_' . COOKIEHASH, $comment->comment_author, time() + $comment_cookie_lifetime, COOKIEPATH, COOKIE_DOMAIN);
			setcookie('comment_author_email_' . COOKIEHASH, $comment->comment_author_email, time() + $comment_cookie_lifetime, COOKIEPATH, COOKIE_DOMAIN);
			setcookie('comment_author_url_' . COOKIEHASH, esc_url($comment->comment_author_url), time() + $comment_cookie_lifetime, COOKIEPATH, COOKIE_DOMAIN);
		}
	}

	function add_hooks () {
		// Register options and menu
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_menu', array($this, 'create_admin_menu_entry'));
		add_action('network_admin_menu', array($this, 'create_admin_menu_entry'));

		// Bind AJAX requests
		add_action('wp_ajax_nopriv_wdcp_get_form', array($this, 'json_get_form'));
		add_action('wp_ajax_wdcp_get_form', array($this, 'json_get_form'));

		add_action('wp_ajax_nopriv_wdcp_google_auth_url', array($this, 'json_google_auth_url'));
		add_action('wp_ajax_wdcp_google_auth_url', array($this, 'json_google_auth_url'));
		add_action('wp_ajax_nopriv_wdcp_twitter_auth_url', array($this, 'json_twitter_auth_url'));
		add_action('wp_ajax_wdcp_twitter_auth_url', array($this, 'json_twitter_auth_url'));

		add_action('wp_ajax_wdcp_post_facebook_comment', array($this, 'json_post_facebook_comment'));
		add_action('wp_ajax_nopriv_wdcp_post_facebook_comment', array($this, 'json_post_facebook_comment'));

		add_action('wp_ajax_wdcp_post_google_comment', array($this, 'json_post_google_comment'));
		add_action('wp_ajax_nopriv_wdcp_post_google_comment', array($this, 'json_post_google_comment'));

		add_action('wp_ajax_wdcp_post_twitter_comment', array($this, 'json_post_twitter_comment'));
		add_action('wp_ajax_nopriv_wdcp_post_twitter_comment', array($this, 'json_post_twitter_comment'));

		add_action('wp_ajax_wdcp_facebook_logout', array($this, 'json_facebook_logout'));
		add_action('wp_ajax_nopriv_wdcp_facebook_logout', array($this, 'json_facebook_logout'));


		add_action('wp_ajax_wdcp_google_logout', array($this, 'json_google_logout'));
		add_action('wp_ajax_nopriv_wdcp_google_logout', array($this, 'json_google_logout'));

		add_action('wp_ajax_wdcp_twitter_logout', array($this, 'json_twitter_logout'));
		add_action('wp_ajax_nopriv_wdcp_twitter_logout', array($this, 'json_twitter_logout'));
	}
}