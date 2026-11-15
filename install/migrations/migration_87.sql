-- move timestamp to the start of the table

ALTER TABLE `%PREFIX%advanced_stats_history`    MODIFY `timestamp` int FIRST;
ALTER TABLE `%PREFIX%statpoints_history`        MODIFY `timestamp` int FIRST;
