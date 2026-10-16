<?php

namespace App\Services\StoreCatalog;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Support\ProductLandingHtml;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductLandingPages
{
    public const TYPES = ['slider', 'image', 'banner', 'youtube', 'simple', 'advanced', 'heading', 'rich_text', 'html', 'checkout', 'faq', 'store_features', 'custom_reviews', 'promo_banners', 'countdown', 'video', 'gallery', 'how_to', 'content', 'buy_button', 'product_features', 'reviews', 'product_description', 'billboard'];

    public static function empty(): array
    {
        return ['schema' => 1, 'mode' => 'standard', 'sections' => []];
    }

    public static function document(Product $product): array
    {
        return $product->landing_page ?? self::empty();
    }

    public static function version(Product $product): string
    {
        return self::hash(self::document($product));
    }

    private static function hash(array $document): string
    {
        return hash('sha256', json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function canonical(array $document, ?Product $product = null): array
    {
        // Laravel turns cleared JSON text inputs into null. Retain an explicit empty
        // value instead of making optional editor fields impossible to clear.
        if (is_array($document['sections'] ?? null)) {
            foreach ($document['sections'] as &$block) {
                if (! is_array($block) || ! is_array($block['settings'] ?? null)) {
                    continue;
                }
                foreach (['title', 'description', 'html', 'css', 'button_text', 'video'] as $field) {
                    if (array_key_exists($field, $block['settings']) && $block['settings'][$field] === null) {
                        $block['settings'][$field] = '';
                    }
                }
                if (is_array($block['settings']['items'] ?? null)) {
                    foreach ($block['settings']['items'] as &$item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        foreach (['title', 'text', 'html', 'author', 'caption', 'platform', 'url', 'icon'] as $field) {
                            if (array_key_exists($field, $item) && $item[$field] === null) {
                                $item[$field] = '';
                            }
                        }
                    }
                    unset($item);
                }
            }
            unset($block);
        }
        $rules = ['schema' => ['required', 'integer', 'in:1'], 'mode' => ['required', 'in:standard,standalone,below'], 'sections' => ['present', 'array', 'list', 'max:60'],
            'sections.*' => ['array:id,type,settings'], 'sections.*.id' => ['required', 'uuid', 'distinct:ignore_case'], 'sections.*.type' => ['required', Rule::in(self::TYPES)],
            'sections.*.settings' => ['present', 'array:title,description,html,image,background_image,images,video,button_text,button_target,background,foreground,accent,card_background,question_background,variant,align,layout,items,hours,ends_at,rating,css,padding,radius,font_size,hover_background,hover_foreground,secondary_foreground,hover_secondary_foreground,icon_background,icon_color,motion'],
            'sections.*.settings.variant' => ['sometimes', 'string', Rule::in([...array_map(fn ($id) => 'testimonials-'.$id, range(1, 11)), ...array_map(fn ($id) => 'banners-'.$id, range(1, 9)), ...array_map(fn ($id) => 'features-'.$id, range(1, 45))])],
            'sections.*.settings.motion' => ['sometimes', 'boolean'],
            'sections.*.settings.title' => ['sometimes', 'string', 'max:300'], 'sections.*.settings.description' => ['sometimes', 'string', 'max:2000'],
            'sections.*.settings.html' => ['sometimes', 'string', 'max:20000'], 'sections.*.settings.css' => ['sometimes', 'string', 'max:12000'],
            'sections.*.settings.button_text' => ['sometimes', 'string', 'max:120'], 'sections.*.settings.button_target' => ['sometimes', 'in:purchase,product'],
            'sections.*.settings.align' => ['sometimes', 'in:start,center,end'], 'sections.*.settings.layout' => ['sometimes', 'in:stack,split,reverse,tiles,columns,overlay,carousel,grid'],
            'sections.*.settings.video' => ['sometimes', 'string', 'max:2000'], 'sections.*.settings.hours' => ['sometimes', 'integer', 'between:1,720'],
            'sections.*.settings.ends_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:s\Z'], 'sections.*.settings.rating' => ['sometimes', 'numeric', 'between:0,5'],
            'sections.*.settings.padding' => ['sometimes', 'integer', 'between:0,160'], 'sections.*.settings.radius' => ['sometimes', 'integer', 'between:0,48'], 'sections.*.settings.font_size' => ['sometimes', 'integer', 'between:12,96'],
            'sections.*.settings.images' => ['sometimes', 'array', 'list', 'max:30'], 'sections.*.settings.items' => ['sometimes', 'array', 'list', 'max:30'],
            'sections.*.settings.items.*' => ['array:title,text,html,image,url,icon,rating,author,caption,date,platform,destination,accent,background,foreground'], 'sections.*.settings.items.*.title' => ['sometimes', 'string', 'max:300'],
            'sections.*.settings.items.*.destination' => ['sometimes', 'array:type,id', 'required_array_keys:type,id'], 'sections.*.settings.items.*.destination.type' => ['required_with:sections.*.settings.items.*.destination', 'in:product,category,page'], 'sections.*.settings.items.*.destination.id' => ['required_with:sections.*.settings.items.*.destination', 'integer', 'min:1'],
            'sections.*.settings.items.*.author' => ['sometimes', 'string', 'max:150'], 'sections.*.settings.items.*.caption' => ['sometimes', 'string', 'max:200'],
            'sections.*.settings.items.*.date' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'sections.*.settings.items.*.platform' => ['sometimes', 'string', 'max:40'],
            'sections.*.settings.items.*.text' => ['sometimes', 'string', 'max:6000'], 'sections.*.settings.items.*.html' => ['sometimes', 'string', 'max:6000'],
            'sections.*.settings.items.*.url' => ['sometimes', 'string', 'max:2000'], 'sections.*.settings.items.*.icon' => ['sometimes', 'string', 'max:40'], 'sections.*.settings.items.*.rating' => ['sometimes', 'numeric', 'between:0,5']];
        foreach (['background', 'foreground', 'accent', 'card_background', 'question_background', 'hover_background', 'hover_foreground', 'secondary_foreground', 'hover_secondary_foreground', 'icon_background', 'icon_color'] as $field) {
            $rules['sections.*.settings.'.$field] = ['sometimes', 'string', 'regex:/^(#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})|transparent)$/i'];
        }
        foreach (['accent', 'background', 'foreground'] as $field) {
            $rules['sections.*.settings.items.*.'.$field] = ['sometimes', 'string', 'regex:/^(#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})|transparent)$/i'];
        }
        foreach (['sections.*.settings.image', 'sections.*.settings.background_image', 'sections.*.settings.images.*', 'sections.*.settings.items.*.image'] as $path) {
            $rules[$path] = ['sometimes', 'array:media_id,url,alt'];
            $rules[$path.'.media_id'] = ['sometimes', 'integer', 'min:1'];
            $rules[$path.'.url'] = ['sometimes', 'string', 'max:2000'];
            $rules[$path.'.alt'] = ['sometimes', 'nullable', 'string', 'max:300'];
        }
        $data = Validator::make($document, $rules)->validate();
        if (array_diff(array_keys($document), ['schema', 'mode', 'sections']) !== [] || strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 750000) {
            throw ValidationException::withMessages(['document' => 'A landing page supports its schema, mode and sections within 750 KB.']);
        }
        foreach ($data['sections'] as $index => &$section) {
            $section['id'] = strtolower($section['id']);
            $settings = &$section['settings'];
            $family = isset($settings['variant']) ? match (true) {
                str_starts_with($settings['variant'], 'testimonials-') => 'custom_reviews',
                str_starts_with($settings['variant'], 'features-') => 'store_features',
                default => 'promo_banners',
            } : null;
            if ($family !== null && $section['type'] !== $family) {
                throw ValidationException::withMessages(['document.sections.'.$index.'.settings.variant' => 'Choose a design belonging to this section family.']);
            }
            if (isset($settings['motion'])) {
                $settings['motion'] = (bool) $settings['motion'];
            }
            foreach (['hours', 'padding', 'radius', 'font_size'] as $field) {
                if (isset($settings[$field])) {
                    $settings[$field] = (int) $settings[$field];
                }
            }
            foreach (['html'] as $field) {
                if (isset($settings[$field])) {
                    $settings[$field] = ProductLandingHtml::clean($settings[$field]);
                }
            }
            if (str_contains($settings['css'] ?? '', "\0")) {
                throw ValidationException::withMessages(['document.sections.'.$index.'.settings.css' => 'CSS cannot contain null characters.']);
            }
            if (isset($settings['video']) && $settings['video'] !== '' && ! self::video($settings['video'])) {
                throw ValidationException::withMessages(['document.sections.'.$index.'.settings.video' => 'Use an MP4 URL or a YouTube or Vimeo video link.']);
            }
            if (isset($settings['image'])) {
                $settings['image'] = self::image($settings['image'], $product);
            }
            if (isset($settings['background_image'])) {
                $settings['background_image'] = self::image($settings['background_image'], $product);
            }
            foreach ($settings['images'] ?? [] as $key => $image) {
                $settings['images'][$key] = self::image($image, $product);
            }
            foreach ($settings['items'] ?? [] as $key => $item) {
                if (isset($item['destination'])) {
                    if ($section['type'] !== 'promo_banners' || ! $product) {
                        throw ValidationException::withMessages(['document' => 'Destinations belong to promotional banners.']);
                    }
                    $item['destination'] = ProductLandingDestinations::canonical($item['destination'], $product);
                    unset($item['url']);
                }
                if (isset($item['rating'])) {
                    $item['rating'] = (float) $item['rating'];
                }
                if (isset($item['image'])) {
                    $item['image'] = self::image($item['image'], $product);
                }
                if (isset($item['html'])) {
                    $item['html'] = ProductLandingHtml::clean($item['html']);
                }
                if (! empty($item['url']) && ! self::url($item['url'])) {
                    throw ValidationException::withMessages(['document' => 'Use an HTTP(S) or relative link.']);
                }
                ksort($item);
                $settings['items'][$key] = $item;
            }
            ksort($settings);
            if (isset($settings['rating'])) {
                $settings['rating'] = (float) $settings['rating'];
            }
        }
        unset($section, $settings);
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 750000) {
            throw ValidationException::withMessages(['document' => 'The saved landing page must stay within 750 KB.']);
        }

        return ['schema' => 1, 'mode' => $data['mode'], 'sections' => $data['sections']];
    }

    private static function image(array $image, ?Product $product): array
    {
        if (array_key_exists('alt', $image) && $image['alt'] === null) {
            $image['alt'] = '';
        }
        if (isset($image['media_id'])) {
            if (! $product || ! ProductMedia::query()->where('store_id', $product->store_id)->where('store_product_id', $product->id)->whereKey($image['media_id'])->where('type', '!=', 'video')->exists()) {
                throw ValidationException::withMessages(['document' => 'Every selected image must belong to this product.']);
            }
            unset($image['url']);
        } elseif (! isset($image['url']) || str_starts_with($image['url'], '#') || ! self::url($image['url'])) {
            throw ValidationException::withMessages(['document' => 'Choose a product image or a valid image URL.']);
        }
        ksort($image);

        return $image;
    }

    public static function url(string $url): bool
    {
        return preg_match('~^(?:https?://[^\s<>"\x00-\x1f]+|/(?!/)[^\s<>"\x00-\x1f]*|\#[a-zA-Z0-9_-]+)$~D', $url) === 1;
    }

    public static function video(string $url): bool
    {
        if (! self::url($url)) {
            return false;
        }
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        return preg_match('/\.mp4(?:[?#]|$)/i', $url) === 1 || in_array($host, ['youtube.com', 'www.youtube.com', 'youtu.be', 'www.youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true);
    }

    public static function save(Product $product, array $document, string $expected): Product
    {
        $canonical = self::canonical($document, $product);
        $version = self::version($product);
        abort_unless(hash_equals($version, $expected) || hash_equals($version, self::hash($canonical)), 409, 'The product landing page changed. Reload before saving.');
        if (! hash_equals($version, self::hash($canonical))) {
            $product->landing_page = $canonical;
            $product->save();
        }

        return $product;
    }

    public static function mode(Product $product, string $mode, string $expected): void
    {
        $document = self::document($product);
        $document['mode'] = $mode;
        self::save($product, $document, $expected);
    }

    public static function hasCheckout(Product $product): bool
    {
        $document = self::document($product);

        return $document['mode'] !== 'standard' && in_array('checkout', array_column($document['sections'], 'type'), true);
    }

    public static function publicPayload(Product $product): ?array
    {
        if (! $product->landing_page || self::document($product)['mode'] === 'standard' || self::document($product)['sections'] === []) {
            return null;
        }

        return self::portablePayload($product);
    }

    public static function portablePayload(Product $product): ?array
    {
        if (! $product->landing_page) {
            return null;
        }
        $document = self::document($product);
        $media = $product->media()->where('store_id', $product->store_id)->get()->keyBy('id');
        $resolve = function (array $image) use ($media): array {
            if (isset($image['media_id'])) {
                $image['url'] = $media->get($image['media_id'])?->url() ?? '';
                unset($image['media_id']);
            }

            return $image;
        };
        $links = [];
        foreach ($document['sections'] as &$section) {
            $settings = &$section['settings'];
            if (isset($settings['image'])) {
                $settings['image'] = $resolve($settings['image']);
            }
            if (isset($settings['background_image'])) {
                $settings['background_image'] = $resolve($settings['background_image']);
            }
            foreach ($settings['images'] ?? [] as $key => $image) {
                $settings['images'][$key] = $resolve($image);
            }
            foreach ($settings['items'] ?? [] as $key => $item) {
                if (isset($item['destination'])) {
                    $linkKey = $item['destination']['type'].':'.$item['destination']['id'];
                    if (! array_key_exists($linkKey, $links)) {
                        $links[$linkKey] = ProductLandingDestinations::url((int) $product->store_id, $item['destination']);
                    }
                    unset($settings['items'][$key]['destination'], $settings['items'][$key]['url']);
                    if ($links[$linkKey] !== null) {
                        $settings['items'][$key]['url'] = $links[$linkKey];
                    }
                }
                if (isset($item['image'])) {
                    $settings['items'][$key]['image'] = $resolve($item['image']);
                }
            }
        }

        return $document;
    }

    public static function referencesMedia(Product $product, array $ids): bool
    {
        $walk = function (mixed $value) use (&$walk, $ids): bool {
            if (! is_array($value)) {
                return false;
            } if (isset($value['media_id']) && in_array((int) $value['media_id'], $ids, true)) {
                return true;
            } foreach ($value as $child) {
                if ($walk($child)) {
                    return true;
                }
            }

            return false;
        };
        if ($walk($product->landing_page ?? [])) {
            return true;
        }
        $text = json_encode($product->landing_page ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($product->media()->whereIn('id', $ids)->get() as $media) {
            $url = $media->url();
            $path = parse_url($url, PHP_URL_PATH);
            if (($url && str_contains($text, $url)) || ($path && str_contains($text, $path))) {
                return true;
            }
        }

        return false;
    }
}
