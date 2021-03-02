<?php
authorize();

if (!check_perms('site_moderate_forums')) {
    error(403);
}

$threadId = (int)$_POST['threadid'];
$forum = (new Gazelle\Manager\Forum)->findByThreadId($threadId);
if (is_null($forum)) {
    error(404);
}
if (!in_array($forum->id(), $ForumsRevealVoters)) {
    error(403);
}

$forum->addPollAnswer($threadId, trim($_POST['new_option']));

header("Location: forums.php?action=viewthread&threadid=$threadId");
