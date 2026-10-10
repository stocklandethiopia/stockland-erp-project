(function(){
  'use strict';
  const A=window.StocklandApp||{};
  let deferredPrompt=null;
  const isStandalone=()=>window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true;
  const isIOS=()=>/iphone|ipad|ipod/i.test(navigator.userAgent);
  const isMobile=()=>/android|iphone|ipad|ipod/i.test(navigator.userAgent);
  function addInstallButton(){
    if(isStandalone()) return;
    const mobile=isMobile() || window.innerWidth<=767;
    const host=mobile
      ? document.querySelector('.sl-mobile-menu .sl-mobile-menu-foot')
      : document.querySelector('.sl-user');
    if(!host || document.getElementById('sl-pwa-install'))return;
    const btn=document.createElement('button');
    btn.type='button'; btn.id='sl-pwa-install'; btn.className='sl-btn sl-pwa-install'+(mobile?' sl-pwa-install-mobile':'');
    btn.textContent=mobile?(isIOS()?'ADD HOME':'INSTALL'):(isIOS()?'Add to Home':'Install App');
    btn.title=isIOS()?'Add Stockland ERP to your home screen':'Install Stockland ERP as an app';
    btn.setAttribute('aria-label',btn.title);
    btn.addEventListener('click',async()=>{
      if(deferredPrompt){
        deferredPrompt.prompt();
        try{await deferredPrompt.userChoice;}catch(e){}
        deferredPrompt=null; btn.remove(); return;
      }
      const overlay=document.createElement('div');
      overlay.className='sl-overlay';
      const help=isIOS()
        ? 'On iPhone/iPad: tap Share, then choose “Add to Home Screen”.'
        : 'Use your browser menu and choose “Install app” or “Add to home screen”.';
      overlay.innerHTML='<div class="sl-drawer sl-pwa-help"><div class="sl-page-title"><div><div class="sl-eyebrow">STOCKLAND ETHIOPIA</div><h2 style="font-size:21px">Install Stockland ERP</h2><p>'+help+'</p></div><button class="sl-btn" id="sl-pwa-close">Close</button></div><div class="sl-notice">The installed ERP keeps the live database online. It does not create a second offline copy of inventory or sales data.</div></div>';
      document.body.appendChild(overlay);
      overlay.onclick=e=>{if(e.target===overlay)overlay.remove()};
      const close=overlay.querySelector('#sl-pwa-close'); if(close)close.onclick=()=>overlay.remove();
    });
    if(mobile)host.appendChild(btn); else host.insertBefore(btn,host.querySelector('.sl-exit')||null);
  }

  function observeShell(){
    addInstallButton();
    const root=document.getElementById('stockland-app');
    if(!root||window.__stocklandPwaObserver)return;
    window.__stocklandPwaObserver=new MutationObserver(()=>addInstallButton());
    window.__stocklandPwaObserver.observe(root,{subtree:true,childList:true});
  }
  window.addEventListener('stockland:mobile-menu-open',()=>addInstallButton());
  window.addEventListener('resize',()=>{ if(!isStandalone()) { const b=document.getElementById('sl-pwa-install'); if(b) b.remove(); addInstallButton(); } });
  window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();deferredPrompt=e;addInstallButton();});
  window.addEventListener('appinstalled',()=>{deferredPrompt=null;const b=document.getElementById('sl-pwa-install');if(b)b.remove();});
  async function registerShellServiceWorker(){
    if(!('serviceWorker' in navigator) || !A.sw || !A.home || !window.isSecureContext) return;
    try{
      const home=new URL(A.home,window.location.origin);
      // Register only on a known Stockland ERP entry path. Never let the worker control
      // the whole WordPress site, especially when another application shares the same domain.
      let path=home.pathname||'/';
      if(path==='/' || !/\/stockland(?:-erp(?:-app)?)?\/$/.test(path)) return;
      const existing=await navigator.serviceWorker.getRegistration(path);
      const registration=await navigator.serviceWorker.register(A.sw,{scope:path});
      if(existing && typeof registration.update==='function'){ try{ await registration.update(); }catch(_e){} }
    }catch(e){ /* PWA install remains available; shell caching is optional. */ }
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{observeShell();registerShellServiceWorker();}); else {observeShell();registerShellServiceWorker();}
})();
