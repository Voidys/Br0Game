-- reCAPTCHA is replaced by the self-hosted proof-of-work bot check (settings pow_max_number, pow_min_seconds have defaults)
DROP TABLE IF EXISTS `%PREFIX%recaptcha`;
DELETE FROM `%PREFIX%config` WHERE `key` IN ('recaptcha_priv_key', 'recaptcha_pub_key');
DELETE FROM `%PREFIX%config_universe` WHERE `key` IN ('recaptcha_priv_key', 'recaptcha_pub_key');
