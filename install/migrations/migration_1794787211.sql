-- Missiles (502 interceptor, 503 interplanetary) had class 0 and ship_type 500 (shifted columns), so they were
-- missing from reslist['missile']. Class 500 is the missile element class.
UPDATE `%PREFIX%vars` SET `class` = 500, `ship_type` = NULL WHERE `elementID` IN (502, 503);
