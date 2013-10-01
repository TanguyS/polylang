<?php

/*
 * it is best practice that plugins do nothing before plugins_loaded is fired
 * so it is what Polylang intends to do
 * but some plugins load their text domain as soon as loaded, thus before plugins_loaded is fired
 * this class differs text domain loading until the language is defined
 * either in a plugins_loaded action or in a wp action (when the language is set from content on frontend)
 *
 * @since 1.2
 */
class PLL_OLT_Manager {
	protected $default_locale;
	protected $list_textdomains = array(); // all text domains
	protected $labels = array(); // post types and taxonomies labels to translate

	/*
	 * constructor: setups relevant filters
	 *
	 * @since 1.2
	 */
	public function __construct() {
		// saves the default locale before we start any language manipulation
		$this->default_locale = get_locale();

		// filters for text domain management
		add_filter('override_load_textdomain', array(&$this, 'mofile'), 10, 3);
		add_filter('gettext', array(&$this, 'gettext'), 10, 3);
		add_filter('gettext_with_context', array(&$this, 'gettext_with_context'), 10, 4);

		// loads text domains
		add_action('pll_language_defined', array(&$this, 'load_textdomains'), 2); // after PLL_Frontend::pll_language_defined

		// allows Polylang to be the first plugin loaded ;-)
		add_filter('pre_update_option_active_plugins', array(&$this, 'make_polylang_first'));
		add_filter('pre_update_option_active_sitewide_plugins', array(&$this, 'make_polylang_first'));
	}

	/*
	 * loads text domains
	 *
	 * @since 0.1
	 */
	public function load_textdomains() {
		// our override_load_textdomain filter has done its job. let's remove it before calling load_textdomain
		remove_filter('override_load_textdomain', array(&$this, 'mofile'));
		remove_filter('gettext', array(&$this, 'gettext'), 10, 3);
		remove_filter('gettext_with_context', array(&$this, 'gettext_with_context'), 10, 4);
		$new_locale = get_locale();

		// now we can load all overriden text domains with the right language
		foreach ($this->list_textdomains as $textdomain) {
			if (!load_textdomain($textdomain['domain'], str_replace("{$this->default_locale}.mo", "$new_locale.mo", $textdomain['mo'])))
				// since WP 3.5 themes may store languages files in /wp-content/languages/themes
				if (!load_textdomain($textdomain['domain'], WP_LANG_DIR . "/themes/{$textdomain['domain']}-$new_locale.mo"))
					// since WP 3.7 plugins may store languages files in /wp-content/languages/plugins
					load_textdomain($textdomain['domain'], WP_LANG_DIR . "/plugins/{$textdomain['domain']}-$new_locale.mo");
		}

		// translate labels of post types and taxonomies
		foreach ($GLOBALS['wp_taxonomies'] as $tax)
			$this->translate_labels($tax);
		foreach ($GLOBALS['wp_post_types'] as $pt)
			$this->translate_labels($pt);

		// act only if the language has not been set early (before default textdomain loading and $wp_locale creation
		if (did_action('after_setup_theme')) {
			// reinitializes wp_locale for weekdays and months
			unset($GLOBALS['wp_locale']);
			$GLOBALS['wp_locale'] = new WP_Locale();
		}

		// free memory
		unset($this->default_locale, $this->list_textdomains, $this->labels);
	}

	/*
	 * saves all text domains in a table for later usage
	 *
	 * @since 0.1
	 */
	public function mofile($bool, $domain, $mofile) {
		$this->list_textdomains[] = array ('mo' => $mofile, 'domain' => $domain);
		return true; // prevents WP loading text domains as we will load them all later
	}

	/*
	 * saves post types and taxonomies labels for a later usage
	 *
	 * @since 0.9
	 */
	public function gettext($translation, $text, $domain) {
		$this->labels[$text] =  array('domain' => $domain);
		return $translation;
	}

	/*
	 * saves post types and taxonomies labels for a later usage
	 *
	 * @since 0.9
	 */
	public function gettext_with_context($translation, $text, $context, $domain) {
		$this->labels[$text] =  array('domain' => $domain, 'context' => $context);
		return $translation;
	}

	/*
	 * translates post types and taxonomies labels once the language is known
	 *
	 * @since 0.9
	 */
	public function translate_labels($type) {
		foreach($type->labels as $key=>$label)
			if (is_string($label) && isset($this->labels[$label]))
				$type->labels->$key = isset($this->labels[$label]['context']) ?
					_x($label, $this->labels[$label]['context'], $this->labels[$label]['domain']) :
					__($label, $this->labels[$label]['domain']);
	}

	/*
	 * allows Polylang to be the first plugin loaded ;-)
	 *
	 * @since 1.2
	 */
	public function make_polylang_first($plugins) {
		if ($key = array_search($plugin = basename(POLYLANG_DIR) . '/polylang.php', $plugins)) {
			unset($plugins[$key]);
			array_unshift($plugins, $plugin);
		}
		return $plugins;
	}
}
