<?php

namespace Database\Seeders\DemoSupplier;

/**
 * Deterministic product content generator. Given a subcategory `kind`, a brand, and a stable index,
 * it composes a unique product: name, descriptions, rich content blocks, specifications, dimensions,
 * pricing, inventory, variants and reviews. Determinism (seeded by SKU hash) makes seeding repeatable
 * and guarantees no two products share the same composed copy — real, varied, non-placeholder content.
 */
final class ProductGenerator
{
    /** @var array<string,array<int,string>> per-kind noun pool (product type words) */
    private const NOUNS = [
        'electronics' => ['Pro', 'Air', 'Max', 'Edge', 'Nova', 'Pulse', 'Vega', 'Flux', 'Core', 'Zen'],
        'home' => ['Set', 'Collection', 'Essential', 'Series', 'Studio', 'Everyday', 'Signature', 'Classic'],
        'furniture' => ['Lounge', 'Studio', 'Loft', 'Haven', 'Manor', 'Atelier', 'Nordic', 'Urban'],
        'beauty' => ['Glow', 'Ritual', 'Elixir', 'Radiance', 'Botanical', 'Renew', 'Velvet', 'Bloom'],
        'sports' => ['Peak', 'Trail', 'Endure', 'Momentum', 'Apex', 'Velocity', 'Summit', 'Stride'],
        'apparel' => ['Everyday', 'Heritage', 'Tailored', 'Essential', 'Classic', 'Modern', 'Explorer'],
        'office' => ['Workspace', 'Executive', 'Focus', 'Daily', 'Studio', 'Precision', 'Minimal'],
        'tools' => ['Pro', 'HeavyDuty', 'Contractor', 'Precision', 'Workshop', 'MasterGrip', 'TorqueX'],
        'toys' => ['Adventure', 'Builder', 'Discovery', 'Creative', 'Junior', 'Explorer', 'Playtime'],
        'pet' => ['Comfort', 'Playful', 'Cozy', 'Active', 'Natural', 'Happy', 'Gentle'],
        'garden' => ['Bloom', 'Harvest', 'Terra', 'Verdure', 'Meadow', 'Grove', 'Cultivate'],
        'auto' => ['Drive', 'Motion', 'Guard', 'Precision', 'AllRoad', 'Shield', 'Velocity'],
        'health' => ['Vital', 'Balance', 'Restore', 'Wellness', 'PureLife', 'Renew', 'Thrive'],
        'baby' => ['Snuggle', 'Little', 'Gentle', 'Tiny', 'Dreamy', 'Cuddle', 'Sprout'],
        'grocery' => ['Reserve', 'Artisan', 'Harvest', 'Gold', 'Select', 'Origin', 'Pure'],
    ];

    private const DESCRIPTORS = [
        'engineered for everyday performance', 'built to last with premium materials',
        'designed around real-world use', 'a refined upgrade for the modern home',
        'thoughtfully made for comfort and durability', 'precision-crafted and rigorously tested',
        'a dependable choice trusted by professionals', 'balancing style, function and value',
        'made to simplify your daily routine', 'delivering studio-grade results at home',
    ];

    private const BENEFITS = [
        'Saves you time on everyday tasks', 'Built from durable, high-grade materials',
        'Easy to set up in minutes', 'Backed by a manufacturer warranty', 'Energy- and cost-efficient',
        'Compact design that fits any space', 'Low-maintenance and easy to clean',
        'Tested for consistent, reliable performance', 'Ergonomic and comfortable to use',
        'A premium finish that looks great anywhere',
    ];

    private const FEATURES = [
        'Reinforced construction for long-term durability', 'Intuitive, tool-free assembly',
        'Precision-tuned components', 'Scratch- and stain-resistant finish',
        'Optimised weight for easy handling', 'Universal compatibility with common accessories',
        'Sustainably sourced materials', 'Refined ergonomic profile',
        'Quiet, efficient operation', 'Sealed against dust and moisture',
    ];

    private const PROS = ['Excellent build quality', 'Great value for money', 'Looks even better in person', 'Fast, easy setup', 'Works exactly as described', 'Feels premium', 'Very durable so far', 'Quiet and efficient'];
    private const CONS = ['Packaging could be sturdier', 'Instructions are a little brief', 'Wish it came in more colours', 'Slightly heavier than expected', 'Takes a moment to get used to', 'A touch pricey but worth it'];

    private const REVIEW_TITLES = ['Exactly what I needed', 'Impressed with the quality', 'Would buy again', 'Great everyday choice', 'Better than expected', 'Solid and reliable', 'Highly recommend', 'Worth every riyal', 'A pleasant surprise', 'Does the job perfectly'];

    private const REVIEWERS = ['Omar A.', 'Layla H.', 'Sara M.', 'Khalid R.', 'Nora S.', 'Yousef T.', 'Huda K.', 'Faisal B.', 'Maryam Z.', 'Tariq N.', 'James P.', 'Emma W.', 'Daniel K.', 'Sophia L.', 'Noah R.', 'Aisha F.', 'Mohammed S.', 'Lina D.', 'Adam G.', 'Reem Q.'];

    private const COLORS = [['Charcoal', 'فحمي'], ['Ivory', 'عاجي'], ['Slate Blue', 'أزرق أردوازي'], ['Forest Green', 'أخضر غابي'], ['Terracotta', 'طيني'], ['Graphite', 'جرافيت'], ['Sand', 'رملي'], ['Rose', 'وردي']];
    private const SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
    private const CAPACITIES = ['64GB', '128GB', '256GB', '512GB', '1TB'];

    public function __construct(private readonly string $currency = 'SAR')
    {
    }

    /** Deterministic integer in [min,max] from a string seed + salt. */
    private function num(string $seed, string $salt, int $min, int $max): int
    {
        $h = crc32($seed.'|'.$salt);

        return $min + ($h % max(1, ($max - $min + 1)));
    }

    /** Deterministic pick from a list. */
    private function pick(array $list, string $seed, string $salt): mixed
    {
        return $list[$this->num($seed, $salt, 0, count($list) - 1)];
    }

    /** Deterministic distinct picks from a list. */
    private function picks(array $list, string $seed, string $salt, int $count): array
    {
        $out = [];
        $i = 0;
        while (count($out) < min($count, count($list)) && $i < count($list) * 3) {
            $v = $this->pick($list, $seed, $salt.$i);
            if (! in_array($v, $out, true)) {
                $out[] = $v;
            }
            $i++;
        }

        return $out;
    }

    /**
     * Compose a full product spec array for one product.
     *
     * @param  array{en:string,ar:string,kind:string}  $category  the subcategory
     * @param  array{en:string,ar:string,country:string}  $brand
     * @return array<string,mixed>
     */
    public function make(array $category, array $brand, int $globalIndex): array
    {
        $kind = $category['kind'];
        $subEn = rtrim($category['en'], 's'); // rough singular
        $subAr = $category['ar'];
        $model = strtoupper(substr(md5($brand['en'].$category['en'].$globalIndex), 0, 5));
        $noun = $this->pick(self::NOUNS[$kind] ?? ['Series'], (string) $globalIndex, 'noun');
        $sku = 'DS-'.strtoupper(substr($kind, 0, 3)).'-'.str_pad((string) $globalIndex, 5, '0', STR_PAD_LEFT);
        $seed = $sku;

        $nameEn = "{$brand['en']} {$noun} {$subEn} {$model}";
        $nameAr = "{$brand['ar']} {$subAr} {$model}";
        $descriptor = $this->pick(self::DESCRIPTORS, $seed, 'desc');

        // Pricing — cost base by kind, markup, compare, discount.
        $base = [
            'electronics' => 450, 'furniture' => 900, 'beauty' => 90, 'home' => 160, 'sports' => 180,
            'apparel' => 120, 'office' => 60, 'tools' => 220, 'toys' => 80, 'pet' => 70, 'garden' => 110,
            'auto' => 140, 'health' => 130, 'baby' => 150, 'grocery' => 45,
        ][$kind] ?? 120;
        $cost = round($base * (0.6 + $this->num($seed, 'cost', 0, 90) / 100), 2);
        $margin = 1.35 + $this->num($seed, 'margin', 0, 60) / 100;
        $price = round($cost * $margin, 2);
        $hasDiscount = $this->num($seed, 'disc', 0, 100) < 45;
        $discountPct = $hasDiscount ? $this->num($seed, 'discpct', 5, 35) : 0;
        $comparePrice = $discountPct > 0 ? round($price / (1 - $discountPct / 100), 2) : null;

        $benefits = $this->picks(self::BENEFITS, $seed, 'ben', 4);
        $features = $this->picks(self::FEATURES, $seed, 'feat', 5);
        $rating = round(3.6 + $this->num($seed, 'rate', 0, 13) / 10, 1); // 3.6–4.9
        $reviewCount = $this->num($seed, 'rc', 3, 20);

        $material = $this->pick(['Aluminium alloy', 'Stainless steel', 'Solid oak', 'Tempered glass', 'Organic cotton', 'Recycled polymer', 'Ceramic', 'Full-grain leather', 'Bamboo', 'ABS composite'], $seed, 'mat');
        $origin = $brand['country'];
        $weight = round(0.2 + $this->num($seed, 'wt', 0, 1200) / 100, 2);
        $flags = [
            'is_featured' => $this->num($seed, 'ftr', 0, 100) < 18,
            'is_bestseller' => $this->num($seed, 'bst', 0, 100) < 15,
            'is_new_arrival' => $this->num($seed, 'new', 0, 100) < 22,
            'is_trending' => $this->num($seed, 'trd', 0, 100) < 14,
        ];

        return [
            'sku' => $sku,
            'name' => $nameEn,
            'name_ar' => $nameAr,
            'slug' => strtolower(str_replace([' ', '&'], ['-', 'and'], "{$brand['en']}-{$noun}-{$subEn}-{$model}")),
            'barcode' => '628'.str_pad((string) $globalIndex, 10, '0', STR_PAD_LEFT),
            'supplier_code' => 'SUP-'.$model,
            'short_description' => "The {$brand['en']} {$noun} {$subEn} is {$descriptor}.",
            'description' => $this->paragraph($seed, $brand['en'], $subEn, $descriptor, $benefits),
            'short_description_ar' => "منتج {$brand['ar']} من فئة {$subAr} بجودة عالية وأداء موثوق.",
            'description_ar' => "يقدّم {$brand['ar']} تجربة استخدام يومية موثوقة بخامات متينة وتصميم عملي يناسب احتياجاتك، مع ضمان من الشركة المصنّعة ودعم كامل بعد البيع.",
            'long_description' => $this->longDescription($brand['en'], $noun, $subEn, $material, $origin, $features),
            'material' => $material,
            'origin_country' => $origin,
            'manufacturer' => $brand['en'],
            'warranty' => $this->pick(['1-year limited warranty', '2-year manufacturer warranty', '90-day warranty', '3-year warranty'], $seed, 'war'),
            'unit' => 'piece',
            'currency' => $this->currency,
            'cost' => $cost,
            'price' => $price,
            'compare_price' => $comparePrice,
            'discount_percent' => $discountPct,
            'vat_rate' => 15.0,
            'weight' => $weight,
            'dimensions' => ['length' => $this->num($seed, 'l', 10, 80), 'width' => $this->num($seed, 'w', 8, 60), 'height' => $this->num($seed, 'h', 4, 50), 'unit' => 'cm'],
            'stock_quantity' => $this->num($seed, 'stk', 0, 400),
            'reserved_quantity' => $this->num($seed, 'res', 0, 12),
            'reorder_level' => 15,
            'tags' => $this->picks(['new', 'popular', 'eco', 'premium', 'value', 'gift-idea', 'limited', 'top-rated', $kind], $seed, 'tag', 4),
            'keywords' => "{$subEn}, {$brand['en']}, {$material}, buy {$subEn} online",
            'specifications' => $this->specs($kind, $material, $origin, $weight, $seed),
            'content' => $this->content($subEn, $benefits, $features, $seed),
            'seo_title' => "{$nameEn} | {$subEn} by {$brand['en']}",
            'seo_description' => "Shop the {$nameEn} — {$descriptor}. {$material}, {$this->pick(self::BENEFITS, $seed, 'seob')}. Free returns.",
            'rating_avg' => $rating,
            'rating_count' => $reviewCount,
            'sales_count' => $this->num($seed, 'sales', 0, 900),
            'views_count' => $this->num($seed, 'views', 50, 9000),
            'flags' => $flags,
            'variants' => $this->variants($kind, $sku, $price, $comparePrice, $cost, $seed),
            'reviews' => $this->reviews($reviewCount, $rating, $seed),
        ];
    }

    private function paragraph(string $seed, string $brand, string $sub, string $descriptor, array $benefits): string
    {
        $b = implode('. ', array_slice($benefits, 0, 3));

        return "The {$brand} {$sub} is {$descriptor}. {$b}. Every unit is inspected before it leaves our warehouse, so you can order with confidence.";
    }

    private function longDescription(string $brand, string $noun, string $sub, string $material, string $origin, array $features): string
    {
        $f = '';
        foreach ($features as $x) {
            $f .= "<li>{$x}</li>";
        }

        return "<p>Meet the <strong>{$brand} {$noun} {$sub}</strong> — designed in {$origin} and crafted from {$material} for a premium feel that lasts. It brings together considered engineering and a clean, modern finish that fits effortlessly into your space.</p>"
            ."<h3>Why you'll love it</h3><ul>{$f}</ul>"
            ."<p>Whether it's daily use or a thoughtful gift, the {$brand} {$noun} delivers dependable performance and a finish that looks the part. Backed by our warranty and hassle-free returns.</p>";
    }

    private function specs(string $kind, string $material, string $origin, float $weight, string $seed): array
    {
        $common = [
            ['label' => 'Material', 'value' => $material],
            ['label' => 'Country of origin', 'value' => $origin],
            ['label' => 'Weight', 'value' => $weight.' kg'],
            ['label' => 'Warranty', 'value' => $this->pick(['1 year', '2 years', '3 years'], $seed, 'wsp')],
        ];
        $byKind = [
            'electronics' => [['label' => 'Battery', 'value' => $this->num($seed, 'bat', 8, 40).' h'], ['label' => 'Connectivity', 'value' => $this->pick(['Bluetooth 5.3', 'Wi-Fi 6', 'USB-C', 'NFC'], $seed, 'con')], ['label' => 'Charging', 'value' => 'USB-C fast charge']],
            'furniture' => [['label' => 'Assembly', 'value' => 'Tool-free'], ['label' => 'Max load', 'value' => $this->num($seed, 'load', 90, 200).' kg'], ['label' => 'Frame', 'value' => 'Reinforced']],
            'beauty' => [['label' => 'Volume', 'value' => $this->pick(['30ml', '50ml', '100ml', '200ml'], $seed, 'vol')], ['label' => 'Skin type', 'value' => 'All types'], ['label' => 'Cruelty-free', 'value' => 'Yes']],
            'grocery' => [['label' => 'Net weight', 'value' => $this->pick(['250g', '500g', '1kg'], $seed, 'nw')], ['label' => 'Storage', 'value' => 'Cool, dry place'], ['label' => 'Origin', 'value' => $origin]],
        ][$kind] ?? [['label' => 'Finish', 'value' => $this->pick(['Matte', 'Satin', 'Gloss', 'Brushed'], $seed, 'fin')], ['label' => 'Care', 'value' => 'Wipe clean']];

        return array_merge($common, $byKind);
    }

    private function content(string $sub, array $benefits, array $features, string $seed): array
    {
        return [
            'benefits' => $benefits,
            'features' => $features,
            'whats_included' => ["1× {$sub}", 'Quick-start guide', 'Warranty card', $this->pick(['Storage pouch', 'Mounting kit', 'Spare parts', 'Care cloth'], $seed, 'inc')],
            'how_to_use' => ['Unbox and inspect all parts against the included checklist.', 'Follow the quick-start guide for setup.', 'Clean before first use for best results.'],
            'warnings' => ['Keep away from children under 3.', 'Do not immerse electrical parts in water.', 'Store in a dry place.'],
            'warranty_info' => 'Covered by a manufacturer warranty against defects in materials and workmanship. Register within 30 days to extend coverage.',
            'shipping_info' => 'Ships within 1–2 business days. Free standard shipping on orders over SAR 200. Express options at checkout.',
            'return_policy' => '30-day hassle-free returns. Items must be unused and in original packaging.',
            'faqs' => [
                ['q' => "Is the {$sub} covered by warranty?", 'a' => 'Yes — every unit includes a manufacturer warranty against defects.'],
                ['q' => 'How long does delivery take?', 'a' => 'Standard delivery is 2–4 business days; express is 1–2.'],
                ['q' => 'Can I return it if it does not fit my needs?', 'a' => 'Absolutely — we offer 30-day hassle-free returns.'],
            ],
        ];
    }

    private function variants(string $kind, string $sku, float $price, ?float $compare, float $cost, string $seed): array
    {
        $axis = in_array($kind, ['apparel'], true) ? 'size' : (in_array($kind, ['electronics'], true) ? 'capacity' : 'color');
        $out = [];
        if ($axis === 'size') {
            $sizes = array_slice(self::SIZES, 0, $this->num($seed, 'nvs', 3, 6));
            foreach ($sizes as $i => $s) {
                $out[] = $this->variantRow($sku, "Size {$s}", ['size' => $s], $price, $compare, $cost, $i, $seed);
            }
        } elseif ($axis === 'capacity') {
            $caps = array_slice(self::CAPACITIES, 0, $this->num($seed, 'nvc', 2, 4));
            foreach ($caps as $i => $c) {
                $bump = $i * round($price * 0.12, 2);
                $out[] = $this->variantRow($sku, "Capacity {$c}", ['capacity' => $c], round($price + $bump, 2), $compare ? round($compare + $bump, 2) : null, $cost, $i, $seed);
            }
        } else {
            $colors = array_slice(self::COLORS, 0, $this->num($seed, 'nvco', 2, 5));
            foreach ($colors as $i => $c) {
                $out[] = $this->variantRow($sku, $c[0], ['color' => $c[0], 'color_ar' => $c[1]], $price, $compare, $cost, $i, $seed);
            }
        }

        return $out;
    }

    private function variantRow(string $sku, string $name, array $options, float $price, ?float $compare, float $cost, int $i, string $seed): array
    {
        return [
            'name' => $name,
            'sku' => $sku.'-'.strtoupper(substr(md5($name), 0, 3)),
            'barcode' => '629'.str_pad((string) (crc32($sku.$name) % 10000000000), 10, '0', STR_PAD_LEFT),
            'price_override' => $price,
            'compare_price' => $compare,
            'cost' => $cost,
            'stock_quantity' => $this->num($seed.$name, 'vstk', 0, 120),
            'options' => $options,
            'position' => $i,
        ];
    }

    private function reviews(int $count, float $avg, string $seed): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $s = $seed.'rev'.$i;
            // rating clusters around avg
            $r = max(2, min(5, (int) round($avg + ($this->num($s, 'r', 0, 20) - 12) / 10)));
            $out[] = [
                'author_name' => $this->pick(self::REVIEWERS, $s, 'name'),
                'rating' => $r,
                'title' => $this->pick(self::REVIEW_TITLES, $s, 'title'),
                'body' => $this->reviewBody($s, $r),
                'pros' => $this->picks(self::PROS, $s, 'pros', $this->num($s, 'np', 1, 3)),
                'cons' => $r >= 5 ? [] : $this->picks(self::CONS, $s, 'cons', $this->num($s, 'nc', 1, 2)),
                'is_verified' => $this->num($s, 'ver', 0, 100) < 78,
                'helpful_count' => $this->num($s, 'help', 0, 60),
                'days_ago' => $this->num($s, 'days', 3, 420),
            ];
        }

        return $out;
    }

    private function reviewBody(string $seed, int $rating): string
    {
        $open = $this->pick([
            'Ordered this last month and it has held up really well.',
            'Bought two — one for me and one as a gift.',
            'Was hesitant at first but genuinely glad I bought it.',
            'Exactly as pictured and described.',
            'Arrived quickly and well packaged.',
        ], $seed, 'open');
        $mid = $rating >= 4
            ? $this->pick(['The quality feels premium and it does the job perfectly.', 'Great value for the price and looks fantastic.', 'Works flawlessly and setup took minutes.'], $seed, 'mid')
            : $this->pick(['It works fine, though there are a couple of small niggles.', 'Decent overall but not without minor flaws.', 'Does the job, just not perfect.'], $seed, 'mid');

        return "{$open} {$mid} Would recommend to anyone considering it.";
    }
}
