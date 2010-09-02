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

class GtsLinkRewriter {

    static $LANG_PARAM = 'gts_lang';
    static $TAG_PARAM = 'gts_tag_id';
    static $CATEGORY_PARAM = 'gts_category_id';
    static $PAGEPATH_PARAM = 'gts_pagepath';

    static $INTERESTING_OPTIONS = array(
        'tag_base',
        'category_base',
        GTS_OPTION_NAME,
    );

    static $SIMPLE_LINKS = array(
        //'attachment_link', - this is a compound one...depends on the post link
        'author_link',
        'author_feed_link',
        'day_link',
        'feed_link',
        'month_link',
        //'page_link', - taking care of this one explicitly
        'year_link',
    );

    static $COMPOUND_LINKS = array(
        'category_link',
        //'category_feed_link',  - this is a compound one...depends on the category_link
        //'post_link',  - we're taking care of this one explicitly
        'tag_link',
    );

    function register_plugin_hooks() {

        $flush_callback = array( $this, 'flush_rewrite_rules' );

        if(is_admin()) {
            foreach ( GtsLinkRewriter::$INTERESTING_OPTIONS as $option ) {
                add_filter( "add_option_$option", $flush_callback );
                add_filter( "update_option_$option", $flush_callback );
            }
        }
        
        add_filter( 'query_vars', array( $this, 'add_query_params'), 1 );
        
        add_filter( 'rewrite_rules_array', array( $this, 'append_rewrite_rules' ), 9999999999 );  // we want to be last last last!

        add_action( 'request' , array($this, 'fix_term_parameters'), 1 );

        add_filter( 'post_link', array($this, 'rewrite_post_link'), 1, 2 );
        add_filter( 'page_link', array($this, 'rewrite_page_link'), 1, 2 );

        foreach ( GtsLinkRewriter::$SIMPLE_LINKS as $filter_name ) {
            add_filter( $filter_name, array($this, 'add_language_parameter') );
        }

        foreach ( GtsLinkRewriter::$COMPOUND_LINKS as $filter_name ) {
            add_filter( $filter_name, array($this, 'add_language_and_term_id_parameters'), 10, 2 );
        }
    }


    function add_language_parameter( $link ) {

        global $gts_plugin;
        if($gts_plugin->language) {
            return $this->insert_param( $link, 'language', $gts_plugin->language );
        }

        return $link;
    }


    function add_language_and_term_id_parameters( $link, $id ) {

        global $gts_plugin, $wp_rewrite;
        if($gts_plugin->language) {

            if($id instanceof StdClass) {
                $id = $id->ID;
            }


            $query_param = null;
            if( !$wp_rewrite->permalink_structure ) {

                // if we don't have permalink support, we have to differentiate between
                // the different types of queries that come through this path.  the easiest
                // way to do that is by comparing queries with params removed.
                //
                // note that in the case of tags, we have to pass the name, so we won't
                // have the translated value in the URL.  oh well.
                if( remove_query_arg( 'tag', $link ) != $link ) {
                    $query_param = 'tag';
                    $id = $gts_plugin->do_without_language( 'get_slug_without_language', $id );
                }
                else if( remove_query_arg( 'cat', $link ) != $link ) {
                    $query_param = 'cat';
                }
                else {
                    $query_param = 'p';
                }
            }

            return $this->add_language_parameter( $this->insert_param( $link, $query_param, $id, false) );
        }

        return $link;
    }

    function get_slug_without_language( $id ) {
        return get_tag( $id )->slug;
    }



    function rewrite_page_link( $link, $id ) {

        global $gts_plugin, $wp_rewrite;
        if( $id && $gts_plugin->language ) {

             $permalink = $wp_rewrite->get_page_permastruct();
             if( preg_match( '/%pagename%/', $permalink ) ) {

                 $page = get_page( $id );
                 $old_path = array();
                 $translated_path = array();

                 while( true ) {
                     $tpage = $gts_plugin->get_translated_blog_post( $page->ID, $gts_plugin->language );
                     array_unshift( $old_path, $page->post_name );
                     array_unshift( $translated_path, $tpage ? $tpage->post_slug : $page->post_name );

                     if( $page->post_parent == 0 ) {
                         break;
                     }

                     $page = get_page( $page->post_parent );
                 }

                 $link = str_replace( implode( '/', $old_path ), implode( '/', $translated_path), $link );
             }

             return $this->add_language_parameter( $link );
        }

        return $link;
    }


    function rewrite_post_link( $link, $post ) {

        global $gts_plugin, $wp_rewrite;
        if( $gts_plugin->language) {

            $permalink = $wp_rewrite->permalink_structure;

            // gts_translated is a handy little field we put on posts so that we know it's already
            // been swapped out.  sometimes, this filter is entered via a route where it's not possible
            // to action or filter the post content to our own (notably in the prev/next link functions...lame).
            // in those cases, we have to do a transformation on the link.
            if( preg_match( '/%postname%/', $permalink ) && !$post->gts_translated ) {
                $tpost = $gts_plugin->get_translated_blog_post( $post->ID, $gts_plugin->language );
                if( $tpost ) {
                    $link = str_replace( $post->post_name, $tpost->post_slug, $link );
                }
            }

            return $this->add_language_parameter( $this->insert_param( $link, "p", $post->ID, false) );
        }

        return $link;
    }

    function insert_param( $link, $name, $value, $include_name_in_permalink = true ) {

        global $wp_rewrite;

        $home = get_option('home');

        if( $wp_rewrite->permalink_structure ) {
            return substr( $link, 0, strlen($home) ) . ($include_name_in_permalink ? "/$name" : '') . "/$value" . substr( $link, strlen($home) );
        }

        return add_query_arg( $name, $value, $link );
    }


    function fix_term_parameters( $query_vars ) {

        $this->replace_with_slug( $query_vars, GtsLinkRewriter::$TAG_PARAM, 'tag', 'get_tag' );
        $this->replace_with_slug( $query_vars, GtsLinkRewriter::$CATEGORY_PARAM, 'category_name', 'get_category' );

        if( $query_vars[GtsLinkRewriter::$LANG_PARAM] && $query_vars['pagename'] ) {
            $query_vars['pagename'] = $this->get_original_pagename( $query_vars['pagename'], $query_vars['gts_lang'] );
        }

        // this one is always set regardless of language.  we use it later to pick up pages because wordpress
        // rewrites the pagename param to be just the last portion, which can't easily be used for lookups.
        $query_vars[GtsLinkRewriter::$PAGEPATH_PARAM] = $query_vars['pagename'];

        if($query_vars["p"] && $query_vars["name"]) {
            unset($query_vars["name"]);
        }

        return $query_vars;
    }


    function get_original_pagename( $translated_page, $language ) {

        global $gts_plugin;

        $original_path = array();

        foreach ( explode( '/', $translated_page ) as $part ) {
            if( $part ) {

                $tpost = $gts_plugin->get_translated_blog_post_by_slug( $part, $language );
                if( $tpost ) {
                    $post = get_page( $tpost->local_id );
                }
                else {
                    $post = get_page_by_title( $part );
                }

                $original_path[] = $post->post_name;
            }
        }

        return implode( '/', $original_path );
    }



    function replace_with_slug( &$query_vars, $gts_param_name, $param_name, $lookup_function ) {

        global $gts_plugin;

        $gts_id = $query_vars[$gts_param_name];
        if( $gts_id ) {
            $object = $gts_plugin->do_without_language( $lookup_function, $gts_id );
            if( $object ) {
                $query_vars[$param_name] = $object->slug;
            }
        }
    }



    function add_query_params( $query_vars ) {
        array_push( $query_vars , GtsLinkRewriter::$LANG_PARAM, GtsLinkRewriter::$TAG_PARAM, GtsLinkRewriter::$CATEGORY_PARAM );
        return $query_vars;
    }

    function append_rewrite_rules( $rules ) {
        
        global $wp_rewrite;

        // only bother messing with this stuff if we have permalinks enabled.
        // otherwise, we just do like WP and return the empty rules array.
        if($wp_rewrite->permalink_structure) {

            $wp_rewrite->add_rewrite_tag('%'.GtsLinkRewriter::$LANG_PARAM.'%', '([a-z]{2})', GtsLinkRewriter::$LANG_PARAM . '=' );
            $wp_rewrite->add_rewrite_tag('%'.GtsLinkRewriter::$TAG_PARAM.'%', '([0-9]+)', GtsLinkRewriter::$TAG_PARAM . '=' );
            $wp_rewrite->add_rewrite_tag('%'.GtsLinkRewriter::$CATEGORY_PARAM.'%', '([0-9]+)', GtsLinkRewriter::$CATEGORY_PARAM . '=' );

            // todo - option-ize and localize this prefix.
            // todo - also localize tag/category in url.
            $lang_prefix = 'language/%' . GtsLinkRewriter::$LANG_PARAM . '%';

            $newrules = array();

            // date rewrites come first because otherwise they get eaten by the permalink rewrite rule.  they
            // are ordered in order of least specific to most specific.
            $newrules += $wp_rewrite->generate_rewrite_rules( $lang_prefix . $wp_rewrite->get_year_permastruct(), EP_YEAR );
            $newrules += $wp_rewrite->generate_rewrite_rules( $lang_prefix . $wp_rewrite->get_month_permastruct(), EP_MONTH );
            $newrules += $wp_rewrite->generate_rewrite_rules( $lang_prefix . $wp_rewrite->get_day_permastruct(), EP_DAY );
            $newrules += $wp_rewrite->generate_rewrite_rules( $lang_prefix . $wp_rewrite->get_date_permastruct(), EP_DATE );

            $newrules += $wp_rewrite->generate_rewrite_rules( "$lang_prefix/" . $wp_rewrite->comments_base, EP_COMMENTS, true, true, true, false );
            
            $newrules += $wp_rewrite->generate_rewrite_rules( "$lang_prefix/%post_id%$wp_rewrite->permalink_structure", EP_PERMALINK );

            // this appears to be a bug in wordpress that we need to work around...  it's eating up our gts_lang variable in places relating
            // to attachments.  this hack seems to fix it, but only when we run it just after adding the permalink rules.  not sure why it has
            // to be here rather than at the end of the function, but i'm not messing around with it further.
            foreach ( $newrules as $match => $params ) {
                if( !preg_match( '/gts_lang=/', $params )) {

                    unset($newrules[$match]);

                    $match = preg_replace( '/(\[a\-z\]\{2\})/', '($1)', $match );
                    $params = $params . '&gts_lang=$matches[0]';
                    $params = preg_replace_callback( '/\$matches\[(\d+)\]/', array( $this, 'callback_reindex_match_array' ), $params);

                    $newrules[$match] = $params;
                }
            }

            $newrules += $wp_rewrite->generate_rewrite_rules( "$lang_prefix/%" . GtsLinkRewriter::$TAG_PARAM . '%' . $wp_rewrite->get_tag_permastruct(), EP_TAGS );
            $newrules += $wp_rewrite->generate_rewrite_rules( "$lang_prefix/%" . GtsLinkRewriter::$CATEGORY_PARAM . '%' . $wp_rewrite->get_category_permastruct(), EP_CATEGORIES );

            $newrules += $wp_rewrite->generate_rewrite_rules( $lang_prefix . $wp_rewrite->get_author_permastruct(), EP_AUTHORS );
            $newrules += $wp_rewrite->generate_rewrite_rules( $lang_prefix . $wp_rewrite->get_search_permastruct(), EP_SEARCH );

            // todo - what is the extra permastruct
            // $newrules += $wp_rewrite->generate_rewrite_rules( $lang_prefix . $wp_rewrite->get_extra_permastruct() );

            // these two go last b/c it will otherwise match some of the above patterns.
            $newrules += $wp_rewrite->generate_rewrite_rule( $lang_prefix . $wp_rewrite->get_page_permastruct(), EP_PAGES );
            $newrules += $wp_rewrite->generate_rewrite_rule( $lang_prefix, EP_ROOT );

            return $newrules + $rules;
        }

        return $rules;
    }

    function callback_reindex_match_array( $matches ) {
        return '$matches[' . ($matches[1] + 1) . ']';
    }


    function flush_rewrite_rules() {
        global $wp_rewrite;
        $wp_rewrite->flush_rules();
    }
}
