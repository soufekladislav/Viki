# Bezpečnostní upozornění k upgradu

V původním dodaném balíku byl nalezen soubor `bootstrap/wk/index.php`, který se maskoval jako GIF a funkcí `eval()` spouštěl PHP stažené z cizího serveru. Viki 0.8 tento soubor neobsahuje.

Pokud byla původní verze nasazena na server, samotná výměna souborů nestačí. Před dalším provozem:

1. izoluj instalaci a zkontroluj webroot, crony, naplánované úlohy, administrátory WordPressu a nedávno změněné PHP soubory,
2. změň databázové heslo, `APP_KEY`, hesla správců a všechny tokeny WooCommerce konektorů,
3. zneplatni aktivní relace a projdi přístupové i PHP error logy,
4. nasaď čistý balík 0.8, nikoli kopii starého adresáře,
5. proveď kontrolu hostingu nebo incident response, pokud byl soubor z internetu dostupný.

Změna `APP_KEY` vyžaduje znovu uložit tokeny konektorů, protože staré zašifrované hodnoty už nepůjde dešifrovat.
