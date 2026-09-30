<?php echo head(['title' => __('Package a Static Site Export'), 'bodyclass' => 'archivematica browse']); ?>
<?php echo flash(); ?>

<?php if (empty($staticSiteExports)): ?>
<p><?php echo __('No completed static site exports found yet. Run a Static Site Export first.'); ?></p>
<?php else: ?>
<div class="table-responsive">
<table>
    <thead>
        <tr>
            <th><?php echo __('Static Site Export'); ?></th>
            <th><?php echo __('Exported'); ?></th>
            <th><?php echo __('SIP Status'); ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($staticSiteExports as $row): ?>
        <tr>
            <td><?php echo html_escape($row['static_site_name']); ?></td>
            <td><?php echo format_date($row['static_site_added']); ?></td>
            <td>
                <?php if ($row['sip_status']): ?>
                    <?php echo html_escape($row['sip_status']); ?>
                    <?php if ($row['sip_status'] == Process::STATUS_ERROR && $row['sip_error_message']): ?>
                    <p class="explanation"><?php echo html_escape($row['sip_error_message']); ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <?php echo __('Not packaged'); ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!in_array($row['sip_status'], [Process::STATUS_STARTING, Process::STATUS_IN_PROGRESS])): ?>
                <form method="post" action="<?php echo url(['controller' => 'index', 'action' => 'package', 'id' => $row['static_site_id']]); ?>">
                    <?php echo $csrf; ?>
                    <input type="submit" value="<?php echo __('Create SIP'); ?>">
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php echo foot(); ?>
