<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Lululemon Scraper Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Lululemon scraper including GraphQL endpoint,
    | category identifiers, and pagination settings.
    |
    | IMPORTANT: To configure the GraphQL query:
    | 1. Open browser DevTools Network tab
    | 2. Navigate to a category page on shop.lululemon.com
    | 3. Find the GraphQL request (check the actual endpoint URL in the request)
    | 4. Copy the entire query string from the request body
    | 5. Set LULULEMON_GRAPHQL_CATEGORY_QUERY in your .env file (use triple quotes for multiline)
    | 6. Verify and set LULULEMON_GRAPHQL_ENDPOINT if different from /api/graphql
    | 7. Update LULULEMON_GRAPHQL_ROOT_FIELD to match the root field name in the query
    | 8. Update LULULEMON_GRAPHQL_PRODUCT_PATH to point to where products array lives in response
    |
    */

    'graphql_endpoint' => env('LULULEMON_GRAPHQL_ENDPOINT', 'https://shop.lululemon.com/api/graphql'),

    'base_url' => env('LULULEMON_BASE_URL', 'https://shop.lululemon.com'),

    'categories' => [
        'women_bras_underwear' => env('LULULEMON_CATEGORY_WOMEN_BRAS_UNDERWEAR', 'women-bras-and-underwear/n1sv6a'),
    ],

    'pagination' => [
        'page_size' => env('LULULEMON_PAGE_SIZE', 40),
        'max_retries' => env('LULULEMON_MAX_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | GraphQL Query Configuration
    |--------------------------------------------------------------------------
    |
    | The GraphQL query string should be captured from browser DevTools.
    | Set LULULEMON_GRAPHQL_CATEGORY_QUERY in .env with the full query.
    | For multiline queries, use triple quotes or escape newlines.
    |
    | The operationName will be automatically extracted from the query,
    | or you can set LULULEMON_GRAPHQL_OPERATION_NAME explicitly.
    |
    */
    'graphql_queries' => [
        'category_page' => env('LULULEMON_GRAPHQL_CATEGORY_QUERY', null),
    ],

    'graphql_operation_name' => env('LULULEMON_GRAPHQL_OPERATION_NAME', null),

    /*
    |--------------------------------------------------------------------------
    | GraphQL Response Path Configuration
    |--------------------------------------------------------------------------
    |
    | These paths define where to find data in the GraphQL response.
    | Use dot notation (e.g., 'data.categoryPageData.products').
    |
    */
    'graphql_root_field' => env('LULULEMON_GRAPHQL_ROOT_FIELD', 'categoryPageData'),

    'graphql_product_path' => env('LULULEMON_GRAPHQL_PRODUCT_PATH', 'data.categoryPageData.products'),

    'graphql_total_pages_path' => env('LULULEMON_GRAPHQL_TOTAL_PAGES_PATH', 'data.categoryPageData.totalProductPages'),

    'graphql_current_page_path' => env('LULULEMON_GRAPHQL_CURRENT_PAGE_PATH', 'data.categoryPageData.currentPage'),
];

