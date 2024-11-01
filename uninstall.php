<?php 
if(!defined('ABSPATH') && !defined('WP_UNINSTALL_PLUGIN') ) die();
delete_option('spam_cap_count');
delete_option('spam_cap_options');
?>