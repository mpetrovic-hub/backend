<?php
/*
Plugin Name: Kiwi Backend
Description: Internal backend tools for Kiwi mVAS services (HLR, SMS, etc.)
Version: 0.1.2
Author: Kiwi
*/

/* This file is the main entry point for the Kiwi Backend plugin. It loads all necessary classes and sets up hooks for frontend assets, 
shortcodes, and export functionality. */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/bootstrap.php';