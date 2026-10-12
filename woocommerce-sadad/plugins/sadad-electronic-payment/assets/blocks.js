(function(){
'use strict';
var registry = window.wc && window.wc.wcBlocksRegistry;
var settings = window.wc && window.wc.wcSettings;
if (!registry || !settings) return;

var el = window.wp.element.createElement;
var decode = window.wp.htmlEntities.decodeEntities;
var data = settings.getSetting('sadad_electronic_payment_data', {});
var title = decode(data.title || 'پرداخت با کارت بانکی');

function Label(){
  return el('span', {style:{display:'inline-flex',alignItems:'center',gap:'.6rem',fontWeight:700}},
    data.icon ? el('img', {src:data.icon, alt:'', style:{width:'2rem',height:'2rem',objectFit:'contain'}}) : null,
    title
  );
}

function Content(){
  return el('div', {className:'sadad-electronic-payment-checkout'},
    data.description ? el('p', null, decode(data.description)) : null,
    el('div', {className:'sadad-electronic-payment-features'},
      (data.features || []).map(function(f){ return el('span', {key:f}, f); })
    )
  );
}

registry.registerPaymentMethod({
  name: 'sadad_electronic_payment',
  label: el(Label),
  ariaLabel: title,
  content: el(Content),
  edit: el(Content),
  canMakePayment: function(){ return true; },
  supports: { features: data.supports || ['products'] }
});
})();
