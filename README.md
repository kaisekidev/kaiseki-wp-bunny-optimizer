# kaiseki/wp-bunny-optimizer

Rewrite WordPress attachment image URLs into Bunny Optimizer (bunny.net) requests, mapping width,
height, aspect ratio, quality and color adjustments to CDN query parameters.

A single `kaiseki/wp-hook` `HookProviderInterface` (`BunnyOptimizer`) that filters
`wp_get_attachment_image_attributes`, `wp_get_attachment_image` and `wp_prepare_attachment_for_js` so
attachment image `src`/`srcset` URLs are rewritten with Bunny Optimizer query parameters
(`width`, `height`, `aspect_ratio`, `quality`, `sharpen`, `blur`, `brightness`, `saturation`, `hue`,
`gamma`, `contrast`, `auto_optimize`). Image dimensions encoded in the file name are detected and
forwarded automatically.

## Installation

```bash
composer require kaiseki/wp-bunny-optimizer
```

Requires PHP 8.2 or newer.

## Usage

Register `ConfigProvider` with your laminas-style config aggregator and activate the provider via
`kaiseki/wp-hook`:

```php
use Kaiseki\WordPress\BunnyOptimizer\BunnyOptimizer;

return [
    'hook' => [
        'provider' => [
            BunnyOptimizer::class,
        ],
    ],
];
```

With the provider active, pass Bunny Optimizer parameters through the `bunny` key of the attachment
image attributes — for example via `wp_get_attachment_image()`:

```php
echo wp_get_attachment_image($attachmentId, 'large', false, [
    'bunny' => [
        'quality'       => 80,
        'aspect_ratio'  => '16:9',
        'sharpen'       => true,
        'auto_optimize' => 'medium',
    ],
]);
```

The resulting `src` and `srcset` URLs are rewritten to Bunny Optimizer requests, e.g.
`https://cdn.example.com/image.jpg?width=1024&height=768&quality=80`.

## Development

```bash
composer install
composer check   # check-deps, cs-check, phpstan
```

## License

MIT — see [LICENSE](LICENSE.md).
