<?php queue_css_file('archivematica'); ?>
<?php echo head(['title' => __('Archivematica'), 'bodyclass' => 'archivematica browse']); ?>
<?php echo flash(); ?>

<a href="<?php echo html_escape(url(['controller' => 'index', 'action' => 'create'])); ?>" class="archivematica-create-sip add full-width-mobile button green"><?php echo __('Create SIP'); ?></a>

<?php if (empty($sips)): ?>
<p><?php echo __('No SIPs have been created yet.'); ?></p>
<?php else: ?>
<div class="table-responsive">
<table>
    <thead>
        <tr>
            <th><?php echo __('Source'); ?></th>
            <th><?php echo __('SIP Name'); ?></th>
            <th><?php echo __('Status'); ?></th>
            <th><?php echo __('Created'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($sips as $sip): ?>
        <tr>
            <td>
                <?php if ($sip['source_type'] === 'static_site'): ?>
                    <strong><?php echo __('Static Site Export'); ?>:</strong> <?php echo html_escape($sip['static_site_name'] ?: sprintf('#%d', $sip['source_id'])); ?>
                <?php elseif ($sip['source_type'] === 'items'): ?>
                    <?php
                    $data = json_decode($sip['data'], true) ?: [];
                    $itemCount = $data['item_count'] ?? 0;
                    // Flatten the filter list to plain text
                    preg_match_all('#<li[^>]*>(.*?)</li>#', item_search_filters($data['query'] ?? []), $filters);
                    ?>
                    <?php if ($filters[1]): ?>
                        <strong><?php echo __(plural('1 item from query:', '%s items from query:', $itemCount), $itemCount); ?></strong>
                        <?php echo implode('; ', $filters[1]); ?>
                    <?php else: ?>
                        <strong><?php echo __(plural('1 item', '%s items', $itemCount), $itemCount); ?></strong>
                    <?php endif; ?>
                <?php else: ?>
                    <?php echo html_escape($sip['source_type']); ?> #<?php echo html_escape($sip['source_id']); ?>
                <?php endif; ?>
            </td>
            <td><?php echo html_escape($sip['sip_name'] ?: __('(pending)')); ?></td>
            <td>
                <?php echo html_escape($sip['status']); ?>
                <?php if ($sip['status'] == Process::STATUS_ERROR && $sip['error_message']): ?>
                <p class="explanation"><?php echo html_escape($sip['error_message']); ?></p>
                <?php endif; ?>
            </td>
            <td><?php echo format_date($sip['added']); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php echo foot(); ?>
