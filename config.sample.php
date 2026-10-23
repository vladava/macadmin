<?php
// Zkopirujte jako config.php a upravte. Chybejici volby ukaze aplikace na strance Udrzba.
return [
    // --- Vzhled ---
    // Nazev aplikace v menu a v titulku stranky
    'app_name' => 'Školní wifi – MAC adresy',
    // Jak se jmenuje wifi sit v textech pro spravce ("pripojit k siti ...")
    'wifi_name' => 'školní wifi',

    // --- Pripojeni k databazi FreeRADIUS ---
    'db_dsn'  => 'mysql:host=localhost;dbname=radius;charset=utf8mb4',
    'db_user' => 'macadmin',
    'db_pass' => 'ZMENTE_HESLO',

    // --- Jediny ucet spravce ---
    // Hash hesla vytvorite prikazem:
    //   php -r 'echo password_hash("vase-heslo", PASSWORD_DEFAULT), PHP_EOL;'
    'admin_user'      => 'admin',
    'admin_pass_hash' => '$2y$10$NAHRADTE_HASHEM',

    // --- Jak se v tabulce radcheck zapisuje povolena MAC adresa ---
    // Overte na starem serveru:
    //   SELECT attribute, op, value, COUNT(*) FROM radcheck GROUP BY 1,2,3;
    'radcheck_attribute' => 'Auth-Type',
    'radcheck_op'        => ':=',
    'radcheck_value'     => 'Accept',

    // --- Zaci ---
    // Domena e-mailu zaku, napr. '@zaci.skola.cz'. Hromadne mazani v seznamu zarizeni pak smaze jen zarizeni
    // s timto e-mailem v poznamce; ostatni (ucitele, technika) jen vypise. Prazdne = hromadne mazani bez omezeni.
    'student_email_domain' => '',
    // Jak z e-mailu zaka odvodit tridu: regularni vyraz, prvni zavorka = trida. Prazdne = tridu neodvozovat.
    // Napr. pro e-maily 2026.g.prijmeni@zaci.skola.cz (rok nastupu + pismeno) -> trida 2026.g:
    //   '/^(\d{4}\.[a-z])\./i'
    'trida_regex' => '',

    // --- Import z MS Forms ---
    // Sdileny odkaz na Excel s odpovedmi na OneDrive / SharePoint (sdileni "Kdokoli s odkazem").
    // Prazdne = import jen nahranim souboru.
    'forms_url' => '',

    // --- Upozorneni e-mailem, kdyz automaticky import selze ---
    // Prijemce (prazdne = neposilat). Posila se pres mistni postfix (PHP mail());
    // vyzkouset: sudo -u apache php import-forms.php --test-mail
    'alert_email' => '',
    // Odesilatel (prazdne = macadmin@<jmeno serveru>)
    'alert_from' => '',

    // --- Ostatni ---
    // Kolik dni historie prihlaseni (radpostauth) uchovavat
    'postauth_keep_days' => 90,
    // Za kolik dni zobrazovat zamitnuta zarizeni
    'rejected_days' => 7,
    // Odhlaseni po neaktivite (sekundy)
    'session_timeout' => 3600,
];
