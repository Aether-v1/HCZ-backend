-- Global message dedupe index
-- Purpose: prevent duplicated global message fanout rows under concurrent writes.
-- Execute once on production database.
ALTER TABLE `cz_user_message`
ADD UNIQUE KEY `uk_user_message_global_biz` (`user_id`, `message_type`, `biz_id`);
