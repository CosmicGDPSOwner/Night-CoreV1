UPDATE `{{prefix}}core_staff_permissions`
SET `description` = CASE `permissionKey`
    WHEN 'levels.rate' THEN 'Rate or unrate levels. Commands: !rate <1-10>, !unrate'
    WHEN 'levels.feature' THEN 'Feature levels. Command: !feature <1-10>'
    WHEN 'levels.epic' THEN 'Set Epic, Legendary or Mythic. Commands: !epic <1-10>, !legendary <1-10>, !mythic <1-10>'
    WHEN 'levels.demon' THEN 'Set demon difficulty. Command: !demon easy|medium|hard|insane|extreme'
    WHEN 'users.ban' THEN 'Ban or unban users. Commands: !ban <username>, !unban <username>'
    WHEN 'users.leaderboard_ban' THEN 'Exclude or restore users in leaderboards. Commands: !leaderboardban <username>, !leaderboardunban <username>'
    WHEN 'rotations.daily' THEN 'Queue a Daily level. Command: !daily'
    WHEN 'rotations.weekly' THEN 'Queue a Weekly level. Command: !weekly'
    WHEN 'rotations.daily.force' THEN 'Force a Daily active immediately. Commands: !daily now, !daily force'
    WHEN 'rotations.weekly.force' THEN 'Force a Weekly active immediately. Commands: !weekly now, !weekly force'
    WHEN 'events.create' THEN 'Create an event. Command: !event duration=<1h-90d> reward=<type:amount,...> [start=now]'
    WHEN 'events.change' THEN 'Change an existing event. Command: !eventchange [duration=<1h-90d>] [reward=<type:amount,...>] [start=now]'
    WHEN 'events.set' THEN 'Create or fully replace an event. Command: !eventset duration=<1h-90d> reward=<type:amount,...> [start=now]'
    ELSE `description`
END
WHERE `permissionKey` IN (
    'levels.rate',
    'levels.feature',
    'levels.epic',
    'levels.demon',
    'users.ban',
    'users.leaderboard_ban',
    'rotations.daily',
    'rotations.weekly',
    'rotations.daily.force',
    'rotations.weekly.force',
    'events.create',
    'events.change',
    'events.set'
);
