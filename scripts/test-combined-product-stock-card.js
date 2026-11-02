#!/usr/bin/env node
'use strict';
/** The same product-and-stock card must work on Use and Dispose. */
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const root=path.join(__dirname,'..');
const js=fs.readFileSync(path.join(root,'assets/js/app.js'),'utf8');
const html=fs.readFileSync(path.join(root,'index.html'),'utf8');
const css=fs.readFileSync(path.join(root,'assets/css/style.css'),'utf8');
const read=(a,b)=>{
    const i=js.indexOf(a),j=js.indexOf(b,i+a.length);
    assert(i>=0 && j>i,[a,b]);return js.slice(i,j);
};
const header=read('function _renderProductStockIdentity(product, showUseControls = false) {',
    '/** Edit inventory row from Use page');
const stock=read('function _renderUseStockSummary(items) {',
    'function _updateUseHeroMeta(items) {');
const expiry=read('function _updateUseHeroMeta(items) {',
    '// Conf-mode tracking for USE form');
const rows=[
    {id:71,product_id:42,location:'dispensa',unit:'conf',
        quantity:14,default_quantity:690,package_unit:'g',expiry_date:'2026-11-07'},
];
function context(imageUrl=null,name='Safari-Staging-Demo'){
    const elements={'use-product-preview':{innerHTML:''},
        'use-hero-meta':{innerHTML:''}};
    const ctx={
        currentProduct:{id:42,name,brand:'Hausmarke',category:'conserve',image_url:imageUrl},
        CATEGORY_ICONS:{conserve:'📦'},
        mapToLocalCategory:()=> 'conserve',
        _collationLocale:()=> 'de-DE',
        escapeHtml:s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'),
        t:k=>({'inventory.item_detail_edit':'Bearbeiten',
            'use.available':'Verfügbar','use.weight_per_package':'je Packung',
            'use.weight_total':'insgesamt',
            'expiry.badge_expiring_short':'Läuft ab in {n} Tagen'})[k]||k,
        stripHtml:String,
        formatQuantity:(n,unit)=>n+(unit==='conf'?' Packungen':' '+unit),
        localizedPackageUnitLabel:n=>+n===1?'Packung':'Packungen',
        getUnitDisplayLabel:u=>u,
        LOCATIONS:{dispensa:{label:'Vorratskammer',icon:'🗄️'},
            frigo:{label:'Kühlschrank',icon:'🧊'}},
        document:{getElementById:id=>elements[id]||null},
    };
    vm.createContext(ctx);
    vm.runInContext(header+'\n'+stock+'\n'+expiry+
        '\nthis.paint=renderUsePreview;this.stock=_renderUseStockSummary;'+
        'this.expiry=_updateUseHeroMeta;this.identity=_renderProductStockIdentity;',ctx);
    return {ctx,elements};
}
function testSingleCardUse(){
    const {ctx,elements}=context();
    ctx.paint();
    const card=elements['use-product-preview'].innerHTML;
    assert(card.includes('class="product-stock-identity"'));
    assert(card.includes('class="use-inventory-info product-stock-card-stock" id="use-inventory-info"'));
    assert(card.includes('class="use-hero-name">Safari-Staging-Demo'));
    assert(card.includes('id="use-hero-meta"'),'Expiry remains part of the identity area');
    assert(card.includes('onclick="openUseInventoryEdit()"'),'Edit action preserved');
    assert(!card.includes('use-stock-summary'),'Stock is populated from validated GET, not invented');
    const summary=ctx.stock(rows);
    assert.equal((summary.match(/14 Packungen/g)||[]).length,1);
    assert(summary.includes('690 g je Packung · 9.660 g insgesamt'));
    assert(summary.includes('Vorratskammer'));
    assert(!summary.includes('product-stock-identity'),'Stock does not duplicate the product name');
    assert(html.includes('class="product-preview-small product-stock-card" id="use-product-preview"'));
    assert(!html.includes('<div class="use-inventory-info" id="use-inventory-info"></div>'),
        'No second top-level stock card in static HTML');
    ctx.paint();
    assert.equal((elements['use-product-preview'].innerHTML.match(/id="use-inventory-info"/g)||[]).length,1,
        'Reopening cannot duplicate the stock container');
    console.log('PASS Use combines identity + a single live stock summary inside one card');
}
function testSharedIdentityAndLongNames(){
    const product={name:'Sehr langes Produkt mit besonderer Packungsgröße',
        brand:'Marke',image_url:null,category:'conserve'};
    const {ctx}=context(null,product.name);
    const use=ctx.identity(product,true);
    const disposal=ctx.identity(product,false);
    assert(use.includes('product-stock-identity') && disposal.includes('product-stock-identity'));
    assert(use.includes(product.name) && disposal.includes(product.name));
    assert(use.includes('id="use-hero-meta"') && !disposal.includes('id="use-hero-meta"'));
    assert(use.includes('openUseInventoryEdit') && !disposal.includes('openUseInventoryEdit'));
    assert(css.includes('.product-stock-card .use-hero-name') &&
        css.includes('overflow-wrap: anywhere'),'Long product names can wrap on narrow iPhones');
    assert(css.includes('.product-stock-card .btn-edit-inline { flex-shrink: 0; }'),
        'Edit button remains visible beside a long product name');
    console.log('PASS same identity rendering, appropriate editing/expiry controls and long-name wrapping');
}
function testMultipleLocationsAndCardCss(){
    const {ctx}=context();
    const summary=ctx.stock([
        {id:1,product_id:42,location:'dispensa',unit:'conf',
            quantity:2,default_quantity:690,package_unit:'g'},
        {id:2,product_id:42,location:'frigo',unit:'conf',
            quantity:3,default_quantity:500,package_unit:'g'},
    ]);
    assert(summary.includes('5 Packungen'));
    assert(summary.includes('2.880 g insgesamt'));
    assert(!summary.includes('690 g je Packung'),'Mixed pack sizes cannot claim one pack size');
    assert(summary.includes('Vorratskammer') && summary.includes('Kühlschrank'));
    assert(css.includes('#page-use #use-product-preview.product-stock-card'));
    assert(css.includes('.product-preview-small.dispose-product-preview.product-stock-card'));
    assert(css.includes('.product-stock-card .use-stock-summary') &&
        css.includes('border-top: 1px solid var(--border)'),
        'One compact card has an internal divider, not a second bordered card');
    assert(css.includes('#page-use .product-stock-card .use-inventory-info') &&
        css.includes('box-shadow: none'),'Theme decorations removed from nested card only');
    console.log('PASS multiple locations/heterogeneous packages and scoped one-card CSS');
}
testSingleCardUse();
testSharedIdentityAndLongNames();
testMultipleLocationsAndCardCss();
console.log('All shared Use/Dispose product-stock card tests passed.');
