(function(){
'use strict';
function fmt(n){try{return new Intl.NumberFormat('fa-IR').format(n);}catch(e){return String(n);}}
function chart(canvas){
  var labels=[],values=[];try{labels=JSON.parse(canvas.dataset.labels||'[]');values=JSON.parse(canvas.dataset.values||'[]');}catch(e){return;}
  var type=canvas.dataset.chart||'bar', unit=canvas.dataset.valueLabel||'';
  function draw(){
    var rect=canvas.getBoundingClientRect(), dpr=window.devicePixelRatio||1, w=Math.max(320,rect.width), h=Math.max(220,rect.height);
    canvas.width=w*dpr;canvas.height=h*dpr;var c=canvas.getContext('2d');c.scale(dpr,dpr);c.clearRect(0,0,w,h);
    var pad={t:18,r:48,b:42,l:18}, pw=w-pad.r-pad.l, ph=h-pad.t-pad.b, max=Math.max.apply(null,values.concat([1]));
    c.font='11px Tahoma, Arial';c.textAlign='right';c.textBaseline='middle';
    for(var i=0;i<=4;i++){var y=pad.t+ph*(i/4);c.strokeStyle='#eef2f6';c.lineWidth=1;c.beginPath();c.moveTo(pad.l,y);c.lineTo(w-pad.r,y);c.stroke();c.fillStyle='#98a2b3';var tick=Math.round(max*(1-i/4));c.fillText(fmt(tick),w-4,y);}
    var step=pw/Math.max(labels.length,1);
    c.textAlign='center';c.textBaseline='top';
    labels.forEach(function(l,i){if(labels.length>9 && i%2!==0)return;c.fillStyle='#98a2b3';c.fillText(l,pad.l+step*(i+.5),h-pad.b+10);});
    if(type==='bar'){
      var bw=Math.max(5,Math.min(24,step*.55));
      values.forEach(function(v,i){var bh=(v/max)*ph,x=pad.l+step*(i+.5)-bw/2,y=pad.t+ph-bh;var g=c.createLinearGradient(0,y,0,pad.t+ph);g.addColorStop(0,'#155eef');g.addColorStop(1,'#84adff');c.fillStyle=g;roundRect(c,x,y,bw,Math.max(2,bh),5);c.fill();});
    } else {
      c.strokeStyle='#d92d20';c.lineWidth=2.5;c.lineJoin='round';c.lineCap='round';c.beginPath();
      values.forEach(function(v,i){var x=pad.l+step*(i+.5),y=pad.t+ph-(v/max)*ph;if(i===0)c.moveTo(x,y);else c.lineTo(x,y);});c.stroke();
      values.forEach(function(v,i){var x=pad.l+step*(i+.5),y=pad.t+ph-(v/max)*ph;c.fillStyle='#fff';c.strokeStyle='#d92d20';c.lineWidth=2;c.beginPath();c.arc(x,y,4,0,Math.PI*2);c.fill();c.stroke();});
    }
  }
  draw(); if(window.ResizeObserver){new ResizeObserver(draw).observe(canvas.parentElement);} else {window.addEventListener('resize',draw);}
}
function roundRect(c,x,y,w,h,r){r=Math.min(r,w/2,h/2);c.beginPath();c.moveTo(x+r,y);c.arcTo(x+w,y,x+w,y+h,r);c.arcTo(x+w,y+h,x,y+h,r);c.arcTo(x,y+h,x,y,r);c.arcTo(x,y,x+w,y,r);c.closePath();}
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.sadad-ui-chart').forEach(chart);
  document.querySelectorAll('.sadad-ui-password-toggle').forEach(function(btn){btn.addEventListener('click',function(){var el=document.getElementById(btn.dataset.target);if(!el)return;var show=el.type==='password';el.type=show?'text':'password';btn.textContent=show?'پنهان':'نمایش';});});
  document.querySelectorAll('.sadad-ui-copy').forEach(function(btn){btn.addEventListener('click',function(){var el=document.getElementById(btn.dataset.copyTarget);if(!el)return;var value=el.textContent||'';if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(value).then(function(){var t=btn.textContent;btn.textContent='کپی شد';setTimeout(function(){btn.textContent=t;},1200);});}});});
});
})();
