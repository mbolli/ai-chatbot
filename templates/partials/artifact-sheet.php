<?php

use App\Domain\Model\Document;

/**
 * @var Document $document
 * @var bool $canEdit
 * @var array<string, string> $actions Action URLs
 * @var string $contentSignal
 * @var callable $e Escape function
 */
$content = $document->content ?? '';

$rows = [];
foreach (explode("\n", mb_trim($content)) as $line) {
    if (mb_trim($line) !== '') {
        $rows[] = str_getcsv($line, escape: '');
    }
}
$headers = $rows[0] ?? [];
$dataRows = array_slice($rows, 1);
?>
<div class="artifact-sheet">
    <div class="artifact-sheet-container">
        <table class="sheet-table" id="artifact-content-text">
            <?php if ($headers !== []) { ?>
                <thead>
                    <tr>
                        <th class="row-number">#</th>
                        <?php foreach ($headers as $header) { ?>
                            <th><?php echo $e((string) $header); ?></th>
                        <?php } ?>
                    </tr>
                </thead>
            <?php } ?>
            <tbody>
                <?php foreach ($dataRows as $index => $row) { ?>
                    <tr>
                        <td class="row-number"><?php echo $index + 1; ?></td>
                        <?php foreach ($row as $cell) { ?>
                            <td><?php echo $e((string) $cell); ?></td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <?php if ($canEdit) {
        [$editLabel, $textareaClass, $placeholder] = ['Edit Data', 'artifact-csv-textarea', 'Enter CSV data...'];

        include __DIR__ . '/artifact-editor.php';
    } ?>
</div>
