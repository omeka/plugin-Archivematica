<?php queue_css_file('archivematica'); ?>
<?php echo head(['title' => __('Archivematica'), 'bodyclass' => 'archivematica browse']); ?>
<?php echo $this->partial('archivematica-navigation.php'); ?>
<?php echo flash(); ?>

<a href="<?php echo html_escape(url(['controller' => 'import', 'action' => 'add'])); ?>" class="archivematica-add add full-width-mobile button green"><?php echo __('Import DIP'); ?></a>

<?php if (empty($archivematica_imports)): ?>
<p><?php echo __('No DIPs have been imported yet.'); ?></p>
<?php else: ?>
<?php echo pagination_links(); ?>
<div class="table-responsive">
<table>
    <thead>
        <tr>
            <th><?php echo __('DIP File'); ?></th>
            <th><?php echo __('Comment'); ?></th>
            <th><?php echo __('Items'); ?></th>
            <th><?php echo __('Status'); ?></th>
            <th><?php echo __('Imported'); ?></th>
            <th><?php echo __('Owner'); ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($archivematica_imports as $import): ?>
        <?php
        $itemCount = get_db()->getTable('ArchivematicaItem')->count(['import_id' => $import->id]);
        $owner = $import->getOwner();
        ?>
        <tr>
            <td><?php echo html_escape($import->original_filename); ?></td>
            <td><?php echo html_escape($import->comment); ?></td>
            <td>
                <?php if ($itemCount): ?>
                <a href="<?php echo html_escape(url('items/browse', ['archivematica_import_id' => $import->id])); ?>"><?php echo __(plural('1 item', '%s items', $itemCount), $itemCount); ?></a>
                <?php endif; ?>
                <p class="explanation"><?php echo __('Added: %1$s, Updated: %2$s', $import->added_count, $import->updated_count); ?></p>
            </td>
            <td>
                <?php echo html_escape($import->status); ?>
                <?php if ($import->status == Process::STATUS_ERROR && $import->error_message): ?>
                <p class="explanation"><?php echo html_escape($import->error_message); ?></p>
                <?php endif; ?>
                <?php if ($skippedCounts = $import->getSkippedFileCounts()): ?>
                <?php
                $skippedParts = [];
                foreach ($skippedCounts as $extension => $count) {
                    $skippedParts[] = sprintf('%s (%s)', $extension === '' ? __('no extension') : $extension, number_format($count));
                }
                ?>
                <p class="explanation"><?php echo __('File types not allowed/skipped: %s', html_escape(implode(', ', $skippedParts))); ?></p>
                <?php endif; ?>
            </td>
            <td><?php echo format_date($import->added); ?></td>
            <td><?php echo $owner ? html_escape($owner->name) : ''; ?></td>
            <td>
                <?php if ($import->canUndo()): ?>
                <form method="post" action="<?php echo url(['controller' => 'import', 'action' => 'undo', 'id' => $import->id]); ?>">
                    <?php echo $csrf; ?>
                    <input type="submit" class="red button" value="<?php echo __('Undo'); ?>">
                </form>
                <?php elseif ($import->status == ArchivematicaImport::STATUS_UNDONE): ?>
                <?php echo __('Undone'); ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php echo pagination_links(); ?>
<?php endif; ?>

<?php echo foot(); ?>
