CREATE TABLE IF NOT EXISTS `%PREFIX%bot_llm_providers` (
  `provider` VARCHAR(20) NOT NULL,
  `api_key` TEXT NOT NULL,
  `model` VARCHAR(128) NOT NULL DEFAULT '',
  `base_url` VARCHAR(255) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `last_test_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_test_ok` TINYINT(1) NOT NULL DEFAULT 0,
  `last_test_message` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%bot_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_key` VARCHAR(32) NOT NULL,
  `name` VARCHAR(64) NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `settings` TEXT NOT NULL,
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profile_key` (`profile_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%bot_players` (
  `user_id` INT UNSIGNED NOT NULL,
  `universe` TINYINT UNSIGNED NOT NULL,
  `profile_id` INT UNSIGNED NOT NULL,
  `skill` FLOAT NOT NULL DEFAULT 0,
  `personality` TEXT NOT NULL,
  `next_tick` INT UNSIGNED NOT NULL DEFAULT 0,
  `session_until` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_action_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `actions_total` INT UNSIGNED NOT NULL DEFAULT 0,
  `persona` TEXT NULL,
  `last_message_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `llm_calls_day` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `llm_calls_date` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`),
  KEY `tick` (`universe`, `next_tick`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%bot_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `universe` TINYINT UNSIGNED NOT NULL,
  `time` INT UNSIGNED NOT NULL,
  `action` VARCHAR(32) NOT NULL,
  `detail` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `uni_time` (`universe`, `time`),
  KEY `user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `%PREFIX%bot_llm_providers` (`provider`, `api_key`, `model`, `base_url`) VALUES
('openai', '', '', 'https://api.openai.com/v1'),
('claude', '', '', 'https://api.anthropic.com/v1'),
('opencode_go', '', '', 'https://opencode.ai/zen/go/v1'),
('deepseek', '', '', 'https://api.deepseek.com'),
('openrouter', '', '', 'https://openrouter.ai/api/v1');

INSERT INTO `%PREFIX%cronjobs` (`cronjobID`, `name`, `isActive`, `min`, `hours`, `dom`, `month`, `dow`, `class`, `nextTime`, `lock`) VALUES
(NULL, 'bots', 1, '*', '*', '*', '*', '*', 'BotCronjob', 0, NULL);

INSERT IGNORE INTO `%PREFIX%bot_profiles` (`profile_key`, `name`, `description`, `is_default`, `settings`, `created_at`) VALUES
('office_worker', 'Office worker', 'Plays before work, at lunch and in the evening.', 1, '{"activity":[0.05,0.05,0.05,0.05,0.05,0.05,0.6,0.6,0.15,0.15,0.15,0.15,0.6,0.15,0.15,0.15,0.15,0.7,0.7,0.7,0.7,0.7,0.7,0.3],"check_interval":[20,60],"session_minutes":[5,15],"actions_per_session":[2,5],"weights":{"mines":42,"energy":15,"storage":6,"facilities":12,"research":17,"ships":5,"defense":3},"expedition_chance":6,"colonize_chance":30,"skill_start":0.75,"skill_max":0.9,"mistake_chance":8,"randomness":20,"reply_chance":50,"message_chance":3,"alliance_chance":10,"buddy_accept_chance":50,"chat_style":"short, polite, businesslike, few words","pattern_shift_hours":0,"pattern_shift_days":0,"skill_gain_per_day":0,"llm_advice":true}', 0),
('jobless', 'Jobless', 'Lots of time, online most of the afternoon and night.', 1, '{"activity":[0.6,0.6,0.6,0.6,0.1,0.1,0.1,0.1,0.1,0.1,0.5,0.5,0.8,0.8,0.8,0.8,0.8,0.8,0.8,0.8,0.8,0.8,0.8,0.8],"check_interval":[10,30],"session_minutes":[10,40],"actions_per_session":[3,9],"weights":{"mines":38,"energy":14,"storage":6,"facilities":12,"research":16,"ships":10,"defense":4},"expedition_chance":15,"colonize_chance":40,"skill_start":0.8,"skill_max":0.92,"mistake_chance":6,"randomness":25,"reply_chance":95,"message_chance":10,"alliance_chance":25,"buddy_accept_chance":70,"chat_style":"chatty, casual, relaxed, uses slang","pattern_shift_hours":0,"pattern_shift_days":0,"skill_gain_per_day":0,"llm_advice":true}', 0),
('shift_worker', 'Shift worker', 'Rotating shifts: the play pattern moves by 8 hours every week.', 1, '{"activity":[0.05,0.05,0.05,0.05,0.05,0.05,0.05,0.05,0.7,0.7,0.7,0.7,0.7,0.7,0.7,0.7,0.4,0.4,0.4,0.4,0.05,0.05,0.05,0.05],"check_interval":[15,45],"session_minutes":[5,25],"actions_per_session":[2,7],"weights":{"mines":40,"energy":15,"storage":7,"facilities":12,"research":16,"ships":6,"defense":4},"expedition_chance":8,"colonize_chance":30,"skill_start":0.75,"skill_max":0.9,"mistake_chance":9,"randomness":25,"reply_chance":75,"message_chance":5,"alliance_chance":20,"buddy_accept_chance":60,"chat_style":"medium length, friendly, a bit tired","pattern_shift_hours":8,"pattern_shift_days":7,"skill_gain_per_day":0,"llm_advice":true}', 0),
('lunatic', 'Lunatic', 'Always online, long sessions, plays almost perfectly.', 1, '{"activity":[0.7,0.7,0.7,0.7,0.7,0.7,0.7,0.7,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9],"check_interval":[3,10],"session_minutes":[20,60],"actions_per_session":[5,15],"weights":{"mines":36,"energy":13,"storage":5,"facilities":14,"research":20,"ships":9,"defense":3},"expedition_chance":25,"colonize_chance":60,"skill_start":0.92,"skill_max":0.98,"mistake_chance":2,"randomness":10,"reply_chance":95,"message_chance":15,"alliance_chance":30,"buddy_accept_chance":80,"chat_style":"very chatty, enthusiastic, long messages, emojis","pattern_shift_hours":0,"pattern_shift_days":0,"skill_gain_per_day":0,"llm_advice":true}', 0),
('noob', 'Noob', 'Still learning: makes many mistakes, improves slowly.', 1, '{"activity":[0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.3,0.3],"check_interval":[15,90],"session_minutes":[3,15],"actions_per_session":[1,5],"weights":{"mines":25,"energy":10,"storage":10,"facilities":15,"research":10,"ships":15,"defense":15},"expedition_chance":5,"colonize_chance":15,"skill_start":0.2,"skill_max":0.85,"skill_gain_per_day":0.03,"mistake_chance":45,"randomness":40,"reply_chance":90,"message_chance":6,"alliance_chance":40,"buddy_accept_chance":85,"chat_style":"friendly, curious newbie, asks naive questions, typos","pattern_shift_hours":0,"pattern_shift_days":0,"llm_advice":true}', 0);
