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

class GTS_LanguageSelectWidget extends WP_Widget {

    protected static $TITLES = array (
        'en' => 'Also available in',
        'de' => 'Auch verfügbar in',
        'es' => 'También disponible en',
        'fr' => 'En outre disponible dans',
        'it' => 'Inoltre  disponibile  in',
    );

    protected static $POWERED_BY = array (
        'en' => 'Powered by',
        'de' => 'Übersetzt durch',
        'es' => 'Traducido por',
        'fr' => 'Traduit par',
        'it' => 'Tradotto da',
    );

    protected static $SELECT_LANGUAGE = array (
        'en' => 'Select Language',
        'de' => 'Wählen Sprache',
        'es' => 'Elige Idioma',
        'fr' => 'Choisir Langue',
        'it' => 'Scegliere Lingua',
    );


    function __construct() {
        parent::__construct(false, 'Gts Language Selector', array (
            'description' => __('Adds a drop-down menu to change the language of the page.')
        ));
    }

    function widget($args, $instance) {

        extract($args);

        global $gts_plugin, $wp_rewrite;

        $available_langs = array ($gts_plugin->config->source_language);
        if( is_array( $gts_plugin->config->target_languages ) ) {
            $available_langs = array_merge($available_langs, $gts_plugin->config->target_languages);
        }

        if(!($curr_lang = $gts_plugin->language)) {
            $curr_lang = $gts_plugin->config->source_language;
        }

        $title = apply_filters('widget_title', GTS_LanguageSelectWidget::$TITLES[$curr_lang]);

        $languages_with_links = array();
        foreach($available_langs as $code) {

            $lang = com_gts_Language::get_by_code($code);
            $is_current = $lang->code == $curr_lang;
            $is_source = $lang->code == $gts_plugin->config->source_language;

            // this little ditty makes sure we get appropriate hostname replacement...
            $home = trailingslashit( $gts_plugin->do_with_language( array( $this, 'callback_get_home' ), $is_source ? null : $lang->code ) );

            // this is the easy case...  no permalinks.  then we just have to toggle the language
            // parameter and we can call it a day.
            if( !$wp_rewrite->permalink_structure ) {

                $link = $this->get_homed_url( $home, null );
                if( $lang->code == $gts_plugin->config->source_language ) {
                    $link = remove_query_arg( "language", $link );
                }
                else {
                    $link = add_query_arg( "language", $lang->code, $link );
                }
            }
            else {

                // and if we have permalink support, there's a whole mess of special cases.  most
                // of them boil down to running the link through the plugin with the language overridden.

                $homed_url = $this->get_homed_url( $home, $lang->code );
                $interesting_part = substr( $homed_url, strlen( $home ) );

                if( $is_source ) {

                    $interesting_part = preg_replace( '/^language\/[a-z]{2}\/?/', '', $interesting_part);

                    if( is_tag() || is_category() || is_single() || is_page() ) {

                        if( is_tag() ) {
                            $link = $gts_plugin->do_without_language( array( $this, 'callback_get_straight_tag_link' ) );
                        }
                        else if( is_category() ) {
                            $link = $gts_plugin->do_without_language( array( $this, 'callback_get_straight_category_link' ) );
                        }
                        else if( is_single() ) {
                            $link = $gts_plugin->do_without_language( array( $this, 'callback_get_straight_post_link' ) );
                        }
                        else if( is_page() ) {
                            $link = $gts_plugin->do_without_language( array( $this, 'callback_get_straight_page_link' ) );
                        }
                    }
                    else {
                        $link = $home . $interesting_part;
                    }
                }
                else {

                    if( is_tag() ) {
                        $link = $gts_plugin->do_with_language( array( $this, 'callback_get_translated_tag_link' ), $lang->code);
                    }
                    else if ( is_category() ) {
                        $link = $gts_plugin->do_with_language( array( $this, 'callback_get_translated_category_link' ), $lang->code);
                    }
                    else if( is_single() ) {
                        $link = $gts_plugin->do_with_language( array( $this, 'callback_get_translated_post_link' ), $lang->code);
                    }
                    else if( is_page() ) {
                        $link = $gts_plugin->do_with_language( array( $this, 'callback_get_translated_page_link' ), $lang->code);
                    }
                    else if(!preg_match( '/^(language\/)([a-z]{2})(\/.*)?$/', $interesting_part, $matches ) ) {
                        $link = $home . 'language/' . $lang->code . '/' . $interesting_part;
                    }
                    else {
                        $link = $home . $matches[1] . $lang->code . $matches[3];
                    }
                }


                // if we have permalinks, then make sure this parameter isn't hanging around where
                // it may accidentally override the displayed page language.
                $link = remove_query_arg( 'language', $link );
            }

            if ( !$is_current ) {
                $languages_with_links[ $lang->code ] = $link;
            }
        }

        echo $before_widget;

        $this->output_widget_html($curr_lang, $title, $languages_with_links, $before_title, $after_title);

        echo $after_widget;
    }


    function output_widget_html($curr_lang, $title, $languages_with_links, $before_title = "", $after_title = "") {
        ?>

        <div class="gtsLanguageSelector">

        <?php if($title) {
            echo $before_title . $title . ":" . $after_title;
        } ?>

            <script type="text/javascript">
                var com_gts_languageLookup = {
                    <?php
                    foreach ( $languages_with_links as $lang => $link ) {
                        echo "$lang : '$link',\n";
                    }
                    ?>
                };

                function sendToTranlsatedPage(select) {
                    var code = select.options[select.selectedIndex].value;
                    if(com_gts_languageLookup[code] != null) {
                        window.location.href = com_gts_languageLookup[code];
                    }
                }
            </script>

            <select onchange="sendToTranlsatedPage(this)">
                <option><?php echo GTS_LanguageSelectWidget::$SELECT_LANGUAGE[$curr_lang]; ?>...</option>
            <?php
            foreach( $languages_with_links as $lang_code => $link ) {
                $lang = com_gts_Language::get_by_code( $lang_code );
                echo "<option value=\"$lang->code\">$lang->name</option>\n";
            }
            ?>
            </select>

            <p style="vertical-align: middle;">
                <span><?php echo GTS_LanguageSelectWidget::$POWERED_BY[$curr_lang]; ?>
                    <a href="http://www.gts-translation.com/" target="_blank"><img height="16" src="<?php echo GTS_PLUGIN_URL ?>/wordpress/images/logo_trans.png"/></a>
                </span>
            </p>
        </div>
        <?php
    }


    /** @see WP_Widget::update */
    function update($new_instance, $old_instance) {
        return $new_instance;
    }


    function callback_get_straight_tag_link() {
        return get_tag_link( get_query_var( 'tag' ) );
    }
    
    function callback_get_straight_category_link() {
        return get_category_link( get_query_var( 'cat' ) );
    }

    function callback_get_straight_post_link() {
        return get_permalink( get_query_var( 'p' ) );
    }

    function callback_get_straight_page_link() {
        return get_page_link( get_page_by_path( get_query_var( GtsLinkRewriter::$PAGEPATH_PARAM ) )->ID );
    }

    function callback_get_translated_tag_link() {
        return get_tag_link( get_query_var('tag_id' ) );
    }

    function callback_get_translated_category_link() {
        $id = get_query_var( 'gts_category_id' );
        if( !$id ) {
            $id = get_cat_ID( get_query_var( 'category_name' ) );
        }
        return get_category_link( $id );
    }

    function callback_get_translated_post_link() {
        $id = get_query_var( 'p' );
        if( !$id ) {
            $id = get_page_by_title( get_query_var( 'name' ) )->ID;
        }
        return get_permalink( $id );
    }

    function callback_get_translated_page_link() {
        return get_page_link( get_page_by_path( get_query_var( GtsLinkRewriter::$PAGEPATH_PARAM ) )->ID );
    }



    function get_homed_url( $home, $lang ) {

        $home = untrailingslashit( $home );
        preg_match( '/^(.*?)([#?].*)?$/', $_SERVER['REQUEST_URI'] , $matches );

        $url_parts = array_filter( explode( '/', $matches[1] ) );

        $non_shared = array();
        while( count($url_parts) > 0 && !strrpos( $home, '/' . implode( '/', $url_parts ) ) ) {
            $non_shared[] = array_pop( $url_parts );
        }

        return trailingslashit($home) . implode('/', array_reverse( $non_shared ) ) . $matches[2] ;
    }


    function callback_get_home() {
        return get_option('home');
    }
}
