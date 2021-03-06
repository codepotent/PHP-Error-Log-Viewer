<?php

/**
 * -----------------------------------------------------------------------------
 * Plugin Name: PHP Error Log Viewer
 * Description: Creates a browser-viewable display of the PHP error log. Error messages are styled and filterable to facilitate quick skimming.
 * Version: 2.1.0
 * Author: Code Potent
 * Author URI: https://codepotent.com
 * Plugin URI: https://codepotent.com/classicpress/plugins/
 * Text Domain: codepotent-php-error-log-viewer
 * Domain Path: /languages
 * -----------------------------------------------------------------------------
 * This is free software released under the terms of the General Public License,
 * version 2, or later. It is distributed WITHOUT ANY WARRANTY; without even the
 * implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. Full
 * text of the license is available at https://www.gnu.org/licenses/gpl-2.0.txt.
 * -----------------------------------------------------------------------------
 * Copyright 2020, Code Potent
 * -----------------------------------------------------------------------------
 *           ____          _      ____       _             _
 *          / ___|___   __| | ___|  _ \ ___ | |_ ___ _ __ | |_
 *         | |   / _ \ / _` |/ _ \ |_) / _ \| __/ _ \ '_ \| __|
 *         | |__| (_) | (_| |  __/  __/ (_) | ||  __/ | | | |_
 *          \____\___/ \__,_|\___|_|   \___/ \__\___|_| |_|\__|.com
 *
 * -----------------------------------------------------------------------------
 */

// Declare the namespace.
namespace CodePotent\PhpErrorLogViewer;

// Prevent direct access.
if (!defined('ABSPATH')) {
	die();
}

class PhpErrorLogViewer {

	/**
	 * Path to error log file.
	 *
	 * @var null
	 */
	public $error_log = null;

	/**
	 * For tallying errors.
	 *
	 * @var integer
	 */
	public $error_count = 0;

	/**
	 * For admin bar alert bubble.
	 *
	 * @var integer
	 */
	public $errors_displayed = 0;

	/**
	 * For gathering plugin options.
	 *
	 * @var array
	 */
	public $options = [];

	/**
	 * Multidimensional array of errors, keyed by type.
	 *
	 * @var array[][]
	 */
	public $errors = [];

	/**
	 * Constructor.
	 *
	 * No properties to set; move straight to initialization.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		// Setup all the things.
		$this->init();

		// Process the error log into object properties.
		$this->convert_error_log_into_properties();

	}

	/**
	 * Plugin initialization.
	 *
	 * Register actions and filters to hook the plugin into the system.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 */
	public function init() {

		// Load constants.
		require_once plugin_dir_path(__FILE__).'includes/constants.php';

		// Load plugin update class.
		require_once(PATH_CLASSES.'/UpdateClient.class.php');

		// Update options in time to redirect; keeps admin bar alerts current.
		add_action('plugins_loaded', [$this, 'update_display_options']);

		// Execute purge requests; if no purge requested, nothing happens.
		add_action('plugins_loaded', [$this, 'process_purge_requests']);

		// Register admin page and menu item.
		add_action('admin_menu', [$this, 'register_admin_menu']);

		// Enqueue backend scripts.
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

		// Enqueue global styles for admin bar alerts on front and back end.
		add_action('wp_enqueue_scripts', [$this, 'enqueue_global_styles']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_global_styles']);

		// Add a quick link to the error log.
		add_action('wp_before_admin_bar_render', [$this, 'register_admin_bar']);

		// Replace footer text with plugin name and version info.
 		add_filter('admin_footer_text', [$this, 'filter_footer_text'], 10000);

 		// Add a "Settings" link to core's plugin admin row.
 		add_filter('plugin_action_links_'.PLUGIN_IDENTIFIER, [$this, 'register_action_links']);

 		// Register hooks for activation, deactivation, and uninstallation.
 		register_uninstall_hook(__FILE__,    [__CLASS__, 'uninstall_plugin']);
 		register_activation_hook(__FILE__,   [$this, 'activate_plugin']);
 		register_deactivation_hook(__FILE__, [$this, 'deactivate_plugin']);

	}

	/**
	 * Admin bar link.
	 *
	 * Add a link to the admin bar that leads to the PHP error log; just a minor
	 * convenience.
	 *
	 * @author John Alarcon
	 *
	 * @since 2.0.0
	 *
	 */
	public function register_admin_bar() {

		// Only site admins can see the admin bar entry.
		if (!current_user_can('manage_options')) {
			return;
		}

		// Primary link text.
		$link_text = esc_html__('PHP Errors', 'codepotent-php-error-log-viewer');

		// Alert bubble for displayed errors.
		$primary_alert = '';
		if ($this->errors_displayed > 0) {
			$primary_alert = '<span class="error-count-bubble">'.number_format($this->errors_displayed).'</span>';
		}

		// Alert bubble for hidden errors.
		$secondary_alert = '';
		if ($this->error_count !== $this->errors_displayed) {
			$secondary_alert = '<span class="error-count-bubble hidden-errors">'.number_format($this->error_count-$this->errors_displayed).'</span>';
		}

		// Filters to remove alert bubbles.
		$primary_alert = apply_filters(PLUGIN_PREFIX.'_primary_alert', $primary_alert);
		$secondary_alert = apply_filters(PLUGIN_PREFIX.'_secondary_alert', $secondary_alert);

		// Bring the admin bar into scope.
		global $wp_admin_bar;

		// Add the link.
		$wp_admin_bar->add_menu([
			'parent' => false,
			'id'     => PLUGIN_PREFIX.'_admin_bar',
			'title'  => $link_text.$primary_alert.$secondary_alert,
			'href'   => admin_url('tools.php?page='.PLUGIN_SHORT_SLUG),
			'meta'   => [
				'title' => sprintf(
					esc_html__('PHP %s', 'codepotent-php-error-log-viewer'),
					phpversion()
				)
			]
		]);

	}

	/**
	 * Register admin view.
	 *
	 * Place a "PHP Error Log" submenu item under the core Tools menu. This also
	 * registers the admin page for same.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 */
	public function register_admin_menu() {

		// Add submenu under the Tools menu.
		add_submenu_page(
			'tools.php',
			PLUGIN_NAME,
			PLUGIN_MENU_TEXT,
			'manage_options',
			PLUGIN_SHORT_SLUG,
			[$this, 'render_php_error_log']
			);

	}

	/**
	 * Add a direct link to the PHP Error Log in the plugin admin display.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @param array $links Administration links for the plugin.
	 *
	 * @return array $links Updated administration links.
	 */
	public function register_action_links($links) {

		// Prepend error log link in plugin row; for admins only.
		if (current_user_can('manage_options')) {
			$error_log_link = '<a href="'.admin_url('tools.php?page='.PLUGIN_SHORT_SLUG).'">'.esc_html__('PHP Error Log', 'codepotent-php-error-log-viewer').'</a>';
			array_unshift($links, $error_log_link);
		}

		// Return the maybe-updated $links array.
		return $links;

	}

	/**
	 * Enqueue JavaScript.
	 *
	 * JavaScript is used to allow the user to confirm the decision to purge the
	 * PHP error log; this prevents accidental deletion. Even though it's only a
	 * few lines of "vanilla" JavaScript, it should stil be enqueued in the same
	 * way as any other script.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 */
	public function enqueue_admin_scripts() {

		// Not in a view related to this plugin? Bail.
		if (!strpos(get_current_screen()->base, PLUGIN_SHORT_SLUG)) {
			return;
		}

		// Enqueue the script.
		wp_enqueue_script(PLUGIN_SLUG.'-admin', URL_SCRIPTS.'/admin-global.js');

		// Create an array of data to make available in the JavaScript.
		$js_vars = [
			'plugin_slug' => PLUGIN_SLUG,
			'question' => esc_html__('Remove all entries from the PHP error log?', 'codepotent-php-error-log-viewer'),
			'redirect' => esc_url(
				wp_nonce_url(
					admin_url('tools.php?page='.PLUGIN_SHORT_SLUG.'&purge_errors=1'),
					PLUGIN_PREFIX.'_purge_error_log'
				)
			),

		];

		// Scope the above PHP array out to the JS file.
		wp_localize_script(PLUGIN_SLUG.'-admin', 'confirmation', $js_vars);

	}

	/**
	 * Enqueue CSS.
	 *
	 * As of version 2.0.0, the plugin integrates admin bar alerts. An admin bar
	 * can be present in both (or either of) the front and back ends, so, styles
	 * for the alert bubble must be present on both sides. Still, the styles are
	 * only needed if the admin bar is visible, so, we use that as the check and
	 * squeeze another bit of performance out of the plugin. Note that the style
	 * sheet (at this writing) is only 7k, so, it's not a huge savings; still, a
	 * saved request never hurts.
	 *
	 * @author John Alarcon
	 *
	 * @since 2.0.0
	 */
	public function enqueue_global_styles() {

		// Used in admin bar alerts; enqueue for all needed pages.
		if (is_admin_bar_showing()) {
			wp_enqueue_style(PLUGIN_SLUG.'-admin', URL_STYLES.'/global.css');
		}

	}

	/**
	 * Get error type.
	 *
	 * This method receives a line from the error log and determines the type of
	 * error it is.
	 *
	 * @author John Alarcon
	 *
	 * @since 2.0.0
	 *
	 * @param string $error A line from the error log.
	 *
	 * @return string The type of error it is.
	 */
	public function get_error_type($error) {

		// Run through various acts of string-fu.
		if (strpos($error, 'PHP Deprecated')) {
			$type = 'deprecated';
		} else if (strpos($error, 'PHP Notice')) {
			$type = 'notice';
		} else if (substr($error, 0, 11) === 'Stack trace' || strpos($error, 'Stack trace')) {
			$type = 'stack_trace_title';
		} else if (substr($error, 0, 1) === '#' || strpos($error, 'stderr: #')) {
			$type = 'stack_trace_step';
		} else if (substr($error, 0, 9) === 'thrown in' || strpos($error, 'thrown in')) {
			$type = 'stack_trace_origin';
		} else if (strpos($error, 'error:') || strpos($error, 'stderr:') || strpos($error, '[error]')) {
			$type = 'error';
		} else if (strpos($error, 'PHP Warning') || strpos($error, '[warn]')) {
			$type = 'warning';
		} else {
			$type = 'other';
		}

		// Return the error type.
		return $type;

	}

	/**
	 * Get error types
	 *
	 * This method returns an array of error types contemplated by the plugin.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @return array Error type texts keyed accordingly.
	 */
	public function get_error_types() {

		// Array of error type texts keyed by type.
		$error_types = [
			'deprecated'         => esc_html__('Deprecated', 'codepotent-php-error-log-viewer'),
			'notice'             => esc_html__('Notice', 'codepotent-php-error-log-viewer'),
			'warning'            => esc_html__('Warning', 'codepotent-php-error-log-viewer'),
			'error'              => esc_html__('Error', 'codepotent-php-error-log-viewer'),
			'stack_trace_title'  => esc_html__('Stack Trace', 'codepotent-php-error-log-viewer'),
			'stack_trace_step'   => '',
			'stack_trace_origin' => '',
			'other'              => esc_html__('Other', 'codepotent-php-error-log-viewer'),
		];

		// Return the error types.
		return $error_types;

	}

	/**
	 * Get error defaults.
	 *
	 * This method is used to ensure all expected elements are initialized.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @return array[]
	 */
	public function get_error_defaults() {

		// Setup an array of empty arrays as defaults.
		$defaults = [];
		foreach (array_keys($this->get_error_types()) as $type) {
			$defaults[$type] = [];
		}

		// Return the defaults array.
		return $defaults;

	}

	/**
	 * Process error log
	 *
	 * This method processes the error log into various object properties.
	 *
	 * @author John Alarcon
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function convert_error_log_into_properties() {

		// Initialization.
		$this->errors = $this->raw_errors = [];

		// Error log not found? Bail.
		if (!file_exists($error_log = ini_get('error_log'))) {
			return;
		}

		// Set the error log path.
		$this->error_log = $error_log;

		// Set the filesize; in bytes.
		$this->filesize = filesize($error_log);

		// Set a default errors array.
		foreach (array_keys($this->get_error_types()) as $type) {
			$this->errors[$type] = [];
		}

		// Set plugin options.
		$this->options = get_option(PLUGIN_PREFIX, []);

		// If no errors found, this is far enough.
		if (empty($this->raw_errors = file($error_log))) {
			return;
		}

		// Reverse sort the array, if requested.
		if (!empty($this->options['reverse_sort']) && $this->options['reverse_sort']) {
			$this->reverse_sort_errors();
		}

		// Iterate over error lines.
		foreach ($this->raw_errors as $n=>$error) {
			// Tidy up the ends.
			$error = trim($error);
			// Determine this error's type.
			$type = $this->get_error_type($error);
			// Map the error to a type in all cases.
			$this->error_map[$n] = $type;
			// Capture only those errors that will be displayed.
			if (!empty($this->options[$type])) {
				$this->errors[$type][$n] = $error;
			}
			// Bold (most) error titles.
			$this->errors[$type][$n] = preg_replace('|(PHP )([A-Za-z]){1,} *([A-Za-z ]){1,}|', '<strong>${0}</strong>', $error);
			// Strip: "mod_fcgid: stderr:"
			$this->errors[$type][$n] = str_replace('mod_fcgid: stderr: ', '', $this->errors[$type][$n]);
			// Regex to find a datetime string.
			$pattern = '|([){1}([A-Za-z0-9_ -:\/]){1,}(]){1}|';
			// Strip date/time, or wrap it for styling purposes.
			if (empty($this->options['datetime'])) {
				$this->errors[$type][$n] = preg_replace($pattern, '', $this->errors[$type][$n]);
			} else {
				$this->errors[$type][$n] = preg_replace($pattern, '<span class="'.PLUGIN_SLUG.'-datetime">${0}</span>', $this->errors[$type][$n]);
			}
		}

		// With errors all gathered and sorted, count them up.
		foreach ($this->errors as $type=>$error_array) {
			// Stack trace data isn't counted; parent errors are.
			if (strpos($type, 'stack_trace_') !== 0) {
				// Count errors to be displayed.
				if (!empty($this->options[$type])) {
					$this->errors_displayed += count($error_array);
				}
				// Total of all errors.
				$this->error_count += count($error_array);
			}
		}

	}

	/**
	 * Purge error log.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 */
	public function process_purge_requests() {

		// Admins only.
		if (!current_user_can('manage_options')) {
			return;
		}

		// No nonce? Bail.
		if (!isset($_GET['_wpnonce'])) {
			return;
		}

		// Suspicious nonce? Bail.
		if (!wp_verify_nonce($_GET['_wpnonce'], PLUGIN_PREFIX.'_purge_error_log')) {
			return;
		}

		// Not requesting purge? Bail.
		if (!isset($_GET['purge_errors']) || !$_GET['purge_errors']) {
			return;
		}

		// Overwrite log file with 0 bytes; set transient.
		if (!empty($this->error_log) && is_writable($this->error_log)) {
			if (file_put_contents($this->error_log, '') !== false) {
				set_transient(PLUGIN_PREFIX.'_purged', 1, 120);
			}
		}

		// Redirect.
		wp_safe_redirect(admin_url('tools.php?page='.PLUGIN_SHORT_SLUG));
		exit;

	}

	/**
	 * Update filter options.
	 *
	 * This method updates the plugin's settings made with the checkboxes at the
	 * top of the display; for filtering the displayed errors.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @return boolean
	 */
	public function update_display_options() {

		// Define the nonce name.
		$nonce_name = PLUGIN_PREFIX.'_nonce';

		// If nonce is missing, bail.
		if (empty($_POST[$nonce_name])) {
			return false;
		}

		// If nonce is suspect, bail.
		if (!wp_verify_nonce($_POST[$nonce_name], $nonce_name)) {
			return false;
		}

		// Date is a display option, not an error type; prepend it manually.
		$this->options['datetime'] = (isset($_POST[PLUGIN_PREFIX]['datetime'])) ? 1 : '';

		// Gather display options; ensure clean values.
		foreach (array_keys($this->get_error_types()) as $type) {
			$this->options[$type] = (isset($_POST[PLUGIN_PREFIX][$type])) ? 1 : '';
		}

		// More stack trace properties; mirrored from stack trace title setting.
		$this->options['stack_trace_step'] = (!empty($this->options['stack_trace_title'])) ? 1 : '';
		$this->options['stack_trace_origin'] = (!empty($this->options['stack_trace_title'])) ? 1 : '';

		// Sorting is a display option, not an error type; append it manually.
		$this->options['reverse_sort'] = (isset($_POST[PLUGIN_PREFIX]['reverse_sort'])) ? 1 : '';

		// Update options.
		update_option(PLUGIN_PREFIX, $this->options);

		// Redirect to ensure admin bar alerts show correct integer(s).
		wp_safe_redirect(admin_url('tools.php?page='.PLUGIN_SHORT_SLUG));
		exit;

	}

	/**
	 * Render success message.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 */
	public function markup_success_message() {

		// Assemble a dismissible message.
		$markup = '<div class="notice notice-success is-dismissible">';
		$markup .= '<p>'.esc_html__('Error log has been emptied.', 'codepotent-php-error-log-viewer').'</p>';
		$markup .= '</div>'."\n";

		// Delete the transient that triggered this message.
		delete_transient(PLUGIN_PREFIX.'_purged');

		// Return the markup string.
		return $markup;

	}

	/**
	 * Markup filter inputs.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @return string HTML form for filtering the errors displayed.
	 */
	public function markup_display_inputs() {

		// Form open.
		$markup = '<form id="'.PLUGIN_SLUG.'-filter" method="post" action="'.admin_url('/tools.php?page='.PLUGIN_SHORT_SLUG).'">';

		// Markup the nonce field.
		$markup .= wp_nonce_field(PLUGIN_PREFIX.'_nonce', PLUGIN_PREFIX.'_nonce', true, false);

		// Date/time input.
		$markup .= '<label>'.esc_html__('Date/Time', 'codepotent-php-error-log-viewer').' <input type="checkbox" name="'.PLUGIN_PREFIX.'[datetime]" value="1" '.checked($this->options['datetime'], 1, false).'></label>';

		// Divider.
		$markup .= '<span class="codepotent-php-error-log-viewer-option-divider"></span>';

		// Error type texts are translated/escaped here, not in the loop below.
		$error_types = $this->get_error_types();

		// Print the labels and inputs.
		foreach ($error_types as $type=>$text) {
			$total = !empty($this->errors[$type]) ? count($this->errors[$type]) : 0;
			if (strpos($type, 'stack_trace') !== 0) {
				$markup .= '<label>'.$text.' ('.number_format($total).') <input type="checkbox" name="'.PLUGIN_PREFIX.'['.$type.']" value="1" '.checked($this->options[$type], 1, false).'></label>';
			}
		}

		// Divider.
		$markup .= '<span class="codepotent-php-error-log-viewer-option-divider"></span>';

		// Sort input.
		$markup .= '<label>';
		$markup .= esc_html__('Show Stack Traces', 'codepotent-php-error-log-viewer');
		$markup .= ' <input type="checkbox" name="'.PLUGIN_PREFIX.'[stack_trace_title]" value="1" '.checked($this->options['stack_trace_title'], 1, false).'>';
		$markup .= '</label>';

		// Divider.
		$markup .= '<span class="codepotent-php-error-log-viewer-option-divider"></span>';

		// Sort input.
		$markup .= '<label>';
		$markup .= esc_html__('Reverse Sort', 'codepotent-php-error-log-viewer');
		$markup .= ' <input type="checkbox" name="'.PLUGIN_PREFIX.'[reverse_sort]" value="1" '.checked($this->options['reverse_sort'], 1, false).'>';
		$markup .= '</label>';

		// Markup the submit button.
		$markup .= '<input type="submit" class="button button-primary" name="submit" value="'.esc_html__('Apply Filters', 'codepotent-php-error-log-viewer').'">';

		// Close the form.
		$markup .= '</form>';

		// Return markup string.
		return $markup;

	}

	/**
	 * Markup jump links.
	 *
	 * Because new errors always appear at the bottom, if the error log has many
	 * entries, the user would have to scroll each time the page was loaded. The
	 * jump-links allow users to easily jump from the top to the bottom and back
	 * again without the need for endless scrolling. These links only display if
	 * there are enough entries showing onscreen.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @param string $where Set to "header" if not "footer".
	 *
	 * @return string Any generated markup.
	 */
	public function markup_jump_link($where) {

		// Initialization.
		$markup = '';

		// Not many errors currently displaying? Bail.
		if ($this->errors_displayed < 10) {
			return $markup;
		}

		// Container.
		$markup .= '<div class="alignleft">';

		// Markup the jump depending on whether it's for the header or footer.
		if ($where === 'header') {
			$markup .= '<a href="#nav-jump-bottom">'.esc_html__('Skip to bottom', 'codepotent-php-error-log-viewer').'</a>';
		} else {
			$markup .= '<a id="nav-jump-bottom" href="#nav-jump-top">'.esc_html__('Back to top', 'codepotent-php-error-log-viewer').'</a>';
		}

		// Container.
		$markup .= '</div>';

		// Return markup string.
		return $markup;

	}

	/**
	 * Markup action buttons.
	 *
	 * Generates markup for the buttons used to refresh and purge the error log.
	 * This is always used at the top of the display. If there are enough errors
	 * that the page begins to scroll, the buttons will also be placed below the
	 * list to convenience.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @return string HTML markup for refresh and purge buttons.
	 */
	public function markup_action_buttons() {

		// Open containers.
		$markup = '<div class="'.PLUGIN_SLUG.'-buttons">';
		$markup .= '<span class="alignright">';

		// Refresh button.
		$markup .= '<a href="'.admin_url('tools.php?page='.PLUGIN_SHORT_SLUG).'" class="button button-secondary">'.esc_html__('Refresh Error Log', 'codepotent-php-error-log-viewer').'</a>';

		// Purge button; if log is writeable.
		if (is_writable($this->error_log)) {
			$markup .= '<a href="#" id="'.PLUGIN_SLUG.'-confirm-purge" class="button button-secondary">'.esc_html__('Purge Error Log', 'codepotent-php-error-log-viewer').'</a>';
		}

		// Close containers.
		$markup .= '</span>';
		$markup .= '</div><!-- .'.PLUGIN_SLUG.'_buttons -->';

		// Return the string.
		return $markup;

	}

	/**
	 * Markup error log size.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @return string Text representation, ie, 123 bytes, 10.3 Kb, 1.2 Mb
	 */
	public function markup_filesize_indicator() {

		// Cast the log size.
		settype($this->filesize, 'int');

		// Setup default display text.
		$display_text = sprintf(
				esc_html__('%d bytes', 'codepotent-php-error-log-viewer'),
				$this->filesize
				);

		// Is error log greater than 1Mb? Change the text to suit.
		if ($this->filesize > 1000000) {
			$display_text = sprintf(
					esc_html__('%d Mb', 'codepotent-php-error-log-viewer'),
					round($this->filesize/1000000, 1)
					);
		}
		// Is error log greater than 1Kb? Change the text to suit.
		else if ($this->filesize > 1000) {
			$display_text = sprintf(
					esc_html__('%d Kb', 'codepotent-php-error-log-viewer'),
					round($this->filesize/1000, 1)
					);
		}

		// Markup filesize display.
		$markup = '<div class="'.PLUGIN_SLUG.'-filesize">';
		$markup .= '<strong>'.esc_html__('Log Size', 'codepotent-php-error-log-viewer').'</strong>: '.$display_text;
		$markup .= '</div>';

		// Return the string.
		return $markup;

	}

	/**
	 * Markup information legend.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @return string Markup for the legend.
	 */
	public function markup_legend() {

		// Error types.
		$types = $this->get_error_types();

		// Open container.
		$markup = '<div class="'.PLUGIN_SLUG.'-legend">';

		// Title.
		$markup .= '<h3 class="'.PLUGIN_SLUG.'-legend-title">'.esc_html__('Legend', 'codepotent-php-error-log-viewer').'</h3>';

		// Markup each legend item.
		foreach ($types as $type=>$text) {
			if ($type !== 'stack_trace_step' && $type !== 'stack_trace_origin') {
				$markup .= '<div class="'.PLUGIN_SLUG.'-legend-box item-php-'.str_replace('_', '-', $type).'">'.$text.'</div>';
			}
		}

		// Close container.
		$markup .= '</div> <!-- .'.PLUGIN_SLUG.'-legend -->';

		// Return the markup.
		return $markup;

	}

	/**
	 * Markup error rows.
	 *
	 * This method handles markup generation for the error entries.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_errors All errors as read from the log file.
	 * @param array $typed_errors[type][line] Line numbers keyed by error type.
	 *
	 * @return string|mixed
	 */
	public function markup_error_rows() {

		// Initialize the markup string.
		$markup = '';

		// Iterate over raw_errors array.
		foreach (array_keys($this->raw_errors) as $n) {

			// Get error type from its line position.
			$type = $this->error_map[$n];

			// Not currently displaying this type of error? Next!
			if (!$this->options[$type]) {
				continue;
			}

			/**
			 * Stack trace titles are padded to make sure they "touch" the error
			 * that produced them. If stack traces are displayed and errors have
			 * been supressed from display, this block ensures that the rows are
			 * separated appropriately.
			 */
			$style = '';
			if ($type === 'stack_trace_title' && !$this->options['error']) {
				$style = ' style="margin-top:13px;"';
			}

			// Mark up the error row.
			$markup .= '<p class="error-log-row php-'.str_replace('_', '-', $type).'"'.$style.'>';
			$markup .= $this->errors[$type][$n];
			$markup .= '</p>';

		}

		// Return the string.
		return $markup;

	}

	/**
	 * Reverse sort errors
	 *
	 * Reversing the display order of errors in the log is more complicated than
	 * it seems on the surface. It's the stack trace data that screws everything
	 * up – simply reversing the array means the stack traces are then contained
	 * in the array above their respective errors and things break down. To sort
	 * the entries in reverse order, the stack trace data must first be removed,
	 * saved aside in a temp variable, then the remaining (actual) errors sorted
	 * while preserving their line position values. From there, the stack traces
	 * are re-added back to the mix, in preserved order and having been keyed to
	 * the particular error line to which they apply with some creative keywork.
	 * Since those newly keyed items will be at the end of the array, (and would
	 * display at the end of the error list,) a final reverse sort is applied. I
	 * am only bothering to explain this here because when someone sees the code
	 * it took to achieve this, there is going to be some 'splaining to do. Hey,
	 * if you have a better solution, I'm all ears! :)
	 *
	 * @author John Alarcon
	 *
	 * @since 2.0.0
	 */
	public function reverse_sort_errors() {

		// Initialization.
		$stack_trace_parts = $actual_errors = [];

		// Key for reuniting stack trace data with parent errors after sort.
		$error_line_number = 0;

		// Iterate over the raw lines read in from the error log.
		foreach ($this->raw_errors as $n=>$error) {

			// Trim any split ends off the line.
			$error = trim($error);

			// Get the error's type.
			$type = $this->get_error_type($error);

			// If dealing with stack trace data, capture it; move on.
			if (strpos($type, 'stack_trace') === 0) {
				$stack_trace_parts[$error_line_number][$n] = $error;
				continue;
			}

			// Capture everyting else (ie, not stack trace data) as an error.
			$actual_errors[$n] = $error;

			// Update the key to ensure stack trace data stays in sync.
			$error_line_number = $n;

		}

		// Sort the now-stack-trace-free array; preserve keys.
		krsort($actual_errors, SORT_NUMERIC);

		// Iterate over the stack trace data that was captured.
		$i = 0;
		foreach ($stack_trace_parts as $error_line=>$errors) {
			// Rekey stack traces to fall under related errors in the array.
			foreach ($errors as $error) {
				$i += .05;
				$actual_errors[(string)($error_line-$i)] = $error;
			}
		}

		// Newly keyed items are at the bottom; resort, preserving keys.
		krsort($actual_errors, SORT_NUMERIC);

		// And set the whole affair back to the object.
		$this->raw_errors = $actual_errors;

	}

	/**
	 * Provide notice and possible solutions if error log not found.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 */
	public function markup_error_log_404() {

		// Core container.
		$markup = '<div class="wrap" id="'.PLUGIN_SLUG.'">';

		// Plugin container.
		$markup .= '<div id="'.PLUGIN_SLUG.'-not-found">';

		// Title.
		$markup .= '<h1>'.esc_html__('PHP Error Log', 'codepotent-php-error-log-viewer').'</h1>';

		// Description of issue.
		$markup .= '<div class="notice notice-error"><p>'.esc_html__('Your PHP error log could not be found.', 'codepotent-php-error-log-viewer').'</p></div>';

		// Probable solution.
		$markup .= '<h3>'.esc_html__('Possible Solution', 'codepotent-php-error-log-viewer').'</h3>';
		$markup .= '<p>';
		$markup .= sprintf(
				esc_html__('Open your %1$swp-config.php%2$s file and find the line that reads %1$sdefine(\'WP_DEBUG\', false);%2$s. Replace that single line with the following five (5) lines. Be sure to change the path to reflect the location of your PHP error log file. You can (and should) place your error log file outside your publicly accessible web directory.', 'codepotent-php-error-log-viewer'),
				'<code>',
				'</code>'
				);
		$markup .= '</p>';
		$markup .= '<p><textarea rows="5">$error_log_file = \'/path/to/your/php/error/log/file.log\';'."\n".'define(\'WP_DEBUG\', true);'."\n".'define(\'WP_DEBUG_DISPLAY\', false);'."\n".'define(\'WP_DEBUG_LOG\', $error_log_file);'."\n".'ini_set(\'error_log\', $error_log_file);</textarea></p>';

		// No dice? Maybe try .htaccess?
		$markup .= '<h3>'.esc_html__('Still not working?', 'codepotent-php-error-log-viewer').'</h3>';
		$markup .= '<p>¯\_(ツ)_/¯</p>';

		// Plugin container.
		$markup .= '</div><!-- #'.PLUGIN_SLUG.'-not-found -->';

		// Core container.
		$markup .= '</div><!-- .wrap -->';

		// Return the markup string.
		return $markup;

	}

	/**
	 * Render PHP errors.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 */
	public function render_php_error_log() {

		// Can't find error log? Describe a possible solution; return early.
		if (!$this->error_log) {
			echo $this->markup_error_log_404();
			return;
		}

		// Outer container.
		echo '<div class="wrap" id="'.PLUGIN_SLUG.'">';

		// Display success message if error log was just purged.
		if (get_transient(PLUGIN_PREFIX.'_purged')) {
			echo $this->markup_success_message();
		}

		// Print plugin title.
		echo '<h1 id="nav-jump-top">'.PLUGIN_NAME.'</h1>';

		// Print filter checkboxes.
		echo $this->markup_display_inputs($this->errors, $this->options);

		// Print a jump-link in the header.
		echo $this->markup_jump_link('header');

		// Print buttons for refresh and purge actions.
		echo $this->markup_action_buttons();

		// Print the filesize.
		echo $this->markup_filesize_indicator();

		// Filter before legend; for any explanatory text.
		echo apply_filters(PLUGIN_PREFIX.'_before_legend', '');

		// Print the legend.
		echo $this->markup_legend();

		// Filter after legend; for any explanatory text.
		echo apply_filters(PLUGIN_PREFIX.'_after_legend', '');

		// If error log is empty, go no further; close wrapper and return.
		if (empty($this->errors)) {
			echo '</div><!-- .wrap -->';
			return;
		}

		// Print the error rows.
		echo $this->markup_error_rows();

		// Another jump-link, if the display grows long.
		echo $this->markup_jump_link('footer');

		// Print buttons to refresh and purge errors; for long pages.
		if ($this->errors_displayed > 10) {
			echo $this->markup_action_buttons();
		}

		// That's a wrap – thanks, everyone!
		echo '</div><!-- .wrap -->';

	}

	/**
	 * Filter footer text.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @param string $text The original footer text.
	 *
	 * @return void|string Branded footer text if in this plugin's admin.
	 */
	public function filter_footer_text($text) {

		// Are we on this post type's screen? If so, change the footer text.
		if (strpos(get_current_screen()->base, PLUGIN_SHORT_SLUG)) {
			$text = '<span id="footer-thankyou" style="vertical-align:text-bottom;"><a href="'.VENDOR_PLUGIN_URL.'/" title="'.PLUGIN_DESCRIPTION.'">'.PLUGIN_NAME.'</a> '.PLUGIN_VERSION.' &#8211; by <a href="'.VENDOR_HOME_URL.'" title="'.VENDOR_TAGLINE.'"><img src="'.VENDOR_WORDMARK_URL.'" alt="'.VENDOR_TAGLINE.'" style="height:1.02em;vertical-align:sub !important;"></a></span>';
		}

		// Return the string.
		return $text;

	}

	/**
	 * Plugin activation.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 */
	public function activate_plugin() {

		// No permission to activate plugins? Bail.
		if (!current_user_can('activate_plugins')) {
			return;
		}

		// Initialize the options array.
		$options = [];

		// Make sure the datetime variable is set.
		$options['datetime'] = 1;

		// Iterate over error types; ensure they, too, are set.
		foreach (array_keys($this->get_error_types()) as $type) {
			$options[$type] = 1;
		}

		// Set the sort order.
		$options['reverse_sort'] = 0;

		// Update with defaults.
		update_option(PLUGIN_PREFIX, $options);

	}

	/**
	 * Plugin deactivation.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 */
	public function deactivate_plugin() {

		// No permission to activate plugins? None to deactivate either. Bail.
		if (!current_user_can('activate_plugins')) {
			return;
		}

		// Not that there was anything to do here anyway. :)

	}

	/**
	 * Plugin deletion.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 */
	public static function uninstall_plugin() {

		// No permission to delete plugins? Bail.
		if (!current_user_can('delete_plugins')) {
			return;
		}

		// Delete options related to the plugin.
		delete_option(PLUGIN_PREFIX);

	}

	/**
	 * Admin bar link.
	 *
	 * Add a link to the admin bar that leads to the PHP error log; just a minor
	 * convenience.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.1.0
	 *
	 * @deprecated 2.0.0 Replaced with $this->register_admin_bar() method.
	 *
	 */
	public function adminbar_quicklink() {

		// Flag as deprecated before proceeding; recommend an alternative.
		_doing_it_wrong(
			'<code>'.__METHOD__.'()</code>',
			sprintf(
				esc_html__('You can use the %s method of this object to register the admin bar menu item.', 'codepotent-php-error-log-viewer'),
				'<code>'.__NAMESPACE__.'::register_admin_bar()</code>'),
			'2.0.0'
			);

		// Bring admin bar into scope.
		global $wp_admin_bar;

		// Add the item.
		$wp_admin_bar->add_menu([
			'parent' => false,
			'id'     => PLUGIN_PREFIX.'_adminbar_quicklink',
			'title'  => esc_html__('PHP Errors', 'codepotent-php-error-log-viewer'),
				'href'   => admin_url('tools.php?page='.PLUGIN_SHORT_SLUG),
			'meta'   => [
				'title' => sprintf(
					esc_html__('PHP %s', 'codepotent-php-error-log-viewer'),
					phpversion()
				)
			],
		]);

	}

	/**
	 * Enqueue CSS.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @deprecated 2.0.0 Replaced with enqueue_global_styles() method.
	 */
	public function enqueue_admin_styles() {

		// Flag as deprecated before proceeding; recommend an alternative.
		_doing_it_wrong(
			'<code>'.__METHOD__.'()</code>',
			sprintf(
				esc_html__('You can use the %s method of this object to enqueue the needed stylesheet.', 'codepotent-php-error-log-viewer'),
				'<code>'.__NAMESPACE__.'::enqueue_global_styles()</code>'),
			'2.0.0'
			);

		// Not in a view related to this plugin? Bail.
		if (!strpos(get_current_screen()->base, PLUGIN_SHORT_SLUG)) {
			return;
		}

		// Enqueue the styles.
		wp_enqueue_style(PLUGIN_SLUG.'-admin', URL_STYLES.'/admin-global.css');

	}

	/**
	 * Get errors from log.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @deprecated 2.0.0
	 *
	 * @param string $error_log Path to the PHP error log.
	 *
	 * @return array Array of errors from the log.
	 */
	private function get_errors_raw($error_log) {

		// Flag as deprecated before proceeding; recommend an alternative.
		_doing_it_wrong(
			'<code>'.__METHOD__.'()</code>',
			sprintf(
				esc_html__('You can use the %s property of the object, which now holds an array of lines read from the error log without any processing.', 'codepotent-php-error-log-viewer'),
				'<code>$this->raw_errors</code>'),
			'2.0.0'
			);

		// Initialize the return variable.
		$raw_errors = [];

		// Error log doesn't exist? Bail.
		if (!file_exists($error_log)) {
			return $raw_errors;
		}

		// Get the filesize.
		$raw_errors['size'] = filesize($error_log);

		// Get the error log's entries as an array.
		$raw_errors['lines'] = file($error_log);

		// Retrn the size/lines array.
		return $raw_errors;

	}

	/**
	 * Process errors into expected arrays.
	 *
	 * This method processes the raw errors array into various other arrays that
	 * are used to determine the error type of each entry.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @deprecated 2.0.0 Replaced with the $this->errors property.
	 *
	 * @param string $error_log Path to PHP error log.
	 * @param array $options Array of display options.
	 *
	 * @return array[]
	 */
	private function get_errors_processed($error_log, $options) {

		// Flag as deprecated before proceeding; recommend an alternative.
		_doing_it_wrong(
			'<code>'.__METHOD__.'()</code>',
			sprintf(
				esc_html__('You can use the %s property of the object, which now holds the processed error arrays.', 'codepotent-php-error-log-viewer'),
				'<code>$this->errors</code>'),
			'2.0.0'
			);

		// Initialize defaults.
		$processed_errors = [];

		// Get errors.
		$raw_errors = $this->get_errors_raw($error_log);
		if (!isset($raw_errors['size']) || !isset($raw_errors['lines'])) {
			return $processed_errors;
		}

		// Grab the log size.
		$processed_errors['size'] = $raw_errors['size'];

		// Grab the raw unordered lines.
		$processed_errors['lines'] = $raw_errors['lines'];

		// Ensure defaults are set to prevent PHP warnings.
		$processed_errors['errors'] = $this->get_error_defaults();

		// Iterate over errors.
		foreach ($raw_errors['lines'] as $n=>$error) {

			$error = trim($error);
			// Set a key depending on the type of error.
			if (strpos($error, 'PHP Deprecated')) {
				$error_key = 'deprecated';
			} else if (strpos($error, 'PHP Notice')) {
				$error_key = 'notice';
			} else if (substr($error, 0, 11) === 'Stack trace' || strpos($error, 'Stack trace')) {
				$error_key = 'stack_trace_title';
			} else if (substr($error, 0, 1) === '#' || strpos($error, 'stderr: #')) {
				$error_key = 'stack_trace_step';
			} else if (substr($error, 0, 9) === 'thrown in' || strpos($error, 'thrown in')) {
				$error_key = 'stack_trace_origin';
			} else if (strpos($error, 'error:') || strpos($error, 'stderr:') || strpos($error, '[error]')) {
				$error_key = 'error';
			} else if (strpos($error, 'PHP Warning') || strpos($error, '[warn]')) {
				$error_key = 'warning';
			} else {
				$error_key = 'other';
			}

			// Map the URL to a type in all cases.
			$processed_errors['mapped'][$n] = $error_key;

			// Capture only those errors that will be displayed.
			if ($options[$error_key]) {
				$processed_errors['errors'][$error_key][$n] = $error;
			}

			// Bold (most) error titles.
			$processed_errors['errors'][$error_key][$n] =
			preg_replace(
					'|(PHP )([A-Za-z]){1,} *([A-Za-z ]){1,}|',
					'<strong>${0}</strong>',
					$error
					);

			// Strip: mod_fcgid: stderr:
			$processed_errors['errors'][$error_key][$n] = str_replace('mod_fcgid: stderr: ', '', $processed_errors['errors'][$error_key][$n]);

			// Strip datetime, else wrap it for styling purposes.
			if (empty($options['datetime'])) {
				$processed_errors['errors'][$error_key][$n] = preg_replace('|([){1}([A-Za-z0-9_ -:\/]){1,}(]){1}|', '', $processed_errors['errors'][$error_key][$n]);
			} else {
				$processed_errors['errors'][$error_key][$n] = preg_replace('|([){1}([A-Za-z0-9_ -:\/]){1,}(]){1}|', '<span class="'.PLUGIN_SLUG.'-datetime">${0}</span>', $processed_errors['errors'][$error_key][$n]);
			}

		}

		// Return the processed errors.
		return $processed_errors;

	}

	/**
	 * Count displayed rows.
	 *
	 * This method counts the errors that are currently being displayed. This is
	 * used to show the refresh/purge buttons at the bottom when the display has
	 * quite a few errors showing.
	 *
	 * @author John Alarcon
	 *
	 * @since 1.0.0
	 *
	 * @deprecated 2.0.0 Use $this->errors_displayed instead.
	 *
	 * @param array $errors MulLine numbers keyed by error type.
	 * @param array $options Which error types to display.
	 *
	 * @return int Total number of rows to be displayed.
	 */
	public function count_displayed_items($errors, $options) {

		// Flag as deprecated before proceeding; recommend an alternative.
		_doing_it_wrong(
			'<code>'.__METHOD__.'()</code>',
			sprintf(
				esc_html__('You can use the %s property of the object, which now holds the number of displayed errors.', 'codepotent-php-error-log-viewer'),
				'<code>$this->errors_displayed</code>'),
			'2.0.0'
			);

		// Initialization.
		$displayed_errors = 0;

		// No errors mapped? Bail.
		if (empty($errors['mapped'])) {
			return $displayed_errors;
		}

		// Iterate over error type map.
		foreach ($errors['mapped'] as $error_type) {

			// Not displaying this type of error? Don't count it.
			if (!$options[$error_type]) {
				continue;
			}

			// Count errors...
			if ($error_type === 'stack_trace_title') {
				// ...only count title entries for stack traces...
				if (!$options['error']) {
					$displayed_errors++;
				}
			} else if ($error_type === 'stack_trace_step') {
				// ...skip counting stack trace steps...
				continue;
			} else if ($error_type === 'stack_trace_origin') {
				// ...skip counting stack trace origins...
				continue;
			} else {
				// Count the error.
				$displayed_errors++;
			}

		}

		// Total displayed error items.
		return $displayed_errors;

	}

}

// Make awesome all the errors.
new PhpErrorLogViewer;