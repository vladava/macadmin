<?php
// Zkopirujte jako config.php a upravte.
return [
    // Pripojeni k databazi FreeRADIUS
    'db_dsn'  => 'mysql:host=localhost;dbname=radius;charset=utf8mb4',
    'db_user' => 'macadmin',
    'db_pass' => 'ZMENTE_HESLO',

    // Jediny ucet spravce.
    // Hash hesla vytvorite prikazem:
    //   php -r 'echo password_hash("vase-heslo", PASSWORD_DEFAULT), PHP_EOL;'
    'admin_user'      => 'admin',
    'admin_pass_hash' => '$2y$10$NAHRADTE_HASHEM',

    // Jak se v tabulce radcheck zapisuje povolena MAC adresa.
    // Overte na starem serveru:
    //   SELECT attribute, op, value, COUNT(*) FROM radcheck GROUP BY 1,2,3;
    'radcheck_attribute' => 'Auth-Type',
    'radcheck_op'        => ':=',
    'radcheck_value'     => 'Accept',

    // Sdileny odkaz na Excel s odpovedmi z MS Forms na OneDrive / SharePoint
    // (sdileni "Kdokoli s odkazem"). Prazdne = import jen nahranim souboru.
    'forms_url' => '',

    // Kam poslat e-mail, kdyz automaticky import z OneDrive selze (prazdne = neposilat).
    // Posila se pres mistni postfix (PHP mail()); vyzkouset: php import-forms.php --test-mail
    'alert_email' => '',

    // Kolik dni historie prihlaseni (radpostauth) uchovavat
    'postauth_keep_days' => 90,

    // Za kolik dni zobrazovat zamitnuta zarizeni
    'rejected_days' => 7,

    // Odhlaseni po neaktivite (sekundy)
    'session_timeout' => 3600,
];
