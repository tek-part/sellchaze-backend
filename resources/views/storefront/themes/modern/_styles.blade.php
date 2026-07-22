{{--
    Modern theme — Blade stylesheet MIRROR of storefront-ssr/themes/modern/styles.mjs
    + tokens.mjs. The SSR module is the canonical source; this partial reproduces the
    same :root token block (computed from theme settings, same defaults) and the same
    static token-driven CSS, so the Blade fallback is visually identical to SSR.
    Expects: $s (theme settings array).
--}}
@php
    $fontStack = fn ($k) => match ($k) {
        'serif' => 'Georgia, "Times New Roman", serif',
        'mono' => 'ui-monospace, SFMono-Regular, Menlo, monospace',
        'rounded' => '"Segoe UI", "Helvetica Neue", system-ui, sans-serif',
        default => 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
    };
    $shadow = match ($s['shadow'] ?? null) {
        'none' => ['none', 'none', 'none'],
        'medium' => ['0 2px 8px rgba(2,6,23,.08)', '0 12px 40px rgba(2,6,23,.12)', '0 28px 70px rgba(2,6,23,.20)'],
        'strong' => ['0 4px 12px rgba(2,6,23,.12)', '0 20px 60px rgba(2,6,23,.18)', '0 40px 90px rgba(2,6,23,.28)'],
        default => ['0 1px 3px rgba(2,6,23,.06)', '0 8px 30px rgba(2,6,23,.08)', '0 24px 60px rgba(2,6,23,.16)'],
    };
    $r = is_numeric($s['radius'] ?? null) ? (int) $s['radius'] : 14;
    $tok = [
        'primary' => $s['primary'] ?? '#111827', 'on-primary' => $s['on_primary'] ?? '#ffffff', 'accent' => $s['accent'] ?? '#e11d48',
        'bg' => $s['background'] ?? '#ffffff', 'surface' => $s['surface'] ?? '#f8fafc', 'text' => $s['text'] ?? '#0f172a',
        'muted' => $s['muted'] ?? '#64748b', 'border' => $s['border'] ?? '#e5e7eb', 'success' => $s['success'] ?? '#16a34a',
        'danger' => $s['danger'] ?? '#dc2626', 'sale' => $s['sale'] ?? ($s['accent'] ?? '#e11d48'),
        'font' => $fontStack($s['font'] ?? 'system'), 'heading' => $fontStack($s['heading_font'] ?? ($s['font'] ?? 'system')),
        'fs-sm' => '14px', 'fs-md' => '16px', 'fs-lg' => '18px', 'fs-xl' => '24px', 'fs-2xl' => '32px', 'lh' => '1.55', 'lh-tight' => '1.15',
        'sp-1' => '4px', 'sp-2' => '8px', 'sp-3' => '12px', 'sp-4' => '16px', 'sp-5' => '20px', 'sp-6' => '24px', 'sp-8' => '32px', 'sp-10' => '44px', 'sp-12' => '64px',
        'radius' => $r.'px', 'radius-sm' => max(0, $r - 6).'px', 'radius-lg' => ($r + 8).'px', 'radius-pill' => '999px',
        'shadow-sm' => $shadow[0], 'shadow' => $shadow[1], 'shadow-lg' => $shadow[2],
        'transition' => '.2s ease', 'transition-slow' => '.35s ease', 'icon-sm' => '18px', 'icon-md' => '22px',
        'container' => (is_numeric($s['container_width'] ?? null) ? (int) $s['container_width'] : 1200).'px', 'tap' => '42px',
        'z-header' => '40', 'z-overlay' => '60', 'z-drawer' => '70',
    ];
    $root = ':root{'.collect($tok)->map(fn ($v, $k) => "--{$k}:{$v}")->implode(';').'}';
@endphp
<style>
{!! $root !!}
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font-family:var(--font);color:var(--text);background:var(--bg);line-height:var(--lh);-webkit-font-smoothing:antialiased}
img{max-width:100%;display:block}
a{color:inherit;text-decoration:none}
h1,h2,h3{font-family:var(--heading);line-height:var(--lh-tight);margin:0}
.mod-container{max-width:var(--container);margin-inline:auto;padding-inline:var(--sp-5)}
.mod-only-mobile{display:none}
.mod-btn{display:inline-flex;align-items:center;justify-content:center;gap:var(--sp-2);border:0;cursor:pointer;font-weight:600;font-size:var(--fs-md);padding:var(--sp-3) var(--sp-6);border-radius:var(--radius-pill);background:var(--primary);color:var(--on-primary);transition:transform var(--transition),opacity var(--transition)}
.mod-btn:hover{transform:translateY(-1px);opacity:.92}
.mod-btn--ghost{background:transparent;color:var(--text);border:1px solid var(--border)}
.mod-iconbtn{display:inline-grid;place-items:center;width:var(--tap);height:var(--tap);border-radius:var(--radius-pill);border:1px solid var(--border);background:var(--bg);cursor:pointer;font-size:var(--icon-md);color:var(--text)}
.mod-iconbtn:hover{border-color:var(--primary)}
.mod-input{width:100%;padding:var(--sp-3) var(--sp-4);border:1px solid var(--border);border-radius:var(--radius);font-size:var(--fs-md);background:var(--bg);color:var(--text)}
.mod-input:focus{outline:2px solid var(--primary);outline-offset:1px}
.mod-badge{display:inline-block;font-size:12px;font-weight:700;padding:var(--sp-1) var(--sp-3);border-radius:var(--radius-pill);background:var(--surface);color:var(--text)}
.mod-badge--sale{background:var(--sale);color:#fff}
.mod-badge--success{background:var(--success);color:#fff}
.mod-price{font-weight:800;font-size:var(--fs-md)}
.mod-price--lg{font-size:var(--fs-xl)}
.mod-price__was{color:var(--muted);text-decoration:line-through;font-weight:500;margin-inline-start:var(--sp-2);font-size:var(--fs-sm)}
.mod-rating{display:inline-flex;align-items:center;gap:var(--sp-1);color:var(--accent);font-size:var(--fs-sm)}
.mod-rating__count{color:var(--muted)}
.mod-empty{color:var(--muted);padding:var(--sp-8);text-align:center;border:1px dashed var(--border);border-radius:var(--radius)}
.mod-crumb{display:flex;gap:var(--sp-2);color:var(--muted);font-size:var(--fs-sm);margin:var(--sp-5) 0}
.mod-crumb a:hover{color:var(--primary)}
.mod-card{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:box-shadow var(--transition),transform var(--transition)}
.mod-card:hover{box-shadow:var(--shadow);transform:translateY(-3px)}
.mod-card__media{position:relative;aspect-ratio:1/1;background:var(--surface);display:grid;place-items:center;color:var(--muted);font-size:var(--fs-sm)}
.mod-card__media .mod-badge{position:absolute;inset-block-start:var(--sp-2);inset-inline-start:var(--sp-2)}
.mod-card__body{padding:var(--sp-4)}
.mod-card__name{font-weight:600;margin:0 0 var(--sp-2)}
.mod-catcard{display:flex;flex-direction:column;gap:var(--sp-2);align-items:center;text-align:center;padding:var(--sp-4);border:1px solid var(--border);border-radius:var(--radius);background:var(--surface);transition:border-color var(--transition)}
.mod-catcard:hover{border-color:var(--primary)}
.mod-catcard__media{width:100%;aspect-ratio:16/10;border-radius:var(--radius-sm);background:var(--bg)}
.mod-catcard__name{font-weight:700}
.mod-catcard__count{color:var(--muted);font-size:var(--fs-sm)}
.mod-announce{background:var(--primary);color:var(--on-primary);text-align:center;font-size:var(--fs-sm);padding:var(--sp-2) var(--sp-3)}
.mod-header{position:sticky;top:0;z-index:var(--z-header);background:color-mix(in srgb,var(--bg) 88%,transparent);backdrop-filter:saturate(180%) blur(10px);border-bottom:1px solid var(--border)}
.mod-header__row{display:flex;align-items:center;gap:var(--sp-5);height:70px}
.mod-brand{font-family:var(--heading);font-weight:800;font-size:var(--fs-xl);letter-spacing:-.02em}
.mod-brand--sm{font-size:var(--fs-lg)}
.mod-nav{display:flex;gap:var(--sp-6);margin-inline-start:var(--sp-2)}
.mod-nav a{font-weight:600;opacity:.85;padding:var(--sp-2) 0;border-bottom:2px solid transparent}
.mod-nav a:hover{opacity:1;border-bottom-color:var(--primary)}
.mod-header__spacer{flex:1}
.mod-header__actions{display:flex;gap:var(--sp-2)}
.mod-toggle{position:absolute;opacity:0;width:0;height:0;pointer-events:none}
.mod-overlay{position:fixed;inset:0;z-index:var(--z-overlay);display:grid;place-items:start center;padding-top:12vh;opacity:0;visibility:hidden;transition:opacity var(--transition),visibility var(--transition)}
.mod-overlay__scrim{position:absolute;inset:0;background:rgba(2,6,23,.5)}
.mod-search-box{position:relative;display:flex;gap:var(--sp-2);width:min(640px,92vw);background:var(--bg);padding:var(--sp-4);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg)}
#mod-search:checked ~ .mod-search-overlay{opacity:1;visibility:visible}
.mod-drawer{position:fixed;inset:0;z-index:var(--z-drawer);visibility:hidden}
.mod-drawer__scrim{position:absolute;inset:0;background:rgba(2,6,23,.5);opacity:0;transition:opacity var(--transition)}
.mod-drawer__panel{position:absolute;inset-block:0;inset-inline-end:0;width:min(380px,86vw);background:var(--bg);box-shadow:var(--shadow-lg);padding:var(--sp-5);transform:translateX(100%);transition:transform var(--transition-slow);display:flex;flex-direction:column;gap:var(--sp-4)}
[dir="rtl"] .mod-drawer__panel{transform:translateX(-100%)}
.mod-mobile-nav .mod-drawer__panel{gap:var(--sp-3)}
.mod-mobile-nav a{font-weight:600;padding:var(--sp-2) 0;border-bottom:1px solid var(--border)}
.mod-drawer__head{display:flex;align-items:center;justify-content:space-between}
.mod-drawer__body{flex:1}
#mod-cart:checked ~ .mod-cart-drawer,#mod-menu:checked ~ .mod-mobile-nav{visibility:visible}
#mod-cart:checked ~ .mod-cart-drawer .mod-drawer__scrim,#mod-menu:checked ~ .mod-mobile-nav .mod-drawer__scrim{opacity:1}
#mod-cart:checked ~ .mod-cart-drawer .mod-drawer__panel,#mod-menu:checked ~ .mod-mobile-nav .mod-drawer__panel{transform:none}
.mod-footer{margin-top:var(--sp-12);background:var(--surface);border-top:1px solid var(--border)}
.mod-footer__row{display:flex;flex-wrap:wrap;gap:var(--sp-8);justify-content:space-between;padding:var(--sp-10) 0}
.mod-footer__about{color:var(--muted);max-width:40ch}
.mod-footer__links{display:flex;flex-wrap:wrap;gap:var(--sp-5)}
.mod-footer__links a{color:var(--muted);font-weight:600}
.mod-footer__meta{color:var(--muted);font-size:var(--fs-sm);padding:var(--sp-4) 0;border-top:1px solid var(--border)}
.mod-section{margin:var(--sp-10) 0}
.mod-section__head{display:flex;align-items:end;justify-content:space-between;gap:var(--sp-3);margin-bottom:var(--sp-5)}
.mod-section__head h2{font-size:var(--fs-2xl)}
.mod-section__head a{color:var(--muted);font-weight:600}
.mod-hero{position:relative;background:linear-gradient(135deg,var(--surface),color-mix(in srgb,var(--primary) 10%,var(--surface)));border-radius:var(--radius-lg);overflow:hidden;padding:var(--sp-12) var(--sp-8);margin:var(--sp-6) 0}
.mod-hero__eyebrow{font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--accent);font-size:13px}
.mod-hero h1{font-size:clamp(30px,5vw,52px);margin:var(--sp-3) 0}
.mod-hero p{font-size:var(--fs-lg);color:var(--muted);max-width:56ch}
.mod-hero .mod-btn{margin-top:var(--sp-5)}
.mod-grid{display:grid;gap:var(--sp-5);grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}
.mod-catgrid{display:grid;gap:var(--sp-4);grid-template-columns:repeat(auto-fill,minmax(180px,1fr))}
.mod-cathead h1{font-size:var(--fs-2xl)}
.mod-cathead__desc{color:var(--muted)}
.mod-pd{display:grid;grid-template-columns:1.1fr 1fr;gap:var(--sp-10);margin:var(--sp-6) 0}
.mod-pd__gallery{aspect-ratio:1/1;background:var(--surface);border-radius:var(--radius-lg);display:grid;place-items:center;color:var(--muted)}
.mod-pd__info{display:flex;flex-direction:column;gap:var(--sp-3)}
.mod-pd__desc{color:var(--muted)}
.mod-richtext{line-height:1.7}
@media(max-width:768px){.mod-nav{display:none}.mod-only-mobile{display:inline-grid}.mod-pd{grid-template-columns:1fr}.mod-hero{padding:var(--sp-10) var(--sp-6)}}
/* --- transactional / static pages --- */
.mod-page{padding:var(--sp-10) 0}
.mod-page__head{margin-bottom:var(--sp-6)}
.mod-page__head h1{font-size:var(--fs-2xl)}
.mod-page__head p{color:var(--muted);margin-top:6px}
.mod-cols{display:grid;grid-template-columns:1fr 360px;gap:var(--sp-8);align-items:start}
.mod-panel{border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--bg);padding:var(--sp-6)}
.mod-summary{position:sticky;top:90px;border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--surface);padding:var(--sp-6);display:flex;flex-direction:column;gap:var(--sp-3)}
.mod-summary h3{font-size:var(--fs-lg)}
.mod-sumrow{display:flex;justify-content:space-between;color:var(--muted)}
.mod-sumrow--total{color:var(--text);font-weight:800;font-size:var(--fs-lg);border-top:1px solid var(--border);padding-top:var(--sp-3);margin-top:var(--sp-2)}
.mod-line{display:flex;gap:var(--sp-4);align-items:center;padding:var(--sp-4) 0;border-bottom:1px solid var(--border)}
.mod-line__media{width:88px;height:88px;border-radius:var(--radius);background:var(--surface);overflow:hidden;flex:none}
.mod-line__media img{width:100%;height:100%;object-fit:cover}
.mod-line__info{flex:1;min-width:0}
.mod-line__name{font-weight:600}
.mod-qty{display:inline-flex;align-items:center;border:1px solid var(--border);border-radius:var(--radius-pill);overflow:hidden}
.mod-qty button{width:34px;height:34px;border:0;background:var(--bg);cursor:pointer;font-size:18px}
.mod-qty span{min-width:34px;text-align:center;font-weight:600}
.mod-couponbar{display:flex;gap:var(--sp-2)}
.mod-steps{display:flex;align-items:center;gap:var(--sp-3);margin:var(--sp-6) 0}
.mod-step{display:flex;align-items:center;gap:var(--sp-2);color:var(--muted);font-weight:600}
.mod-step__dot{width:34px;height:34px;border-radius:var(--radius-pill);display:grid;place-items:center;background:var(--surface);border:1px solid var(--border)}
.mod-step--active{color:var(--text)}
.mod-step--active .mod-step__dot{background:var(--primary);color:var(--on-primary);border-color:var(--primary)}
.mod-steps__line{flex:1;height:2px;background:var(--border)}
.mod-optcard{display:flex;justify-content:space-between;align-items:center;border:1px solid var(--border);border-radius:var(--radius);padding:var(--sp-4);margin-bottom:var(--sp-3)}
.mod-optcard--active{border-color:var(--primary);box-shadow:0 0 0 1px var(--primary) inset}
.mod-field{display:flex;flex-direction:column;gap:6px;margin-bottom:var(--sp-4)}
.mod-field label{font-weight:600;font-size:var(--fs-sm)}
.mod-trust{display:flex;gap:var(--sp-5);color:var(--muted);font-size:var(--fs-sm);justify-content:center;margin-top:var(--sp-4);flex-wrap:wrap}
.mod-auth{display:grid;grid-template-columns:1fr 1fr;min-height:100vh}
.mod-auth__form{display:flex;flex-direction:column;justify-content:center;padding:var(--sp-12) 8%;gap:var(--sp-1)}
.mod-auth__aside{background:linear-gradient(160deg,var(--primary),color-mix(in srgb,var(--primary) 70%,#000));color:var(--on-primary);padding:var(--sp-12) 8%;display:flex;flex-direction:column;justify-content:center;gap:var(--sp-4)}
.mod-auth__aside h2{font-size:var(--fs-2xl)}
.mod-auth__benefit{display:flex;gap:var(--sp-3);align-items:center;background:rgba(255,255,255,.12);border-radius:var(--radius);padding:var(--sp-3) var(--sp-4)}
.mod-ctable{width:100%;border-collapse:collapse}
.mod-ctable th,.mod-ctable td{border:1px solid var(--border);padding:var(--sp-3);text-align:start;vertical-align:top}
.mod-ctable thead td{background:var(--surface)}
.mod-ctable__group td{background:var(--surface);font-weight:700}
.mod-success{max-width:720px;margin:var(--sp-12) auto;text-align:center}
.mod-success__icon{width:72px;height:72px;border-radius:var(--radius-pill);background:color-mix(in srgb,var(--success) 15%,transparent);color:var(--success);display:grid;place-items:center;font-size:34px;margin:0 auto var(--sp-4)}
.mod-success h1{font-size:var(--fs-2xl)}
@media(max-width:860px){.mod-cols{grid-template-columns:1fr}.mod-auth{grid-template-columns:1fr}.mod-auth__aside{display:none}.mod-summary{position:static}}
/* --- account module + info/legal/utility pages --- */
.mod-account{display:grid;grid-template-columns:240px 1fr;gap:var(--sp-6);padding:var(--sp-8) 0;align-items:start}
.mod-anav{display:flex;flex-direction:column;gap:4px;position:sticky;top:90px}
.mod-anav a{padding:var(--sp-3) var(--sp-4);border-radius:var(--radius);color:var(--muted);font-weight:600;display:flex;gap:10px;align-items:center}
.mod-anav a.is-active,.mod-anav a:hover{background:var(--surface);color:var(--text)}
.mod-anav a.is-active{color:var(--primary)}
.mod-statgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-6)}
.mod-stat{border:1px solid var(--border);border-radius:var(--radius);padding:var(--sp-4);background:var(--bg)}
.mod-stat__n{font-size:var(--fs-2xl);font-weight:800}
.mod-stat__l{color:var(--muted);font-size:14px}
.mod-order{display:flex;gap:var(--sp-4);align-items:center;border:1px solid var(--border);border-radius:var(--radius);padding:var(--sp-4);margin-bottom:var(--sp-3)}
.mod-order__thumbs{display:flex}
.mod-order__thumbs img{width:44px;height:44px;border-radius:8px;object-fit:cover;border:2px solid var(--bg);margin-inline-start:-10px}
.mod-timeline{display:flex;flex-direction:column}
.mod-tl{display:flex;gap:var(--sp-3);position:relative;padding-bottom:var(--sp-5)}
.mod-tl__dot{width:28px;height:28px;border-radius:999px;display:grid;place-items:center;background:var(--surface);border:1px solid var(--border);flex:none;z-index:1;font-size:13px}
.mod-tl--done .mod-tl__dot{background:var(--primary);color:var(--on-primary);border-color:var(--primary)}
.mod-tl:not(:last-child)::before{content:"";position:absolute;inset-inline-start:13px;top:28px;bottom:0;width:2px;background:var(--border)}
.mod-tl--done:not(:last-child)::before{background:var(--primary)}
.mod-sw{width:44px;height:26px;border-radius:999px;background:var(--border);position:relative;flex:none}
.mod-sw::after{content:"";position:absolute;width:20px;height:20px;border-radius:999px;background:#fff;top:3px;inset-inline-start:3px;transition:var(--transition)}
.mod-sw.is-on{background:var(--primary)}.mod-sw.is-on::after{inset-inline-start:21px}
.mod-setrow{display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);padding:var(--sp-4) 0;border-bottom:1px solid var(--border)}
.mod-acc{border:1px solid var(--border);border-radius:var(--radius);margin-bottom:var(--sp-3);overflow:hidden}
.mod-acc summary{padding:var(--sp-4);font-weight:600;cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center}
.mod-acc summary::-webkit-details-marker{display:none}
.mod-acc[open] summary{border-bottom:1px solid var(--border)}
.mod-acc__body{padding:var(--sp-4);color:var(--muted);margin:0}
.mod-legal{display:grid;grid-template-columns:240px 1fr;gap:var(--sp-8);padding:var(--sp-8) 0;align-items:start}
.mod-legal__toc{position:sticky;top:90px;display:flex;flex-direction:column;gap:8px;border-inline-start:2px solid var(--border);padding-inline-start:var(--sp-4)}
.mod-legal__toc a{color:var(--muted);font-weight:600;font-size:14px}
.mod-legal__toc a:hover{color:var(--primary)}
.mod-legal__body h2{margin-top:var(--sp-6);font-size:var(--fs-xl)}
.mod-legal__body p,.mod-legal__body li{color:var(--muted);line-height:1.9}
.mod-band{background:linear-gradient(135deg,var(--primary),color-mix(in srgb,var(--primary) 70%,#000));color:var(--on-primary);border-radius:var(--radius-lg);padding:var(--sp-12) var(--sp-8);text-align:center}
.mod-band h2{font-size:var(--fs-2xl)}
.mod-centered{min-height:72vh;display:grid;place-items:center;text-align:center}
.mod-centered__inner{max-width:520px;display:flex;flex-direction:column;gap:var(--sp-4);align-items:center}
.mod-avatar{width:64px;height:64px;border-radius:999px;background:var(--surface);display:grid;place-items:center;font-size:24px;flex:none}
.mod-values{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:var(--sp-4)}
.mod-values .mod-panel{padding:var(--sp-5)}
.mod-blog-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:var(--sp-5)}
.mod-blog-card{border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;background:var(--bg)}
.mod-blog-card__media{aspect-ratio:16/10;background:var(--surface);overflow:hidden}
.mod-blog-card__media img{width:100%;height:100%;object-fit:cover}
.mod-blog-card__body{padding:var(--sp-4)}
.mod-two{display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-8);align-items:center}
.mod-count{font-size:36px;font-weight:800;color:var(--primary)}
@media(max-width:860px){.mod-account,.mod-legal,.mod-two{grid-template-columns:1fr}.mod-anav,.mod-legal__toc{position:static;flex-direction:row;overflow:auto;border:0;padding:0}}
/* --- premium homepage sections (Part 7) --- */
.mod-features{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:var(--sp-4);margin:var(--sp-8) 0}
.mod-feature{display:flex;gap:var(--sp-3);align-items:center;border:1px solid var(--border);border-radius:var(--radius);padding:var(--sp-4);background:var(--bg)}
.mod-feature__icon{width:44px;height:44px;border-radius:var(--radius);background:var(--surface);display:grid;place-items:center;font-size:22px;flex:none}
.mod-feature__text{color:var(--muted);font-size:var(--fs-sm)}
.mod-promo{position:relative;border-radius:var(--radius-lg);overflow:hidden;background:linear-gradient(135deg,var(--primary),color-mix(in srgb,var(--primary) 65%,#000));color:var(--on-primary);padding:var(--sp-12) var(--sp-8);margin:var(--sp-8) 0;background-size:cover;background-position:center}
.mod-promo__eyebrow{font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.9;font-size:13px}
.mod-promo h2{font-size:clamp(24px,4vw,40px);margin:var(--sp-3) 0}
.mod-promo p{opacity:.92;max-width:52ch}
.mod-promo .mod-btn{margin-top:var(--sp-4);background:#fff;color:var(--primary)}
.mod-news{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:var(--sp-10) var(--sp-8);margin:var(--sp-8) 0;text-align:center}
.mod-news h2{font-size:var(--fs-2xl)}
.mod-news__sub{color:var(--muted);max-width:52ch;margin:var(--sp-2) auto var(--sp-5)}
.mod-news__form{display:flex;gap:var(--sp-2);max-width:480px;margin:0 auto}
.mod-tst{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:var(--sp-4)}
.mod-tstcard{border:1px solid var(--border);border-radius:var(--radius);padding:var(--sp-5);background:var(--bg);display:flex;flex-direction:column;gap:var(--sp-3)}
.mod-tstcard__stars{color:var(--accent)}
.mod-tstcard__name{display:flex;gap:var(--sp-2);align-items:center;color:var(--muted)}
.mod-stats2{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:var(--sp-4);text-align:center;margin:var(--sp-8) 0;padding:var(--sp-8);background:var(--surface);border-radius:var(--radius-lg)}
.mod-stat2__n{font-size:var(--fs-2xl);font-weight:800;color:var(--primary)}
.mod-stat2__l{color:var(--muted);font-size:var(--fs-sm)}
/* --- PDP showpiece (Part 8) --- */
.mod-pd__thumbs{display:flex;gap:var(--sp-2);margin-top:var(--sp-3)}
.mod-pd__thumb{width:64px;height:64px;border-radius:var(--radius-sm);overflow:hidden;border:1px solid var(--border);background:var(--surface);cursor:pointer}
.mod-pd__thumb.is-active{border-color:var(--primary)}
.mod-pd__thumb img{width:100%;height:100%;object-fit:cover}
.mod-crumb{margin-bottom:var(--sp-2)}
.mod-swatches{display:flex;gap:var(--sp-2);flex-wrap:wrap}
.mod-swatch{width:34px;height:34px;border-radius:999px;border:2px solid var(--border);cursor:pointer}
.mod-swatch.is-active{border-color:var(--primary);box-shadow:0 0 0 2px var(--bg),0 0 0 4px var(--primary)}
.mod-sizes{display:flex;gap:var(--sp-2);flex-wrap:wrap}
.mod-size{min-width:44px;height:40px;padding:0 12px;display:grid;place-items:center;border:1px solid var(--border);border-radius:var(--radius);font-weight:600;cursor:pointer}
.mod-size.is-active{border-color:var(--primary);color:var(--primary)}
.mod-size.is-off{opacity:.4;text-decoration:line-through;cursor:not-allowed}
.mod-stock{display:inline-flex;align-items:center;gap:6px;font-weight:600;color:var(--success)}
.mod-buyrow{display:flex;gap:var(--sp-3);align-items:center}
.mod-tabs{display:flex;gap:var(--sp-5);border-bottom:1px solid var(--border);margin:var(--sp-8) 0 var(--sp-4)}
.mod-tab{padding:var(--sp-3) 0;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;cursor:pointer}
.mod-tab.is-active{color:var(--text);border-bottom-color:var(--primary)}
.mod-spec{width:100%;border-collapse:collapse}
.mod-spec th,.mod-spec td{border-bottom:1px solid var(--border);padding:var(--sp-3);text-align:start;color:var(--muted)}
.mod-spec th{color:var(--text);font-weight:600;width:200px}
.mod-rev{display:flex;gap:var(--sp-6);padding:var(--sp-4) 0;border-bottom:1px solid var(--border);flex-wrap:wrap}
.mod-rev__bars{display:flex;flex-direction:column;gap:6px;min-width:220px;flex:1}
.mod-rev__bar{height:8px;border-radius:4px;background:var(--border);overflow:hidden;flex:1}
.mod-rev__bar>span{display:block;height:100%;background:var(--accent)}
.mod-stickybar{position:sticky;bottom:0;z-index:30;background:color-mix(in srgb,var(--bg) 92%,transparent);backdrop-filter:blur(10px);border-top:1px solid var(--border);padding:var(--sp-3) 0;margin-top:var(--sp-8)}
.mod-stickybar__row{display:flex;gap:var(--sp-4);align-items:center;justify-content:space-between;flex-wrap:wrap}
.mod-pd__label{font-weight:700;font-size:var(--fs-sm);margin-top:var(--sp-2)}
.mod-pd__sku{color:var(--muted);font-size:var(--fs-sm);margin-top:var(--sp-3)}
.mod-pd__panel{margin-bottom:var(--sp-8)}
.mod-pd__panel h3{font-size:var(--fs-lg);margin-bottom:var(--sp-3)}
.mod-rev__summary{text-align:center;min-width:140px}
.mod-rev__score{font-size:var(--fs-2xl);font-weight:800;color:var(--primary)}
.mod-rev__barrow{display:flex;align-items:center;gap:var(--sp-2);font-size:var(--fs-sm);color:var(--muted)}
.mod-review{padding:var(--sp-4) 0;border-bottom:1px solid var(--border)}
.mod-review__head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:var(--sp-3)}
.mod-review__name{font-weight:700}
.mod-catalog__bar{display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);flex-wrap:wrap;margin:var(--sp-4) 0}
.mod-catalog__count{color:var(--muted)}
.mod-catalog__sort{display:flex;align-items:center;gap:var(--sp-2);color:var(--muted);font-size:var(--fs-sm)}
.mod-select{padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text);font:inherit;cursor:pointer}
.mod-catalog__body{display:grid;grid-template-columns:260px 1fr;gap:var(--sp-6);align-items:start}
.mod-filters{border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--bg);padding:var(--sp-5);position:sticky;top:90px;display:flex;flex-direction:column;gap:var(--sp-5)}
.mod-filters__head{display:flex;justify-content:space-between;align-items:center}
.mod-filters__clear{background:none;border:0;color:var(--primary);font-weight:600;cursor:pointer;font-size:var(--fs-sm)}
.mod-filter__title{font-weight:700;margin-bottom:var(--sp-2)}
.mod-filter__opt{display:flex;justify-content:space-between;align-items:center;padding:6px 10px;border-radius:var(--radius);color:var(--muted);text-decoration:none}
.mod-filter__opt:hover{background:var(--surface);color:var(--text)}
.mod-filter__opt.is-active{background:color-mix(in srgb,var(--primary) 12%,transparent);color:var(--primary);font-weight:600}
.mod-filter__count{font-size:var(--fs-sm);opacity:.7}
.mod-range{height:6px;border-radius:3px;background:var(--border);position:relative;margin:var(--sp-3) 0}
.mod-range__fill{position:absolute;inset-inline-start:15%;inset-inline-end:25%;top:0;bottom:0;background:var(--primary);border-radius:3px}
.mod-range__vals{display:flex;justify-content:space-between;color:var(--muted);font-size:var(--fs-sm)}
.mod-check{display:flex;align-items:center;gap:var(--sp-2);padding:5px 0;color:var(--muted);cursor:pointer}
.mod-check input{accent-color:var(--primary)}
.mod-chips{display:flex;gap:var(--sp-2);flex-wrap:wrap;margin-bottom:var(--sp-4)}
.mod-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:var(--radius-pill);background:var(--surface);border:1px solid var(--border);font-size:var(--fs-sm)}
.mod-chip__x{color:var(--muted);cursor:pointer}
.mod-pager{display:flex;gap:var(--sp-2);justify-content:center;margin-top:var(--sp-8)}
.mod-pager__link{min-width:40px;height:40px;display:grid;place-items:center;border:1px solid var(--border);border-radius:var(--radius);color:var(--text);text-decoration:none;font-weight:600}
.mod-pager__link.is-active{background:var(--primary);color:var(--on-primary);border-color:var(--primary)}
.mod-pager__link.is-disabled{opacity:.4}
.mod-pager__gap{align-self:end;padding:0 4px;color:var(--muted)}
@media(max-width:860px){.mod-catalog__body{grid-template-columns:1fr}.mod-filters{position:static}}
.mod-cathead--banner{background-size:cover;background-position:center;color:#fff;padding:var(--sp-12) var(--sp-8);border-radius:var(--radius-lg);text-align:center;margin-top:var(--sp-4)}
.mod-cathead--banner .mod-cathead__desc{color:rgba(255,255,255,.9)}
.mod-gallery{display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-3)}
.mod-sumitems{display:flex;flex-direction:column;gap:var(--sp-3);border-bottom:1px solid var(--border);padding-bottom:var(--sp-4);margin-bottom:var(--sp-1)}
.mod-sumitem{display:flex;gap:var(--sp-3);align-items:center}
.mod-sumitem__media{position:relative;width:54px;height:54px;border-radius:var(--radius);overflow:hidden;background:var(--surface);flex:none}
.mod-sumitem__media img{width:100%;height:100%;object-fit:cover}
.mod-sumitem__qty{position:absolute;top:-6px;inset-inline-start:-6px;min-width:20px;height:20px;padding:0 5px;border-radius:999px;background:var(--primary);color:var(--on-primary);font-size:12px;display:grid;place-items:center}
.mod-sumitem__info{flex:1;min-width:0}
.mod-sumitem__name{font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:600px){.mod-gallery{grid-template-columns:repeat(2,1fr)}}
</style>
