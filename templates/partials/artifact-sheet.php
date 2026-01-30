<?php

use App\Domain\Model\Document;

/**
 * @var Document $document
 */
$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$content = $document->content ?? '';

// Parse CSV content
$rows = [];
if (!empty($content)) {
    $lines = explode("\n", mb_trim($content));
    foreach ($lines as $line) {
        if (!empty(mb_trim($line))) {
            $rows[] = str_getcsv($line);
        }
    }
}
$headers = $rows[0] ?? [];
$dataRows = array_slice($rows, 1);
?>
<div class="artifact-sheet">
    <div class="artifact-sheet-container">
        <table class="sheet-table" id="artifact-content-text">
            <?php if (!empty($headers)) { ?>
                <thead>
                    <tr>
                        <th class="row-number">#</th>
                        <?php foreach ($headers as $header) { ?>
                            <th><?php echo $e($header); ?></th>
                        <?php } ?>
                    </tr>
                </thead>
            <?php } ?>
            <tbody>
                <?php foreach ($dataRows as $index => $row) { ?>
                    <tr>
                        <td class="row-number"><?php echo $index + 1; ?></td>
                        <?php foreach ($row as $cell) { ?>
                            <td><?php echo $e($cell); ?></td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <div class="artifact-sheet-edit" data-show="$_artifactEditing">
        <textarea
            class="artifact-csv-textarea"
            data-bind="_artifactContent"
            placeholder="Enter CSV data..."
            spellcheck="false"
        ><?php echo $e($content); ?></textarea>

        <div class="artifact-edit-actions">
            <button class="btn btn-secondary" data-on:click="$_artifactEditing = false">
                Cancel
            </button>
            <button class="btn btn-primary"
                    data-on:click="@put('/cmd/document/<?php echo $e($document->id); ?>', {payload: {content: $_artifactContent}}); $_artifactEditing = false">
                Save
            </button>
        </div>
    </div>

    <button class="btn btn-edit" data-show="!$_artifactEditing"
            data-on:click="$_artifactEditing = true; $_artifactContent = <?php echo $e(json_encode($content)); ?>">
        <i class="fas fa-edit"></i> Edit Data
    </button>
</div>
