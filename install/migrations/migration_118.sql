-- Expedition Deck Configuration Table (per universe)
CREATE TABLE IF NOT EXISTS `%PREFIX%expedition_deck_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `universe` INT NOT NULL,
  `deck_data` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uni_deck` (`universe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expedition User Deck Table (per user per universe)
CREATE TABLE IF NOT EXISTS `%PREFIX%expedition_user_deck` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `universe` INT NOT NULL,
  `deck_array` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `user_uni_deck` (`user_id`, `universe`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_universe` (`universe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize default deck configuration for all universes
INSERT INTO `%PREFIX%expedition_deck_config` (`universe`, `deck_data`) 
SELECT `uni`, '[
{"type":"Res","size":"Small","count":329},
{"type":"Res","size":"Medium","count":37},
{"type":"Res","size":"Big","count":4},
{"type":"Ships","size":"Small","count":235},
{"type":"Ships","size":"Medium","count":26},
{"type":"Ships","size":"Big","count":3},
{"type":"Pirates","size":"Small","count":51},
{"type":"Pirates","size":"Medium","count":5},
{"type":"Pirates","size":"Big","count":1},
{"type":"Aliens","size":"Small","count":23},
{"type":"Aliens","size":"Medium","count":2},
{"type":"Aliens","size":"Big","count":1},
{"type":"Fast","size":"Small","count":17},
{"type":"Fast","size":"Medium","count":4},
{"type":"Fast","size":"Big","count":2},
{"type":"Slow","size":"Small","count":48},
{"type":"Slow","size":"Medium","count":13},
{"type":"Slow","size":"Big","count":6},
{"type":"BlackHole","size":"None","count":3},
{"type":"Nothing","size":"None","count":190}
]'
FROM `%PREFIX%config_universe`
GROUP BY `uni`
ON DUPLICATE KEY UPDATE `deck_data` = VALUES(`deck_data`);
