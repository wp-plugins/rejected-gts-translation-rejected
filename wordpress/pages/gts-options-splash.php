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


global $gts_plugin;

$host = $gts_plugin->config->api_host;
$port = $gts_plugin->config->secure_api_port;

$home = get_option('home');
$plugin_path = GTS_PLUGIN_URL;
if ( strpos( $plugin_path, $home ) == 0) {
    $plugin_path = substr( $plugin_path, strlen( $home ) );
}

global $current_user;
get_currentuserinfo();
$admin_email = $current_user->user_email;

if ( ! $auth = get_option( GTS_AUTHORIZATION_OPTION_NAME ) ) {
    $auth = array(
        'code' => GtsUtils::random_alphanumeric( 128 ),
        'email' => $admin_email,
    );
    update_option( GTS_AUTHORIZATION_OPTION_NAME, $auth );
}


if ( $auth['email'] != $admin_email ) {
    $auth['email'] = $admin_email;
    update_option( GTS_AUTHORIZATION_OPTION_NAME, $auth );
}

$args = array(
    'auth' => $auth['code'],
    'blogUrl' => $home,
    'blogTitle' => get_option('blogname'),
    'blogDescription' => get_option('blogdescription'),
    'pluginPath' => $plugin_path,
    'adminEmail' => $admin_email,
);

$url = "https://$host" . ( $port == 443 ? '' : ":$port") . "/api/setup/landing";


?>
<div class="wrap" style="width: 60%">

    <form id="registrationForm" method="post" action="<?php echo $url; ?>" enctype="application/x-www-form-urlencoded;charset=utf-8">
    <?php foreach ( $args as $key => $value ) { ?>
        <input type="hidden" name="<?php echo $key ?>" value="<?php echo htmlentities( $value ) ?>"/>
    <?php } ?>
    </form>

    <h2>GTS</h2>

    <p>
        Before we can start translating your blog, <i><?php echo get_option('blogname')?></i>, you need to
        <a style="font-weight: bold; cursor: pointer" onclick="document.getElementById('registrationForm').submit()">register</a> with GTS.
    </p>

    <p>
        If you have previously registered and have the activation information GTS sent to you via email, please follow the
        link provided in that mail to finalize the registration process.  Please click <a href="#">here</a> if you need to
        have that email re-sent.  <b>NOTE: emails are not currently sent...will be part of public release</b>
    </p>

</div>