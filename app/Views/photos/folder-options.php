<?php
/** @var list<array> $folders */
/** @var int $selectedFolder */
/** @var int $excludedFolder */
$folderMap = array_column($folders, null, 'id');
?>
<option value="0" <?= (int) $selectedFolder === 0 ? 'selected' : '' ?>>/ Akar album</option>
<?php foreach ($folders as $destination): ?>
    <?php
    if ((int) $destination['id'] === (int) ($excludedFolder ?? 0)) {
        continue;
    }
    $parts = [$destination['name']];
    $parent = (int) $destination['parent_id'];
    $seen = [(int) $destination['id'] => true];
    while ($parent > 0 && isset($folderMap[$parent]) && ! isset($seen[$parent]) && count($parts) < 20) {
        $seen[$parent] = true;
        array_unshift($parts, $folderMap[$parent]['name']);
        $parent = (int) $folderMap[$parent]['parent_id'];
    }
    ?>
    <option value="<?= (int) $destination['id'] ?>" <?= (int) $selectedFolder === (int) $destination['id'] ? 'selected' : '' ?>>/ <?= esc(implode(' / ', $parts)) ?></option>
<?php endforeach ?>