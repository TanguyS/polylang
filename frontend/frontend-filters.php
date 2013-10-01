<?php

/*
 * filters content by language on frontend
 *
 * @since 1.2
 */
class PLL_Frontend_Filters extends PLL_Filters_Base {
	public $curlang;

	/*
	 * constructor: setups filters and actions
	 *
	 * @since 1.2
	 */
	public function __construct(&$links_model, &$curlang) {
		parent::__construct($links_model);

		$this->curlang = &$curlang;

		// filters the WordPress locale
		add_filter('locale', array(&$this, 'get_locale'));

		// backward compatibility WP < 3.4, modifies the language information in rss feed
		add_filter('option_rss_language', array(&$this, 'option_rss_language'));

		// translates page for posts and page on front
		add_filter('option_page_for_posts', array(&$this, 'translate_page'));
		add_filter('option_page_on_front', array(&$this, 'translate_page'));

		// filter sticky posts by current language
		add_filter('option_sticky_posts', array(&$this, 'option_sticky_posts'));

		// filters posts by language
		add_filter('pre_get_posts', array(&$this, 'pre_get_posts'), 5); // filters posts according to the language

		// filters categories and post tags by language
		add_filter('terms_clauses', array(&$this, 'terms_clauses'), 10, 3);

		// filters the pages according to the current language in wp_list_pages
		add_filter('wp_list_pages_excludes', array(&$this, 'wp_list_pages_excludes'));

		// filters the comments according to the current language
		add_filter('comments_clauses', array(&$this, 'comments_clauses'), 10, 2);

		// rewrites archives, next and previous post links to filter them by language
		foreach (array('getarchives', 'get_previous_post', 'get_next_post') as $filter)
			foreach (array('_join', '_where') as $clause)
				add_filter($filter.$clause, array(&$this, 'posts'.$clause));

		// filters the widgets according to the current language
		add_filter('widget_display_callback', array(&$this, 'widget_display_callback'), 10, 3);

		// strings translation (must be applied before WordPress applies its default formatting filters)
		foreach (array('widget_title', 'option_blogname', 'option_blogdescription', 'option_date_format', 'option_time_format') as $filter)
			add_filter($filter, 'pll__', 1);

		// translates biography
		add_filter('get_user_metadata', array(&$this,'get_user_metadata'), 10, 4);

		// set posts and terms language when created from frontend (ex with P2 theme)
		add_action('save_post', array(&$this, 'save_post'), 200, 2);
		add_action('create_term', array(&$this, 'save_term'), 10, 3);
		add_action('edit_term', array(&$this, 'save_term'), 10, 3);
	}

	/*
	 * returns the locale based on current language
	 *
	 * @since 0.1
	 */
	public function get_locale($locale) {
		return $this->curlang->locale;
	}

	/*
	 * modifies the language information in rss feed
	 * backward compatibility WP < 3.4
	 *
	 * @since 0.8
	 */
	public function option_rss_language($value) {
		return get_bloginfo_rss('language');
	}

	/*
	 * translates page for posts and page on front
	 * backward compatibility WP < 3.4
	 *
	 * @since 0.8
	 */
	// FIXME comes too late when language is set from content
	public function translate_page($v) {
		static $posts = array(); // the fonction may be often called so let's store the result

		// returns the current page if there is no translation to avoid ugly notices
		return isset($this->curlang) && $v && (isset($posts[$v]) || $posts[$v] = $this->model->get_post($v, $this->curlang)) ? $posts[$v] : $v;
	}

	/*
	 * filters sticky posts by current language
	 *
	 * @since 0.8
	 */
	public function option_sticky_posts($posts) {
		if ($this->curlang && !empty($posts)) {
			update_object_term_cache($posts, 'post'); // to avoid queries in foreach
			foreach ($posts as $key=>$post_id) {
				if ($this->model->get_post_language($post_id)->term_id != $this->curlang->term_id)
					unset($posts[$key]);
			}
		}
		return $posts;
	}

	/*
	 * filters posts according to the language
	 * Note: pll_is_translated_post_type is correctly defined only once the action 'wp_loaded' has been fired
	 *
	 * @since 0.1
	 */
	public function pre_get_posts($query) {
		$qv = $query->query_vars;

		// allow filtering recent posts and secondary queries by the current language
		// take care not to break queries for non visible post types such as nav_menu_items
		// do not filter if lang is set to an empty value
		if (!isset($qv['lang']) && (empty($qv['post_type']) || pll_is_translated_post_type($qv['post_type'])))
			$query->set('lang', $this->curlang->slug);
	}


	/*
	 * filters categories and post tags by language when needed
	 *
	 * @since 0.2
	 */
	public function terms_clauses($clauses, $taxonomies, $args) {
		// does nothing except on taxonomies which are filterable
		if (!array_intersect($taxonomies, $this->model->taxonomies))
			return $clauses;

		// adds our clauses to filter by language
		return $this->model->terms_clauses($clauses, isset($args['lang']) ? $args['lang'] : $this->curlang);
	}

	/*
	 * excludes pages which are not in the current language for wp_list_pages
	 * useful for the pages widget
	 *
	 * @since 0.4
	 */
	public function wp_list_pages_excludes($pages) {
		return array_merge($pages, $this->exclude_pages($this->curlang));
	}

	/*
	 * filters the comments according to the current language mainly for the recent comments widget
	 *
	 * @since 0.2
	 */
	public function comments_clauses($clauses, $query) {
		return $this->model->comments_clauses($clauses, isset($query->query_vars['lang']) ? $query->query_vars['lang'] : $this->curlang);
	}

	/*
	 * modifies the sql request for wp_get_archives an get_adjacent_post to filter by the current language
	 *
	 * @since 0.1
	 */
	public function posts_join($sql) {
		return $sql . $this->model->join_clause('post');
	}

	/*
	 * modifies the sql request for wp_get_archives and get_adjacent_post to filter by the current language
	 *
	 * @since 0.1
	 */
	public function posts_where($sql) {
		preg_match("#post_type = '([^']+)'#", $sql, $matches);	// find the queried post type
		return !empty($matches[1]) && in_array($matches[1], $this->model->post_types) ? $sql . $this->model->where_clause($this->curlang, 'post') : $sql;
	}

	/*
	 * filters the widgets according to the current language
	 * don't display if a language filter is set and this is not the current one
	 *
	 * @since 0.3
	 */
	public function widget_display_callback($instance, $widget, $args) {
		return !empty($this->options['widgets'][$widget->id]) && $this->options['widgets'][$widget->id] != $this->curlang->slug ? false : $instance;
	}

	/*
	 * translates biography
	 *
	 * @since 0.9
	 */
	public function get_user_metadata($null, $id, $meta_key, $single) {
		return $meta_key == 'description' ? get_user_meta($id, 'description_'.$this->curlang->slug, true) : $null;
	}

	/*
	 * called when a post (or page) is saved, published or updated
	 * does nothing except on post types which are filterable
	 *
	 * @since 1.1
	 */
	public function save_post($post_id, $post) {
		if (in_array($post->post_type, $this->model->post_types)) {
			if (isset($_REQUEST['lang']))
				$this->model->set_post_language($post_id, $_REQUEST['lang']);

			elseif ($this->model->get_post_language($post_id))
				{}

			elseif (($parent_id = wp_get_post_parent_id($post_id)) && $parent_lang = $this->model->get_post_language($parent_id))
				$this->model->set_post_language($post_id, $parent_lang);

			else
				$this->model->set_post_language($post_id, $this->curlang);
		}
	}

	/*
	 * called when a category or post tag is created or edited
	 * does nothing except on taxonomies which are filterable
	 *
	 * @since 1.1
	 */
	public function save_term($term_id, $tt_id, $taxonomy) {
		if (in_array($taxonomy, $this->model->taxonomies)) {
			if (isset($_REQUEST['lang']))
				$this->model->set_term_language($term_id, $_REQUEST['lang']);

			elseif ($this->model->get_term_language($term_id))
				{}

			elseif (($term = get_term($term_id, $taxonomy)) && !empty($term->parent) && $parent_lang = $this->model->get_term_language($term->parent))
				$this->model->set_term_language($term_id, $parent_lang);

			else
				$this->model->set_term_language($term_id, $this->curlang);
		}
	}
}
