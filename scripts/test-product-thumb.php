#!/usr/bin/env php
<?php
/**
 * Guard test — the product photo on every row.
 *
 * Why it exists: a packaging is recognised in milliseconds (the colours of a biscuit box,
 * the silhouette of a milk bottle), while a title such as "Vino bianco Trebbiano IGT
 * Rubicone (Vesoletto)" is read word by word. The audit asked for one 44×44 thumbnail at
 * the far left of every product row — Home, banner, pantry — with a semantic fallback
 * instead of a broken image or an empty square.
 *
 * What it locks:
 *   - ONE component (`_productThumbHtml`) renders the photo everywhere, and the dashboard,
 *     the banner and the pantry all go through it;
 *   - the fallback is the category emoji (📦 when the category is unknown), revealed by
 *     `is-icon` — never an empty box, never a broken-image icon;
 *   - the CSS is the agreed frame: 44×44 (min-width 44 so flexbox cannot squeeze it),
 *     `object-fit: contain` on a light neutral surface with a hairline border;
 *   - the backend actually ships the column: `getStats()` selects `p.image_url` for both
 *     the "in scadenza" and the "scaduti" lists (the rows had no photo to show).
 *
 * Run: php scripts/test-product-thumb.php
 */

$root = dirname(__DIR__);
$app  = (string)file_get_contents($root . '/assets/js/app.js');
$css  = (string)file_get_contents($root . '/assets/css/surfaces.css');
$api  = (string)file_get_contents($root . '/api/index.php');
$dom  = (string)file_get_contents($root . '/assets/js/core/dom.js');
$fail = 0;
$checks = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail, $checks;
    $checks++;
    if ($ok) {
        echo "ok    {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** Pull a top-level function out of a source file by matching braces.
 *  The parameter list is skipped first: a default object (`opts = {}`) made a naive
 *  brace count stop at the first `}`, returning half a signature. */
function extract_fn(string $src, string $name): string
{
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $len = strlen($src);
    $i = (int)strpos($src, '(', $start);
    $depth = 0;
    for (; $i < $len; $i++) {
        if ($src[$i] === '(') {
            $depth++;
        } elseif ($src[$i] === ')') {
            $depth--;
            if ($depth === 0) {
                $i++;
                break;
            }
        }
    }
    $open = strpos($src, '{', $i);
    if ($open === false) {
        return '';
    }
    $depth = 0;
    for ($i = $open; $i < $len; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    return '';
}

// ── One component, three surfaces ────────────────────────────────────────────
$thumbSrc = extract_fn($app, '_productThumbHtml');
check('_productThumbHtml() exists', $thumbSrc !== '');
check('the dashboard rows render the thumbnail', substr_count($app, '${_productThumbHtml(item)}') >= 4,
    (string)substr_count($app, '${_productThumbHtml(item)}'));
check('the banner uses the same component (photo, else the situation emoji)',
    str_contains($app, 'function _bannerIconSet(')
    && substr_count($app, '_bannerIconSet(iconEl, ') >= 6
    && str_contains(extract_fn($app, '_bannerIconSet'), '_productThumbHtml('));
check('the pantry row keeps the same frame (`_invImageHtml`, category emoji fallback)',
    str_contains($app, 'function _invImageHtml(')
    && str_contains($app, "const thumb = opts.hideCatIcon")
    && str_contains($app, '_invImageHtml(item.image_url'));
check('the product modal keeps its own picture',
    substr_count($app, 'currentProduct.image_url') >= 3);

// ── The frame: 44×44, contain, neutral surface ──────────────────────────────
preg_match('/\.product-thumb \{([^}]*)\}/', $css, $thumbCss);
$block = $thumbCss[1] ?? '';
check('the thumbnail is 44×44 and cannot be squeezed by flexbox',
    preg_match('/width:\s*44px/', $block) === 1
    && preg_match('/height:\s*44px/', $block) === 1
    && preg_match('/min-width:\s*44px/', $block) === 1,
    $block);
check('the frame is neutral: light surface, hairline border, 8 px radius',
    preg_match('/background:\s*var\(--bg-app\)/', $block) === 1
    && preg_match('/border:\s*1px solid var\(--surface-border\)/', $block) === 1
    && preg_match('/border-radius:\s*8px/', $block) === 1,
    $block);
check('the picture is contained, not cropped (transparent PNGs stay whole)',
    preg_match('/\.product-thumb-img \{[^}]*object-fit:\s*contain/s', $css) === 1
    && preg_match('/\.inv-image img \{[^}]*object-fit:\s*contain/s', $css) === 1);
check('the pantry row uses the same 44 px frame',
    preg_match('/\.inv-image \{[^}]*width:\s*44px[^}]*height:\s*44px[^}]*min-width:\s*44px/s', $css) === 1);
check('the emoji fallback is hidden while a photo is shown, and only then',
    preg_match('/\.product-thumb-emoji \{\s*display:\s*none;/s', $css) === 1
    && str_contains($css, '.product-thumb.is-icon .product-thumb-emoji { display: block; }'));

// ── The fallback is semantic, never a broken image ──────────────────────────
check('the fallback comes from the category, 📦 when unknown',
    str_contains($thumbSrc, 'CATEGORY_ICONS[mapToLocalCategory(')
    && str_contains($thumbSrc, "'📦'"));
check('a failed image reveals the emoji instead of the broken icon',
    str_contains($app, "onerror=\"this.parentElement.classList.add('is-icon')\""));
check('a grouped pantry list can suppress the icon (the header already shows it)',
    str_contains($thumbSrc, "opts.icon === false"));
check('the picture is decorative for screen readers',
    str_contains($thumbSrc, 'aria-hidden="true"') && str_contains($thumbSrc, 'alt=""'));

// ── The backend ships the column ────────────────────────────────────────────
preg_match('/function getStats\(PDO \$db\): void \{(.*?)\n\}/s', $api, $stats);
$statsBody = $stats[1] ?? '';
check('getStats() is readable', $statsBody !== '');
check('both dashboard lists select the product image',
    substr_count($statsBody, 'p.category, p.image_url, p.unit') >= 2,
    (string)substr_count($statsBody, 'p.category, p.image_url, p.unit'));



// ── Live: the helper itself, in node, with stubbed category data ────────────
$validSrc = extract_fn($app, '_validProductImageUrl');
$escSrc   = extract_fn($dom, 'escapeHtml');
$attrSrc  = extract_fn($dom, 'escapeAttr');
check('_validProductImageUrl(), escapeHtml() and escapeAttr() are extractable',
    $validSrc !== '' && $escSrc !== '' && $attrSrc !== '');
if ($thumbSrc !== '' && $validSrc !== '' && $escSrc !== '' && $attrSrc !== '') {
    // escapeHtml() in the app goes through the DOM: a tiny shim reproduces the browser's
    // text-node escaping, so the harness runs the REAL helpers, not copies.
    $shim = "globalThis.document = { createElement: () => { let text = '';\n"
        . "    return { set textContent(v) { text = String(v); }, get textContent() { return text; },\n"
        . "             get innerHTML() { return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); } };\n"
        . "} };\n";
    $harness = $shim . $escSrc . "\n" . $attrSrc . "\n" . $validSrc . "\n"
        . "const CATEGORY_ICONS = { frutta: '🍎', latticini: '🥛', altro: '📦' };\n"
        . "const mapToLocalCategory = (cat, name) => (cat || (/mela|pera/i.test(name || '') ? 'frutta' : 'altro'));\n"
        . $thumbSrc . "\n"
        . <<<'JS'
const withPhoto = _productThumbHtml({ category: 'frutta', name: 'Mele', image_url: 'https://images.example/mela.png' });
const noPhoto   = _productThumbHtml({ category: 'latticini', name: 'Latte' });
const unknown   = _productThumbHtml({ name: 'Vino Trebbiano' });
const grouped   = _productThumbHtml({ name: 'Pane' }, { icon: false });
const junk      = _productThumbHtml({ category: 'frutta', name: 'Mele', image_url: 'pz' });
const quoted    = _productThumbHtml({ category: 'frutta', name: 'Mele', image_url: '/img/a"b.png' });
console.log(JSON.stringify({ withPhoto, noPhoto, unknown, grouped, junk, quoted }));
JS;
    $tmp = sys_get_temp_dir() . '/thumb_' . getmypid() . '.js';
    file_put_contents($tmp, $harness);
    $raw = shell_exec('node ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
    $out = json_decode(trim((string)$raw), true);
    check('the thumbnail helper runs in node', is_array($out), (string)$raw);
    if (is_array($out)) {
        check('a product with a photo shows the photo',
            str_contains((string)$out['withPhoto'], '<img class="product-thumb-img"')
            && str_contains((string)$out['withPhoto'], 'https://images.example/mela.png')
            && !str_contains((string)$out['withPhoto'], ' is-icon'), (string)$out['withPhoto']);
        check('a product without a photo falls back to its category emoji',
            str_contains((string)$out['noPhoto'], ' is-icon') && str_contains((string)$out['noPhoto'], '🥛'),
            (string)$out['noPhoto']);
        check('an unknown category falls back to the shopping-box 📦',
            str_contains((string)$out['unknown'], '📦'), (string)$out['unknown']);
        check('a grouped list can render no box at all', ($out['grouped'] ?? 'x') === '');
        check('a unit code stored by mistake is not treated as an image',
            str_contains((string)$out['junk'], ' is-icon') && !str_contains((string)$out['junk'], '<img'),
            (string)$out['junk']);
        check('the URL is escaped before it reaches the markup',
            str_contains((string)$out['quoted'], 'a&quot;b.png'), (string)$out['quoted']);
    }
}

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
