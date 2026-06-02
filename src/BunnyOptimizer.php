<?php

declare(strict_types=1);

namespace Kaiseki\WordPress\BunnyOptimizer;

use Kaiseki\WordPress\Hook\HookProviderInterface;
use WP_HTML_Tag_Processor;
use WP_Post;

use function add_filter;
use function array_filter;
use function array_keys;
use function array_map;
use function count;
use function explode;
use function floor;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function parse_url;
use function pathinfo;
use function preg_replace;
use function sprintf;
use function str_replace;
use function wp_get_attachment_metadata;

use const PHP_URL_HOST;

final class BunnyOptimizer implements HookProviderInterface
{
    public const WIDTH = 'width';
    public const HEIGHT = 'height';
    public const ASPECT_RATIO = 'aspect_ratio';
    public const QUALITY = 'quality';
    public const SHARPEN = 'sharpen';
    public const BLUR = 'blur';
    public const BRIGHTNESS = 'brightness';
    public const SATURATION = 'saturation';
    public const HUE = 'hue';
    public const GAMMA = 'gamma';
    public const CONTRAST = 'contrast';
    public const AUTO_OPTIMIZE = 'auto_optimize';

    public function __construct(
        private readonly string $cdnHost = 'cdn.woda.dev',
    ) {
    }

    public function addHooks(): void
    {
        add_filter('wp_get_attachment_image_attributes', [$this, 'filterAttributes'], 10, 2);
        add_filter('wp_get_attachment_image', [$this, 'filterHtml']);
        add_filter('wp_prepare_attachment_for_js', [$this, 'prepareAttachmentForJs']);
    }

    /**
     * @param array<array-key, mixed> $attr
     * @param WP_Post                 $attachment
     *
     * @return array<array-key, mixed>
     */
    public function filterAttributes(array $attr, WP_Post $attachment): array
    {
        $meta = wp_get_attachment_metadata($attachment->ID);

        if (!is_array($meta) || !isset($meta['file']) || !is_string($meta['file'])) {
            return $attr;
        }

        $file = $meta['file'];
        $bunny = $attr['bunny'] ?? null;
        $bunnyParams = is_array($bunny) ? $this->getBunnyParams($bunny, true) : [];

        $src = $attr['src'] ?? null;
        if (is_string($src)) {
            $attr['src'] = $this->buildUrl($src, $file, $bunnyParams);
        }

        $srcset = $attr['srcset'] ?? null;
        if (is_string($srcset)) {
            $attr['srcset'] = $this->filterSrcset($srcset, $file, $bunnyParams);
        }

        $width = $meta['width'] ?? null;
        $height = $meta['height'] ?? null;

        if (isset($bunnyParams[self::ASPECT_RATIO]) && is_int($width) && is_int($height)) {
            $attr = $this->addBunnyDimensions($attr, $width, $height, $bunnyParams[self::ASPECT_RATIO]);
        }

        unset($attr['bunny']);

        return $attr;
    }

    public function filterHtml(string $html): string
    {
        $processor = new WP_HTML_Tag_Processor($html);
        $processor->next_tag(['tag_name' => 'img']);
        $width = $processor->get_attribute('bunny-width');
        $height = $processor->get_attribute('bunny-height');

        if (is_string($width)) {
            $processor->set_attribute('width', $width);
            $processor->remove_attribute('bunny-width');
        }

        if (is_string($height)) {
            $processor->set_attribute('height', $height);
            $processor->remove_attribute('bunny-height');
        }

        return $processor->get_updated_html();
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    public function prepareAttachmentForJs(array $response): array
    {
        $sizes = $response['sizes'] ?? null;
        $responseUrl = $response['url'] ?? null;

        if (!is_array($sizes) || !is_string($responseUrl)) {
            return $response;
        }

        foreach ($sizes as $name => $size) {
            if (!is_array($size) || !isset($size['url']) || !is_string($size['url'])) {
                continue;
            }
            $url = $this->buildUrl($size['url'], $responseUrl);
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host)) {
                continue;
            }
            $size['url'] = str_replace($host, $this->cdnHost, $url);
            $sizes[$name] = $size;
        }

        $response['sizes'] = $sizes;

        return $response;
    }

    /**
     * @param string                $srcset
     * @param string                $file
     * @param array<string, string> $bunnyParams
     */
    private function filterSrcset(string $srcset, string $file, array $bunnyParams = []): string
    {
        $sets = array_map(
            static fn(string $set): array => explode(' ', $set),
            explode(', ', $srcset)
        );

        foreach ($sets as $index => $set) {
            $sets[$index][0] = $this->buildUrl($set[0], $file, $bunnyParams);
        }

        return implode(', ', array_map(static fn(array $set): string => implode(' ', $set), $sets));
    }

    /**
     * @param string                $url
     * @param string                $file
     * @param array<string, string> $bunnyParams
     */
    private function buildUrl(string $url, string $file, array $bunnyParams = []): string
    {
        $pathInfo = pathinfo($url);
        $fileInfo = pathinfo($file);

        if (
            !isset($pathInfo['dirname'])
            || !isset($fileInfo['extension'])
        ) {
            return $url;
        }

        $dimensions = $this->getDimensionsFromUrl($pathInfo['filename']);

        $imageParams = $bunnyParams;

        if ($dimensions !== null && !isset($imageParams[self::ASPECT_RATIO])) {
            $imageParams = [
                self::HEIGHT => $dimensions[1],
                ...$imageParams,
            ];
        }

        if ($dimensions !== null) {
            $imageParams = [
                self::WIDTH => $dimensions[0],
                ...$imageParams,
            ];
        }

        $queryParams = $this->buildQuery($imageParams);

        return sprintf(
            '%s/%s.%s%s%s',
            $pathInfo['dirname'],
            $fileInfo['filename'],
            $fileInfo['extension'],
            $queryParams !== '' ? '?' : '',
            $queryParams,
        );
    }

    /**
     * @param array<array-key, mixed> $attr
     * @param int                     $width
     * @param int                     $height
     * @param string                  $aspectRatio
     *
     * @return array<array-key, mixed>
     */
    private function addBunnyDimensions(array $attr, int $width, int $height, string $aspectRatio): array
    {
        [$x, $y] = array_map(
            static fn(string $value): int => (int)$value,
            explode(':', $aspectRatio),
        );

        if ($width / $x > $height / $y) {
            $attr['bunny-width'] = (string)floor($width * $height / $width);
            $attr['bunny-height'] = (string)$height;
        } else {
            $attr['bunny-width'] = (string)$width;
            $attr['bunny-height'] = (string)floor($height * $width / $height);
        }

        return $attr;
    }

    /**
     * @param array<array-key, mixed> $attr
     * @param bool                    $withoutDimensions
     *
     * @return array<string, string>
     */
    private function getBunnyParams(array $attr, bool $withoutDimensions = false): array
    {
        return array_filter(
            [
                self::WIDTH => $withoutDimensions ? '' : $this->getWidth($attr),
                self::HEIGHT => $withoutDimensions || isset($attr['aspect_ratio']) ? '' : $this->getHeight($attr),
                self::ASPECT_RATIO => $this->getAspectRatio($attr),
                self::QUALITY => $this->getQuality($attr),
                self::SHARPEN => $this->getSharpen($attr),
                self::BLUR => $this->getBlur($attr),
                self::BRIGHTNESS => $this->getBrightness($attr),
                self::SATURATION => $this->getSaturation($attr),
                self::HUE => $this->getHue($attr),
                self::GAMMA => $this->getGamma($attr),
                self::CONTRAST => $this->getContrast($attr),
                self::AUTO_OPTIMIZE => $this->getAutomaticOptimization($attr),
            ],
            static fn(string $value): bool => $value !== '',
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function buildQuery(array $params): string
    {
        return implode(
            '&',
            array_map(
                static fn(string $key, string $value): string => $key . '=' . $value,
                array_keys($params),
                $params,
            ),
        );
    }

    /**
     * @param string $filename
     *
     * @return array{0: string, 1: string}|null
     */
    private function getDimensionsFromUrl(string $filename): ?array
    {
        $size = preg_replace('/.*-(\d+x\d+)$/', '$1', $filename);

        if (!is_string($size)) {
            return null;
        }

        $dimensions = explode('x', $size);

        return count($dimensions) === 2 && is_numeric($dimensions[0]) && is_numeric($dimensions[1])
            ? [$dimensions[0], $dimensions[1]]
            : null;
    }

    /**
     * @param array<array-key, mixed> $attr
     */
    private function getWidth(array $attr): string
    {
        return $this->numericString($attr[self::WIDTH] ?? null, 1);
    }

    /**
     * @param array<array-key, mixed> $attr
     */
    private function getHeight(array $attr): string
    {
        return $this->numericString($attr[self::HEIGHT] ?? null, 1);
    }

    /**
     * @param array<array-key, mixed> $attr
     */
    private function getAspectRatio(array $attr): string
    {
        $value = $attr[self::ASPECT_RATIO] ?? null;

        if (!is_string($value) || $value === '') {
            return '';
        }

        $values = explode(':', $value);

        if (
            count($values) !== 2
            || !is_numeric($values[0])
            || !is_numeric($values[1])
        ) {
            return '';
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $attr
     */
    private function getQuality(array $attr): string
    {
        return $this->numericString($attr[self::QUALITY] ?? null, 0, 100);
    }

    /**
     * @param array<array-key, mixed> $attr
     */
    private function getSharpen(array $attr): string
    {
        $value = $attr[self::SHARPEN] ?? null;

        if (!is_bool($value)) {
            return '';
        }

        return $value === true ? 'true' : 'false';
    }

    /**
     * @param array<array-key, mixed> $attr
     */
    private function getBlur(array $attr): string
    {
        return $this->numericString($attr[self::BLUR] ?? null, 0, 100);
    }

    /**
     * @param array<array-key, mixed> $attr
     */
    private function getBrightness(array $attr): string
    {
        return $this->numericString($attr[self::BRIGHTNESS] ?? null, -100, 100);
    }

    /**
     * @param array<array-key, mixed> $attr
     */
    private function getSaturation(array $attr): string
    {
        return $this->numericString($attr[self::SATURATION] ?? null, -100, 100);
    }

    /**
     * @param array<array-key, mixed> $attr
     */
    private function getHue(array $attr): string
    {
        return $this->numericString($attr[self::HUE] ?? null, 0, 100);
    }

    /**
     * @param array<array-key, mixed> $attr
     */
    private function getGamma(array $attr): string
    {
        return $this->numericString($attr[self::GAMMA] ?? null, -100, 100);
    }

    /**
     * @param array<array-key, mixed> $attr
     */
    private function getContrast(array $attr): string
    {
        return $this->numericString($attr[self::CONTRAST] ?? null, -100, 100);
    }

    /**
     * @param array<array-key, mixed> $attr
     */
    private function getAutomaticOptimization(array $attr): string
    {
        $value = $attr[self::AUTO_OPTIMIZE] ?? null;

        if (
            $value !== 'low'
            && $value !== 'medium'
            && $value !== 'high'
        ) {
            return '';
        }

        return $value;
    }

    private function numericString(mixed $value, ?int $min = null, ?int $max = null): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        $number = (int)$value;

        if ($min !== null && $number < $min) {
            return '';
        }

        if ($max !== null && $number > $max) {
            return '';
        }

        return (string)$value;
    }
}
