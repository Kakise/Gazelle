<?php

if (!$Viewer->hasAttr('feature-seedbox') && !$Viewer->permitted('users_view_ips')) {
    error(403);
}

if (!isset($_POST['action'])) {
    $userId = (int)($_GET['userid'] ?? $Viewer->id());
} else {
    authorize();
    $userId = (int)$_POST['userid'];
}
$user = (new Gazelle\Manager\User)->findById($userId);
if (!$user) {
    error(404);
}
if ($Viewer->id() != $userId && !$Viewer->permitted('users_view_ips')) {
    error(403);
}

$union = trim($_REQUEST['view'] ?? 'union') === 'union';
$source = ($_REQUEST['source'] ?? null);
$target = ($_REQUEST['target'] ?? null);

$seedbox = new Gazelle\Seedbox($user);
if (isset($_POST['action']) || isset($_REQUEST['viewby'])) {
    if (is_null($source) || is_null($target) || $source === $target) {
        error("Invalid comparison between two seedbox instances");
    }
    $seedbox->setSource($source)
        ->setTarget($target)
        ->setUnion($union);
    if (isset($_REQUEST['viewby']) && $_REQUEST['viewby'] == Gazelle\Seedbox::VIEW_BY_PATH) {
        $seedbox->setViewByPath();
    } else {
        $seedbox->setViewByName();
    }
    // this seems hackish
    if (isset($_POST['action'])) {
        $_SERVER['REQUEST_URI'] .= "&source={$_POST['source']}&target={$_POST['target']}&viewby={$_POST['viewby']}&view=" . ($union ? 'union' : 'exclude');
    }
}

$paginator = new Gazelle\Util\Paginator(TORRENTS_PER_PAGE, (int)($_REQUEST['page'] ?? 1));
$paginator->setTotal($seedbox->total());

View::show_header($user->username() . ' &rsaquo; Seedboxes &rsaquo; View');
?>
<div class="thin">
    <div class="header">
        <h2><?=Users::format_username($userId, false, false, false)?> &rsaquo; Seedboxes</h2>
        <div class="linkbox">
            <a href="user.php?action=seedbox&amp;userid=<?= $userId ?>" class="brackets">Configure</a>
            <a href="user.php?action=seedbox-view&amp;userid=<?= $userId ?>" class="brackets">View</a>
        </div>
    </div>
<?php

if ($source && $target) {
    echo $Twig->render('seedbox/report.twig', [
        'list' => $seedbox->torrentList(
            new Gazelle\Manager\Torrent,
            $paginator->limit(),
            $paginator->offset()
        ),
        'auth'      => $Viewer->auth(),
        'mode'      => $union ? 'union' : 'exclude',
        'paginator' => $paginator,
        'source_id' => $source,
        'target_id' => $target,
        'source'    => $seedbox->name($source),
        'target'    => $seedbox->name($target),
        'user_id'   => $user->id(),
    ]);
}

echo $Twig->render('seedbox/view.twig', [
    'auth'    => $Viewer->auth(),
    'host'    => $seedbox->hostList(),
    'mode'    => $union ? 'union' : 'exclude',
    'source'  => $source,
    'target'  => $target,
    'user_id' => $userId,
    'viewby'  => $seedbox->viewBy(),
]);
