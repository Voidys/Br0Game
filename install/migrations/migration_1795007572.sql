-- Shield domes and special defence (407-411) had class 0 and the class in ship_type (shifted columns, like the
-- missiles fixed in migration_1794787211), so they landed in the buildings group: the shipyard refused them and the
-- buildings page never offered them. 407/408 are regular defence (class 400); 409-411 are the special defence
-- (class 401, not buildable, like the special ships 216-219 with class 201).
UPDATE `%PREFIX%vars` SET `class` = 400, `ship_type` = NULL WHERE `elementID` IN (407, 408);
UPDATE `%PREFIX%vars` SET `class` = 401, `ship_type` = NULL WHERE `elementID` IN (409, 410, 411);
