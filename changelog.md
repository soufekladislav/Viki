# Changelog

## 0.8.2 — 2026-08-26

- přidán přímý vstup `api.php` pro hostingy bez Apache `mod_rewrite` a bez aktivního `.htaccess`,
- automatická detekce standardního i přímého API v kořeni, podsložce a adresáři `public`,
- zachování parametrů stránkování a filtrů i při přímém volání front controlleru,
- přímé API se používá také na stránce Nastavení,
- přesnější diagnostická hláška při nedostupném PHP nebo chybějících souborech.
- favicon aplikace změněn na ikonu Fresh Crackers.

## 0.8.1 — 2026-08-25

- automatické rozpoznání umístění API v kořeni, podsložce i adresáři `public`,
- odstranění obecné hlášky „Login failed“ a české rozlišení chyby hesla, API, databáze a konfigurace,
- zpětná kompatibilita se starším kratším `APP_KEY` bez zablokování loginu; po přihlášení se zobrazí bezpečnostní upozornění,
- nová responzivní přihlašovací stránka ve stylu Karla a Lindy s popisem současných funkcí i další etapy Viki,
- přepínač zobrazení hesla, přihlášení Enterem a stav načítání.

## 0.8.0 — 2026-08-25

- zabezpečení tokenů konektorů, CORS, TLS/SSRF kontrola, rate limit loginu a kontrola oprávnění e-shopu,
- migrace databáze a sjednocení WooCommerce mapovacích tabulek,
- zdrojová identita produktů a normalizované varianty,
- serverové stránkování, hledání, filtry a řazení katalogu,
- import a synchronizace variantních produktů,
- nový Viki Connector 0.8 se stránkovaným exportem,
- nové responzivní UI a branding Viki / Fresh Crackers,
- odstranění tajných údajů, logů a lokálních záloh z distribučního balíčku.
- odstranění skrytého vzdáleného PHP loaderu nalezeného v původním balíku; viz `SECURITY.md`.
