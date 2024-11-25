<?php

declare(strict_types=1);

namespace Kaiseki\WordPress\BunnyOptimizer;

use Kaiseki\WordPress\Hook\HookProviderInterface;

use function add_filter;
use function array_filter;
use function array_keys;
use function array_map;
use function count;
use function explode;
use function floor;
use function implode;
use function is_bool;
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

    public function addHooks(): void
    {
        add_filter('wp_get_attachment_image_attributes', [$this, 'filterAttributes'], 10, 2);
        add_filter('wp_get_attachment_image', [$this, 'filterHtml']);
        add_filter('wp_prepare_attachment_for_js', [$this, 'prepareAttachmentForJs']);
    }

    public function filterAttributes(array $attr, WP_Post $attachment): array
    {
        $meta = wp_get_attachment_metadata($attachment->ID);

        if ($meta === false) {
            return $attr;
        }

        $bunnyParams = isset($attr['bunny']) ? $this->getBunnyParams($attr['bunny'], true) : [];

        $attr['src'] = $this->buildUrl($attr['src'], $meta['file'], $bunnyParams);

        if (isset($attr['srcset'])) {
            $attr['srcset'] = $this->filterSrcset($attr['srcset'], $meta['file'], $bunnyParams);
        }

        if (isset($bunnyParams[self::ASPECT_RATIO])) {
            $attr = $this->addBunnyDimensions($attr, $meta['width'], $meta['height'], $bunnyParams[self::ASPECT_RATIO]);
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

        if ($width) {
            $processor->set_attribute('width', $width);
            $processor->remove_attribute('bunny-width');
        }

        if ($height) {
            $processor->set_attribute('height', $height);
            $processor->remove_attribute('bunny-height');
        }

        return $processor->get_updated_html();
    }

    public function prepareAttachmentForJs(array $response): array
    {
        if (!isset($response['sizes'])) {
            return $response;
        }

        foreach ($response['sizes'] as $name => $size) {
            $url = $this->buildUrl($size['url'], $response['url']);
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host)) {
                continue;
            }
            $url = str_replace($host, 'cdn.woda.dev', $url);
            $response['sizes'][$name]['url'] = $url;
        }

        return $response;
    }

    private function filterSrcset(string $srcset, string $file, array $bunnyParams = []): string
    {
        $sets = array_map(
            fn($set) => explode(' ', $set),
            explode(', ', $srcset)
        );

        foreach ($sets as $index => $set) {
            $sets[$index][0] = $this->buildUrl($set[0], $file, $bunnyParams);
        }

        return implode(', ', array_map(fn($set) => implode(' ', $set), $sets));
    }

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

        if ($dimensions && !isset($imageParams[self::ASPECT_RATIO])) {
            $imageParams = [
                self::HEIGHT => $dimensions[1],
                ...$imageParams,
            ];
        }

        if ($dimensions) {
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
            $queryParams ? '?' : '',
            $queryParams,
        );
    }

    private function addBunnyDimensions(array $attr, int $width, int $height, string $aspectRatio): array
    {
        [$x, $y] = array_map(
            fn($value) => (int)$value,
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

    private function getBunnyParams(array $attr, bool $withoutDimensions = false): array
    {
        return array_filter([
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
        ]);
    }

    private function buildQuery(array $params): string
    {
        return implode('&', array_map(fn($key, $value) => $key . '=' . $value, array_keys($params), $params));
    }

    private function getDimensionsFromUrl(string $filename): ?array
    {
        $size = preg_replace('/.*-(\d+x\d+)$/', '$1', $filename);

        $dimensions = explode('x', $size);

        return count($dimensions) === 2 && is_numeric($dimensions[0]) && is_numeric($dimensions[1])
            ? $dimensions
            : null;
    }

    private function getWidth(array $attr): string
    {
        $value = $attr[self::WIDTH] ?? null;

        return $this->isNumeric($value, 1) ? (string)$value : '';
    }

    private function getHeight(array $attr): string
    {
        $value = $attr[self::HEIGHT] ?? null;

        return $this->isNumeric($value, 1) ? (string)$value : '';
    }

    private function getAspectRatio(array $attr): string
    {
        $value = $attr[self::ASPECT_RATIO] ?? null;

        if (!$value) {
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

    private function getQuality(array $attr): string
    {
        $value = $attr[self::QUALITY] ?? null;

        return $this->isNumeric($value, 0, 100) ? (string)$value : '';
    }

    private function getSharpen(array $attr): string
    {
        $value = $attr[self::SHARPEN] ?? null;

        if (!is_bool($value)) {
            return '';
        }

        return $value === true ? 'true' : 'false';
    }

    private function getBlur(array $attr): string
    {
        $value = $attr[self::BLUR] ?? null;

        return $this->isNumeric($value, 0, 100) ? (string)$value : '';
    }

    private function getBrightness(array $attr): string
    {
        $value = $attr[self::BRIGHTNESS] ?? null;

        return $this->isNumeric($value, -100, 100) ? (string)$value : '';
    }

    private function getSaturation(array $attr): string
    {
        $value = $attr[self::SATURATION] ?? null;

        return $this->isNumeric($value, -100, 100) ? (string)$value : '';
    }

    private function getHue(array $attr): string
    {
        $value = $attr[self::HUE] ?? null;

        return $this->isNumeric($value, 0, 100) ? (string)$value : '';
    }

    private function getGamma(array $attr): string
    {
        $value = $attr[self::GAMMA] ?? null;

        return $this->isNumeric($value, -100, 100) ? (string)$value : '';
    }

    private function getContrast(array $attr): string
    {
        $value = $attr[self::CONTRAST] ?? null;

        return $this->isNumeric($value, -100, 100) ? (string)$value : '';
    }

    private function getAutomaticOptimization(array $attr): string
    {
        $value = $attr[self::AUTO_OPTIMIZE] ?? null;

        if (
            !$value
            || (
                $value !== 'low'
                && $value !== 'medium'
                && $value !== 'high'
            )
        ) {
            return '';
        }

        return $value;
    }

    private function isNumeric(mixed $value, ?int $min = null, ?int $max = null): bool
    {
        if (
            !$value
            || !is_numeric($value)
        ) {
            return false;
        }

        if ($min !== null && (int)$value < $min) {
            return false;
        }

        return $max === null || (int)$value <= $max;
    }
}
