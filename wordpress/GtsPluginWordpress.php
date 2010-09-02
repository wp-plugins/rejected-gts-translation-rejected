<?php

/*
 * Copyright (c) 2010, Localization Technologies (LT) LLC
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *     * Neither the name of Localization Technologies (LT) LLC nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL LOCALIZATION TECHNOLOGIES (LT) LLC BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

require_once("GtsLinkRewriter.php");


class GtsPluginWordpress extends GtsPlugin {


    static $TRANSLATE_OPTIONS = array(
        'blogname',
        'blogdescription',
    );


    var $wpdb;
    var $skip_config_check;

    var $link_rewriter;
    var $theme_language;


    function __construct() {
        parent::__construct();

        global $wpdb, $wp_rewrite;
        $this->wpdb = $wpdb;

        $this->link_rewriter = new GtsLinkRewriter();

        // HACK ALERT:  the problem we're running into here is that the theme gets selected
        // before the query is parsed.  therefore, we have to pull out the language pre-emptively.
        // for reasons not completely understood at this time, the term substitution gets borked
        // if we set the language variable, so we have a bad fake variable set here.  hack hack hack!
        //
        // note that we use get_option b/c wp_rewrite hasn't initialized yet, and we won't
        // be able to switch off values in there.
        if( get_option( 'permalink_structure') ) {
            if( preg_match('/\/language\/([a-z]{2})\//', $_SERVER['REQUEST_URI'], $matches)) {
                $this->theme_language = $matches[1];
            }
            if( $this->target_hostname ) {
                $this->theme_language = $this->language;
            }
        }
        else {
            $this->theme_language = $_GET['language'];
        }
    }


    function update_language_from_wp_query( $wp_query ) {
        if(!$this->language) {
            $this->language = $wp_query->query_vars[GtsLinkRewriter::$LANG_PARAM];
        }
    }
    

    function activate_plugin() {
        $this->link_rewriter->flush_rewrite_rules();

        if( !is_dir( GTS_THEME_DIR ) ) {
            if( $this->is_plugin_directory_writable() ) {
                mkdir( GTS_THEME_DIR );
            }
        }
    }

    function deactivate_plugin() {
        $this->link_rewriter->flush_rewrite_rules();
    }


    public static function uninstall_gts_plugin() {

        if( GTS_DEBUG_MODE || WP_UNINSTALL_PLUGIN ) {

            global $wpdb;

            delete_option( GTS_OPTION_NAME );
            delete_option( GTS_THEME_OPTION_NAME );
            delete_option( GTS_AUTHORIZATION_OPTION_NAME );
            delete_option( GTS_DB_INITIALIZED_OPTION_NAME );

            foreach ( GtsDbSchema::$gts_db_schema as $table ) {
                $matches = array();
                preg_match( '/create\s+table\s+(\S+)/i', $table, $matches );
                $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $matches[1] );
            }
        }
    }

    
    function register_plugin_hooks() {

        register_activation_hook( __FILE__, array($this, 'activate_plugin') );
        register_deactivation_hook( __FILE__, array($this, 'deactivate_plugin') );

        if(is_admin()) {
            $this->register_admin_filters();
            $this->register_admin_actions();
        }

        $this->register_filters();
        $this->register_actions();

        $this->link_rewriter->register_plugin_hooks();
    }


    function register_admin_actions() {

        add_action( 'admin_init', array($this, 'register_settings'));
        add_action( 'admin_menu', array($this, 'create_admin_menu'));

        add_action( 'publish_post', array($this, 'translate_post_id'), 1 );
        add_action( 'publish_page', array($this, 'translate_post_id'), 1 );

        // these have to run after the term cache is cleared.
        add_action( 'created_term', array($this, 'translate_term_id'), 1, 3 );
        add_action( 'edited_term', array($this, 'translate_term_id'), 1, 3 );


        if (!$this->config->plugin_initialized && GTS_MENU_NAME != $_GET['page'] ) {
            $this->send_info_notification(__('GTS is almost ready to translate your blog'), sprintf(__('Please visit the <a href="admin.php?page=%1$s">configuration page</a> to get started.'), GTS_MENU_NAME));
        }

        $save_config |= $this->send_notifications( $this->config->info_messages, 'info' );
        $save_config |= $this->send_notifications( $this->config->error_messages, 'error' );

        if($save_config) {
            $this->save_config();
        }

        foreach ( GtsPluginWordpress::$TRANSLATE_OPTIONS as $option ) {
            add_action( "add_option_$option", array( $this, 'translate_option_on_add', 1, 2 ) );
            add_action( "update_option_$option", array( $this, "translate_option_on_update_$option"), 1, 2 );
        }


        add_action( 'wp_ajax_gts_delete_translated_post', array($this, 'delete_translated_blog_post'));

        if ( GTS_DEBUG_MODE ) {
            add_action( 'wp_ajax_gts_kill_blog', array( get_class( $this ), 'uninstall_gts_plugin') );
        }
    }


    function ensure_db_current() {

        if ( !get_option( GTS_DB_INITIALIZED_OPTION_NAME ) || $this->config->plugin_version != GTS_PLUGIN_VERSION ) {

            global $wpdb;

            foreach ( GtsDbSchema::$gts_db_schema as $table ) {
                $table = preg_replace( '/(create\s+table\s+)(\S+)/i', '\1' . $wpdb->prefix . '\2', $table, 1 );
                $this->create_db_table( $table );
            }

            $this->config->plugin_version = GTS_PLUGIN_VERSION;
            update_option( GTS_DB_INITIALIZED_OPTION_NAME, true );
        }
    }


    function translate_option_on_add( $name, $value ) {
        $this->translate_named_option( $name, $value );
    }

    function translate_option_on_update_blogname( $old, $new) {
        $this->translate_named_option( 'blogname', $new );
    }

    function translate_option_on_update_blogdescription( $old, $new) {
        $this->translate_named_option( 'blogdescription', $new );
    }


    /**
     * handles the fact that certain WP options are html-escaped (including the quote marks) in the DB.
     * if we don't unescape them before passing them on to the renite API, then the translation gets messy.
     * @param  $name
     * @param  $value
     * @return void
     */
    function translate_named_option( $name, $value ) {

        switch($name) {
            case 'blogname':
            case 'blogdescription':
                $value = wp_specialchars_decode( $value, ENT_QUOTES );
                break;
        }

        return parent::translate_named_option( $name, $value );
    }



    function filter_autogenerated_themes( $theme ) {
        return !preg_match( '/^gts_autogenerated (.*?)\.[a-z]{2}$/', $theme['Name'] );
    }


    function register_admin_filters() {
    }


    function register_actions() {
        
        add_action( 'parse_request' , array($this, 'update_language_from_wp_query') );

        add_action( 'widgets_init', create_function('', 'return register_widget("GTS_LanguageSelectWidget");') );
        
        add_action( 'the_posts' , array($this, 'substitute_translated_posts'), 1 );

        add_action( 'get_term', array($this, 'substitute_translated_term'), 1 );
        add_action( 'get_terms', array($this, 'substitute_translated_terms'), 1 );

        add_action( 'wp_get_object_terms', array($this, 'substitute_translated_terms'), 1 );


        // only register our theme directories when we're not in admin view.  otherwise, it will
        // clutter up the view.
        //
        // theme translation requires WP 2.9 so that we can keep our thenes out of the main
        // wp-content directory.
        if( function_exists( 'register_theme_directory') && $this->config->use_translated_theme ) {
            register_theme_directory( GTS_THEME_DIR );
        }
    }


    function register_filters() {

        add_filter( 'the_title', array($this, 'get_translated_title'), 1, 2 );
        add_filter( 'page_title', array($this, 'get_translated_title'), 1, 2 );

        add_filter( 'bloginfo', array($this, 'filter_translated_bloginfo'), 1, 2 );

        add_filter( 'template', array($this, 'substitute_translated_template'), 1 );
        add_filter( 'stylesheet', array($this, 'substitute_translated_stylesheet'), 1 );

        add_filter( 'get_pages', array($this, 'substitute_translated_posts'), 1 );

        add_filter( 'the_date', array($this, 'localize_date_string' ), 1 );
        add_filter( 'the_time', array($this, 'localize_date_string' ), 1 );

        add_filter( 'option_home', array( $this, 'replace_hostname_if_available' ), 1 );
        add_filter( 'option_siteurl', array( $this, 'replace_hostname_if_available' ), 1 );
    }

    function replace_hostname_if_available( $url ) {

        if ( $this->language ) {

            $target_hostname = $this->config->target_hostnames[$this->language];
            if ( $target_hostname ) {

                $parts = parse_url( $url );
                if( $parts ) {
                    return $parts['scheme'] . '://' . $target_hostname . $parts['path'] .
                            ( $parts['query'] ? '?' . $parts['query'] : '') .
                            ( $parts['fragment'] ? '#' . $parts['fragment'] : '')
                            ;
                }
            }
        }
        
        return $url;
    }


    function load_config() {

        $config = new GtsConfig();
        $config_class = new ReflectionClass( get_class( $config ) );

        $config_option = get_option( GTS_OPTION_NAME );

        if($config_option) {
            foreach( $config_option as $key => $value ) {
                if( $config_class->hasProperty($key) ) {
                    $config_class->getProperty($key)->setValue( $config , $value );
                }
            }
        }

        return $config;
    }


    function save_config() {

        $config_array = array();
        $config_class = new ReflectionClass( get_class( $this->config ) );

        foreach ( $config_class->getProperties() as $property ) {
            $property_value = $property->getValue( $this->config );
            if ( $property_value ) {
                $config_array[$property->getName()] = $property_value;
            }
        }

        if( count( $config_array ) > 0 ) {
            $this->skip_config_check = true;
            update_option( GTS_OPTION_NAME , $config_array );
            $this->skip_config_check = false;
        }
        else {
            delete_option( GTS_OPTION_NAME );
        }
    }


    function create_db_table( $sql ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    function get_blog_post( $id ) {
        return get_post($id);
    }

    function get_blog_post_terms( $id ) {
        return array_merge( wp_get_post_tags($id) , wp_get_post_categories($id, array( "fields" => "all" )) );
    }

    function get_blog_term( $id, $taxonomy ) {
        return get_term( $id, $taxonomy );
    }


    function get_translated_named_option( $id, $language ) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM " . $this->wpdb->prefix . "gts_translated_options WHERE local_id = %s AND language = %s",
                $id, $language
            )
        );
    }


    function save_translated_named_option( $translated_option ) {

        $columns = array(
            "foreign_id" => $this->get_attribute_value( $translated_option, 'id' ),
            "name" => $translated_option->name,
            "value" => $translated_option->value,
        );

        $ids = array(
            "local_id" => $translated_option->remoteId,
            "language" => $translated_option->language,
        );

        $this->wpdb_upsert($this->wpdb->prefix ."gts_translated_options", $columns, $ids);
    }



    function get_translated_blog_post( $id, $language ) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM " . $this->wpdb->prefix . "gts_translated_posts WHERE local_id = %s AND language = %s",
                $id, $language
            )
        );
    }


    function get_translated_blog_post_by_slug( $slug, $language ) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM " . $this->wpdb->prefix . "gts_translated_posts WHERE post_slug = %s AND language = %s",
                $slug, $language
            )
        );
    }

    function get_translated_blog_post_metadata( $lang_code ) {
        return $this->wpdb->get_results(
            'SELECT id, local_id, foreign_id, post_title, post_slug, language, modified_time ' .
            'FROM ' . $this->wpdb->prefix . 'gts_translated_posts ' .
            'WHERE language = \'' . $lang_code . '\' ' .
            'ORDER BY modified_time DESC'
        );
    }

    function save_translated_blog_post( $translated_post ) {

        $translated_post->slug = sanitize_title( $translated_post->slug );

        $columns = array(
            "foreign_id" => $this->get_attribute_value( $translated_post, 'id' ),
            "post_title" => $translated_post->title,
            "post_excerpt" => $translated_post->excerpt,
            "post_body" => $translated_post->body,
            "post_slug" => $translated_post->slug,
        );

        $ids = array(
            "local_id" => $translated_post->remoteId,
            "language" => $translated_post->language,
        );

        $id_fmts = array(
            "local_id" => "%d",
        );

        $this->wpdb_upsert($this->wpdb->prefix ."gts_translated_posts", $columns, $ids, $id_fmts);
    }

    function delete_translated_blog_post( $id ) {
        $this->wpdb->query('DELETE FROM ' . $this->wpdb->prefix . 'gts_translated_posts WHERE id = ' . ((int) $_POST['id']));
    }



    function get_translated_blog_term( $name, $language ) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM " . $this->wpdb->prefix . "gts_translated_terms WHERE local_name = %s AND language = %s",
                $name, $language
            )
        );
    }

    function save_translated_blog_term( $translated_term ) {

        $translated_term->slug = sanitize_title( $translated_term->slug );

        $columns = array(
            "foreign_id" => $this->get_attribute_value( $translated_term, 'id' ),
            "name" => $translated_term->term,
            "slug" => $translated_term->slug,
            "description" => $translated_term->description,
        );

        $ids = array(
            "local_name" => $translated_term->remoteId,
            "language" => $translated_term->language,
        );

        $id_fmts = array(
            "local_name" => "%d"
        );

        $this->wpdb_upsert($this->wpdb->prefix ."gts_translated_terms", $columns, $ids, $id_fmts);
    }


    function wpdb_upsert($table, $columns, $ids, $id_formats = array()) {

        $select_where = "";
        $select_binds = array();

        foreach (array_keys($ids) as $id) {
            if($select_where) {
                $select_where .= " AND ";
            }

            $select_where .= ("$id = " . (array_key_exists($id, $id_formats) ? $id_formats[$id] : "%s"));
            array_push($select_binds, $ids[$id]);
        }


        $found = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE $select_where", $select_binds
            )
        );

        if($found) {
            $this->wpdb->update($table, $columns, $ids);
        }
        else {
            $now = $this->wpdb->get_row( "select now()", ARRAY_N );
            $this->wpdb->insert($table, array_merge($columns, $ids, array("created_time" => $now[0] )));
        }
    }


    function filter_translated_bloginfo( $bloginfo, $show ) {

        if($show == 'name') {
            $show = 'blogname';
        }
        else if($show == 'description') {
            $show = "blog$show";
        }

        if($this->language) {
            switch($show) {
                case 'language':
                    return $this->language;
                case 'blogname':
                case 'blogdescription':
                    $translated_option = $this->get_translated_named_option( $show, $this->language );
                    if($translated_option) {
                        return $translated_option->value;
                    }
            }
        }

        return $bloginfo;
    }



    function localize_date_string( $date ) {

        if( $this->language ) {
            $date = com_gts_Localization::replace_strings_in_date( $date, $this->config->source_language, $this->language );
        }

        return $date;
    }



    function register_settings() {
        register_setting(GTS_SETTING_GROUP, GTS_OPTION_NAME, array($this, 'validate_settings' ));
        register_setting(GTS_THEME_SETTING_GROUP, GTS_THEME_OPTION_NAME, array($this, 'validate_theme_settings' ));
    }


    function validate_settings( $input ) {

        if($this->skip_config_check) {
            return $input;
        }

        $input[GTS_SETTING_API_KEY] = preg_replace('/\s+/m', '', $input[GTS_SETTING_API_KEY] );
        $input[GTS_SETTING_API_KEY] = preg_replace('/(=+[^=]+=+)/', '', $input[GTS_SETTING_API_KEY] );

        $valid_key = true;
        if( $input[GTS_SETTING_API_KEY] != $this->config->api_key ) {
            $this->validate_api_key( $input[GTS_SETTING_API_HOST], $input[GTS_SETTING_API_PORT], $input[GTS_SETTING_API_KEY] );
        }

        // do a slight transform on the languages...
        $languages = array();
        foreach (com_gts_Language::$OUTPUT_LANGUAGES as $lang) {
            if($input[GTS_SETTING_TARGET_LANGUAGES][$lang->code]) {
                array_push($languages, $lang->code);
            }
        }

        if( $input[GTS_SETTING_TARGET_LANGUAGES] != $this->config->target_languages ) {
            try {
                $input[GTS_SETTING_TARGET_LANGUAGES] = $this->save_configured_languages( $languages );
                $this->link_rewriter->flush_rewrite_rules();
                $rewrite_rules_flushed = true;
            }
            catch(Exception $e) {
                $this->queue_info_notification( "Unable to set languages", "Unable to contact GTS API.  Will keep old values");
                $input[GTS_SETTING_TARGET_LANGUAGES] = $this->config->target_languages;
            }
        }
        $this->config->target_languages = $input[GTS_SETTING_TARGET_LANGUAGES];

        if(count($input[GTS_SETTING_TARGET_LANGUAGES]) == 0) {
            $this->queue_info_notification(__('No Languages Selected'), __('You should select something!'));
        }

        if ( !$rewrite_rules_flushed && $this->config->target_hostnames != $input[GTS_SETTING_TARGET_HOSTS] ) {
            $this->link_rewriter->flush_rewrite_rules();
        }

        // not sure why, but this is getting a boolean true even when unchecked.  this
        // double-check fixes it.
        $input[GTS_SETTING_SYNCHRONOUS] = "on" == $input[GTS_SETTING_SYNCHRONOUS];
        $input[GTS_SETTING_USE_THEME] = "on" == $input[GTS_SETTING_USE_THEME];

        // if our form input is valid, then go ahead and toggle this setting...
        // note that it's sticky so that once set, we don't send the user back to the
        // plugin splash screen.
        $this->config->plugin_initialized = $this->config->plugin_initialized || ( $valid_key && count( $languages ) > 0 );

        // this is a little bit of magic to make sure that our config object gets properly
        // restored.  anything that's not a part of the user-configurable portion will get
        // copied into our saved config object.
        $config_class = new ReflectionClass( get_class( $this->config ) );
        foreach ( $config_class->getProperties() as $property ) {
            switch( $property->getName() ) {
                // these first few are only available in debug mode.
                // otherwise, they always have to be the default value.
                case GTS_SETTING_BLOG_ID:
                case GTS_SETTING_API_HOST:
                case GTS_SETTING_API_PORT: if ( !GTS_DEBUG_MODE ) break;

                case GTS_SETTING_API_KEY:
                case GTS_SETTING_TARGET_HOSTS:
                case GTS_SETTING_TARGET_LANGUAGES:
                case GTS_SETTING_SYNCHRONOUS:
                    break;
                default:
                    $input[ $property->getName() ] = $property->getValue( $this->config );
            }
        }

        return $input;
    }

    function validate_theme_settings( $input ) {

        $ticked = 'on' == $input;
        
        $this->config->use_translated_theme = $ticked;
        $this->save_config();
        
        return $ticked;
    }


    function create_admin_menu() {
        add_menu_page( 'GTS Plugin Settings', 'GTS Settings', 'manage_options', GTS_MENU_NAME, array($this, 'settings_page') );

        add_submenu_page( GTS_MENU_NAME, 'GTS Theme Settings', 'Translate Theme', 'manage_options', GTS_MENU_NAME . '-theme', array($this, 'settings_theme_page') );
        add_submenu_page( GTS_MENU_NAME, 'GTS Manage Translated Posts', 'Manage Posts', 'manage_options', GTS_MENU_NAME . '-posts', array($this, 'settings_posts_page') );
    }

    function settings_page() {
        $this->include_page_or_splash( 'options' );
    }

    function settings_theme_page() {
        $this->include_page_or_splash( 'theme', true );
    }

    function settings_posts_page() {
        $this->include_page_or_splash( 'translated-posts', true );
    }

    function include_page_or_splash( $page, $not_available_yet = false ) {
        if(!$this->config->plugin_initialized && !$_GET['initialize']) {
            if ( $not_available_yet ) {
                include("pages/gts-options-notavailable.php");
            }
            else {
                include("pages/gts-options-splash.php");
            }
        }
        else {
            include("pages/gts-settings-$page.php");
        }
    }


    function send_info_notification($heading, $text) {
        $this->send_wp_notification($heading, $text, "info");
    }

    function send_error_notification($heading, $text) {
        $this->send_wp_notification($heading, $text, "error");
    }

    function send_wp_notification($heading, $text, $type) {

        $html = "<div id=\"gts-warning-$heading\" class=\"" . ($type == "info" ? "updated" : "error") . " fade\">" 
                . "<p><strong>" . __($heading) . "</strong>: " . __($text) . "</p></div>";

        add_action("admin_notices", create_function("", "echo '". preg_replace('/\'/', "\\'", $html) .  "\n';"));
    }
    
    
    function send_notifications( &$messages, $type ) {

        $count = 0;

        while( $message = array_shift( $messages ) ) {
            $line = explode("|", $message);
            $count++;

            if($type == 'error') {
                $this->send_error_notification($line[0], $line[1]);
            }
            else {
                $this->send_info_notification($line[0], $line[1]);
            }
        }

        return $count;
    }



    function get_translated_title( $title, $post_id = "" ) {

        if( !$post_id ) {
            return $title;
        }

        if( $post_id instanceof StdClass ) {
            $post_id = $post_id->ID;
        }

        $post = $this->get_translated_blog_post( $post_id, $this->language );
        if( $post ) {
            return $post->post_title;
        }

        return $title;
    }



    function substitute_translated_template( $template ) {
        return $this->get_translated_theme_attribute( 'Template', $template );
    }

    function substitute_translated_stylesheet( $stylesheet ) {
        return $this->get_translated_theme_attribute( 'Stylesheet', $stylesheet );
    }

    function get_translated_theme_attribute( $attribute, $if_not_found ) {

        // making sure to use the theme_language and not the normal language due to wordpress wonkiness
        if($this->theme_language) {
            
            $current = get_current_theme();

            // depending on whether the theme has a package, we have different replace conditions here.
            if( strpos( $current, ' ') ) {
                $translated_theme = preg_replace( '/(\S+)\s+(.*)/', 'gts_autogenerated $2', $current) . ".$this->theme_language";
            }
            else {
                $translated_theme = "gts_autogenerated $current.$this->theme_language";
            }

            if( ($theme = get_theme( $translated_theme ) ) && $theme[$attribute] ) {
                return $theme[$attribute];
            }
        }

        return $if_not_found;
    }



    function add_autogenerated_theme_dir( $dirs ) {
        return $dirs; 
    }



    function translate_current_theme( $pre_file_callback = null, $post_file_callback = null ) {

        $theme = get_theme( get_current_theme() );

        $this->prepare_theme_for_translation( $theme );

        foreach ( $this->get_template_filenames( $theme ) as $file ) {

            if( $pre_file_callback ) {
                $pre_file_callback( $theme, $file );
            }

            $this->translate_template_file( $theme, $file );

            if( $post_file_callback ) {
                $post_file_callback( $theme, $file );
            }
        }
    }


    function prepare_theme_for_translation( $theme ) {
        foreach ( $this->config->target_languages as $lang ) {
            $template_dir = GTS_THEME_DIR . '/' . basename( $theme['Template Dir'] ) . ".$lang";
            if ( !file_exists( $template_dir ) ) {
                GtsUtils::mkdir_dash_p( $template_dir );
            }

            GtsUtils::copy_directory( $theme['Template Dir'], $template_dir );
            GtsUtils::copy_directory( $theme['Stylesheet Dir'], $template_dir );
        }
    }

    function get_template_filenames( $theme = null ) {

        if( !$theme ) {
            $theme = get_theme( get_current_theme() );
        }

        $files = array_merge( $theme['Template Files'], $theme['Stylesheet Files'] );
        array_walk( $files, array( $this, 'array_walk_get_relative_name') );

        return $files;
    }

    function array_walk_get_relative_name( &$file ) {
        $file = str_replace( WP_CONTENT_DIR . '/themes', '', $file );
    }


    function translate_template_file( $theme, $file ) {

        $template_request = new com_gts_BlogTemplate();

        $template_request->language = $this->config->source_language;

        $template_request->theme = $theme['Name'];
        $template_request->path = $file;
        $template_request->text = stream_get_contents( fopen( WP_CONTENT_DIR . '/themes' . $file, 'r' ) );

        $template_request->remoteId = $template_request->theme . ':' . $template_request->path;

        $response = $this->do_api_call( 'translateTemplate' , $template_request, true );

        if ( $response ) {

            foreach ( $response->translationResult->translations->blogTemplate as $template ) {

                $template_file = GTS_THEME_DIR . '/' . $template->path;
                $template_dir = dirname( $template_file );

                if ( !file_exists( $template_dir ) ) {
                    GtsUtils::mkdir_dash_p( $template_dir );
                }

                $fh = @fopen( $template_file, 'w' );
                if ( !$fh ) {
                    throw new Exception("Unable to write template files!");
                }

                fwrite( $fh, $template->text );
                fclose( $fh );
            }
        }
    }

    function is_plugin_directory_writable() {
        return is_writable( GTS_PLUGIN_DIR ); 
    }

    function is_plugin_theme_directory_writable() {
        return $this->is_plugin_directory_writable() || ( is_dir( GTS_THEME_DIR ) && is_writable( GTS_THEME_DIR ) );
    }
}



function gtsenv_is_wp_loaded() {
    return defined('WP_PLUGIN_URL');
}


// find and configure the wp runtime if we're not being called from within a WP
// request.  this block must be executed in the global scope in order to succeed.
// if decomped into a function, it will not work...so all vars are namespaced.
if(!gtsenv_is_wp_loaded()) {

    // first start going up the directory tree until we find a copy of the wp-load.php file.
    // this will be the common case when running in a webserver.
    foreach( explode(PATH_SEPARATOR, get_include_path() ) as $gtsenv_include_dir ) {

        $gtsenv_script_file = $_SERVER['SCRIPT_FILENAME'];
        $gtsenv_offset = -1;

        while(($idx = strrpos( $gtsenv_script_file, DIRECTORY_SEPARATOR, $gtsenv_offset )) > 0) {
            $gtsenv_filename = substr( $gtsenv_script_file, 0, $idx + 1) . 'wp-load.php';
            $gtsenv_offset = ($idx - 1) - strlen($gtsenv_script_file);

            if(@file_exists($gtsenv_filename)) {
                require_once $gtsenv_filename;
                break;
            }
        }
    }

    // if we still haven't been able to find our file, then try to pull the file from our include path.
    // this will be the case when we're running from the IDE or unit tests.
    if( !gtsenv_is_wp_loaded() ) {

       $gtsenv_filename = $gtsenv_include_dir . DIRECTORY_SEPARATOR . 'wp-load.php';

        if(@file_exists($gtsenv_filename)) {
            require_once $gtsenv_filename;
            break;
        }
    }

    // todo - should provide an option to specify a path if PLUGINDIR is not under ABSPATH?    
    if(!gtsenv_is_wp_loaded()) {
        die('unable to find wp config...');
    }
}


/**
 * now that the WP runtime is loaded up, we'll define all our constants.
 */

define( 'GTS_PLUGIN_NAME', 'rejected-gts-translation-rejected' );
define( 'GTS_PLUGIN_DIR', WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . GTS_PLUGIN_NAME );
define( 'GTS_PLUGIN_URL', trailingslashit( WP_PLUGIN_URL ) . basename( GTS_PLUGIN_DIR) );

define( 'GTS_THEME_DIR_NAME', 'translated_themes' );
define( 'GTS_THEME_DIR', GTS_PLUGIN_DIR . DIRECTORY_SEPARATOR . GTS_THEME_DIR_NAME  );

define( 'GTS_OPTION_NAME' , 'gts_plugin_config');
define( 'GTS_THEME_OPTION_NAME' , 'gts_plugin_config_theme');
define( 'GTS_AUTHORIZATION_OPTION_NAME', 'gts_plugin_authorization' );
define( 'GTS_DB_INITIALIZED_OPTION_NAME' , 'gts_database_initialized');

// names of menus and such for the wp-admin interface.
define( 'GTS_MENU_NAME', 'gts-settings' );
define( 'GTS_SETTING_GROUP', 'gts-plugin-settings' );
define( 'GTS_THEME_SETTING_GROUP', 'gts-plugin-settings-templates' );

// these are names of individual settings that are keys in the associative
// array that wordpress passes around for our main option.
define( 'GTS_SETTING_BLOG_ID', 'blog_id' );
define( 'GTS_SETTING_API_KEY', 'api_key' );
define( 'GTS_SETTING_API_HOST', 'api_host' );
define( 'GTS_SETTING_API_PORT', 'api_port' );
define( 'GTS_SETTING_TARGET_LANGUAGES', 'target_languages' );
define( 'GTS_SETTING_TARGET_HOSTS', 'target_hostnames' );
define( 'GTS_SETTING_SYNCHRONOUS', 'synchronous' );
define( 'GTS_SETTING_USE_THEME', 'use_translated_theme' );


?>
