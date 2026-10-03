// Consimțământul pentru măsurare (GA4). Până când vizitatorul apasă „Accept", de la Google nu se încarcă nimic:
// nici scriptul, nici cookie-urile. Alegerea stă în browser (localStorage), nu într-un cookie, și se schimbă din
// „Setări cookie", în subsol. Identificatorul GA4 vine din atributul data-ga4, pus de șablon (nu din conținut).
(function () {
  var CHEIE = 'minicms-consimtamant-ga4';
  var eticheta = document.currentScript;
  var id = eticheta ? eticheta.getAttribute('data-ga4') : '';
  var banner = document.getElementById('consimtamant');
  if (!id || !banner) return;

  function citeste() { try { return localStorage.getItem(CHEIE); } catch (e) { return null; } }
  function scrie(v) { try { localStorage.setItem(CHEIE, v); } catch (e) {} }

  // Consent Mode v2 (data-mod="da"): eticheta se încarcă de la început, cu stocarea refuzată. Fără cookie-uri și fără identificator,
  // Google primește doar semnale anonime de vizită; la „Accept" trece pe acordat și măsurarea devine completă. Reclamele rămân
  // mereu refuzate: site-ul nu face reclame.
  var mod = eticheta && eticheta.getAttribute('data-mod') === 'da';

  var pornit = false;
  function porneste(acordat) {
    if (pornit) return;
    pornit = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    if (mod) {
      window.gtag('consent', 'default', {
        analytics_storage: acordat ? 'granted' : 'denied',
        ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied'
      });
    }
    window.gtag('js', new Date());
    window.gtag('config', id);
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
    document.head.appendChild(s);
  }
  function actualizeaza(acordat) {
    if (pornit && window.gtag) window.gtag('consent', 'update', { analytics_storage: acordat ? 'granted' : 'denied' });
  }

  // La refuz, cookie-urile puse de GA4 la un acord anterior (_ga, _ga_<id>) se șterg. GA4 le pune pe domeniul cel mai
  // larg pe care îl poate folosi (pe cms.paycode.ro, pe .paycode.ro), deci se încearcă fiecare domeniu părinte al gazdei.
  function stergeCookieGa() {
    var parti = location.hostname.split('.'), domenii = [''];
    for (var i = 0; i < parti.length - 1; i++) {
      var d = parti.slice(i).join('.');
      domenii.push(';domain=' + d, ';domain=.' + d);
    }
    document.cookie.split(';').forEach(function (c) {
      var nume = c.split('=')[0].trim();
      if (!/^_ga/.test(nume)) return;
      domenii.forEach(function (d) {
        document.cookie = nume + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/' + d;
      });
    });
  }

  banner.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-consimtamant]');
    if (!b) return;
    var alegere = b.getAttribute('data-consimtamant') === 'da' ? 'da' : 'nu';
    scrie(alegere);
    banner.hidden = true;
    if (alegere === 'da') { if (pornit) actualizeaza(true); else porneste(true); return; }
    stergeCookieGa();
    if (mod) { actualizeaza(false); return; }   // cu Consent Mode, refuzul se comunică etichetei; nu e nevoie de reîncărcare
    if (pornit) location.reload();   // scriptul Google deja încărcat nu se poate descărca altfel
  });

  document.querySelectorAll('[data-consimtamant-deschide]').forEach(function (b) {
    b.addEventListener('click', function () { banner.hidden = false; });
  });

  var ales = citeste();
  if (ales === 'da') porneste(true);
  else {
    if (mod) porneste(false);   // înainte de acord și după refuz: eticheta rulează, dar fără stocare
    if (ales !== 'nu') banner.hidden = false;
  }
})();
