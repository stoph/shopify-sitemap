<?php

declare(strict_types=1);

return [
    'store' => [
        'shopify_domain' => 'https://field-stream.myshopify.com/',
        'product_url_template' => 'https://shop.fieldandstream.com/products/<id>',
        //'password' => 'password' // If store is password protected
    ],
    'cache' => [
        'dir' => __DIR__ . '/cache',
        'ttl' => 3600 // 1 hour
    ],
    'api' => [
        'limit' => 5,
        'start_page' => 1,
        'rate_limit' => 1, // seconds between requests
        'max_pages' => 2 // Optional: limit number of pages to fetch for testing
    ],
    'xml' => [
        'namespaces' => [
            'sitemap' => 'http://www.sitemaps.org/schemas/sitemap/0.9',
            'image' => 'http://www.google.com/schemas/sitemap-image/1.1'
        ]
    ]
]; 