CREATE TABLE `%PREFIX%planet_data` (
  `universe` INT unsigned not NULL,
  `planet_position` INT unsigned not NULL,
  `temp_min` INT,
  `temp_max` INT,
  `fields_min` INT unsigned,
  `fields_max` INT unsigned,
  `metalBonusPercent` FLOAT,
  `crystalBonusPercent` FLOAT,
  `deuteriumBonusPercent` FLOAT,
  PRIMARY KEY (`universe`,`planet_position`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `%PREFIX%planet_data` (`universe`, `planet_position`, `temp_min`, `temp_max`, `fields_min`, `fields_max`, `metalBonusPercent`, `crystalBonusPercent`, `deuteriumBonusPercent`) VALUES
(0, 1,   220,  260,     96,   172,   0,   0,    0),
(0, 2,   170,  210,    104,   176,   0,   0,    0),
(0, 3,   120,  160,    112,   182,   0,   0,    0),
(0, 4,    70,  110,    118,   208,   0,   0,    0),
(0, 5,    60,  100,    133,   232,   0,   0,    0),
(0, 6,    50,   90,    146,   242,   0,   0,    0),
(0, 7,    40,   80,    152,   248,   0,   0,    0),
(0, 8,    30,   70,    156,   252,   0,   0,    0),
(0, 9,    20,   60,    150,   246,   0,   0,    0),
(0, 10,   10,   50,    142,   232,   0,   0,    0),
(0, 11,    0,   40,    136,   210,   0,   0,    0),
(0, 12,  -10,   30,    125,   186,   0,   0,    0),
(0, 13,  -50,  -10,    114,   172,   0,   0,    0),
(0, 14,  -90,  -50,    100,   168,   0,   0,    0),
(0, 15, -130,  -90,     90,   164,   0,   0,    0);
