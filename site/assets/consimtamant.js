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

  var pornit = false;
  function porneste() {
    if (pornit) return;
    pornit = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', id);
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
    document.head.appendChild(s);
  }

  // La refuz, cookie-urile puse de GA4 la un acord anterior (_ga, _ga_<id>) se șterg, pe domeniu și pe subdomeniu.
  function stergeCookieGa() {
    var gazda = location.hostname.replace(/^www\./, '');
    document.cookie.split(';').forEach(function (c) {
      var nume = c.split('=')[0].trim();
      if (!/^_ga/.test(nume)) return;
      ['', ';domain=' + gazda, ';domain=.' + gazda].forEach(function (d) {
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
    if (alegere === 'da') { porneste(); return; }
    stergeCookieGa();
    if (pornit) location.reload();   // scriptul Google deja încărcat nu se poate descărca altfel
  });

  document.querySelectorAll('[data-consimtamant-deschide]').forEach(function (b) {
    b.addEventListener('click', function () { banner.hidden = false; });
  });

  var ales = citeste();
  if (ales === 'da') porneste();
  else if (ales !== 'nu') banner.hidden = false;
})();
