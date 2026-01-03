<?php

return [
    'base_url' => env('WORDPRESS_BASE_URL'),
    'consumer_key' => env('WORDPRESS_CONSUMER_KEY'),
    'consumer_secret' => env('WORDPRESS_CONSUMER_SECRET'),
    'api_version' => env('WORDPRESS_API_VERSION', 'wc/v3'),
        
    // WordPress Application Password for Media Library uploads (/wp/v2/media)
    // Required for uploading images to WordPress Media Library
    // Generate in WordPress: Users > Your Profile > Application Passwords
    // Format: username:application_password (e.g., "admin:xxxx xxxx xxxx xxxx xxxx xxxx")
    'wp_username' => env('WORDPRESS_USERNAME', null),
    'wp_app_password' => env('WORDPRESS_APP_PASSWORD', null),
        
    // Default category name for all synced products (optional)
    // If set, all products will be assigned to this category
    // If not set, products will be assigned to a category matching the website name
    'default_category' => env('WORDPRESS_DEFAULT_CATEGORY', null),
];

