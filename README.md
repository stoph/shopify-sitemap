# Shopify Product Sitemap Generator

This script generates a sitemap.xml file for Shopify products using the public products.json endpoint.

## Requirements

- PHP 8.2 or higher
- Composer
- JSON extension
- SimpleXML extension

## Installation

1. Clone this repository
2. Install dependencies:
```bash
composer install
```

## Usage

Run the script:
```bash
php generate-sitemap.php
```

## Configuration

You can modify the following configuration values in `config.php`:

### Store Settings
- `store.shopify_domain`: Your Shopify store URL
- `store.product_url_template`: Your product detail page URL pattern (uses `<id>` placeholder)
- `store.password`: Optional password if store is password protected

### Cache Settings
- `cache.dir`: Directory to store cached API responses
- `cache.ttl`: Cache time-to-live in seconds

### API Settings
- `api.limit`: Number of products to fetch per page
- `api.start_page`: Page number to start from
- `api.rate_limit`: Delay between requests in seconds
- `api.max_pages`: Optional limit to number of pages to process

### XML Settings
- `xml.namespaces`: XML namespace definitions for sitemap and image elements

## Output

The script will:
- Generate a sitemap.xml file in the current directory
- Cache API responses in the configured cache directory
- Handle password-protected stores
- Include product images and titles in the sitemap
- Rate limit requests to prevent API throttling
- Clean up expired cache files automatically

The script generates a sitemap.xml file containing:
- Product URLs (based on product_url_template)
- Last modified dates (ISO 8601 format)
- First product image for each product
- Image titles (properly escaped)

## Error Handling

The script will:
- Validate the store URL before proceeding
- Create the cache directory if it doesn't exist
- Verify API responses contain valid JSON
- Check for proper content type responses
- Handle password-protected store authentication
- Log all operations with severity levels 