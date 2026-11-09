# Viki 0.8.2

Viki je PHP/MySQL aplikace pro správu produktových dat více e-shopů. Verze 0.8.2 navíc opravuje připojení k API na hostingách, které nepodporují Apache `mod_rewrite` nebo ignorují soubory `.htaccess`.

## Rychlá oprava přihlášení

1. Nahraj celý obsah balíku 0.8.2 přes stávající soubory, ale nepřepisuj svůj `.env`.
2. Není potřeba nový SQL import; `php bin/migrate.php` lze bezpečně spustit znovu.
3. V prohlížeči proveď tvrdé obnovení `Ctrl+F5`, aby se nenačetla stará přihlašovací stránka z cache.
4. Přihlas se znovu. Viki automaticky vyzkouší standardní API i přímý vstup přes `api.php` nebo `public/index.php`.

Soubor `api.php` v kořeni balíku je kompatibilní vstup pro hostingy bez přepisu URL. Není nutné ručně měnit adresu API ani aktivovat `.htaccess`.

Rozhraní je dostupné v `public/admin/`. Kopie v kořenovém `admin/` zůstává kvůli kompatibilitě s hostingy, které používají kořen balíku jako document root.

## Požadavky

- PHP 8.1+ s PDO MySQL, cURL, OpenSSL a mbstring
- MySQL 8.0+ nebo MariaDB 10.6+
- HTTPS pro Viki i připojené WordPress weby
- webroot nastavený na adresář `public/`

## Aktualizace z předchozí verze

1. Zálohuj databázi a současnou aplikaci.
2. Nahraj soubory Viki 0.8, ale zachovej vlastní `.env`.
3. Doplň nové hodnoty podle `.env.example`, zejména silný `APP_KEY`, `APP_URL` a `ALLOWED_ORIGINS`.
4. Spusť `php bin/migrate.php`. Migrace je opakovatelná a nesmaže katalog.
5. Nastav práva pro zápis do `storage/private/`; tento adresář nesmí být veřejně dostupný.
6. Ve WordPressu nahraď starý konektor balíčkem `viki-connector-0.8.0.zip`.
7. Ověř připojení na stránce **Nastavení → E-shopy** a teprve potom spusť první import.

## Čistá instalace

Načti v tomto pořadí:

1. `database/sql/feedhub_v2_auth_permissions.sql`
2. `database/sql/feedhub_v2_1_catalog_schema.sql`
3. `database/sql/feedhub_v2_2_store_connections.sql`
4. `database/sql/feedhub_v2_4_woo_sync_prepare.sql`
5. `php bin/migrate.php`

Původní tabulky `product_woo_map` a `category_woo_map` nejsou pro novou instalaci potřeba. Migrace ale jejich data při upgradu převede do kanonických tabulek `woo_product_map` a `woo_category_map`.

## Bezpečnostní poznámky

- Token konektoru se v databázi šifruje pomocí `APP_KEY` a API jej nikdy neposílá do prohlížeče.
- Připojit lze pouze veřejnou HTTPS adresu; lokální a privátní IP jsou odmítnuty.
- Přihlášení má limit neúspěšných pokusů a token relace je v prohlížeči uložen jen po dobu panelu/karty.
- Produktové HTML se čistí na serveru. Před veřejným nasazením přesto doporučujeme bezpečnostní test ve stagingu.
- Změna `APP_KEY` znemožní dešifrovat již uložené tokeny konektorů.

## Co obsahuje 0.8

- serverové hledání a stránkování katalogu až po 200 položkách na stránku,
- bezpečně povolené filtrování a řazení v API,
- tabulku `product_variants` a import variant WooCommerce,
- synchronizaci simple i variable produktů,
- stránkovaný export ve WordPress konektoru,
- migraci zdrojových identit, map WooCommerce a auditního základu,
- responzivní administraci ve stylu Karel/Linda s identitou Viki.

AI generování popisů není v 0.8 aktivováno. Rozhraní na něj připravuje kontext Viki, ale nepředstírá spuštěné AI úlohy.
