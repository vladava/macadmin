-- Spustte na NOVEM serveru az po importu tabulek FreeRADIUS:
--   mysql -u root -p radius < setup.sql

-- Udaje o zarizenich (jmeno, trida, poznamka)
CREATE TABLE IF NOT EXISTS mac_info (
  mac        VARCHAR(17)  NOT NULL PRIMARY KEY,
  jmeno      VARCHAR(100) NOT NULL DEFAULT '',
  trida      VARCHAR(20)  NOT NULL DEFAULT '',
  poznamka   VARCHAR(255) NOT NULL DEFAULT '',
  vytvoreno  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  upraveno   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY trida (trida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Smazana zarizeni - import ze souboru Forms je znovu neprida (rucni pridani zaznam zrusi)
CREATE TABLE IF NOT EXISTS mac_smazane (
  mac        VARCHAR(17)  NOT NULL PRIMARY KEY,
  smazano    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- udaje smazaneho zarizeni pro obnoveni; smazano = davka (stejny cas pro jedno smazani)
ALTER TABLE mac_smazane
  ADD COLUMN IF NOT EXISTS jmeno    VARCHAR(100) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS trida    VARCHAR(20)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS poznamka VARCHAR(255) NOT NULL DEFAULT '',
  ADD INDEX IF NOT EXISTS smazano (smazano);

-- Stav automatickych uloh (import z OneDrive, nocni zaloha) pro zobrazeni v Udrzbe
CREATE TABLE IF NOT EXISTS macadmin_stav (
  klic       VARCHAR(50)  NOT NULL PRIMARY KEY,
  hodnota    TEXT         NOT NULL,
  zmeneno    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historie importu, ktere pridaly zarizeni (Udrzba); starsi nez rok maze udrzba.php
CREATE TABLE IF NOT EXISTS import_historie (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cas        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  zdroj      VARCHAR(60)  NOT NULL,
  pridano    INT          NOT NULL,
  doplneno   INT          NOT NULL,
  zarizeni   TEXT         NOT NULL,
  KEY cas (cas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- radcheck: kazda MAC jen jednou (stary daloRADIUS nechaval duplicity); nejdriv smazat nadbytecne radky
DELETE r1 FROM radcheck r1 JOIN radcheck r2
  ON r1.username = r2.username AND r1.attribute = r2.attribute AND r1.id > r2.id;
ALTER TABLE radcheck ADD UNIQUE INDEX IF NOT EXISTS user_attr (username, attribute);

-- Index pro rychle hledani podle data (zamitnuta zarizeni, promazavani)
-- (IF NOT EXISTS: skript jde spustit opakovane)
ALTER TABLE radpostauth ADD INDEX IF NOT EXISTS authdate (authdate);
ALTER TABLE radpostauth ADD INDEX IF NOT EXISTS reply_date (reply, authdate);
-- posledni prihlaseni zarizeni (sloupec Naposledy, filtr neprihlasenych)
ALTER TABLE radpostauth ADD INDEX IF NOT EXISTS user_reply_date (username, reply, authdate);

-- Uzivatel pro webove rozhrani - jen nutna prava
CREATE USER IF NOT EXISTS 'macadmin'@'localhost' IDENTIFIED BY 'ZMENTE_HESLO';
GRANT SELECT, INSERT, UPDATE, DELETE ON radius.radcheck    TO 'macadmin'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON radius.mac_info    TO 'macadmin'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON radius.mac_smazane TO 'macadmin'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON radius.macadmin_stav TO 'macadmin'@'localhost';
GRANT SELECT, INSERT, DELETE ON radius.import_historie TO 'macadmin'@'localhost';
GRANT SELECT, DELETE                 ON radius.radpostauth TO 'macadmin'@'localhost';
FLUSH PRIVILEGES;
