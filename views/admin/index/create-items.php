<?php queue_css_file('archivematica'); ?>
<?php echo head(['title' => __('Package Items'), 'bodyclass' => 'archivematica items advanced-search']); ?>
<?php echo flash(); ?>

<?php if (isset($itemCount)): ?>
<?php if ($itemCount): ?>
<form method="post" action="<?php echo url(['controller' => 'index', 'action' => 'package-items']); ?>">
    <?php echo $this->formHidden('query', http_build_query($query)); ?>
    <div class="field">
        <div class="two columns alpha">
            <label for="include_items_without_files"><?php echo __('Include items without files'); ?></label>
        </div>
        <div class="inputs five columns omega">
            <p class="explanation"><?php echo __('Archivematica requires a corresponding content file for every entry in metadata.csv. If checked, generate a JSON metadata snapshot file for Omeka items without file/s to point to. If unchecked, skip items without file/s.'); ?></p>
            <?php echo $this->formCheckbox('include_items_without_files', true, ['checked' => true]); ?>
        </div>
    </div>
    <?php echo $csrf; ?>
    <input type="submit" class="submit big green button" value="<?php echo __('Create SIP'); ?>">
</form>
<?php endif; ?>

<div class="archivematica-results-summary">
    <?php echo item_search_filters($query); ?>
    <p><?php echo __(plural('1 item matches this search.', '%s items match this search.', $itemCount), $itemCount); ?></p>
    <?php echo pagination_links(); ?>
</div>

<?php if ($itemCount): ?>
<div class="table-responsive">
<table>
    <thead>
        <tr>
            <th><?php echo __('Title'); ?></th>
            <th><?php echo __('Files'); ?></th>
            <th><?php echo __('Date Added'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $item): ?>
        <tr>
            <td>
                <span class="title"><?php echo link_to_item(metadata($item, 'rich_title', ['no_escape' => true]), [], 'show', $item); ?></span>
            </td>
            <td><?php echo $item->fileCount(); ?></td>
            <td><?php echo format_date($item->added); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php echo pagination_links(); ?>
<?php endif; ?>
<?php endif; ?>

<?php echo items_search_form(
    ['id' => 'advanced-search-form'],
    url(['controller' => 'index', 'action' => 'create-items']),
    __('Find items')
); ?>

<?php echo foot(); ?>
